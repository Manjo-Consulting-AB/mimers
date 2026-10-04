<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 720 · Schemats sida får knapparna Pause/Resume och Delete. Se
 * App\Http\Controllers\ScheduleOccurrenceController::show(),
 * App\Http\Controllers\ScheduleController::update() och
 * resources/js/pages/Containers/Items/Schedules/Show.vue.
 *
 * Itemets Tasks-flik blir en lista av förekomster (issue 227), och
 * hanteringen av REGELN flyttar till regelns egen sida. Den här filen bevisar
 * den nya platsen:
 *
 * 1. **Grinden per nivå** — `can.delete` följer med schemats sida bredvid
 *    `can.update` (Beslut 1), samma pinne som ScheduleController::destroy()
 *    prövar: ägaren ser båda, en `write`-mottagare ser pausen men inte
 *    raderingen.
 * 2. **Pausen landar på schemats sida** — en PATCH som bär bara `is_active`
 *    kommer från pausknappen och svarar med en omdirigering tillbaka dit
 *    (Beslut 3).
 * 3. **Formuläret och raderingen landar på itemet** — en sparning bär fler
 *    fält, och efter en radering finns schemats sida inte kvar (Beslut 3).
 * 4. **Knapparna ritas efter `can`** — pausen när `can.update`, raderingen
 *    när `can.delete`, och raderingen frågar först (Beslut 2).
 *
 * Hjälparna har prefixet `schemasidan` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs, och SchemavyTest.php har redan `schemavy*`.
 */

/**
 * Ett konto med en medlem, och en container med ett item under kontot — samma
 * uppsättning som SchemavyTest::schemavyKontext(), med svensk locale så
 * flashmeddelandena går att jämföra mot `lang/`.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function schemasidanKontext(): array
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
 * En mottagare UTANFÖR ägarkontot, med en itemgrant på angiven nivå.
 */
function schemasidanMottagare(Container $container, Item $item, string $niva): User
{
    $mottagare = User::factory()->create(['locale' => 'sv_SE']);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $niva,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/**
 * Ett schema på itemet — samma standardrad som SchemavyTest::schemavySchema().
 *
 * @param  array<string, mixed>  $attribut
 */
function schemasidanSchema(Item $item, array $attribut = []): Schedule
{
    return Schedule::factory()->for($item, 'item')->create(array_merge([
        'title' => 'Byt olja',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2027-05-05',
        'lead_days' => 0,
        'is_active' => true,
    ], $attribut));
}

/**
 * Den öppna förekomsten. Byggd direkt så förfallodatumet är känt, precis som
 * SchemavyTest gör — ingen kalender att räkna.
 */
function schemasidanFörekomst(Schedule $schedule, string $due): ScheduleOccurrence
{
    return ScheduleOccurrence::factory()->create([
        'schedule_id' => $schedule->id,
        'due_at' => $due,
        'visible_from' => $due,
        'status' => 'open',
    ]);
}

function schemasidanItemUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}";
}

/**
 * Schemats sida — samma URL för GET, PATCH och DELETE: den är både ytan
 * knapparna ritas på och rutten de anropar.
 */
function schemasidanSidaUrl(Container $container, Item $item, Schedule $schedule): string
{
    return schemasidanItemUrl($container, $item)."/schedules/{$schedule->ulid}";
}

// --- grinden per nivå -----------------------------------------------------

/*
 * Klart när: `skickar can.delete till schemats sida` — ägare: can.delete ===
 * true, write-mottagare: can.delete === false och can.update === true.
 */
it('skickar can.delete till schemats sida', function () {
    withoutVite();

    [$konto, $agaren, $container, $item] = schemasidanKontext();
    $schema = schemasidanSchema($item);

    actingAs($agaren)->get(schemasidanSidaUrl($container, $item, $schema))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('can.update', true)
            ->where('can.delete', true)
    );

    $skrivare = schemasidanMottagare($container, $item, 'write');

    // Laddern är kumulativ ([[ADR-0028 Åtkomst på itemnivå]]): `write` står
    // över `create`, så en skrivare får pausa men inte radera. Det är samma
    // pinne som ScheduleController::destroy() prövar (Beslut 1).
    actingAs($skrivare)->get(schemasidanSidaUrl($container, $item, $schema))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('can.update', true)
            ->where('can.delete', false)
    );
});

// --- vart svaret landar ---------------------------------------------------

/*
 * Klart när: `en paus från schemats sida landar på schemats sida` — PATCH
 * {is_active: false} → redirect till schemats sida, status schedule-paused.
 *
 * `from()` sätter `Referer` till schemats sida, precis som webbläsaren gör när
 * knappen trycks där (Beslut 3). Utan den landar pausen på itemet, som förut —
 * se `pausar och återupptar med en PATCH som bär bara is_active` i
 * SchemavyTest.php, som prövar just den vägen.
 */
it('en paus från schemats sida landar på schemats sida', function () {
    [$konto, $anvandare, $container, $item] = schemasidanKontext();

    $schema = schemasidanSchema($item);
    schemasidanFörekomst($schema, '2027-05-05');

    actingAs($anvandare)
        ->from(schemasidanSidaUrl($container, $item, $schema))
        ->patch(schemasidanSidaUrl($container, $item, $schema), ['is_active' => false])
        ->assertRedirect(schemasidanSidaUrl($container, $item, $schema))
        ->assertSessionHas('status', 'schedule-paused');

    expect($schema->fresh()->is_active)->toBeFalse();
    // Att pausa rör ALDRIG den öppna förekomsten (Beslut 6, issue 63c).
    expect($schema->openOccurrence()->count())->toBe(1);
});

/*
 * Motsatt gren: samma PATCH, men från itemets flik, landar på itemet —
 * knapparna står kvar i ScheduleListSection.vue tills issue 227 tar bort dem,
 * och en paus där ska inte kasta användaren till en annan sida.
 */
it('en paus från itemets flik landar fortfarande på itemet', function () {
    [$konto, $anvandare, $container, $item] = schemasidanKontext();

    $schema = schemasidanSchema($item);
    schemasidanFörekomst($schema, '2027-05-05');

    actingAs($anvandare)
        ->from(schemasidanItemUrl($container, $item))
        ->patch(schemasidanSidaUrl($container, $item, $schema), ['is_active' => false])
        ->assertRedirect(schemasidanItemUrl($container, $item))
        ->assertSessionHas('status', 'schedule-paused');

    expect($schema->fresh()->is_active)->toBeFalse();
});

/*
 * Klart när: `en sparning från formuläret landar fortfarande på itemet` —
 * PATCH med title → redirect till itemets sida.
 */
it('en sparning från formuläret landar fortfarande på itemet', function () {
    [$konto, $anvandare, $container, $item] = schemasidanKontext();

    $schema = schemasidanSchema($item);

    actingAs($anvandare)->patch(schemasidanSidaUrl($container, $item, $schema), [
        'title' => 'Byt olja och filter',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 6,
        'anchor_date' => '2027-05-05',
        'lead_days' => 7,
    ])
        ->assertRedirect(schemasidanItemUrl($container, $item))
        ->assertSessionHas('status', 'schedule-updated');

    expect($schema->fresh()->title)->toBe('Byt olja och filter');
});

// --- raderingen -----------------------------------------------------------

/*
 * Klart när: `raderingen landar på itemet` — DELETE → redirect till itemets
 * sida, och schemat är mjukraderat.
 */
it('raderingen landar på itemet', function () {
    [$konto, $anvandare, $container, $item] = schemasidanKontext();

    $schema = schemasidanSchema($item);
    schemasidanFörekomst($schema, '2027-05-05');

    actingAs($anvandare)->delete(schemasidanSidaUrl($container, $item, $schema))
        ->assertRedirect(schemasidanItemUrl($container, $item))
        ->assertSessionHas('status', 'schedule-deleted');

    // Mjuk radering: raden ligger kvar med `deleted_at`.
    expect(Schedule::query()->count())->toBe(0);
    expect(Schedule::query()->onlyTrashed()->count())->toBe(1);
});

/*
 * Klart när: `en write-mottagare kan inte radera från schemats sida` —
 * DELETE → 403.
 */
it('en write-mottagare kan inte radera från schemats sida', function () {
    [$konto, $agaren, $container, $item] = schemasidanKontext();

    $schema = schemasidanSchema($item);
    $skrivare = schemasidanMottagare($container, $item, 'write');

    actingAs($skrivare)->delete(schemasidanSidaUrl($container, $item, $schema))->assertForbidden();

    expect(Schedule::query()->count())->toBe(1);
});

// --- vyn ------------------------------------------------------------------

/*
 * Klart när: `sidan ritar pausen och raderingen efter can` — Show.vue
 * innehåller `v-if="can.update"` runt pausknappen, `v-if="can.delete"` runt
 * raderingen och `item.schedule.destroy_confirm`.
 *
 * Källkodsprov: knappen skärs ut ur filen först, så att nålen hör till just
 * den knappen och inte till Edit-länken intill, som bär samma `can.update`.
 */
it('sidan ritar pausen och raderingen efter can', function () {
    $vy = File::get(resource_path('js/pages/Containers/Items/Schedules/Show.vue'));

    preg_match('/<button\b[^>]*@click="toggle"[^>]*>/s', $vy, $paus);
    expect($paus)->not->toBeEmpty('pausknappen (Pause/Resume) saknas i Show.vue');
    expect($paus[0])->toContain('v-if="can.update"');

    preg_match('/<button\b[^>]*@click="destroy"[^>]*>/s', $vy, $radering);
    expect($radering)->not->toBeEmpty('raderingsknappen saknas i Show.vue');
    expect($radering[0])->toContain('v-if="can.delete"');

    // Raderingen frågar innan den raderar, med meningens text ur `lang/` —
    // ingen sträng i JavaScript (Beslut 2, samma mönster som listan).
    expect($vy)->toContain('window.confirm')
        ->and($vy)->toContain("t('item.schedule.destroy_confirm')");

    // Pausen är synlig och reversibel (Beslut 3): samma märke och mening som
    // listan använder.
    expect($vy)->toContain("t('item.schedule.paused')")
        ->and($vy)->toContain("t('item.schedule.paused_note')");
});
