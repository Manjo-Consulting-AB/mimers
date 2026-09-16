<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 63a · Schemat som regel — sektionen på itemets detaljvy, de två
 * formulärsidorna, pausen och raderingen. Se
 * App\Http\Controllers\ScheduleController,
 * App\Http\Controllers\ItemController::show(),
 * resources/js/components/ScheduleListSection.vue,
 * resources/js/components/ScheduleForm.vue och
 * resources/js/components/schedulePresentation.js.
 *
 * Filen bevisar de fem gränserna issuen är byggd kring:
 *
 * 1. **Listan** — schemana kommer med detaljvyns props, sorterade på titel som
 *    `/api`, med återkommandet i ord och den öppna förekomstens förfallodatum
 *    (Beslut 1, 2 och 9).
 * 2. **Skrivningarna är `/api`:s** — `StoreScheduleRequest`,
 *    `UpdateScheduleRequest` och App\Actions\Schedule\OpenNextOccurrence delas
 *    rakt av, och ett aktivt schema öppnar sin första förekomst i samma svep
 *    (issue 22 § Beslut 3 och 9).
 * 3. **Skillnaden mellan de tre typerna** — `anchor_date` frågas för alla tre,
 *    intervallfälten krävs för `fixed`/`interval` och avvisas för `none`
 *    (issue 21 § Beslut 5, Beslut 3 och 4).
 * 4. **Grindarna på itemet** (Beslut 7) — `read` ser listan men ingen skrivyta
 *    och nekas på allt skrivande, `create` får lägga till men inte ändra eller
 *    radera, `write` får ändra och pausa men inte radera.
 * 5. **Raderingen lovar ingen papperskorg** (Beslut 8) — raden mjukraderas,
 *    men texten nämner varken papperskorgen eller de 30 dagarna.
 *
 * Att ingen svensk sträng står kvar i en Vue-komponent och att varje ny nyckel
 * finns på båda språken prövas av tests/Feature/Frontend/SprakTest.php, som
 * läser varenda fil under resources/js; den sista testen här binder de NYA
 * nycklarna till just det testet.
 *
 * Hjälparna har prefixet `schemavy` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem, och en pärm med ett item under kontot. Båda på
 * svenska, så meningarna nedan kan jämföras mot `Lang::get(…, 'sv')`.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function schemavyKontext(): array
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
function schemavyMottagare(Container $container, Item $item, string $niva): User
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
 * Ett schema på itemet. Standardraden är ett årsintervall räknat från senast
 * utfört — oljebytet ur [[ADR-0005 Schema och förekomst]].
 *
 * @param  array<string, mixed>  $attribut
 */
function schemavySchema(Item $item, array $attribut = []): Schedule
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
 * Den ÖPPNA förekomsten på ett schema. Produktionen går alltid genom
 * App\Actions\Schedule\OpenNextOccurrence (se fabrikens docblock) — här
 * byggs raden direkt så att förfallodatumet är känt och listan går att pröva
 * utan att räkna kalender.
 */
function schemavyFörekomst(Schedule $schedule, string $due): ScheduleOccurrence
{
    return ScheduleOccurrence::factory()->create([
        'schedule_id' => $schedule->id,
        'due_at' => $due,
        'visible_from' => $due,
        'status' => 'open',
    ]);
}

function schemavyUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}";
}

function schemavySchemaUrl(Container $container, Item $item): string
{
    return schemavyUrl($container, $item).'/schedules';
}

/**
 * Kör en snutt mot resources/js/components/schedulePresentation.js i node och
 * returnerar det som skrivs på stdout — samma teknik som itemdetaljKör() i
 * ItemdetaljTest.php. Valet av nyckel är en ren funktion i en egen modul just
 * för att gå att köra så här; en mall går inte att pröva.
 *
 * `t()` stubbas till att skriva ut nyckeln och `:count` — testet ser därmed
 * VILKEN mening komponenten valde och vilket tal den skickade med, och
 * jämför den nyckeln mot lang/sv/ui.php i testet nedan.
 */
function schemavyKör(string $skript): string
{
    $kod = implode("\n", [
        "const { pathToFileURL } = await import('node:url');",
        'const m = await import(pathToFileURL('
            .json_encode(resource_path('js/components/schedulePresentation.js'), JSON_UNESCAPED_SLASHES).').href);',
        'const t = (key, params = {}) => key + (params.count === undefined ? "" : ":" + params.count);',
        $skript,
    ]);

    $rader = [];
    $status = 0;

    exec('node --input-type=module -e '.escapeshellarg($kod).' 2>&1', $rader, $status);

    expect($status)->toBe(0, implode("\n", $rader));

    return implode("\n", $rader);
}

// --- listan: samma uppsättning, samma ordning, nästa förfall -------------

/*
 * Klart när: itemets detaljvy listar itemets scheman, sorterade på titel som
 * /api.
 */
it('listar itemets scheman sorterade på titel, i samma ordning som /api', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = schemavyKontext();

    // Skapade i oordning, så att sorteringen faktiskt prövas.
    schemavySchema($item, ['title' => 'Service livflotte', 'recurrence_type' => 'fixed', 'interval_unit' => 'year', 'interval_count' => 3]);
    schemavySchema($item, ['title' => 'Byt impeller']);
    schemavySchema($item, ['title' => 'Byt olja']);

    $token = $anvandare->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    $api = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/schedules", $headers)
        ->assertOk()
        ->json('data');

    $svar = actingAs($anvandare)->get(schemavyUrl($container, $item))->assertOk();

    $webb = $svar->viewData('page')['props']['schedules'];

    expect(array_column($webb, 'title'))->toBe(['Byt impeller', 'Byt olja', 'Service livflotte']);
    expect(array_column($webb, 'title'))->toBe(array_column($api, 'title'));
});

/*
 * Klart när: varje rad visar återkommandet i ord, inte som kolumnvärden.
 */
it('visar nästa förfall ur den öppna förekomsten och inget påhittat datum', function () {
    withoutVite();

    [, $anvandare, $container, $item] = schemavyKontext();

    $oljebyte = schemavySchema($item, ['title' => 'Byt olja']);
    // Ett pausat schema har ingen öppen förekomst — pausen rör aldrig raden,
    // men ett schema som skapats pausat har aldrig fått någon.
    $pausat = schemavySchema($item, ['title' => 'Byt impeller', 'is_active' => false]);

    schemavyFörekomst($oljebyte, '2027-05-05');

    actingAs($anvandare)->get(schemavyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('schedules', 2)
            ->where('schedules.0.title', 'Byt impeller')
            ->where('schedules.0.is_active', false)
            ->where('schedules.1.title', 'Byt olja')
            ->where('schedules.1.is_active', true)
            // Sedan issue 63b är det den öppna FÖREKOMSTEN och inte dess
            // datum som ligger i propen — avbockningen behöver ULID:n,
            // `overdue` och `visible_from`, och `due_at` är ett av dess fält
            // (issue 63b § Beslut 2). Nästa förfall är fortfarande samma rad.
            ->where("openOccurrences.{$oljebyte->ulid}.due_at", '2027-05-05')
            // Nyckeln finns med `null` och är inte utelämnad: vyns uppslag är
            // detsamma för alla rader och slipper en andra gren.
            ->where("openOccurrences.{$pausat->ulid}", null)
    );
});

it('formulerar återkommandet i ord och aldrig som kolumnvärden', function () {
    // Nyckeln komponenten väljer, per typ, enhet och antal. Antal 1 har en egen
    // nyckel — `t()` har ingen pluralisering (issue 52 § Beslut 4).
    expect(schemavyKör('console.log(m.recurrenceLabel(t, { recurrence_type: "interval", interval_unit: "month", interval_count: 12 }));'))
        ->toBe('item.schedule.recurrence.interval.month_count:12');

    expect(schemavyKör('console.log(m.recurrenceLabel(t, { recurrence_type: "interval", interval_unit: "week", interval_count: 1 }));'))
        ->toBe('item.schedule.recurrence.interval.week');

    expect(schemavyKör('console.log(m.recurrenceLabel(t, { recurrence_type: "fixed", interval_unit: "year", interval_count: 3 }));'))
        ->toBe('item.schedule.recurrence.fixed.year_count:3');

    expect(schemavyKör('console.log(m.recurrenceLabel(t, { recurrence_type: "none", interval_unit: null, interval_count: null }));'))
        ->toBe('item.schedule.recurrence.none');

    // Och meningarna nycklarna pekar på: orden står i lang/, på båda språken,
    // och `:count` bär talet.
    expect(Lang::get('ui.item.schedule.recurrence.interval.month_count', ['count' => 12], 'sv'))
        ->toBe('Var 12:e månad, räknat från senast utfört');

    expect(Lang::get('ui.item.schedule.recurrence.fixed.year_count', ['count' => 3], 'sv'))
        ->toBe('Var 3:e år enligt kalendern');

    expect(Lang::get('ui.item.schedule.recurrence.none', [], 'sv'))->toBe('En gång');

    // De två typerna säger olika saker om VARIFRÅN nästa förfall räknas — det
    // är hela skillnaden ([[Scheman och uppgifter]] § De två
    // återkommandetyperna]]).
    expect(Lang::get('ui.item.schedule.recurrence.interval.month_count', ['count' => 12], 'en'))
        ->not->toBe(Lang::get('ui.item.schedule.recurrence.fixed.month_count', ['count' => 12], 'en'));
});

// --- skrivningarna: samma regler och samma Action som /api -----------------

/*
 * Klart när: ett interval-schema kan skapas och dess första förekomst öppnas i
 * samma svep.
 */
it('skapar ett interval-schema och öppnar dess första förekomst i samma svep', function () {
    [$konto, $anvandare, $container, $item] = schemavyKontext();

    actingAs($anvandare)->post(schemavySchemaUrl($container, $item), [
        'title' => 'Byt olja',
        'notes' => 'Varje vår',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2027-05-05',
        'lead_days' => 14,
    ])
        ->assertRedirect(schemavyUrl($container, $item))
        ->assertSessionHas('status', 'schedule-created');

    $schema = Schedule::query()->sole();

    expect($schema->item_id)->toBe($item->id);
    expect($schema->lead_days)->toBe(14);
    expect($schema->is_active)->toBeTrue();

    // Förekomsten öppnas av App\Actions\Schedule\OpenNextOccurrence i SAMMA
    // transaktion (issue 22 § Beslut 9). För `interval` är det första
    // förfallet seriens startpunkt.
    $förekomst = $schema->openOccurrence()->sole();

    expect($förekomst->due_at->toDateString())->toBe('2027-05-05');
    // Glappet fryses i raden: visible_from är förfallet minus lead_days.
    expect($förekomst->visible_from->toDateString())->toBe('2027-04-21');
});

/*
 * Klart när: ett none-schema kan skapas utan intervallfält, och intervallfält
 * som ändå skickas ger 422 från /api:s egen regel.
 */
it('skapar ett none-schema utan intervallfält och avvisar fält som ändå skickas', function () {
    [$konto, $anvandare, $container, $item] = schemavyKontext();

    actingAs($anvandare)->post(schemavySchemaUrl($container, $item), [
        'title' => 'Byt impeller',
        'recurrence_type' => 'none',
        'anchor_date' => '2026-10-01',
    ])->assertRedirect(schemavyUrl($container, $item));

    $schema = Schedule::query()->sole();

    expect($schema->recurrence_type)->toBe('none');
    expect($schema->interval_unit)->toBeNull();
    expect($schema->interval_count)->toBeNull();
    expect($schema->openOccurrence()->sole()->due_at->toDateString())->toBe('2026-10-01');

    // Samma kropp, men med intervallfälten med: `prohibited_if` i den DELADE
    // FormRequesten avvisar dem. Regeln är `/api`:s och inte en vyn hittat på.
    $svar = actingAs($anvandare)->post(schemavySchemaUrl($container, $item), [
        'title' => 'Byt rem',
        'recurrence_type' => 'none',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2026-10-01',
    ]);

    $svar->assertSessionHasErrors('interval_unit');
    expect(Schedule::query()->count())->toBe(1);

    // Och `/api` svarar 422 på exakt samma kropp — det är samma requestklass.
    [$apiKonto, , $headers] = kontoMedMedlem();
    $apiContainer = Container::factory()->for($apiKonto, 'account')->create();
    $apiItem = Item::factory()->for($apiContainer, 'container')->create();

    $api = postJson("/api/containers/{$apiContainer->ulid}/items/{$apiItem->ulid}/schedules", [
        'title' => 'Byt rem',
        'recurrence_type' => 'none',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2026-10-01',
    ], $headers)->assertStatus(422);

    $api->assertJsonPath('error.code', 'validation.failed');
    expect(array_keys($api->json('error.data.fields')))->toContain('interval_unit', 'interval_count');
});

/*
 * Klart när: ett fixed-schema kräver enhet och antal, och formuläret säger
 * vilket fält som saknas.
 */
it('kräver enhet och antal för fixed och lägger felet på fältet', function () {
    [$konto, $anvandare, $container, $item] = schemavyKontext();

    actingAs($anvandare)->post(schemavySchemaUrl($container, $item), [
        'title' => 'Service livflotte',
        'recurrence_type' => 'fixed',
        'anchor_date' => '2027-01-01',
    ])->assertSessionHasErrors(['interval_unit', 'interval_count']);

    expect(Schedule::query()->count())->toBe(0);

    // Formuläret bär felet vid sina EGNA fält — `interval_count` och
    // `interval_unit` — och fälten ritas bara när typen kräver dem.
    $vy = File::get(resource_path('js/components/ScheduleForm.vue'));

    expect($vy)->toContain(':error="form.errors.interval_count"');
    expect($vy)->toContain(':error="form.errors.interval_unit"');
});

/*
 * Klart när: anchor_date frågas för alla tre typerna och etiketten följer
 * typen.
 */
it('kräver anchor_date för alla tre typerna', function () {
    [$konto, $anvandare, $container, $item] = schemavyKontext();

    foreach (['none', 'fixed', 'interval'] as $typ) {
        actingAs($anvandare)->post(schemavySchemaUrl($container, $item), [
            'title' => 'Utan startpunkt',
            'recurrence_type' => $typ,
            'interval_unit' => 'month',
            'interval_count' => 1,
        ])->assertSessionHasErrors('anchor_date');
    }

    expect(Schedule::query()->count())->toBe(0);

    // Etiketten byter med typen: `fixed` frågar efter seriens startpunkt, de
    // andra efter första förfallodatumet. Ett obligatoriskt fält som ser
    // valfritt ut är ett 422 användaren inte förstår (Beslut 4).
    $vy = File::get(resource_path('js/components/ScheduleForm.vue'));

    expect($vy)->toContain('anchor_date_fixed');
    expect($vy)->toContain("t('item.schedule.form.anchor_date')");

    expect(Lang::get('ui.item.schedule.form.anchor_date', [], 'sv'))->toBe('Första förfallodatum');
    expect(Lang::get('ui.item.schedule.form.anchor_date_fixed', [], 'sv'))->toBe('Startpunkt i serien');
});

/*
 * Klart när: lead_days går att sätta och förklaras med vad den gör.
 */
it('sätter lead_days och förklarar fältet med vad det gör', function () {
    [$konto, $anvandare, $container, $item] = schemavyKontext();

    actingAs($anvandare)->post(schemavySchemaUrl($container, $item), [
        'title' => 'Byt olja',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2027-05-05',
        'lead_days' => 30,
    ])->assertRedirect(schemavyUrl($container, $item));

    $schema = Schedule::query()->sole();

    expect($schema->lead_days)->toBe(30);
    // 30 dagar innan förfall är uppgiften synlig — `visible_from`.
    expect($schema->openOccurrence()->sole()->visible_from->toDateString())->toBe('2027-04-05');

    // Förklaringen står i lang/ och ritas vid fältet; standarden är serverns
    // (`lead_days` är 0 i modellens $attributes och i migrationen), och vyn
    // hittar ingen egen.
    expect(Lang::get('ui.item.schedule.form.lead_days_hint', [], 'sv'))
        ->toContain('todo-listan');

    expect(File::get(resource_path('js/components/ScheduleForm.vue')))
        ->toContain('item.schedule.form.lead_days_hint');
});

// --- pausen och raderingen -------------------------------------------------

/*
 * Klart när: ett schema kan pausas och återupptas med en PATCH som bara bär
 * is_active.
 */
it('pausar och återupptar med en PATCH som bär bara is_active', function () {
    [$konto, $anvandare, $container, $item] = schemavyKontext();

    $schema = schemavySchema($item, ['title' => 'Byt olja']);
    schemavyFörekomst($schema, '2027-05-05');

    $url = schemavySchemaUrl($container, $item)."/{$schema->ulid}";

    actingAs($anvandare)->patch($url, ['is_active' => false])
        ->assertRedirect(schemavyUrl($container, $item))
        ->assertSessionHas('status', 'schedule-paused');

    expect($schema->fresh()->is_active)->toBeFalse();
    // Att pausa rör ALDRIG den öppna förekomsten: raden ligger kvar, och en
    // pausad förekomst blockerar fortfarande de uppgifter som beror på den
    // (Beslut 6).
    expect($schema->openOccurrence()->count())->toBe(1);

    actingAs($anvandare)->patch($url, ['is_active' => true])
        ->assertSessionHas('status', 'schedule-resumed');

    expect($schema->fresh()->is_active)->toBeTrue();
    // Och återaktiveringen skriver inte om historien: fortfarande exakt en
    // öppen förekomst, med samma datum.
    expect($schema->openOccurrence()->sole()->due_at->toDateString())->toBe('2027-05-05');
});

it('öppnar en förekomst när ett pausat schema återupptas utan en öppen rad', function () {
    [$konto, $anvandare, $container, $item] = schemavyKontext();

    $schema = schemavySchema($item, ['title' => 'Byt olja', 'is_active' => false]);

    expect($schema->openOccurrence()->count())->toBe(0);

    actingAs($anvandare)->patch(schemavySchemaUrl($container, $item)."/{$schema->ulid}", ['is_active' => true])
        ->assertSessionHas('status', 'schedule-resumed');

    // Återaktiveringen öppnar en, i samma transaktion (issue 22 § Beslut 3).
    expect($schema->openOccurrence()->sole()->due_at->toDateString())->toBe('2027-05-05');
});

/*
 * Klart när: ett pausat schema är märkt i listan.
 */
it('märker ett pausat schema i listan men låter raden ligga kvar', function () {
    withoutVite();

    [, $anvandare, $container, $item] = schemavyKontext();

    $pausat = schemavySchema($item, ['title' => 'Byt impeller', 'is_active' => false]);

    actingAs($anvandare)->get(schemavyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('schedules', 1)
            ->where('schedules.0.ulid', $pausat->ulid)
            ->where('schedules.0.is_active', false)
    );

    $vy = File::get(resource_path('js/components/ScheduleListSection.vue'));

    // Raden ligger kvar med en markering och en mening om vad pausen gör.
    expect($vy)->toContain("t('item.schedule.paused')");
    expect($vy)->toContain("t('item.schedule.paused_note')");
    // Och sektionen ritas på detaljvyn — en prop ingen vy läser är ingen yta.
    expect(File::get(resource_path('js/pages/Containers/Items/Show.vue')))->toContain('<ScheduleListSection');
    expect(Lang::get('ui.item.schedule.paused_note', [], 'sv'))->toContain('inga nya förekomster');
});

/*
 * Klart när: ett schema kan ändras, och valideringsfel hamnar på sina egna
 * fält med inmatningen kvar.
 */
it('ändrar ett schema och lägger valideringsfelet på sitt eget fält', function () {
    [$konto, $anvandare, $container, $item] = schemavyKontext();

    $schema = schemavySchema($item, ['title' => 'Byt olja', 'anchor_date' => '2027-05-05']);

    $url = schemavySchemaUrl($container, $item)."/{$schema->ulid}";

    // Ett giltigt svep: titeln, enheten och antalet ändras.
    actingAs($anvandare)->patch($url, [
        'title' => 'Byt olja och filter',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 6,
        'anchor_date' => '2027-05-05',
        'lead_days' => 7,
    ])
        ->assertRedirect(schemavyUrl($container, $item))
        ->assertSessionHas('status', 'schedule-updated');

    $schema->refresh();

    expect($schema->title)->toBe('Byt olja och filter');
    expect($schema->interval_count)->toBe(6);
    expect($schema->lead_days)->toBe(7);

    // Ett ogiltigt datum: felet hamnar på `anchor_date` och bara där, och
    // inmatningen följer med tillbaka — formuläret står kvar som användaren
    // lämnade det (Inertia behåller formens tillstånd, och servern flashar
    // `_old_input`).
    actingAs($anvandare)->patch($url, [
        'title' => 'Ännu ett försök',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 6,
        'anchor_date' => 'inte-ett-datum',
    ])->assertSessionHasErrors('anchor_date');

    expect(session('_old_input.title'))->toBe('Ännu ett försök');
    expect($schema->fresh()->title)->toBe('Byt olja och filter');

    $vy = File::get(resource_path('js/components/ScheduleForm.vue'));

    expect($vy)->toContain(':error="form.errors.anchor_date"');
});

/*
 * Klart när: ett schema kan raderas efter bekräftelse, och texten nämner
 * varken papperskorg eller 30 dagar.
 */
it('raderar ett schema efter bekräftelse och lovar ingen papperskorg', function () {
    [$konto, $anvandare, $container, $item] = schemavyKontext();

    $schema = schemavySchema($item, ['title' => 'Byt olja']);
    schemavyFörekomst($schema, '2027-05-05');

    actingAs($anvandare)
        ->delete(schemavySchemaUrl($container, $item)."/{$schema->ulid}")
        ->assertRedirect(schemavyUrl($container, $item))
        ->assertSessionHas('status', 'schedule-deleted');

    // Mjuk radering: raden ligger kvar med `deleted_at`, och listan visar den
    // inte.
    expect(Schedule::query()->count())->toBe(0);
    expect(Schedule::query()->onlyTrashed()->count())->toBe(1);

    // Bekräftelsen säger vad som försvinner och nämner varken papperskorgen
    // eller de 30 dagarna (Beslut 8): papperskorgen listar fyra typer och
    // `schedule` är inte en av dem (issue 20a § Beslut 3), så en återställning
    // som inte finns får inte utlovas.
    $text = Lang::get('ui.item.schedule.destroy_confirm', [], 'sv');

    expect($text)->toContain('tas bort');
    expect($text)->not->toContain('papperskorg');
    expect($text)->not->toContain('30');

    expect(Lang::get('ui.item.schedule.destroy_confirm', [], 'en'))->not->toContain('trash');

    // Och knappen frågar innan den raderar: webbläsarens egen dialog med
    // meningens text ur `lang/` — ingen sträng i JavaScript, samma mönster som
    // detaljvyns radering.
    $vy = File::get(resource_path('js/components/ScheduleListSection.vue'));

    expect($vy)->toContain('window.confirm');
    expect($vy)->toContain("t('item.schedule.destroy_confirm')");
});

// --- grinden: itemets pinnar, en per handling ------------------------------

/*
 * Klart när: en read-mottagare ser listan men ingen skrivyta, och får 403 om
 * hon postar ändå.
 */
it('visar listan för en read-mottagare men ingen skrivyta, och nekar skrivandet', function () {
    withoutVite();

    [$konto, $agaren, $container, $item] = schemavyKontext();

    $schema = schemavySchema($item, ['title' => 'Byt olja']);
    schemavyFörekomst($schema, '2027-05-05');

    $lasare = schemavyMottagare($container, $item, 'read');

    actingAs($lasare)->get(schemavyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('schedules', 1)
            ->where('schedules.0.title', 'Byt olja')
            ->where('can.create', false)
            ->where('can.update', false)
            ->where('can.delete', false)
    );

    // Formulärsidorna är samma grind: en läsare kommer inte ens in i dem.
    actingAs($lasare)->get(schemavySchemaUrl($container, $item).'/create')->assertForbidden();
    actingAs($lasare)->get(schemavySchemaUrl($container, $item)."/{$schema->ulid}/edit")->assertForbidden();

    actingAs($lasare)->post(schemavySchemaUrl($container, $item), [
        'title' => 'Byt rem',
        'recurrence_type' => 'none',
        'anchor_date' => '2027-01-01',
    ])->assertForbidden();

    actingAs($lasare)->patch(schemavySchemaUrl($container, $item)."/{$schema->ulid}", ['is_active' => false])
        ->assertForbidden();

    actingAs($lasare)->delete(schemavySchemaUrl($container, $item)."/{$schema->ulid}")
        ->assertForbidden();

    expect(Schedule::query()->count())->toBe(1);
    expect($schema->fresh()->is_active)->toBeTrue();

    // Ytorna ritas bakom samma flaggor som kontrollern prövar.
    $vy = File::get(resource_path('js/components/ScheduleListSection.vue'));

    expect($vy)->toContain('v-if="can.create"');
    expect($vy)->toContain('v-if="can.update"');
    expect($vy)->toContain('v-if="can.delete"');
});

/*
 * Klart när: en create-mottagare kan lägga till ett schema men inte ändra
 * eller radera ett befintligt.
 */
it('låter en create-mottagare lägga till men inte ändra eller radera', function () {
    withoutVite();

    [$konto, $agaren, $container, $item] = schemavyKontext();

    $befintligt = schemavySchema($item, ['title' => 'Byt olja']);
    $skapare = schemavyMottagare($container, $item, 'create');

    actingAs($skapare)->post(schemavySchemaUrl($container, $item), [
        'title' => 'Byt impeller',
        'recurrence_type' => 'none',
        'anchor_date' => '2027-01-01',
    ])->assertRedirect(schemavyUrl($container, $item));

    expect(Schedule::query()->count())->toBe(2);

    actingAs($skapare)->patch(schemavySchemaUrl($container, $item)."/{$befintligt->ulid}", ['title' => 'Kapat'])
        ->assertForbidden();

    actingAs($skapare)->delete(schemavySchemaUrl($container, $item)."/{$befintligt->ulid}")
        ->assertForbidden();

    expect($befintligt->fresh()->title)->toBe('Byt olja');

    actingAs($skapare)->get(schemavyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('can.create', true)
            ->where('can.update', false)
            ->where('can.delete', false)
    );
});

/*
 * Klart när: en write-mottagare kan ändra men inte radera.
 */
it('låter en write-mottagare ändra och pausa men inte radera', function () {
    withoutVite();

    [$konto, $agaren, $container, $item] = schemavyKontext();

    $schema = schemavySchema($item, ['title' => 'Byt olja']);
    $skrivare = schemavyMottagare($container, $item, 'write');

    $url = schemavySchemaUrl($container, $item)."/{$schema->ulid}";

    actingAs($skrivare)->patch($url, ['is_active' => false])->assertRedirect(schemavyUrl($container, $item));

    expect($schema->fresh()->is_active)->toBeFalse();

    actingAs($skrivare)->delete($url)->assertForbidden();

    expect(Schedule::query()->count())->toBe(1);

    actingAs($skrivare)->get(schemavyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            // Laddern är kumulativ ([[ADR-0028 Åtkomst på itemnivå]]): `write`
            // står ÖVER `create`, så en skrivare får lägga till ett schema. Det
            // som skiljer henne från en `delete`-mottagare är raderingen.
            ->where('can.create', true)
            ->where('can.update', true)
            ->where('can.delete', false)
    );
});

it('ger 404 för ett schema på ett annat item', function () {
    [$konto, $anvandare, $container, $item] = schemavyKontext();

    $annatItem = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    $schema = schemavySchema($annatItem, ['title' => 'Byt olja']);

    // `scopeBindings()` löser `{schedule}` genom Item::schedules(): en ULID
    // som hör till ett annat item är 404, inte rättad.
    actingAs($anvandare)
        ->patch(schemavySchemaUrl($container, $item)."/{$schema->ulid}", ['title' => 'Kapat'])
        ->assertNotFound();

    expect($schema->fresh()->title)->toBe('Byt olja');
});

// --- kostnaden ------------------------------------------------------------

/*
 * Klart när: detaljvyn kostar ett konstant antal frågor oavsett antal scheman,
 * mätt med DB::listen.
 */
it('kostar ett konstant antal frågor oavsett antal scheman', function () {
    withoutVite();

    [, $anvandare, $container, $item] = schemavyKontext();

    $url = schemavyUrl($container, $item);

    // Jämförelsepunkten är ETT schema och inte noll: en tom relation hoppar
    // över sin eager load-fråga helt, så noll rader kostar mindre av ett skäl
    // som inte har med per-rad-arbete att göra. Det som prövas är att rad TIO
    // inte kostar mer än rad ETT.
    $första = schemavySchema($item, ['title' => 'Schema 1']);
    schemavyFörekomst($första, '2027-05-05');

    // Värm sessionen så att den första frågan för `last_active_at` inte räknas
    // med, samma resonemang som BilagevyTest.
    actingAs($anvandare)->get($url)->assertOk();

    $antal = 0;

    DB::listen(function ($query) use (&$antal) {
        if (! str_contains($query->sql, 'last_active_at')) {
            $antal++;
        }
    });

    $antal = 0;
    actingAs($anvandare)->get($url)->assertOk();
    $medEtt = $antal;

    foreach (range(2, 10) as $i) {
        $schema = schemavySchema($item, ['title' => "Schema {$i}"]);
        schemavyFörekomst($schema, '2027-05-05');
    }

    $antal = 0;
    actingAs($anvandare)->get($url)->assertOk();
    $medTio = $antal;

    // Schemana hämtas i EN fråga med `openOccurrence` eager, precis som
    // Api\ScheduleController::index() (Beslut 9): ingen fråga per rad, och
    // ScheduleResource kör ingen oplanerad lazy-load.
    expect($medTio)->toBe($medEtt);
});

// --- språket ---------------------------------------------------------------

/*
 * Klart när: ingen svensk sträng står kvar i en .vue-fil; varje ny nyckel finns
 * på sv och en.
 *
 * Den GLOBALA svepet över resources/js ägs av
 * tests/Feature/Frontend/SprakTest.php. Här binds de NYA nycklarna och de NYA
 * filerna: en nyckel som bara finns på svenska hade fallit där, men den här
 * filen pekar ut vilka nycklar 63a lade till.
 */
it('har varje ny schemanyckel på båda språken och ingen svensk sträng i vyerna', function () {
    $nycklar = [
        'item.schedule.heading',
        'item.schedule.empty',
        'item.schedule.add',
        'item.schedule.back',
        'item.schedule.next_due',
        'item.schedule.no_next_due',
        'item.schedule.edit',
        'item.schedule.pause',
        'item.schedule.resume',
        'item.schedule.paused',
        'item.schedule.paused_note',
        'item.schedule.destroy',
        'item.schedule.destroy_confirm',
        'item.schedule.create.title',
        'item.schedule.create.heading',
        'item.schedule.create.submit',
        'item.schedule.update.title',
        'item.schedule.update.heading',
        'item.schedule.update.submit',
        'item.schedule.form.title',
        'item.schedule.form.notes',
        'item.schedule.form.recurrence_type',
        'item.schedule.form.recurrence_none',
        'item.schedule.form.recurrence_fixed',
        'item.schedule.form.recurrence_interval',
        'item.schedule.form.unit',
        'item.schedule.form.unit_none',
        'item.schedule.form.interval_count',
        'item.schedule.form.anchor_date',
        'item.schedule.form.anchor_date_fixed',
        'item.schedule.form.lead_days',
        'item.schedule.form.lead_days_hint',
        'flash.schedule-created',
        'flash.schedule-updated',
        'flash.schedule-paused',
        'flash.schedule-resumed',
        'flash.schedule-deleted',
    ];

    foreach (['none', 'fixed', 'interval'] as $typ) {
        if ($typ !== 'none') {
            foreach (['day', 'week', 'month', 'year'] as $enhet) {
                $nycklar[] = "item.schedule.form.units.{$enhet}";
            }

            foreach (['day', 'week', 'month', 'year'] as $enhet) {
                $nycklar[] = "item.schedule.recurrence.{$typ}.{$enhet}";
                $nycklar[] = "item.schedule.recurrence.{$typ}.{$enhet}_count";
            }
        }
    }

    foreach ($nycklar as $nyckel) {
        $sv = Lang::get("ui.{$nyckel}", [], 'sv');
        $en = Lang::get("ui.{$nyckel}", [], 'en');

        expect($sv)->not->toBe("ui.{$nyckel}", "{$nyckel} saknas på svenska");
        expect($en)->not->toBe("ui.{$nyckel}", "{$nyckel} saknas på engelska");
    }

    // De tre typvalen har samma nyckelform i väljaren.
    foreach (['none', 'fixed', 'interval'] as $typ) {
        expect(Lang::get("ui.item.schedule.form.type.{$typ}", [], 'en'))
            ->not->toBe("ui.item.schedule.form.type.{$typ}");
    }

    // Och de nya komponenterna bär ingen svensk sträng utanför kommentarerna —
    // samma regel som SprakTest kör över hela resources/js.
    foreach ([
        'components/ScheduleForm.vue',
        'components/ScheduleListSection.vue',
        'components/schedulePresentation.js',
        'pages/Containers/Items/Schedules/Create.vue',
        'pages/Containers/Items/Schedules/Edit.vue',
    ] as $fil) {
        $kod = File::get(resource_path("js/{$fil}"));
        $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
        $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);
        $kod = (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);

        foreach (preg_split('/\R/', $kod) ?: [] as $nummer => $rad) {
            expect($rad)->not->toMatch('/[åäöÅÄÖ]/u', sprintf(
                'svensk text utanför kommentar i %s:%d: %s',
                $fil,
                $nummer + 1,
                trim($rad),
            ));
        }
    }
});
