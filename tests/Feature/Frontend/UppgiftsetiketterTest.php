<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * M24 · Tre etiketter skrivs om när uppgiften syns från att den skapas (issue
 * 698), räknarna är försenat + i dag (issue 697) och ett ändrat datum flyttar
 * den öppna uppgiften (issue 699). Tonys beslut 2026-10-03 — issue 702.
 *
 * Fyra beslut, fyra prov:
 *
 * 1. Påminnelsedagen heter *Reminder :date* och ritas bara när den skiljer sig
 *    från förfallet (`occurrence.visible_from !== occurrence.due_at`, samma
 *    sak som `lead_days > 0`).
 * 2. Räknarbrickorna heter *Tasks due* på dashboarden och på containerns
 *    översikt och hjälte. Den röda *Overdue: :count* rörs inte.
 * 3. Redigeringsformulärets datumfält heter *Next due date* och är förifyllt
 *    med den öppna förekomstens förfall; skapandeformuläret behåller *First
 *    due date*, och för `fixed` gäller *Start of the series*.
 * 4. En sparning utan att röra fältet flyttar ingenting i praktiken (#699
 *    sätter `due_at` till samma datum).
 *
 * Klockan är fryst i det sista provet: `interval` räknar nästa förfall från
 * avbockningens dag, så utan en fryst tid vore sviten beroende av kördatumet
 * (samma skäl som i ForekomstflyttTest).
 *
 * Hjälparna har prefixet `etikett` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-06-15 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en medlem, en container och ett item under kontot.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function etikettKontext(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

/**
 * Ett intervallschema på itemet, byggt direkt så att `anchor_date` är känt.
 *
 * @param  array<string, mixed>  $attribut
 */
function etikettSchema(Item $item, array $attribut = []): Schedule
{
    return Schedule::factory()->for($item, 'item')->create(array_merge([
        'title' => 'Byt olja',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2026-06-01',
        'lead_days' => 0,
        'is_active' => true,
    ], $attribut));
}

/**
 * Den öppna förekomsten på ett schema, med ett känt förfall.
 */
function etikettFörekomst(Schedule $schedule, string $due): ScheduleOccurrence
{
    return ScheduleOccurrence::factory()->create([
        'schedule_id' => $schedule->id,
        'due_at' => $due,
        'visible_from' => $due,
        'status' => 'open',
    ]);
}

function etikettSchemaUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}/schedules";
}

/*
 * Bockar av den öppna förekomsten via webben — samma väg som användaren, så
 * att App\Actions\Schedule\CloseOccurrence öppnar nästa förekomst.
 */
function etikettStäng(
    User $anvandare,
    Account $konto,
    Container $container,
    Item $item,
    Schedule $schema,
    ScheduleOccurrence $förekomst,
): void {
    actingAs($anvandare)
        ->post(
            etikettSchemaUrl($container, $item)."/{$schema->ulid}/occurrences/{$förekomst->ulid}/complete",
            ['account' => $konto->ulid],
        )
        ->assertRedirect();
}

// --- Beslut 1: påminnelsedagen ---------------------------------------------

/*
 * Klart när: `trans('ui.item.schedule.occurrence.visible_from', ['date' =>
 * 'X'])` är `Reminder X`.
 */
it('kallar påminnelsedagen Reminder', function () {
    expect(trans('ui.item.schedule.occurrence.visible_from', ['date' => '2027-05-05'], 'en'))
        ->toBe('Reminder 2027-05-05');
});

/*
 * Klart när: OpenOccurrence.vue ritar raden bara när `visible_from` skiljer
 * sig från `due_at`. Är de samma dag finns ingen påminnelse, och villkoret är
 * `lead_days > 0` — ingen ny prop (Beslut 1).
 */
it('ritar påminnelsedagen bara när den skiljer sig från förfallet', function () {
    $vy = File::get(resource_path('js/components/OpenOccurrence.vue'));

    expect($vy)->toContain('occurrence.visible_from !== occurrence.due_at')
        ->toContain("t('item.schedule.occurrence.visible_from'");
});

// --- Beslut 2: räknarbrickorna ---------------------------------------------

/*
 * Klart när: `trans('ui.dashboard.stats.tasks')` och
 * `trans('ui.container.overview.todos')` är båda `Tasks due`, och
 * `trans('ui.dashboard.stats.overdue', ['count' => 2])` är oförändrat
 * `Overdue: 2`.
 */
it('kallar räknarbrickorna Tasks due', function () {
    expect(trans('ui.dashboard.stats.tasks', [], 'en'))->toBe('Tasks due');
    expect(trans('ui.container.overview.todos', [], 'en'))->toBe('Tasks due');
    expect(trans('ui.dashboard.stats.overdue', ['count' => 2], 'en'))->toBe('Overdue: 2');
});

// --- Beslut 3: redigeringsformulärets förfall ------------------------------

/*
 * Klart när: GET edit för ett intervallschema med en öppen förekomst med
 * `due_at` 2026-11-20 och `anchor_date` 2026-06-01 ger `openDueAt` =
 * 2026-11-20; för ett schema utan öppen förekomst är `openDueAt` null.
 */
it('skickar den öppna förekomstens förfall till redigeringssidan', function () {
    withoutVite();

    [, $anvandare, $container, $item] = etikettKontext();

    $schema = etikettSchema($item, ['anchor_date' => '2026-06-01']);
    etikettFörekomst($schema, '2026-11-20');

    actingAs($anvandare)
        ->get(etikettSchemaUrl($container, $item)."/{$schema->ulid}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('openDueAt', '2026-11-20'));

    $utan = etikettSchema($item, ['title' => 'Utan öppen förekomst']);

    actingAs($anvandare)
        ->get(etikettSchemaUrl($container, $item)."/{$utan->ulid}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('openDueAt', null));
});

/*
 * Klart när: ScheduleForm.vue innehåller `props.openDueAt`, `anchor_date_next`
 * och en `fixed`-gren; `trans('ui.item.schedule.form.anchor_date_next')` är
 * `Next due date` och `trans('ui.item.schedule.form.anchor_date')` är
 * fortfarande `First due date`.
 */
it('förifyller Next due date utom för fixed', function () {
    $vy = File::get(resource_path('js/components/ScheduleForm.vue'));

    expect($vy)->toContain('props.openDueAt')
        ->toContain('anchor_date_next');

    /*
     * `fixed`-grenen måste sitta i `fields`-blocket, där förifyllningen sker —
     * etikettens computed ligger utanför, och en träff där bevisar ingenting om
     * vad fältet startar med. Provet läser blocket och kräver att grenen står
     * före `props.openDueAt`.
     */
    preg_match('/(const fields = \{.*?\n\};)/s', $vy, $traff);
    $falt = $traff[1] ?? '';

    expect($falt)->toContain('props.openDueAt');

    $fixed = strpos($falt, "recurrence_type === 'fixed'");
    $oppen = strpos($falt, 'props.openDueAt');

    expect($fixed)->toBeInt();
    expect($oppen)->toBeInt();
    expect($fixed)->toBeLessThan($oppen);

    expect(trans('ui.item.schedule.form.anchor_date_next', [], 'en'))->toBe('Next due date');
    expect(trans('ui.item.schedule.form.anchor_date', [], 'en'))->toBe('First due date');
});

// --- Beslut 4: en orörd sparning flyttar inget -----------------------------

/*
 * Klart när: ett avbockat intervallschema har sin öppna förekomst på
 * 2027-06-15; en PATCH med formulärets fält och `anchor_date` = 2027-06-15
 * (det förifyllda värdet) lämnar förekomsten med samma ULID och `due_at`
 * 2027-06-15. Beror på #699.
 */
it('flyttar inget när formuläret sparas orört', function () {
    [$konto, $anvandare, $container, $item] = etikettKontext();

    $schema = etikettSchema($item, [
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2026-06-01',
    ]);

    // Den öppna förekomsten ligger på seriens startpunkt, som vid skapandet.
    $första = etikettFörekomst($schema, '2026-06-01');

    // Avbockningen flyttar nästa förfall till tolv månader från i dag
    // (2026-06-15) och inte från det gamla förfallet (issue 699 § Beslut 1).
    etikettStäng($anvandare, $konto, $container, $item, $schema, $första);

    $förekomst = $schema->openOccurrence()->sole();
    $ulid = $förekomst->ulid;

    expect($förekomst->due_at->toDateString())->toBe('2027-06-15');

    // Formuläret är orört: `openDueAt` förifyller `anchor_date` med
    // 2027-06-15, som skiljer sig från schemats sparade 2026-06-01 — det är
    // den sparning Beslut 4 kallar acceptabel. #699 sätter `due_at` till
    // samma datum, så ingen flytt sker i praktiken.
    actingAs($anvandare)
        ->patch(etikettSchemaUrl($container, $item)."/{$schema->ulid}", [
            'title' => 'Byt olja',
            'recurrence_type' => 'interval',
            'interval_unit' => 'month',
            'interval_count' => 12,
            'anchor_date' => '2027-06-15',
            'lead_days' => 0,
        ])
        ->assertRedirect();

    $efter = $schema->openOccurrence()->sole();

    expect($efter->ulid)->toBe($ulid);
    expect($efter->due_at->toDateString())->toBe('2027-06-15');
});
