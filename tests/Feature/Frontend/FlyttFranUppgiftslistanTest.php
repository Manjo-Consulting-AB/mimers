<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleDependency;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * M28 · issue 784 — flytten från uppgiftslistan. Se
 * resources/js/components/TodoRow.vue, resources/js/pages/Tasks/Index.vue,
 * App\Actions\Schedule\ListTodo::row() och [[ADR-0053 Flytt och kopiering]]
 * § 2 och § 6.
 *
 * **En uppgift i taget, och samma målväljare som på uppgiftens sida.** Det
 * finns ingen flervalsflytt: beroendena gör en flytt av flera betydligt
 * svårare, och det får vänta tills användningen motiverar det (Tonys beslut
 * 2026-10-07).
 *
 * Filen prövar de sex punkterna i "Klart när":
 *
 * 1. **Grinden på raden** är `delete` på itemet — `can.move` (Beslut 1).
 * 2. **En läsare** får `can.move` falskt.
 * 3. **Bara `/tasks` ritar menyn**: `Tasks/Index.vue` skickar `movable`, och
 *    ingen annan fil som ritar `TodoRow` gör det (Beslut 2).
 * 4. **Menyn ritas inte för en inboxuppgift** — raden villkorar på
 *    `entry.in_inbox` och på en avbockad rad (Beslut 2).
 * 5. **Flytten postar till den befintliga rutten** `…/move` med målväljaren
 *    (Beslut 3).
 * 6. **Ett domänfel ritas på raden**: en uppgift med beroenden nekas flytt
 *    till en annan container (§ 6), och raden läser fältfelet.
 *
 * Hjälparna har prefixet `flyttlista` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

function flyttlistaDatum(int $dagar): string
{
    return Carbon::today()->addDays($dagar)->toDateString();
}

/**
 * Ett konto med en medlem.
 *
 * @return array{0: Account, 1: User}
 */
function flyttlistaKonto(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare];
}

function flyttlistaItem(Container $container, string $namn = 'Motorn'): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * Ett konto, en medlem, en container och ett item under den.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function flyttlistaKontext(): array
{
    [$konto, $anvandare] = flyttlistaKonto();
    $container = Container::factory()->for($konto, 'account')->create(['name' => 'Varvet Norr']);
    $item = flyttlistaItem($container);

    return [$konto, $anvandare, $container, $item];
}

function flyttlistaSchema(Item $item, string $due, string $titel = 'Byt impeller'): Schedule
{
    return Schedule::factory()->for($item, 'item')->create([
        'title' => $titel,
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => $due,
        'lead_days' => 0,
        'is_active' => true,
    ]);
}

function flyttlistaFörekomst(Schedule $schema, string $due): ScheduleOccurrence
{
    return ScheduleOccurrence::factory()->create([
        'schedule_id' => $schema->id,
        'due_at' => $due,
        'visible_from' => flyttlistaDatum(-30),
        'status' => 'open',
    ]);
}

/**
 * En mottagare UTANFÖR ägarkontot, med en itemgrant på containernivå.
 */
function flyttlistaMottagare(Container $container, string $niva): User
{
    $mottagare = User::factory()->create(['locale' => 'sv_SE']);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => null,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $niva,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/**
 * Alla rader ur svaret, grupp för grupp — samma form som `todovyRader`.
 *
 * @return list<array<string, mixed>>
 */
function flyttlistaRader(TestResponse $svar): array
{
    /** @var array<string, list<array<string, mixed>>> $grupper */
    $grupper = $svar->inertiaProps()['groups'];

    return array_merge(
        $grupper['overdue'],
        $grupper['today'],
        $grupper['this_week'],
        $grupper['upcoming'],
        $grupper['no_date'],
    );
}

// --- Beslut 1: grinden -----------------------------------------------------

/*
 * Klart när: `raden bär can move när användaren får radera på itemet`.
 *
 * `can.move` räknas med `ItemPolicy::delete()` — samma grind som flytten
 * (ADR-0053 § 2) — och ägaren får den sann, precis som `can.update`.
 */
it('raden bär can move när användaren får radera på itemet', function () {
    withoutVite();

    [, $anvandare, , $item] = flyttlistaKontext();
    $schema = flyttlistaSchema($item, flyttlistaDatum(30));
    flyttlistaFörekomst($schema, flyttlistaDatum(30));

    $rader = flyttlistaRader(actingAs($anvandare)->get('/tasks')->assertOk());

    expect($rader)->toHaveCount(1)
        ->and($rader[0]['can']['move'])->toBeTrue()
        ->and($rader[0]['can']['update'])->toBeTrue();
});

/*
 * Klart när: `en läsare får can move false`.
 *
 * En flytt tar bort något från källan, och `read` räcker inte — flaggan är
 * presentation, och rutten auktoriserar ändå.
 */
it('en läsare får can move false', function () {
    withoutVite();

    [, , $container, $item] = flyttlistaKontext();
    $schema = flyttlistaSchema($item, flyttlistaDatum(30));
    flyttlistaFörekomst($schema, flyttlistaDatum(30));

    $lasare = flyttlistaMottagare($container, 'read');

    $rader = flyttlistaRader(actingAs($lasare)->get('/tasks')->assertOk());

    expect($rader)->toHaveCount(1)
        ->and($rader[0]['can']['move'])->toBeFalse();
});

// --- Beslut 2: radmenyn ----------------------------------------------------

/*
 * Klart när: `bara tasks-sidan ritar menyn`.
 *
 * `Tasks/Index.vue` skickar `movable`; de andra ytorna som ritar `TodoRow` —
 * containerns och itemets flikar och dashboardens panel — skickar inget, och
 * raden ritar då ingen meny. Flaggan är ytan och inte rätten.
 *
 * **Ytorna hittas ur koden själv** (granskningsfynd): varje `.vue`-fil som
 * importerar `TodoRow` ritar raden, och en handskriven lista missar den yta
 * som tillkommer nästa gång. Sökningen står på importen och inte på ordet
 * `TodoRow`, så rena omnämnanden i kommentarer — som i `Inbox/Index.vue`, vars
 * rad är en egen — hålls utanför.
 */
it('bara tasks-sidan ritar menyn', function () {
    $sidan = File::get(resource_path('js/pages/Tasks/Index.vue'));

    expect(str_contains($sidan, 'movable'))->toBeTrue('Tasks/Index.vue skickar inte movable');

    $ytor = collect(File::allFiles(resource_path('js')))
        ->filter(fn ($fil) => $fil->getExtension() === 'vue')
        ->filter(fn ($fil) => str_contains(File::get($fil), 'import TodoRow from'))
        ->reject(fn ($fil) => str_ends_with(str_replace('\\', '/', $fil->getPathname()), 'pages/Tasks/Index.vue'))
        ->values();

    expect($ytor)->not->toBeEmpty('ingen annan yta importerar TodoRow — provet prövar ingenting');

    foreach ($ytor as $fil) {
        expect(str_contains(File::get($fil), 'movable'))
            ->toBeFalse($fil->getRelativePathname().' ritar TodoRow med movable — bara /tasks bär menyn');
    }
});

/*
 * Klart när: `menyn ritas inte för en inboxuppgift` — raden villkorar på
 * `entry.in_inbox`, och aldrig på en avbockad rad.
 *
 * Menyns `<details>` skärs ut först, så att nålen hör till just den menyn och
 * inte till en annan `v-if` i filen.
 */
it('menyn ritas inte för en inboxuppgift', function () {
    $vy = File::get(resource_path('js/components/TodoRow.vue'));

    preg_match('/<details\b[^>]*v-if="([^"]*)"/s', $vy, $träff);
    expect($träff)->not->toBeEmpty('radmenyn (details) saknas i TodoRow.vue');

    $villkor = $träff[1];

    expect($villkor)->toContain('movable')
        ->and($villkor)->toContain('entry.can.move')
        ->and($villkor)->toContain('entry.in_inbox')
        ->and($villkor)->toContain('isDone');
});

// --- Beslut 3: flytten -----------------------------------------------------

/*
 * Klart när: `flytten postar till den befintliga rutten` — `TodoRow.vue`
 * innehåller `/move`, öppnar målväljaren och skickar `target` i kroppen.
 *
 * Ingen egen rutt och ingen egen väljare: samma anrop som uppgiftens sida.
 */
it('flytten postar till den befintliga rutten', function () {
    $vy = File::get(resource_path('js/components/TodoRow.vue'));

    expect($vy)->toContain('/move')
        ->and($vy)->toContain('<ItemTargetPicker')
        ->and($vy)->toContain('target: target.ulid')
        ->and($vy)->toContain("t('item.schedule.move_heading')");
});

/*
 * Klart när: `en flytt med beroenden till en annan container ger felet på
 * raden` — rutten svarar som förut, och raden läser felet.
 *
 * Rutten är oförändrad (ADR-0053 § 6, issue 240): ett beroende på
 * schemanivå nekar flytten UT ur containern med ett fältfel på `schedule`,
 * aldrig en JSON-kropp. Raden läser `errors.schedule` och ritar det i sin
 * ENDA felruta.
 */
it('en flytt med beroenden till en annan container ger felet på raden', function () {
    [, $anvandare, $container, $item] = flyttlistaKontext();

    $schema = flyttlistaSchema($item, flyttlistaDatum(30));
    flyttlistaFörekomst($schema, flyttlistaDatum(30));

    // Motparten ligger i SAMMA container — beroendet är giltigt där det
    // skrevs, och det är just därför flytten UT nekas.
    $motpart = flyttlistaSchema($item, flyttlistaDatum(20), 'Serva motorn');
    ScheduleDependency::factory()->create([
        'schedule_id' => $schema->id,
        'depends_on_schedule_id' => $motpart->id,
    ]);

    // Målet ligger i en ANNAN container.
    $annanContainer = Container::factory()->for($container->account, 'account')->create(['name' => 'Varvet Syd']);
    $mål = flyttlistaItem($annanContainer, 'Reservdelen');

    $svar = actingAs($anvandare)->post(
        "/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}/move",
        ['target' => $mål->ulid],
    );

    $svar->assertSessionHasErrors('schedule');
    expect(session('errors')->get('schedule')[0])
        ->toBe(Lang::get('ui.error.schedule.has_dependencies', [], 'en'));

    // Ingenting rördes: uppgiften ligger kvar på källan.
    expect($schema->fresh()->item_id)->toBe($item->id);

    // Raden läser fältfelet och ritar det på raden.
    $vy = File::get(resource_path('js/components/TodoRow.vue'));

    expect($vy)->toContain('errors.schedule')
        ->and($vy)->toContain('role="alert"');
});
