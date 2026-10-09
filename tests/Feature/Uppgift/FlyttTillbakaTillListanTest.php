<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleDependency;
use App\Models\User;
use Illuminate\Support\Facades\Lang;

use function Pest\Laravel\actingAs;

/*
 * M28 · issue 266 — flytten tillbaka till listan. Se
 * App\Http\Controllers\ScheduleController::move(),
 * resources/js/components/TodoRow.vue och [[ADR-0053 Flytt och kopiering]]
 * § 6.
 *
 * **Klienten säger vart svaret ska gå, och servern lyder** (Beslut 1–3).
 * Listan på `/tasks` postar `return: 'back'`, och svaret blir `back()` — samma
 * adress med samma frågesträng, så att servern filtrerar om listan och
 * användaren står kvar i samma vy. Utan fältet är svaret oförändrat och landar
 * på uppgiftens nya sida. Fältet valideras i kontrollern (`nullable`,
 * `in:back`), inte i den delade TargetItemRequest (Beslut 2), och ett
 * domänfel ritas på `schedule` oavsett `return` (Beslut 4).
 *
 * Filen prövar de fem serverpunkterna i "Klart när". Källkodsproven på
 * komponenterna bor i tests/Feature/Frontend/FlyttFranUppgiftslistanTest.php.
 *
 * Hjälparna har prefixet `flyttTillbaka` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem i given typ.
 *
 * @return array{0: Account, 1: User}
 */
function flyttTillbakaKonto(string $typ = 'personal'): array
{
    $konto = Account::factory()->create(['type' => $typ]);
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare];
}

function flyttTillbakaItem(Container $container, Account $konto, User $anvandare, string $namn = 'Motorn'): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);
}

/**
 * Ett konto, en medlem, en container och ett item under den.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function flyttTillbakaKontext(string $typ = 'personal'): array
{
    [$konto, $anvandare] = flyttTillbakaKonto($typ);
    $container = Container::factory()->for($konto, 'account')->create(['name' => 'Varvet Norr']);
    $item = flyttTillbakaItem($container, $konto, $anvandare);

    return [$konto, $anvandare, $container, $item];
}

function flyttTillbakaUrl(Container $container, Item $item, Schedule $schema): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}";
}

/**
 * Skapar ett schema via webben — samma väg som användaren, så att den första
 * förekomsten öppnas av App\Actions\Schedule\OpenNextOccurrence.
 */
function flyttTillbakaSkapa(User $anvandare, Container $container, Item $item, string $titel = 'Byt olja'): Schedule
{
    actingAs($anvandare)
        ->post("/containers/{$container->ulid}/items/{$item->ulid}/schedules", [
            'title' => $titel,
            'recurrence_type' => 'interval',
            'interval_unit' => 'month',
            'interval_count' => 1,
            'anchor_date' => '2026-07-01',
        ])
        ->assertRedirect();

    return Schedule::query()->orderByDesc('id')->firstOrFail();
}

/**
 * Listans adress, med samma frågesträng som `/tasks` postar `return: 'back'`
 * ifrån — flik, containerfilter och sortering.
 */
function flyttTillbakaLista(Container $container): string
{
    return "/tasks?list=active&container={$container->ulid}&sort=due_desc";
}

/**
 * En mottagare UTANFÖR ägarkontot, med en itemgrant på containernivå.
 */
function flyttTillbakaMottagare(Container $container, string $niva): User
{
    $mottagare = User::factory()->create();

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
 * Ett item i en ANNAN container, i ett annat konto, där $anvandare är medlem
 * — så att hon har `create` på målet.
 *
 * @return array{0: Container, 1: Item}
 */
function flyttTillbakaMål(User $anvandare): array
{
    [$annatKonto, $annanAgare] = flyttTillbakaKonto('organisation');
    $annatKonto->users()->attach($anvandare, ['role' => 'member']);
    $container = Container::factory()->for($annatKonto, 'account')->create(['name' => 'Kunden Syd']);
    $item = flyttTillbakaItem($container, $annatKonto, $annanAgare, 'Pumpen');

    return [$container, $item];
}

// --- Beslut 1 och 3: tillbaka till listan -----------------------------------

/*
 * Klart när: `en flytt med return back svarar tillbaka till listan` — med
 * `Referer` satt till listans adress (flik, containerfilter och sortering)
 * blir redirecten exakt den adressen, och flashkoden är `schedule-moved`.
 */
it('en flytt med return back svarar tillbaka till listan', function () {
    [$konto, $anvandare, $container, $item] = flyttTillbakaKontext();
    $mål = flyttTillbakaItem($container, $konto, $anvandare, 'Reservdelen');
    $schema = flyttTillbakaSkapa($anvandare, $container, $item);

    $lista = flyttTillbakaLista($container);

    actingAs($anvandare)
        ->from($lista)
        ->post(flyttTillbakaUrl($container, $item, $schema).'/move', [
            'target' => $mål->ulid,
            'return' => 'back',
        ])
        ->assertRedirect($lista)
        ->assertSessionHas('status', 'schedule-moved');

    // Uppgiften flyttade — svaret gick bara en annan väg.
    expect($schema->fresh()->item_id)->toBe($mål->id);
});

// --- Beslut 1 och 3: utan fältet är svaret oförändrat -----------------------

/*
 * Klart när: `en flytt utan return landar på uppgiftens nya sida` — samma
 * redirect som i dag, till schemats sida på det nya itemet.
 */
it('en flytt utan return landar på uppgiftens nya sida', function () {
    [$konto, $anvandare, $container, $item] = flyttTillbakaKontext();
    $mål = flyttTillbakaItem($container, $konto, $anvandare, 'Reservdelen');
    $schema = flyttTillbakaSkapa($anvandare, $container, $item);

    actingAs($anvandare)
        ->post(flyttTillbakaUrl($container, $item, $schema).'/move', ['target' => $mål->ulid])
        ->assertRedirect(route('containers.items.schedules.show', [$container, $mål, $schema]))
        ->assertSessionHas('status', 'schedule-moved');
});

// --- Beslut 2: valideringen ------------------------------------------------

/*
 * Klart när: `ett okänt return-värde ger 422` — `return: 'elsewhere'` ger ett
 * fältfel på `return`, och uppgiften är inte flyttad.
 */
it('ett okänt return-värde ger 422', function () {
    [$konto, $anvandare, $container, $item] = flyttTillbakaKontext();
    $mål = flyttTillbakaItem($container, $konto, $anvandare, 'Reservdelen');
    $schema = flyttTillbakaSkapa($anvandare, $container, $item);

    actingAs($anvandare)
        ->post(flyttTillbakaUrl($container, $item, $schema).'/move', [
            'target' => $mål->ulid,
            'return' => 'elsewhere',
        ])
        ->assertSessionHasErrors('return');

    // Valideringen står före flytten: ingenting rördes.
    expect($schema->fresh()->item_id)->toBe($item->id);
});

// --- Beslut 4: domänfelet är oförändrat ------------------------------------

/*
 * Klart när: `ett domänfel med return back ger fältfelet på schedule` — en
 * flytt med beroenden till en annan container nekas, och felet ritas på
 * `schedule` oavsett `return` (§ 6).
 */
it('ett domänfel med return back ger fältfelet på schedule', function () {
    [$konto, $anvandare, $container, $item] = flyttTillbakaKontext('organisation');
    [, $mål] = flyttTillbakaMål($anvandare);

    $schema = flyttTillbakaSkapa($anvandare, $container, $item);
    $motpart = flyttTillbakaSkapa($anvandare, $container, $item, 'Serva motorn');

    ScheduleDependency::factory()->create([
        'schedule_id' => $schema->id,
        'depends_on_schedule_id' => $motpart->id,
    ]);

    $svar = actingAs($anvandare)
        ->from(flyttTillbakaLista($container))
        ->post(flyttTillbakaUrl($container, $item, $schema).'/move', [
            'target' => $mål->ulid,
            'return' => 'back',
        ]);

    $svar->assertSessionHasErrors('schedule');
    expect(session('errors')->get('schedule')[0])
        ->toBe(Lang::get('ui.error.schedule.has_dependencies', [], 'en'));

    expect($schema->fresh()->item_id)->toBe($item->id);
});

// --- Beslut 2: grinden gäller även med return ------------------------------

/*
 * Klart när: `en läsare nekas även med return back` — `delete` på källans item
 * krävs, och ett giltigt `return` kringgår inte grinden. 403, och uppgiften är
 * inte flyttad.
 */
it('en läsare nekas även med return back', function () {
    [$konto, $agare, $container, $item] = flyttTillbakaKontext();
    $mål = flyttTillbakaItem($container, $konto, $agare, 'Reservdelen');
    $schema = flyttTillbakaSkapa($agare, $container, $item);

    $lasare = flyttTillbakaMottagare($container, 'read');

    actingAs($lasare)
        ->from(flyttTillbakaLista($container))
        ->post(flyttTillbakaUrl($container, $item, $schema).'/move', [
            'target' => $mål->ulid,
            'return' => 'back',
        ])
        ->assertForbidden();

    expect($schema->fresh()->item_id)->toBe($item->id);
});
