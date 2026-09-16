<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\OccurrenceDependency;
use App\Models\Schedule;
use App\Models\ScheduleDependency;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * Issue 63c · Beroendena på båda nivåerna. Se
 * App\Http\Controllers\ScheduleDependencyController,
 * App\Http\Controllers\OccurrenceDependencyController,
 * App\Http\Controllers\ScheduleOccurrenceController::show(),
 * resources/js/components/ScheduleDependencySection.vue,
 * resources/js/pages/Containers/Items/Schedules/Show.vue och routes/web.php.
 *
 * Filen bevisar de nio gränserna issuen är byggd kring:
 *
 * 1. **Två nivåer, två sektioner** (Beslut 1 och 2) — schemaberoendet är
 *    REGELN som ärvs av varje ny förekomst, förekomstberoendet är UNDANTAGET
 *    för den här gången ([[ADR-0005 Schema och förekomst]] § Motivering).
 * 2. **Motparten följer med sin item** (Beslut 3) — ett schema betyder
 *    ingenting utan sitt item, och väljaren visar bara det användaren får
 *    ändra, bara i samma pärm.
 * 3. **`satisfied` kommer från servern** (Beslut 4) — en uppfylld rad ritas
 *    annorlunda än en som blockerar, innan användaren möter spärren.
 * 4. **Arvet** — en ny förekomst kopplas automatiskt till motpartens då öppna
 *    förekomst, utan att någon lagt till något (issue 23b § Beslut 2).
 * 5. **De sex domänfelen blir fältfel på `depends_on`** (Beslut 6), aldrig en
 *    JSON-kropp — och cykelmeningen namnger kedjan ur `data`.
 * 6. **Att ta bort ett beroende tar inte bort något annat** (Beslut 7).
 * 7. **Grinden är itemets `update`, i BÅDA ändar** (Beslut 8) — 403 får
 *    aldrig röja motpartens titel eller itemnamn.
 * 8. **Ingen GET** (Beslut 1) — båda listorna kommer med schemats sida.
 * 9. **Frågekostnaden är konstant** (Beslut 3 och 4), mätt med `DB::listen`.
 *
 * Att ingen svensk sträng står kvar i en Vue-komponent och att varje ny nyckel
 * finns på båda språken prövas av tests/Feature/Frontend/SprakTest.php, som
 * läser varenda fil under resources/js; den sista testen här binder de NYA
 * nycklarna och de NYA filerna till just det testet.
 *
 * Hjälparna har prefixet `beroende` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem och en pärm med ett item under kontot.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function beroendeKontext(): array
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
 * Ännu ett item i samma pärm — motpartens sida av ett beroende korsar items
 * men aldrig pärmar (issue 23 § Beslut 5).
 */
function beroendeItem(Container $container, User $skapare, Account $konto, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $skapare->id,
        'created_by_account_id' => $konto->id,
    ]);
}

/**
 * En mottagare UTANFÖR ägarkontot, med en itemgrant på angiven nivå.
 */
function beroendeMottagare(Container $container, Item $item, string $niva): User
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
 * @param  array<string, mixed>  $attribut
 */
function beroendeSchema(Item $item, array $attribut = []): Schedule
{
    return Schedule::factory()->for($item, 'item')->create(array_merge([
        'title' => 'Byt impeller',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2027-01-01',
        'lead_days' => 0,
        'is_active' => true,
    ], $attribut));
}

/**
 * En förekomstrad, byggd direkt så att förfallodatumet är känt (samma
 * resonemang som forekomstRad() i ForekomstvyTest.php).
 *
 * @param  array<string, mixed>  $attribut
 */
function beroendeRad(Schedule $schedule, string $due, string $status = 'open', array $attribut = []): ScheduleOccurrence
{
    return ScheduleOccurrence::factory()->create(array_merge([
        'schedule_id' => $schedule->id,
        'due_at' => $due,
        'visible_from' => $due,
        'status' => $status,
    ], $attribut));
}

function beroendeItemUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}";
}

function beroendeSidaUrl(Container $container, Item $item, Schedule $schedule): string
{
    return beroendeItemUrl($container, $item)."/schedules/{$schedule->ulid}";
}

function beroendeSchemaUrl(Container $container, Item $item, Schedule $schedule): string
{
    return beroendeSidaUrl($container, $item, $schedule).'/dependencies';
}

function beroendeForekomstUrl(Container $container, Item $item, Schedule $schedule, ScheduleOccurrence $occurrence): string
{
    return beroendeSidaUrl($container, $item, $schedule)."/occurrences/{$occurrence->ulid}/dependencies";
}

// --- två nivåer, två sektioner ---------------------------------------------

/*
 * Klart när: schemats sida visar två sektioner — schemaberoenden och den öppna
 * förekomstens beroenden — och båda kommer med sidan (Beslut 1 och 2).
 */
it('visar båda nivåerna som props på schemats sida, utan en egen GET', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = beroendeKontext();

    $motpartItem = beroendeItem($container, $anvandare, $konto, 'Impellern');
    $motpart = beroendeSchema($motpartItem, ['title' => 'Serva motorn']);
    $motpartRad = beroendeRad($motpart, '2027-04-01');

    $eget = beroendeSchema($item, ['title' => 'Byt impeller']);
    $egenRad = beroendeRad($eget, '2027-05-05');

    ScheduleDependency::factory()->create([
        'schedule_id' => $eget->id,
        'depends_on_schedule_id' => $motpart->id,
    ]);
    OccurrenceDependency::factory()->create([
        'occurrence_id' => $egenRad->id,
        'depends_on_occurrence_id' => $motpartRad->id,
    ]);

    actingAs($anvandare)->get(beroendeSidaUrl($container, $item, $eget))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            // Schemanivån: regeln. Motpartens TITEL och dess ITEM, för ett
            // schema betyder ingenting utan sitt item (Beslut 3).
            ->has('scheduleDependencies', 1)
            ->where('scheduleDependencies.0.depends_on.title', 'Serva motorn')
            ->where('scheduleDependencies.0.depends_on.item.name', 'Impellern')
            ->where('scheduleDependencies.0.depends_on.item.ulid', $motpartItem->ulid)
            // Förekomstnivån: undantaget, med `satisfied` härlett ur motpartens
            // status (Beslut 4).
            ->has('occurrenceDependencies', 1)
            ->where('occurrenceDependencies.0.depends_on.title', 'Serva motorn')
            ->where('occurrenceDependencies.0.depends_on.due_at', '2027-04-01')
            ->where('occurrenceDependencies.0.satisfied', false)
            // Förekomstens rad bär motpartens SCHEMA-ULID, för länken vidare
            // till dess egen sida (Beslut 5).
            ->where('occurrenceDependencies.0.schedule_ulid', $motpart->ulid)
        );

    // Ingen GET: listorna har ingen egen rutt (Beslut 1). Fyra rutter och
    // ingenting annat byggdes i 63c.
    expect(Route::has('containers.items.schedules.dependencies.index'))->toBeFalse();
    expect(Route::has('containers.items.schedules.dependencies.store'))->toBeTrue();
    expect(Route::has('containers.items.schedules.dependencies.destroy'))->toBeTrue();
    expect(Route::has('containers.items.schedules.occurrences.dependencies.store'))->toBeTrue();
    expect(Route::has('containers.items.schedules.occurrences.dependencies.destroy'))->toBeTrue();

    // Och sidan ritar två sektioner, en per nivå.
    $vy = File::get(resource_path('js/pages/Containers/Items/Schedules/Show.vue'));

    expect($vy)->toContain('level="schedule"');
    expect($vy)->toContain('level="occurrence"');
});

// --- schemaberoendet: regeln -----------------------------------------------

/*
 * Klart när: ett schemaberoende kan läggas till och tas bort, och listan visar
 * motpartens item och titel.
 */
it('lägger till och tar bort ett schemaberoende', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = beroendeKontext();

    $motpartItem = beroendeItem($container, $anvandare, $konto, 'Impellern');
    $motpart = beroendeSchema($motpartItem, ['title' => 'Serva motorn']);
    $eget = beroendeSchema($item, ['title' => 'Byt impeller']);

    $url = beroendeSchemaUrl($container, $item, $eget);

    actingAs($anvandare)->from(beroendeSidaUrl($container, $item, $eget))
        ->post($url, ['depends_on' => $motpart->ulid])
        ->assertRedirect(beroendeSidaUrl($container, $item, $eget))
        ->assertSessionHas('status', 'schedule-dependency-created');

    expect(ScheduleDependency::query()
        ->where('schedule_id', $eget->id)
        ->where('depends_on_schedule_id', $motpart->id)
        ->exists())->toBeTrue();

    // Listan visar motpartens item och titel, och väljaren erbjuder inte
    // samma schema en gång till (dubbletten är ett valideringsfel,
    // issue 23 § Beslut 9).
    actingAs($anvandare)->get(beroendeSidaUrl($container, $item, $eget))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('scheduleDependencies', 1)
            ->where('scheduleDependencies.0.depends_on.item.name', 'Impellern')
            ->where('scheduleDependencies.0.depends_on.title', 'Serva motorn')
            ->has('counterparts.schedule', 0)
        );

    actingAs($anvandare)->from(beroendeSidaUrl($container, $item, $eget))
        ->delete("{$url}/{$motpart->ulid}")
        ->assertRedirect(beroendeSidaUrl($container, $item, $eget))
        ->assertSessionHas('status', 'schedule-dependency-removed');

    expect(ScheduleDependency::query()->count())->toBe(0);

    // Båda schemana finns kvar — det som försvann är regeln (Beslut 7).
    expect($eget->fresh())->not->toBeNull();
    expect($motpart->fresh())->not->toBeNull();
});

/*
 * Klart när: ett förekomstberoende kan läggas till och tas bort på den öppna
 * förekomsten.
 */
it('lägger till och tar bort ett förekomstberoende på den öppna förekomsten', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = beroendeKontext();

    $motpartItem = beroendeItem($container, $anvandare, $konto, 'Impellern');
    $motpart = beroendeSchema($motpartItem, ['title' => 'Serva motorn']);
    $motpartRad = beroendeRad($motpart, '2027-04-01');

    $eget = beroendeSchema($item, ['title' => 'Byt impeller']);
    $egenRad = beroendeRad($eget, '2027-05-05');

    // Väljaren på förekomstnivån erbjuder motpartens ÖPPNA förekomst — det är
    // dess ULID servern tar emot.
    actingAs($anvandare)->get(beroendeSidaUrl($container, $item, $eget))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('counterparts.occurrence', 1)
            ->where('counterparts.occurrence.0.ulid', $motpartRad->ulid)
            ->where('counterparts.occurrence.0.title', 'Serva motorn')
            ->where('counterparts.occurrence.0.item.name', 'Impellern')
        );

    $url = beroendeForekomstUrl($container, $item, $eget, $egenRad);

    actingAs($anvandare)->from(beroendeSidaUrl($container, $item, $eget))
        ->post($url, ['depends_on' => $motpartRad->ulid])
        ->assertRedirect(beroendeSidaUrl($container, $item, $eget))
        ->assertSessionHas('status', 'occurrence-dependency-created');

    expect(OccurrenceDependency::query()
        ->where('occurrence_id', $egenRad->id)
        ->where('depends_on_occurrence_id', $motpartRad->id)
        ->exists())->toBeTrue();

    actingAs($anvandare)->from(beroendeSidaUrl($container, $item, $eget))
        ->delete("{$url}/{$motpartRad->ulid}")
        ->assertRedirect(beroendeSidaUrl($container, $item, $eget))
        ->assertSessionHas('status', 'occurrence-dependency-removed');

    expect(OccurrenceDependency::query()->count())->toBe(0);

    // Både schemana och båda förekomsterna finns kvar (Beslut 7).
    expect($eget->fresh())->not->toBeNull();
    expect($motpart->fresh())->not->toBeNull();
    expect($egenRad->fresh())->not->toBeNull();
    expect($motpartRad->fresh())->not->toBeNull();
});

/*
 * Klart när: en ny förekomst ärver schemaberoendena, och sidan visar dem efter
 * en avbockning — utan att användaren lagt till något.
 *
 * Arvet ligger i App\Actions\Schedule\OpenNextOccurrence (issue 23b § Beslut
 * 2) och rörs inte av den här issuen: det som prövas är att VYN visar raden.
 */
it('visar det ärvda beroendet på den nya förekomsten efter en avbockning', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = beroendeKontext();

    $motpartItem = beroendeItem($container, $anvandare, $konto, 'Impellern');
    $motpart = beroendeSchema($motpartItem, ['title' => 'Serva motorn']);
    beroendeRad($motpart, '2027-04-01');

    $eget = beroendeSchema($item, ['title' => 'Byt olja', 'anchor_date' => '2026-01-01']);
    $egenRad = beroendeRad($eget, '2026-01-01');

    ScheduleDependency::factory()->create([
        'schedule_id' => $eget->id,
        'depends_on_schedule_id' => $motpart->id,
    ]);

    Carbon::setTestNow('2026-09-16 12:00:00');

    try {
        actingAs($anvandare)->from(beroendeSidaUrl($container, $item, $eget))
            ->post(beroendeSidaUrl($container, $item, $eget)."/occurrences/{$egenRad->ulid}/complete", [
                'account' => $konto->ulid,
            ])
            ->assertRedirect(beroendeSidaUrl($container, $item, $eget));
    } finally {
        Carbon::setTestNow();
    }

    // Ingen har lagt till något: raden kom ur arvet.
    expect(OccurrenceDependency::query()->count())->toBe(1);

    $nyRad = $eget->openOccurrence()->sole();

    expect($nyRad->ulid)->not->toBe($egenRad->ulid);

    actingAs($anvandare)->get(beroendeSidaUrl($container, $item, $eget))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('occurrenceDependencies', 1)
            ->where('occurrenceDependencies.0.depends_on.title', 'Serva motorn')
            ->where('occurrenceDependencies.0.satisfied', false)
        );
});

// --- satisfied: uppfylld eller blockerande ----------------------------------

/*
 * Klart när: en uppfylld rad ritas annorlunda än en som blockerar, och
 * skillnaden kommer ur `satisfied` (Beslut 4).
 *
 * Den blockerande raden syns som blockerande INNAN användaren försöker bocka
 * av och möter spärren i App\Actions\Schedule\CloseOccurrence — en blockerad
 * uppgift som ser klickbar ut är ett fel man bara upptäcker genom att göra
 * det.
 */
it('skiljer en uppfylld rad från en som blockerar, ur serverns satisfied', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = beroendeKontext();

    $klarItem = beroendeItem($container, $anvandare, $konto, 'Impellern');
    $klar = beroendeSchema($klarItem, ['title' => 'Serva motorn']);
    $klarRad = beroendeRad($klar, '2026-01-01', 'completed', [
        'completed_at' => '2026-01-01 09:00:00',
        'completed_by_account_id' => $konto->id,
        'completed_by_user_id' => $anvandare->id,
    ]);

    $oppenItem = beroendeItem($container, $anvandare, $konto, 'Vinschen');
    $oppen = beroendeSchema($oppenItem, ['title' => 'Byt vajer']);
    $oppenRad = beroendeRad($oppen, '2027-04-01');

    $eget = beroendeSchema($item, ['title' => 'Byt olja']);
    $egenRad = beroendeRad($eget, '2027-05-05');

    // Skapade i omvänd ordning mot hur de ska sorteras, så att ordningen
    // faktiskt prövas: ouppfyllda först, därefter `due_at` stigande
    // (issue 23b § Beslut 8).
    OccurrenceDependency::factory()->create([
        'occurrence_id' => $egenRad->id,
        'depends_on_occurrence_id' => $klarRad->id,
    ]);

    OccurrenceDependency::factory()->create([
        'occurrence_id' => $egenRad->id,
        'depends_on_occurrence_id' => $oppenRad->id,
    ]);

    actingAs($anvandare)->get(beroendeSidaUrl($container, $item, $eget))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('occurrenceDependencies', 2)
            // Den blockerande raden först — den är det användaren frågar efter.
            ->where('occurrenceDependencies.0.depends_on.title', 'Byt vajer')
            ->where('occurrenceDependencies.0.satisfied', false)
            // Och den uppfyllda sist, med `satisfied` ur motpartens status.
            ->where('occurrenceDependencies.1.depends_on.title', 'Serva motorn')
            ->where('occurrenceDependencies.1.satisfied', true)
        );

    // Vyn räknar aldrig fram `satisfied` själv: den läser fältet och väljer
    // klass och ord på det.
    $sektion = File::get(resource_path('js/components/ScheduleDependencySection.vue'));

    expect($sektion)->toContain('row.satisfied');
    expect($sektion)->toContain("t('item.schedule.dependency.satisfied')");
    expect($sektion)->toContain("t('item.schedule.dependency.blocking')");

    expect(Lang::get('ui.item.schedule.dependency.satisfied', [], 'sv'))->toBe('Klar');
    expect(Lang::get('ui.item.schedule.dependency.blocking', [], 'sv'))->toBe('Blockerar');
});

// --- motpartsväljaren -------------------------------------------------------

/*
 * Klart när: motpartsväljaren listar bara scheman i samma pärm som användaren
 * får ändra (Beslut 3).
 *
 * En motpart utanför omfånget visas inte alls — inte som ett namnlöst spöke.
 * Samma regel som issue 58 § Beslut 3.
 */
it('visar bara motparter i samma pärm som användaren får ändra', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = beroendeKontext();

    // En motpart användaren får ändra, och en hon bara får läsa.
    $skrivbarItem = beroendeItem($container, $anvandare, $konto, 'Impellern');
    $skrivbar = beroendeSchema($skrivbarItem, ['title' => 'Serva motorn']);
    beroendeRad($skrivbar, '2027-04-01');

    $lasbarItem = beroendeItem($container, $anvandare, $konto, 'Vinschen');
    $lasbar = beroendeSchema($lasbarItem, ['title' => 'Byt vajer']);
    beroendeRad($lasbar, '2027-06-01');

    // Och en i en ANNAN pärm, som aldrig får synas.
    $annanParm = Container::factory()->for($konto, 'account')->create();
    $frammandeItem = beroendeItem($annanParm, $anvandare, $konto, 'Trailern');
    $frammande = beroendeSchema($frammandeItem, ['title' => 'Besiktiga trailern']);
    beroendeRad($frammande, '2027-07-01');

    $lasare = User::factory()->create(['locale' => 'sv_SE']);
    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $lasare->id,
        'level' => 'write',
        'kind' => 'guest',
        'granted_by_user_id' => $anvandare->id,
    ]);

    // `read` på den ena motparten: hon ser den, men får inte ändra den.
    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $lasbarItem->id,
        'grantee_type' => 'user',
        'grantee_id' => $lasare->id,
        'level' => 'read',
        'kind' => 'guest',
        'granted_by_user_id' => $anvandare->id,
    ]);

    // `write` på den andra: den är en kandidat.
    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $skrivbarItem->id,
        'grantee_type' => 'user',
        'grantee_id' => $lasare->id,
        'level' => 'write',
        'kind' => 'guest',
        'granted_by_user_id' => $anvandare->id,
    ]);

    $eget = beroendeSchema($item, ['title' => 'Byt olja']);
    beroendeRad($eget, '2027-05-05');

    actingAs($lasare)->get(beroendeSidaUrl($container, $item, $eget))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('counterparts.schedule', 1)
            ->where('counterparts.schedule.0.title', 'Serva motorn')
            ->where('counterparts.schedule.0.item.name', 'Impellern')
        );
});

/*
 * Klart när: motpartsväljaren sorteras på itemets namn och därefter schemats
 * titel — ett schema betyder ingenting utan sitt item (Beslut 3).
 */
it('sorterar motparterna på itemets namn och därefter schemats titel', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = beroendeKontext();

    // Skapade i omvänd ordning mot hur de ska sorteras.
    $zItem = beroendeItem($container, $anvandare, $konto, 'Zonen');
    beroendeSchema($zItem, ['title' => 'Alfa']);
    $aItem = beroendeItem($container, $anvandare, $konto, 'Ankaret');
    beroendeSchema($aItem, ['title' => 'Zeta']);
    beroendeSchema($aItem, ['title' => 'Beta']);

    $eget = beroendeSchema($item, ['title' => 'Byt olja']);
    beroendeRad($eget, '2027-05-05');

    actingAs($anvandare)->get(beroendeSidaUrl($container, $item, $eget))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('counterparts.schedule', 3)
            ->where('counterparts.schedule.0.item.name', 'Ankaret')
            ->where('counterparts.schedule.0.title', 'Beta')
            ->where('counterparts.schedule.1.item.name', 'Ankaret')
            ->where('counterparts.schedule.1.title', 'Zeta')
            ->where('counterparts.schedule.2.item.name', 'Zonen')
        );
});

/*
 * Klart när: ett schema utan öppen förekomst erbjuds inte på förekomstnivån —
 * `depends_on` är en förekomst-ULID, och en stängd motpart är inget undantag
 * någon väljer i förväg.
 */
it('erbjuder bara scheman med öppen förekomst på förekomstnivån', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = beroendeKontext();

    $medRadItem = beroendeItem($container, $anvandare, $konto, 'Impellern');
    $medRad = beroendeSchema($medRadItem, ['title' => 'Serva motorn']);
    beroendeRad($medRad, '2027-04-01');

    $utanRadItem = beroendeItem($container, $anvandare, $konto, 'Vinschen');
    beroendeSchema($utanRadItem, ['title' => 'Byt vajer']);

    $eget = beroendeSchema($item, ['title' => 'Byt olja']);
    beroendeRad($eget, '2027-05-05');

    actingAs($anvandare)->get(beroendeSidaUrl($container, $item, $eget))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            // Båda är kandidater på SCHEMANIVÅN: regeln gäller oavsett om
            // motparten råkar ha en öppen förekomst just nu.
            ->has('counterparts.schedule', 2)
            // Men bara den med en öppen förekomst går att välja som undantag.
            ->has('counterparts.occurrence', 1)
            ->where('counterparts.occurrence.0.title', 'Serva motorn')
        );
});

// --- de sex domänfelen ------------------------------------------------------

/*
 * Klart när: ett schema som väntar på sig självt ger ett fältfel, ingen
 * JSON-kropp (Beslut 6).
 */
it('ger ett fältfel när ett schema väntar på sig självt', function () {
    [$konto, $anvandare, $container, $item] = beroendeKontext();

    $eget = beroendeSchema($item, ['title' => 'Byt impeller']);

    $svar = actingAs($anvandare)->from(beroendeSidaUrl($container, $item, $eget))
        ->post(beroendeSchemaUrl($container, $item, $eget), ['depends_on' => $eget->ulid]);

    $svar->assertSessionHasErrors('depends_on');

    $mening = session('errors')->getBag('default')->first('depends_on');

    expect($mening)->toBe(Lang::get('ui.error.schedule.dependency_self', [], 'sv'));
    expect($mening)->not->toContain('{"error"');
    expect(ScheduleDependency::query()->count())->toBe(0);
});

/*
 * Klart när: en cykel ger ett fältfel som säger VILKEN kedja som skulle
 * uppstå, ur `data` (Beslut 6).
 *
 * Ett meddelande som slänger bort `data` är sämre än felkoden det ersatte —
 * samma regel som issue 56a § Beslut 4 och 58 § Beslut 6. `data` bär de två
 * ULID:erna för kanten som försöktes, och meningen namnger dem med sina
 * titlar: "vilken kedja" är obegripligt som två strängar ur en databas.
 */
it('ger ett fältfel som namnger kedjan när en cykel skulle uppstå', function () {
    [$konto, $anvandare, $container, $item] = beroendeKontext();

    $motpartItem = beroendeItem($container, $anvandare, $konto, 'Impellern');
    $motpart = beroendeSchema($motpartItem, ['title' => 'Serva motorn']);
    $eget = beroendeSchema($item, ['title' => 'Byt impeller']);

    // Motorn väntar redan på impellern.
    ScheduleDependency::factory()->create([
        'schedule_id' => $eget->id,
        'depends_on_schedule_id' => $motpart->id,
    ]);

    // ...och nu skulle impellern vänta på motorn. Ringen sluts.
    $svar = actingAs($anvandare)->from(beroendeSidaUrl($container, $motpartItem, $motpart))
        ->post(beroendeSchemaUrl($container, $motpartItem, $motpart), ['depends_on' => $eget->ulid]);

    $svar->assertSessionHasErrors('depends_on');

    $mening = session('errors')->getBag('default')->first('depends_on');

    expect($mening)->toContain('cirkel');
    expect($mening)->toContain('Serva motorn');
    expect($mening)->toContain('Byt impeller');
    expect($mening)->not->toContain('{"error"');

    // Ingen rad skrevs.
    expect(ScheduleDependency::query()->count())->toBe(1);
});

/*
 * Klart när: en motpart i en annan pärm ger ett fältfel (Beslut 6).
 *
 * `StoreScheduleDependencyRequest`s `Rule::exists` binder motparten till
 * samma pärm och är halva skyddet — en främmande ULID är ett VALIDERINGSFEL,
 * inte en 404 och inte en tyst "hittade inget" (issue 23 § Beslut 4).
 */
it('ger ett fältfel för en motpart i en annan pärm', function () {
    [$konto, $anvandare, $container, $item] = beroendeKontext();

    $annanParm = Container::factory()->for($konto, 'account')->create();
    $frammandeItem = beroendeItem($annanParm, $anvandare, $konto, 'Trailern');
    $frammande = beroendeSchema($frammandeItem, ['title' => 'Besiktiga trailern']);

    $eget = beroendeSchema($item, ['title' => 'Byt impeller']);

    $svar = actingAs($anvandare)->from(beroendeSidaUrl($container, $item, $eget))
        ->post(beroendeSchemaUrl($container, $item, $eget), ['depends_on' => $frammande->ulid]);

    $svar->assertSessionHasErrors('depends_on');
    $svar->assertStatus(302);

    expect(ScheduleDependency::query()->count())->toBe(0);
});

/*
 * Klart när: ett beroende på en stängd förekomst nekas med en begriplig mening
 * (Beslut 6).
 */
it('nekar ett beroende som läggs på en stängd förekomst', function () {
    [$konto, $anvandare, $container, $item] = beroendeKontext();

    $motpartItem = beroendeItem($container, $anvandare, $konto, 'Impellern');
    $motpart = beroendeSchema($motpartItem, ['title' => 'Serva motorn']);
    $motpartRad = beroendeRad($motpart, '2027-04-01');

    $eget = beroendeSchema($item, ['title' => 'Byt olja']);
    $stangd = beroendeRad($eget, '2026-01-01', 'completed', [
        'completed_at' => '2026-01-01 09:00:00',
        'completed_by_account_id' => $konto->id,
        'completed_by_user_id' => $anvandare->id,
    ]);

    $svar = actingAs($anvandare)->from(beroendeSidaUrl($container, $item, $eget))
        ->post(beroendeForekomstUrl($container, $item, $eget, $stangd), ['depends_on' => $motpartRad->ulid]);

    $svar->assertSessionHasErrors('depends_on');

    $mening = session('errors')->getBag('default')->first('depends_on');

    expect($mening)->toBe(Lang::get('ui.error.occurrence.not_open', [], 'sv'));
    expect($mening)->toContain('redan avslutad');
    expect($mening)->not->toContain('{"error"');

    expect(OccurrenceDependency::query()->count())->toBe(0);
});

/*
 * Klart när: alla sex felkoder finns på båda språken och blir fältfel på
 * `depends_on`, aldrig JSON-kroppar.
 */
it('har alla sex felkoder på båda språken och lägger dem på depends_on', function () {
    $nycklar = [
        'error.schedule.dependency_self',
        'error.schedule.dependency_cycle',
        'error.schedule.dependency_not_in_container',
        'error.occurrence.dependency_self',
        'error.occurrence.dependency_cycle',
        'error.occurrence.dependency_not_in_container',
        'error.occurrence.not_open',
    ];

    foreach ($nycklar as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'sv'))->not->toBe("ui.{$nyckel}", "{$nyckel} saknas på svenska");
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "{$nyckel} saknas på engelska");
    }

    // Alla sex hamnar på fältet `depends_on`: varje regel handlar om vilket
    // schema eller vilken förekomst som valdes.
    foreach ([
        'Http/Controllers/ScheduleDependencyController.php',
        'Http/Controllers/OccurrenceDependencyController.php',
    ] as $fil) {
        expect(File::get(app_path($fil)))->toContain("'depends_on' => \$this->errorMessage");
    }

    // Och ingen av kontrollerna svarar med en JSON-kropp: de kastar
    // ValidationException, som blir en felpåse — inte ApiException, som hade
    // blivit `{"error":{"code":…}}` mitt i en sida.
    expect(File::get(app_path('Http/Controllers/ScheduleDependencyController.php')))
        ->toContain('ValidationException::withMessages');
    expect(File::get(app_path('Http/Controllers/OccurrenceDependencyController.php')))
        ->toContain('ValidationException::withMessages');
});

// --- grinden: itemets `update`, i båda ändar --------------------------------

/*
 * Klart när: en read-mottagare ser beroendena men ingen skrivyta (Beslut 8).
 */
it('låter en read-mottagare se beroendena men inte röra dem', function () {
    withoutVite();

    [$konto, $agaren, $container, $item] = beroendeKontext();

    $motpartItem = beroendeItem($container, $agaren, $konto, 'Impellern');
    $motpart = beroendeSchema($motpartItem, ['title' => 'Serva motorn']);
    $motpartRad = beroendeRad($motpart, '2027-04-01');

    $eget = beroendeSchema($item, ['title' => 'Byt impeller']);
    $egenRad = beroendeRad($eget, '2027-05-05');

    ScheduleDependency::factory()->create([
        'schedule_id' => $eget->id,
        'depends_on_schedule_id' => $motpart->id,
    ]);

    // En tredje motpart UTAN befintligt beroende. POST:en går genom
    // StoreScheduleDependencyRequest först, och ett par som redan finns hade
    // fallit på dubbletten (validation.unique) innan grinden hann prövas —
    // det är 302 och inte 403, och det är inte det här testet mäter.
    $oandradItem = beroendeItem($container, $agaren, $konto, 'Vinschen');
    $oandrad = beroendeSchema($oandradItem, ['title' => 'Byt vajer']);
    $oandradRad = beroendeRad($oandrad, '2027-08-01');

    $lasare = beroendeMottagare($container, $item, 'read');

    actingAs($lasare)->get(beroendeSidaUrl($container, $item, $eget))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            // Hon SER beroendet — det följer itemet.
            ->has('scheduleDependencies', 1)
            ->where('scheduleDependencies.0.depends_on.title', 'Serva motorn')
            // Men ingen skrivyta: `can.update` är falskt, och det är flaggan
            // både formuläret och ta-bort-knappen hänger på.
            ->where('can.update', false)
            // Och ingen motpart att välja: hon får inte ändra något av dem.
            ->has('counterparts.schedule', 0)
        );

    expect(File::get(resource_path('js/components/ScheduleDependencySection.vue')))
        ->toContain('v-if="can.update"');

    // Postar hon ändå är svaret 403 — knappen är presentation, grinden är
    // Gate::authorize('update', $schedule->item).
    actingAs($lasare)
        ->post(beroendeSchemaUrl($container, $item, $eget), ['depends_on' => $oandrad->ulid])
        ->assertForbidden();

    actingAs($lasare)
        ->delete(beroendeSchemaUrl($container, $item, $eget)."/{$motpart->ulid}")
        ->assertForbidden();

    actingAs($lasare)
        ->post(beroendeForekomstUrl($container, $item, $eget, $egenRad), ['depends_on' => $oandradRad->ulid])
        ->assertForbidden();

    expect(ScheduleDependency::query()->count())->toBe(1);
});

/*
 * Klart när: en write-mottagare utan åtkomst till motparten får 403 utan att
 * motpartens namn syns (Beslut 8).
 *
 * En 403 får aldrig avslöja motpartens titel eller att den finns.
 */
it('nekar en motpart utanför omfånget utan att röja dess namn', function () {
    withoutVite();

    [$konto, $agaren, $container, $item] = beroendeKontext();

    $motpartItem = beroendeItem($container, $agaren, $konto, 'Impellern');
    $motpart = beroendeSchema($motpartItem, ['title' => 'Serva motorn']);

    $eget = beroendeSchema($item, ['title' => 'Byt impeller']);

    $skrivare = beroendeMottagare($container, $item, 'write');

    $svar = actingAs($skrivare)->from(beroendeSidaUrl($container, $item, $eget))
        ->post(beroendeSchemaUrl($container, $item, $eget), ['depends_on' => $motpart->ulid]);

    $svar->assertForbidden();

    // Svarskroppen röjer varken titeln eller itemnamnet.
    expect($svar->getContent())->not->toContain('Serva motorn');
    expect($svar->getContent())->not->toContain('Impellern');

    // Och motparten syns inte heller i väljaren: hon får inte ändra den.
    actingAs($skrivare)->get(beroendeSidaUrl($container, $item, $eget))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('counterparts.schedule', 0));

    expect(ScheduleDependency::query()->count())->toBe(0);
});

// --- kostnaden --------------------------------------------------------------

/*
 * Klart när: sidan kostar ett konstant antal frågor oavsett antal beroenden,
 * mätt med DB::listen (Beslut 3 och 4).
 */
it('kostar ett konstant antal frågor oavsett antal beroenden', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = beroendeKontext();

    $eget = beroendeSchema($item, ['title' => 'Byt impeller']);

    // Jämförelsepunkten är ETT beroende och inte noll: en tom relation hoppar
    // över sin eager load-fråga helt. Det som prövas är att rad TIO inte
    // kostar mer än rad ETT.
    $förstaItem = beroendeItem($container, $anvandare, $konto, 'Item 1');
    $första = beroendeSchema($förstaItem, ['title' => 'Schema 1']);

    ScheduleDependency::factory()->create([
        'schedule_id' => $eget->id,
        'depends_on_schedule_id' => $första->id,
    ]);

    $url = beroendeSidaUrl($container, $item, $eget);

    // Värm sessionen så att den första frågan för `last_active_at` inte räknas
    // med, samma resonemang som ForekomstvyTest och BilagevyTest.
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
        $motpartItem = beroendeItem($container, $anvandare, $konto, "Item {$i}");
        $motpart = beroendeSchema($motpartItem, ['title' => "Schema {$i}"]);
        beroendeRad($motpart, '2027-04-01');

        ScheduleDependency::factory()->create([
            'schedule_id' => $eget->id,
            'depends_on_schedule_id' => $motpart->id,
        ]);
    }

    $antal = 0;
    actingAs($anvandare)->get($url)->assertOk();
    $medTio = $antal;

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
 * filen pekar ut vilka nycklar 63c lade till.
 */
it('har varje ny beroendenyckel på båda språken och ingen svensk sträng i vyn', function () {
    $nycklar = [
        'item.schedule.dependency.heading_schedule',
        'item.schedule.dependency.heading_occurrence',
        'item.schedule.dependency.note_schedule',
        'item.schedule.dependency.note_occurrence',
        'item.schedule.dependency.empty_schedule',
        'item.schedule.dependency.empty_occurrence',
        'item.schedule.dependency.occurrence_none',
        'item.schedule.dependency.counterpart',
        'item.schedule.dependency.counterpart_none',
        'item.schedule.dependency.no_counterparts',
        'item.schedule.dependency.submit',
        'item.schedule.dependency.remove',
        'item.schedule.dependency.remove_confirm_schedule',
        'item.schedule.dependency.remove_confirm_occurrence',
        'item.schedule.dependency.satisfied',
        'item.schedule.dependency.blocking',
        'flash.schedule-dependency-created',
        'flash.schedule-dependency-removed',
        'flash.occurrence-dependency-created',
        'flash.occurrence-dependency-removed',
    ];

    foreach ($nycklar as $nyckel) {
        $sv = Lang::get("ui.{$nyckel}", [], 'sv');
        $en = Lang::get("ui.{$nyckel}", [], 'en');

        expect($sv)->not->toBe("ui.{$nyckel}", "{$nyckel} saknas på svenska");
        expect($en)->not->toBe("ui.{$nyckel}", "{$nyckel} saknas på engelska");
    }

    // De två rubrikerna bär skillnaden mellan nivåerna (Beslut 2), och
    // ärvsmeningen säger vad en regel gör utan att användaren frågar.
    expect(Lang::get('ui.item.schedule.dependency.heading_schedule', [], 'sv'))
        ->toBe('Väntar alltid på');
    expect(Lang::get('ui.item.schedule.dependency.heading_occurrence', [], 'sv'))
        ->toBe('Väntar den här gången på');
    expect(Lang::get('ui.item.schedule.dependency.note_schedule', [], 'sv'))
        ->toContain('Varje ny förekomst');

    // Och borttagningen säger att ingenting annat försvann (Beslut 7).
    expect(Lang::get('ui.flash.schedule-dependency-removed', [], 'sv'))
        ->toBe('Beroendet är borttaget. Båda schemana finns kvar.');
    expect(Lang::get('ui.flash.occurrence-dependency-removed', [], 'sv'))
        ->toContain('båda förekomsterna');

    // De nya vyerna bär ingen svensk sträng utanför kommentarerna — samma
    // regel som SprakTest kör över hela resources/js.
    foreach ([
        'components/ScheduleDependencySection.vue',
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
