<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\OccurrenceDependency;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 63b · Förekomsten — den öppna uppgiften, avbockningen, historiken och
 * det härledda försenat. Se
 * App\Http\Controllers\ScheduleOccurrenceController,
 * App\Http\Controllers\ItemController::show(),
 * resources/js/components/OpenOccurrence.vue,
 * resources/js/components/ScheduleListSection.vue,
 * resources/js/components/occurrencePresentation.js och
 * resources/js/pages/Containers/Items/Schedules/Show.vue.
 *
 * Filen bevisar de nio gränserna issuen är byggd kring:
 *
 * 1. **Schemats sida bär historiken** (Beslut 1 och 7) — den öppna
 *    förekomsten och hela listan, i serverns ordning, utan paginering och
 *    utan sortering i vyn.
 * 2. **Tre datum i rätt roll** (Beslut 2) och **försenat ur servern**
 *    (Beslut 3) — `overdue` läses, räknas aldrig i vyn.
 * 3. **Avbockningen kostar en knapptryckning från itemet** (Beslut 1 och 8) —
 *    sektionen stänger förekomsten och öppnar nästa, och det nya datumet
 *    kommer från servern.
 * 4. **Samma räkning som `/api`** — `interval` från avbockningen, `fixed`
 *    från kalendern (issue 22b § Beslut 4). `CloseOccurrence` är orörd, och
 *    det prövas genom att jämföra de två ytornas svar.
 * 5. **Kontot och anteckningen** (Beslut 4) — varvet, inte den anställde, och
 *    anteckningen i historiken.
 * 6. **Hoppa över är en egen mening** (Beslut 5) — stänger utan att påstå att
 *    jobbet gjordes, och syns annorlunda i loggen.
 * 7. **Ett blockerat avslut visar vad som blockerar** (Beslut 6) — hela
 *    listan, med titel och datum, och aldrig en array genom `trans()`.
 * 8. **Grinden är itemets `update`** — `read` ser historiken men ingen knapp
 *    och nekas; `create` nekas; `write` klarar den.
 * 9. **Frågekostnaden är konstant** (Beslut 9), mätt med `DB::listen`.
 *
 * Att ingen svensk sträng står kvar i en Vue-komponent och att varje ny nyckel
 * finns på båda språken prövas av tests/Feature/Frontend/SprakTest.php, som
 * läser varenda fil under resources/js; den sista testen här binder de NYA
 * nycklarna och de NYA filerna till just det testet.
 *
 * Hjälparna har prefixet `forekomst` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem, och en container med ett item under kontot. Båda på
 * svenska, så meningarna nedan kan jämföras mot `Lang::get(…, 'sv')`.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function forekomstKontext(): array
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
function forekomstMottagare(Container $container, Item $item, string $niva): User
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
function forekomstSchema(Item $item, array $attribut = []): Schedule
{
    return Schedule::factory()->for($item, 'item')->create(array_merge([
        'title' => 'Byt olja',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2026-01-01',
        'lead_days' => 0,
        'is_active' => true,
    ], $attribut));
}

/**
 * En förekomstrad. Produktionen går alltid genom
 * App\Actions\Schedule\OpenNextOccurrence (se fabrikens docblock) — här
 * byggs raden direkt så att förfallodatumet är känt och listan går att pröva
 * utan att räkna kalender.
 *
 * @param  array<string, mixed>  $attribut
 */
function forekomstRad(Schedule $schedule, string $due, string $status = 'open', array $attribut = []): ScheduleOccurrence
{
    return ScheduleOccurrence::factory()->create(array_merge([
        'schedule_id' => $schedule->id,
        'due_at' => $due,
        'visible_from' => $due,
        'status' => $status,
    ], $attribut));
}

function forekomstItemUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}";
}

function forekomstSidaUrl(Container $container, Item $item, Schedule $schedule): string
{
    return forekomstItemUrl($container, $item)."/schedules/{$schedule->ulid}";
}

function forekomstStängUrl(Container $container, Item $item, Schedule $schedule, ScheduleOccurrence $occurrence, string $action): string
{
    return forekomstSidaUrl($container, $item, $schedule)."/occurrences/{$occurrence->ulid}/{$action}";
}

/*
 * Kör en snutt mot resources/js/components/occurrencePresentation.js i node
 * och returnerar det som skrivs på stdout — samma teknik som schemavyKör() i
 * SchemavyTest.php. Uppslaget är en ren funktion i en egen modul just för att
 * gå att köra så här; en mall går inte att pröva.
 *
 * `t()` stubbas till att skriva ut nyckeln och sina parametrar, så testet ser
 * VILKEN mening komponenten valde och vilka värden den skickade med.
 */
function forekomstKör(string $skript): string
{
    $kod = implode("\n", [
        "const { pathToFileURL } = await import('node:url');",
        'const m = await import(pathToFileURL('
            .json_encode(resource_path('js/components/occurrencePresentation.js'), JSON_UNESCAPED_SLASHES).').href);',
        'const t = (key, params = {}) => key + Object.entries(params).map(([k, v]) => ":" + k + "=" + v).join("");',
        $skript,
    ]);

    $rader = [];
    $status = 0;

    exec('node --input-type=module -e '.escapeshellarg($kod).' 2>&1', $rader, $status);

    expect($status)->toBe(0, implode("\n", $rader));

    return implode("\n", $rader);
}

// --- schemats sida: den öppna förekomsten och historiken --------------------

/*
 * Klart när: schemats sida visar den öppna förekomsten och hela historiken, i
 * samma ordning som /api.
 */
it('visar den öppna förekomsten och hela historiken i samma ordning som /api', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = forekomstKontext();

    $schema = forekomstSchema($item, ['title' => 'Byt olja', 'anchor_date' => '2027-05-05']);

    // Skapade i oordning, så att sorteringen faktiskt prövas.
    forekomstRad($schema, '2026-01-01', 'completed', [
        'completed_at' => '2026-01-01 09:00:00',
        'completed_by_account_id' => $konto->id,
        'completed_by_user_id' => $anvandare->id,
    ]);
    forekomstRad($schema, '2027-05-05');
    forekomstRad($schema, '2025-01-01', 'completed', [
        'completed_at' => '2025-01-01 09:00:00',
        'completed_by_account_id' => $konto->id,
        'completed_by_user_id' => $anvandare->id,
    ]);

    $token = $anvandare->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    $api = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}/occurrences", $headers)
        ->assertOk()
        ->json('data');

    $svar = actingAs($anvandare)->get(forekomstSidaUrl($container, $item, $schema))->assertOk();

    $webb = $svar->viewData('page')['props']['occurrences'];

    // `due_at` fallande med `id` fallande, precis som `/api` svarar (Beslut 7).
    // Ingen paginering och ingen egen sortering: historiken är HELA listan.
    expect(array_column($webb, 'due_at'))->toBe(['2027-05-05', '2026-01-01', '2025-01-01']);
    expect(array_column($webb, 'ulid'))->toBe(array_column($api, 'ulid'));

    // De tre datumen i rätt roll (Beslut 2): förfallodagen, när uppgiften dök
    // upp, och glappet dem emellan.
    expect($webb[0]['visible_from'])->toBe('2027-05-05');
    expect($webb[0]['status'])->toBe('open');

    expect(File::get(resource_path('js/pages/Containers/Items/Schedules/Show.vue')))
        ->toContain('item.schedule.occurrence.history');
});

/*
 * Klart när: en förfallen förekomst är märkt som försenad, och märkningen
 * kommer ur serverns `overdue`.
 */
it('märker en förfallen förekomst ur serverns härledda overdue', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-16 12:00:00');

    try {
        [, $anvandare, $container, $item] = forekomstKontext();

        $försenat = forekomstSchema($item, ['title' => 'Byt olja', 'anchor_date' => '2026-01-01']);
        forekomstRad($försenat, '2026-09-01');

        $framtida = forekomstSchema($item, ['title' => 'Byt impeller', 'anchor_date' => '2027-01-01']);
        forekomstRad($framtida, '2027-01-01');

        $sida = actingAs($anvandare)->get(forekomstSidaUrl($container, $item, $försenat))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('occurrences', 1)
                ->where('occurrences.0.overdue', true)
            );

        // Och det är samma fält sektionen på itemet läser: `overdue` följer med
        // den öppna förekomsten i `openOccurrences` (Beslut 3).
        actingAs($anvandare)->get(forekomstItemUrl($container, $item))->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where("openOccurrences.{$försenat->ulid}.overdue", true)
                ->where("openOccurrences.{$framtida->ulid}.overdue", false)
        );

        expect($sida->viewData('page')['props']['occurrences'][0]['overdue'])->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

/*
 * Klart när: klientens klocka kan inte göra en förekomst försenad.
 *
 * Regeln bor i [[ADR-0005 Schema och förekomst]]: ett tillstånd klockan
 * ändrar lagras aldrig, och det gäller lika mycket för en `computed` i en
 * komponent som för en kolumn. Komponenten LÄSER `occurrence.overdue` och
 * jämför aldrig `due_at` mot något datum den räknar fram själv.
 */
it('räknar aldrig försenat i vyn utan läser serverns fält', function () {
    $komponent = File::get(resource_path('js/components/OpenOccurrence.vue'));

    expect($komponent)->toContain('occurrence.overdue');
    // Ingen klocka och ingen jämförelse i komponenten. `new Date(` finns bara
    // i occurrencePresentation.js, och bara för att räkna glappet mellan två
    // datum servern redan gett — aldrig för att avgöra ett tillstånd.
    expect($komponent)->not->toContain('new Date');
    expect($komponent)->not->toContain('Date.now');
    expect($komponent)->not->toContain('toISOString');

    $modul = (string) preg_replace(
        '#/\*.*?\*/#s',
        '',
        File::get(resource_path('js/components/occurrencePresentation.js')),
    );

    // Modulen räknar glappet mellan två datum och ingenting annat: ordet
    // `overdue` finns inte i koden, bara i kommentaren som förklarar varför.
    expect($modul)->not->toContain('overdue');

    // Och fältet är serverns: ScheduleOccurrenceResource räknar det ur
    // `due_at` mot dagens datum, aldrig ur en kolumn.
    expect(File::get(app_path('Http/Resources/ScheduleOccurrenceResource.php')))
        ->toContain("'overdue' => \$this->status === 'open' && \$this->due_at->lessThan(Carbon::today())");
});

// --- avbockningen: en knapptryckning, och nästa förfall ur servern ----------

/*
 * Klart när: en avbockning från itemets sektion stänger förekomsten och
 * öppnar nästa, och sidan visar det nya datumet.
 */
it('stänger förekomsten från itemets sektion och visar det nya datumet', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-16 12:00:00');

    try {
        [$konto, $anvandare, $container, $item] = forekomstKontext();

        $schema = forekomstSchema($item, ['title' => 'Byt olja', 'anchor_date' => '2026-01-01']);
        $öppen = forekomstRad($schema, '2026-01-01');

        $itemUrl = forekomstItemUrl($container, $item);

        actingAs($anvandare)->from($itemUrl)
            ->post(forekomstStängUrl($container, $item, $schema, $öppen, 'complete'), [
                'account' => $konto->ulid,
            ])
            ->assertRedirect($itemUrl)
            ->assertSessionHas('status', 'occurrence-completed');

        expect($öppen->fresh()->status)->toBe('completed');

        // Den nya förekomsten skapas i SAMMA transaktion som den gamla stängs,
        // och dess `due_at` räknas av App\Actions\Schedule\OpenNextOccurrence
        // — vyn gissar den aldrig (Beslut 8).
        $nästa = $schema->openOccurrence()->sole();

        expect($nästa->ulid)->not->toBe($öppen->ulid);
        expect($nästa->due_at->toDateString())->toBe('2027-09-16');

        // Och sidan ritas om ur serverns svar: sektionen läser det nya datumet
        // ur `openOccurrences`.
        actingAs($anvandare)->get($itemUrl)->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where("openOccurrences.{$schema->ulid}.due_at", '2027-09-16')
                ->where("openOccurrences.{$schema->ulid}.ulid", $nästa->ulid)
        );
    } finally {
        Carbon::setTestNow();
    }
});

/*
 * Klart när: ett interval-schema får sitt nästa förfall räknat från
 * avbockningen, ett fixed från kalendern — som /api gör.
 *
 * Jämförelsen är mot `/api`:s SVAR och inte mot en kalenderregel skriven i
 * testet. `CloseOccurrence` är orörd av den här issuen, och det som ska
 * bevisas är att webben går genom exakt samma flöde — inte att testet kan
 * räkna månader.
 */
it('räknar interval-förfallet från avbockningen, som /api gör', function () {
    Carbon::setTestNow('2026-09-16 12:00:00');

    try {
        // `/api` först och webben efter: `actingAs()` sätter webbguarden för
        // resten av testet, och Sanctums guard faller tillbaka på den
        // (`config('sanctum.guard')` är `web`) — en tidigare `actingAs` hade
        // alltså gjort token-anropet till fel användares anrop.
        [$apiKonto, $apiAnvandare, $apiContainer, $apiItem] = forekomstKontext();
        $apiSchema = forekomstSchema($apiItem, ['title' => 'Byt olja', 'anchor_date' => '2026-01-01']);
        $apiÖppen = forekomstRad($apiSchema, '2026-01-01');

        $token = $apiAnvandare->createToken('api');
        $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

        $api = postJson(
            "/api/containers/{$apiContainer->ulid}/items/{$apiItem->ulid}/schedules/{$apiSchema->ulid}/occurrences/{$apiÖppen->ulid}/complete",
            ['account' => $apiKonto->ulid],
            $headers,
        )->assertOk()->json('data.next');

        // Tolv månader efter avbockningen, inte efter den gamla förfallodagen.
        expect($api['due_at'])->toBe('2027-09-16');

        // Samma uppsättning, samma kropp — och samma svar ur webben.
        [$konto, $anvandare, $container, $item] = forekomstKontext();
        $schema = forekomstSchema($item, ['title' => 'Byt olja', 'anchor_date' => '2026-01-01']);
        $öppen = forekomstRad($schema, '2026-01-01');

        actingAs($anvandare)->from(forekomstItemUrl($container, $item))
            ->post(forekomstStängUrl($container, $item, $schema, $öppen, 'complete'), ['account' => $konto->ulid])
            ->assertRedirect(forekomstItemUrl($container, $item));

        $webb = $schema->openOccurrence()->sole()->due_at->toDateString();

        expect($webb)->toBe($api['due_at']);
    } finally {
        Carbon::setTestNow();
    }
});

it('räknar fixed-förfallet från kalendern, som /api gör', function () {
    Carbon::setTestNow('2026-09-16 12:00:00');

    try {
        // `/api` först, av samma skäl som i interval-testet ovan.
        [$apiKonto, $apiAnvandare, $apiContainer, $apiItem] = forekomstKontext();
        $apiSchema = forekomstSchema($apiItem, [
            'title' => 'Service livflotte',
            'recurrence_type' => 'fixed',
            'interval_unit' => 'year',
            'interval_count' => 1,
            'anchor_date' => '2026-01-01',
        ]);
        $apiÖppen = forekomstRad($apiSchema, '2026-01-01');

        $token = $apiAnvandare->createToken('api');
        $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

        $api = postJson(
            "/api/containers/{$apiContainer->ulid}/items/{$apiItem->ulid}/schedules/{$apiSchema->ulid}/occurrences/{$apiÖppen->ulid}/complete",
            ['account' => $apiKonto->ulid],
            $headers,
        )->assertOk()->json('data.next');

        // Avbockningen flyttar ingenting: nästa förfall är kalenderns,
        // framflyttat tills det ligger i framtiden (issue 22b § Beslut 4).
        expect($api['due_at'])->toBe('2027-01-01');

        [$konto, $anvandare, $container, $item] = forekomstKontext();
        $schema = forekomstSchema($item, [
            'title' => 'Service livflotte',
            'recurrence_type' => 'fixed',
            'interval_unit' => 'year',
            'interval_count' => 1,
            'anchor_date' => '2026-01-01',
        ]);
        $öppen = forekomstRad($schema, '2026-01-01');

        actingAs($anvandare)->from(forekomstSidaUrl($container, $item, $schema))
            ->post(forekomstStängUrl($container, $item, $schema, $öppen, 'complete'), ['account' => $konto->ulid])
            ->assertRedirect(forekomstSidaUrl($container, $item, $schema));

        $webb = $schema->openOccurrence()->sole()->due_at->toDateString();

        expect($webb)->toBe($api['due_at']);
    } finally {
        Carbon::setTestNow();
    }
});

/*
 * Klart när: ett none-schema får ingen ny förekomst och sektionen säger att
 * uppgiften är klar.
 */
it('öppnar ingen ny förekomst för ett none-schema och säger att uppgiften är klar', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = forekomstKontext();

    $schema = forekomstSchema($item, ['title' => 'Byt impeller', 'recurrence_type' => 'none', 'anchor_date' => '2026-01-01']);
    $öppen = forekomstRad($schema, '2026-01-01');

    actingAs($anvandare)->from(forekomstItemUrl($container, $item))
        ->post(forekomstStängUrl($container, $item, $schema, $öppen, 'complete'), ['account' => $konto->ulid])
        ->assertRedirect(forekomstItemUrl($container, $item));

    // Ingen nästa: `recurrence_type: none` har ingen serie (issue 22 §
    // Beslut 1), och Actionen svarar null.
    expect($schema->occurrences()->count())->toBe(1);
    expect($schema->openOccurrence()->exists())->toBeFalse();

    actingAs($anvandare)->get(forekomstItemUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where("openOccurrences.{$schema->ulid}", null)
    );

    // Och sektionen säger att uppgiften är klar i stället för att visa ett
    // tomt förfallodatum — olika en pausad rad, som har sin egen mening.
    $sektion = File::get(resource_path('js/components/ScheduleListSection.vue'));

    expect($sektion)->toContain('schedule.done');
    expect($sektion)->toContain("t('item.schedule.occurrence.done')");

    expect(Lang::get('ui.item.schedule.occurrence.done', [], 'sv'))->toBe('Uppgiften är klar.');
    expect(Lang::get('ui.item.schedule.occurrence.done', [], 'en'))->not->toBe('ui.item.schedule.occurrence.done');
});

/*
 * Klart när: avbockningen sparar kontot, och kontot syns i historiken.
 */
it('sparar kontot på avbockningen och visar det i historiken', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = forekomstKontext();

    $schema = forekomstSchema($item, ['title' => 'Byt olja', 'anchor_date' => '2026-01-01']);
    $öppen = forekomstRad($schema, '2026-01-01');

    actingAs($anvandare)->from(forekomstSidaUrl($container, $item, $schema))
        ->post(forekomstStängUrl($container, $item, $schema, $öppen, 'complete'), ['account' => $konto->ulid])
        ->assertRedirect(forekomstSidaUrl($container, $item, $schema));

    // Varvet, inte den anställde: båda kolumnerna skrivs, men resursen
    // exponerar bara kontot (issue 22 § Beslut 8).
    $stängd = $öppen->fresh();

    expect($stängd->completed_by_account_id)->toBe($konto->id);
    expect($stängd->completed_by_user_id)->toBe($anvandare->id);

    // Raden ligger i historiken och inte först: den NYA öppna förekomsten har
    // ett senare förfall och står därför överst (Beslut 7).
    actingAs($anvandare)->get(forekomstSidaUrl($container, $item, $schema))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('occurrences.0.status', 'open')
            ->where('occurrences.1.completed_by_account.ulid', $konto->ulid)
            ->where('occurrences.1.completed_by_account.name', $konto->name)
    );

    // Och kontot RITAS i historiken — en prop ingen vy läser är ingen yta.
    expect(File::get(resource_path('js/pages/Containers/Items/Schedules/Show.vue')))
        ->toContain('completed_by_account');
    expect(Lang::get('ui.item.schedule.occurrence.completed_by', ['name' => 'Varvet'], 'sv'))->toBe('av Varvet');
});

/*
 * Klart när: en anteckning sparas och visas i historiken.
 */
it('sparar anteckningen och visar den i historiken', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = forekomstKontext();

    $schema = forekomstSchema($item, ['title' => 'Byt olja', 'anchor_date' => '2026-01-01']);
    $öppen = forekomstRad($schema, '2026-01-01');

    actingAs($anvandare)->from(forekomstSidaUrl($container, $item, $schema))
        ->post(forekomstStängUrl($container, $item, $schema, $öppen, 'complete'), [
            'account' => $konto->ulid,
            'completion_note' => 'Bytte även termostaten',
        ])
        ->assertRedirect(forekomstSidaUrl($container, $item, $schema));

    expect($öppen->fresh()->completion_note)->toBe('Bytte även termostaten');

    // Historikraden, inte den nya öppna förekomsten högst upp.
    actingAs($anvandare)->get(forekomstSidaUrl($container, $item, $schema))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('occurrences.1.completion_note', 'Bytte även termostaten')
    );

    expect(File::get(resource_path('js/pages/Containers/Items/Schedules/Show.vue')))
        ->toContain('completion_note');
});

/*
 * Klart när: hoppa över stänger förekomsten och syns annorlunda än en
 * avklarad i historiken.
 */
it('stänger förekomsten vid skip och skiljer raden från en avklarad', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = forekomstKontext();

    $schema = forekomstSchema($item, ['title' => 'Byt olja', 'anchor_date' => '2026-01-01']);

    // En avslutad rad i historiken, och den öppna som ska hoppas över.
    forekomstRad($schema, '2025-01-01', 'completed', [
        'completed_at' => '2025-01-01 09:00:00',
        'completed_by_account_id' => $konto->id,
        'completed_by_user_id' => $anvandare->id,
    ]);
    $öppen = forekomstRad($schema, '2026-01-01');

    actingAs($anvandare)->from(forekomstSidaUrl($container, $item, $schema))
        ->post(forekomstStängUrl($container, $item, $schema, $öppen, 'skip'), ['account' => $konto->ulid])
        ->assertRedirect(forekomstSidaUrl($container, $item, $schema))
        // Överhoppningen har sin egen flash: den stänger utan att påstå att
        // jobbet gjordes (Beslut 5).
        ->assertSessionHas('status', 'occurrence-skipped');

    $stängd = $öppen->fresh();

    expect($stängd->status)->toBe('skipped');
    // Även `skip` öppnar nästa — men för `interval` räknat från den
    // ÖVERHOPPADE förekomstens `due_at`, inte från `completed_at`
    // (issue 22b § Beslut 4): en knapptryckning får inte flytta serien.
    expect($schema->openOccurrence()->sole()->due_at->toDateString())->toBe('2027-01-01');

    actingAs($anvandare)->get(forekomstSidaUrl($container, $item, $schema))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('occurrences', 3)
            ->where('occurrences.1.status', 'skipped')
    );

    // De två raderna får inte se likadana ut — orden skiljer dem, och färgen
    // är den andra halvan (Beslut 5). Ordningen i historiken är serverns.
    expect(forekomstKör('console.log(m.occurrenceStatusLabel(t, { status: "completed" }));'))
        ->toBe('item.schedule.occurrence.status.completed');

    expect(forekomstKör('console.log(m.occurrenceStatusLabel(t, { status: "skipped" }));'))
        ->toBe('item.schedule.occurrence.status.skipped');

    expect(Lang::get('ui.item.schedule.occurrence.status.completed', [], 'sv'))->toBe('Avklarad');
    expect(Lang::get('ui.item.schedule.occurrence.status.skipped', [], 'sv'))->toBe('Överhoppad');

    $vy = File::get(resource_path('js/pages/Containers/Items/Schedules/Show.vue'));

    expect($vy)->toContain('occurrenceStatusLabel');
    expect($vy)->toContain('badgeClass');

    // Och bekräftelsen säger vad som händer med nästa förekomst.
    expect(File::get(resource_path('js/components/OpenOccurrence.vue')))
        ->toContain("t('item.schedule.occurrence.skip_confirm')");
    expect(Lang::get('ui.item.schedule.occurrence.skip_confirm', [], 'sv'))
        ->toContain('nästa förekomst');
});

// --- domänfelen: blockerade, pausade och redan stängda ----------------------

/*
 * Klart när: en avbockning som blockeras av ett öppet beroende visar vilka
 * uppgifter som blockerar, med titel och datum — ingen "Array to string
 * conversion", ingen JSON-kropp.
 *
 * `occurrence.blocked` bär `data.blocked_by` som en LISTA, och
 * ApiErrorTranslator skickar `data` rakt in i `trans()` som ersättningar — en
 * array som ersättning är i bästa fall en varning och i sämsta ett undantag
 * mitt i felhanteringen. Kontrollern hanterar därför koden särskilt
 * (Beslut 6).
 */
it('visar vilka uppgifter som blockerar, med titel och datum', function () {
    [$konto, $anvandare, $container, $item] = forekomstKontext();

    $blockerare = forekomstSchema($item, ['title' => 'Byt impeller', 'anchor_date' => '2027-05-05']);
    $blockerad = forekomstSchema($item, ['title' => 'Byt olja', 'anchor_date' => '2027-06-01']);

    $a = forekomstRad($blockerare, '2027-05-05');
    $b = forekomstRad($blockerad, '2027-06-01');

    OccurrenceDependency::factory()->create([
        'occurrence_id' => $b->id,
        'depends_on_occurrence_id' => $a->id,
    ]);

    $svar = actingAs($anvandare)->from(forekomstSidaUrl($container, $item, $blockerad))
        ->post(forekomstStängUrl($container, $item, $blockerad, $b, 'complete'), ['account' => $konto->ulid]);

    $svar->assertSessionHasErrors('occurrence');

    $mening = session('errors')->getBag('default')->first('occurrence');

    // Den ledande meningen ur `lang/`, och EN RAD PER BLOCKERARE med titel
    // och datum. Hela listan, inte den första: annars bockar användaren av
    // en, får samma fel igen och lär sig att systemet ljuger om vad som
    // återstår (issue 23b § Beslut 4).
    expect($mening)->toContain(Lang::get('ui.error.occurrence.blocked', [], 'sv'));
    expect($mening)->toContain('Byt impeller');
    expect($mening)->toContain('5 maj 2027');
    expect(substr_count($mening, "\n"))->toBe(1);

    // Ingen "Array to string conversion" och ingen JSON-kropp — felet är en
    // mening i formuläret, inte ett råd uppslag.
    expect($mening)->not->toContain('Array');
    expect($mening)->not->toContain('{"error"');

    // Ingenting skrevs: spärren ligger först i flödet, före stängningen.
    expect($b->fresh()->status)->toBe('open');

    // Och vyn renderar meningen som rader, inte som en enda lång rad.
    expect(File::get(resource_path('js/components/OpenOccurrence.vue')))
        ->toContain('whitespace-pre-line')
        ->toContain('form.errors.occurrence');
});

/*
 * Klart när: en avbockning på ett pausat schema säger att schemat är pausat.
 */
it('säger att schemat är pausat när pausen nekar avbockningen', function () {
    [$konto, $anvandare, $container, $item] = forekomstKontext();

    // Pausen rör aldrig den öppna förekomsten (63a § Beslut 6): raden ligger
    // kvar, och det är den användaren försöker bocka av.
    $schema = forekomstSchema($item, ['title' => 'Byt olja', 'anchor_date' => '2027-05-05', 'is_active' => false]);
    $öppen = forekomstRad($schema, '2027-05-05');

    $svar = actingAs($anvandare)->from(forekomstSidaUrl($container, $item, $schema))
        ->post(forekomstStängUrl($container, $item, $schema, $öppen, 'complete'), ['account' => $konto->ulid]);

    $svar->assertSessionHasErrors('occurrence');

    $mening = session('errors')->getBag('default')->first('occurrence');

    expect($mening)->toBe(Lang::get('ui.error.schedule.inactive', [], 'sv'));
    expect($mening)->toContain('pausat');

    expect($öppen->fresh()->status)->toBe('open');
    expect($schema->occurrences()->count())->toBe(1);
});

/*
 * Klart när: en redan stängd förekomst går inte att bocka av igen, och
 * meningen säger varför.
 */
it('vägrar bocka av en redan stängd förekomst och säger varför', function () {
    [$konto, $anvandare, $container, $item] = forekomstKontext();

    $schema = forekomstSchema($item, ['title' => 'Byt olja', 'anchor_date' => '2026-01-01']);
    $öppen = forekomstRad($schema, '2026-01-01');

    $url = forekomstStängUrl($container, $item, $schema, $öppen, 'complete');

    actingAs($anvandare)->from(forekomstSidaUrl($container, $item, $schema))
        ->post($url, ['account' => $konto->ulid])
        ->assertSessionHas('status', 'occurrence-completed');

    // Andra gången på SAMMA rad: den är stängd, och CloseOccurrence nekar
    // (issue 22b § Beslut 5). Utan regeln blir ett dubbelklick två
    // stängningar och två nya förekomster.
    actingAs($anvandare)->from(forekomstSidaUrl($container, $item, $schema))
        ->post($url, ['account' => $konto->ulid])
        ->assertSessionHasErrors('occurrence');

    $mening = session('errors')->getBag('default')->first('occurrence');

    expect($mening)->toBe(Lang::get('ui.error.occurrence.not_open', [], 'sv'));
    expect($mening)->toContain('redan avslutad');

    // Serien har inte hoppat ett steg: fortfarande exakt en öppen förekomst.
    expect($schema->openOccurrence()->count())->toBe(1);
    expect($schema->occurrences()->count())->toBe(2);
});

// --- grinden: itemets `update` ---------------------------------------------

/*
 * Klart när: en read-mottagare ser historiken men ingen avbockningsknapp, och
 * får 403 om hon postar ändå.
 */
it('låter en read-mottagare se historiken men varken bocka av eller hoppa över', function () {
    withoutVite();

    [$konto, $agaren, $container, $item] = forekomstKontext();

    $schema = forekomstSchema($item, ['title' => 'Byt olja', 'anchor_date' => '2027-05-05']);
    $öppen = forekomstRad($schema, '2027-05-05');
    forekomstRad($schema, '2026-01-01', 'completed', [
        'completed_at' => '2026-01-01 09:00:00',
        'completed_by_account_id' => $konto->id,
        'completed_by_user_id' => $agaren->id,
    ]);

    $lasare = forekomstMottagare($container, $item, 'read');

    // Historiken ritas, men `can.update` är falskt — och det är flaggan
    // formuläret hänger på.
    actingAs($lasare)->get(forekomstSidaUrl($container, $item, $schema))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('occurrences', 2)
            ->where('can.update', false)
    );

    actingAs($lasare)->get(forekomstItemUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('can.update', false)
    );

    expect(File::get(resource_path('js/components/OpenOccurrence.vue')))->toContain('v-if="can.update"');

    // Och postar hon ändå är svaret 403 — knappen är presentation, grinden är
    // Gate::authorize('update', $schedule->item).
    actingAs($lasare)
        ->post(forekomstStängUrl($container, $item, $schema, $öppen, 'complete'), ['account' => $konto->ulid])
        ->assertForbidden();

    actingAs($lasare)
        ->post(forekomstStängUrl($container, $item, $schema, $öppen, 'skip'), ['account' => $konto->ulid])
        ->assertForbidden();

    expect($öppen->fresh()->status)->toBe('open');
});

/*
 * Klart när: en create-mottagare nekas avbockning; en write-mottagare klarar
 * den.
 */
it('nekar en create-mottagare och släpper igenom en write-mottagare', function () {
    [$konto, $agaren, $container, $item] = forekomstKontext();

    $skapare = forekomstMottagare($container, $item, 'create');
    $skrivare = forekomstMottagare($container, $item, 'write');

    $skaparensSchema = forekomstSchema($item, ['title' => 'Byt olja', 'anchor_date' => '2027-05-05']);
    $skaparensRad = forekomstRad($skaparensSchema, '2027-05-05');

    // `create` lägger till men rör inte det som redan finns: att bocka av
    // ändrar en förekomst som redan finns och kräver `update`
    // (issue 71 § Beslut 5).
    actingAs($skapare)
        ->post(forekomstStängUrl($container, $item, $skaparensSchema, $skaparensRad, 'complete'), ['account' => $konto->ulid])
        ->assertForbidden();

    expect($skaparensRad->fresh()->status)->toBe('open');

    $skrivarensSchema = forekomstSchema($item, ['title' => 'Byt impeller', 'anchor_date' => '2027-06-01']);
    $skrivarensRad = forekomstRad($skrivarensSchema, '2027-06-01');

    // En `write`-mottagare klarar den. Kontot är containerns ägarkonto, som hon
    // inte är medlem i — därför skickas kontot hon ÄR medlem i, och det är
    // samma prövning som `/api` gör (403 för ett konto hon inte tillhör).
    $hennesKonto = Account::factory()->create(['locale' => 'sv_SE']);
    $hennesKonto->users()->attach($skrivare, ['role' => 'owner']);

    actingAs($skrivare)->from(forekomstSidaUrl($container, $item, $skrivarensSchema))
        ->post(forekomstStängUrl($container, $item, $skrivarensSchema, $skrivarensRad, 'complete'), ['account' => $hennesKonto->ulid])
        ->assertRedirect(forekomstSidaUrl($container, $item, $skrivarensSchema))
        ->assertSessionHas('status', 'occurrence-completed');

    expect($skrivarensRad->fresh()->completed_by_account_id)->toBe($hennesKonto->id);

    // Och ett konto hon inte är medlem i är 403, inte 422 — samma svar som
    // `/api` ger (issue 22b § Beslut 2).
    $frammandeKonto = Account::factory()->create(['locale' => 'sv_SE']);

    actingAs($skrivare)
        ->post(forekomstStängUrl($container, $item, $skaparensSchema, $skaparensRad, 'complete'), ['account' => $frammandeKonto->ulid])
        ->assertForbidden();
});

// --- kostnaden -------------------------------------------------------------

/*
 * Klart när: sidan kostar ett konstant antal frågor oavsett antal
 * förekomster, mätt med DB::listen (Beslut 9).
 */
it('kostar ett konstant antal frågor oavsett antal förekomster', function () {
    withoutVite();

    [, $anvandare, $container, $item] = forekomstKontext();

    $schema = forekomstSchema($item, ['title' => 'Byt olja', 'anchor_date' => '2027-05-05']);

    // Jämförelsepunkten är EN avslutad rad och inte noll: en tom relation
    // hoppar över sin eager load-fråga helt. Det som prövas är att rad TIO
    // inte kostar mer än rad ETT.
    forekomstRad($schema, '2026-01-01', 'completed', [
        'completed_at' => '2026-01-01 09:00:00',
        'completed_by_account_id' => $container->account_id,
        'completed_by_user_id' => $anvandare->id,
    ]);

    $url = forekomstSidaUrl($container, $item, $schema);

    // Värm sessionen så att den första frågan för `last_active_at` inte räknas
    // med, samma resonemang som SchemavyTest och BilagevyTest.
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
        $dag = sprintf('2024-01-%02d', $i);

        forekomstRad($schema, $dag, 'completed', [
            'completed_at' => "{$dag} 09:00:00",
            'completed_by_account_id' => $container->account_id,
            'completed_by_user_id' => $anvandare->id,
        ]);
    }

    $antal = 0;
    actingAs($anvandare)->get($url)->assertOk();
    $medTio = $antal;

    // Historiken hämtas i EN fråga med `completedByAccount` eager, precis som
    // Api\ScheduleOccurrenceController::index() (Beslut 9): ingen fråga per
    // rad, och ScheduleOccurrenceResource kör ingen oplanerad lazy-load.
    expect($medTio)->toBe($medEtt);
});

// --- språket ---------------------------------------------------------------

/*
 * Klart när: ingen svensk sträng står kvar i en .vue-fil; varje ny nyckel
 * finns på sv och en.
 *
 * Den GLOBALA svepet över resources/js ägs av
 * tests/Feature/Frontend/SprakTest.php. Här binds de NYA nycklarna och de NYA
 * filerna: en nyckel som bara finns på svenska hade fallit där, men den här
 * filen pekar ut vilka nycklar 63b lade till.
 */
it('har varje ny förekomstnyckel på båda språken och ingen svensk sträng i vyerna', function () {
    $nycklar = [
        'item.schedule.occurrence.heading',
        'item.schedule.occurrence.none',
        'item.schedule.occurrence.done',
        'item.schedule.occurrence.view',
        'item.schedule.occurrence.due',
        'item.schedule.occurrence.visible_from',
        'item.schedule.occurrence.window',
        'item.schedule.occurrence.window_one',
        'item.schedule.occurrence.overdue',
        'item.schedule.occurrence.account',
        'item.schedule.occurrence.account_hint',
        'item.schedule.occurrence.note',
        'item.schedule.occurrence.note_hint',
        'item.schedule.occurrence.complete',
        'item.schedule.occurrence.skip',
        'item.schedule.occurrence.skip_confirm',
        'item.schedule.occurrence.history',
        'item.schedule.occurrence.history_empty',
        'item.schedule.occurrence.completed_at',
        'item.schedule.occurrence.completed_by',
        'error.occurrence.blocked',
        'error.occurrence.blocked_row',
        'error.occurrence.not_open',
        'error.schedule.inactive',
        'flash.occurrence-completed',
        'flash.occurrence-skipped',
    ];

    foreach (['open', 'completed', 'skipped'] as $status) {
        $nycklar[] = "item.schedule.occurrence.status.{$status}";
    }

    foreach ($nycklar as $nyckel) {
        $sv = Lang::get("ui.{$nyckel}", [], 'sv');
        $en = Lang::get("ui.{$nyckel}", [], 'en');

        expect($sv)->not->toBe("ui.{$nyckel}", "{$nyckel} saknas på svenska");
        expect($en)->not->toBe("ui.{$nyckel}", "{$nyckel} saknas på engelska");
    }

    // Fönstret är tiden man har på sig (Beslut 2), och det formulerar glappet
    // ur två av serverns datum — talet kommer från vyn, meningen ur lang/.
    expect(forekomstKör(
        'console.log(m.occurrenceWindow(t, { visible_from: "2027-04-21", due_at: "2027-05-05" }));'
    ))->toBe('item.schedule.occurrence.window:days=14');

    expect(forekomstKör(
        'console.log(m.occurrenceWindow(t, { visible_from: "2027-05-04", due_at: "2027-05-05" }));'
    ))->toBe('item.schedule.occurrence.window_one');

    // Och de två rutterna byggs på ett ställe, samma form som routen svarar på.
    expect(forekomstKör(
        'console.log(m.occurrenceActionUrl("c1", "i1", "s1", "o1", "complete"));'
    ))->toBe('/containers/c1/items/i1/schedules/s1/occurrences/o1/complete');

    expect(forekomstKör(
        'console.log(m.occurrenceActionUrl("c1", "i1", "s1", "o1", "skip"));'
    ))->toBe('/containers/c1/items/i1/schedules/s1/occurrences/o1/skip');

    // De nya komponenterna bär ingen svensk sträng utanför kommentarerna —
    // samma regel som SprakTest kör över hela resources/js.
    foreach ([
        'components/OpenOccurrence.vue',
        'components/occurrencePresentation.js',
        'components/ScheduleListSection.vue',
        'pages/Containers/Items/Schedules/Show.vue',
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
