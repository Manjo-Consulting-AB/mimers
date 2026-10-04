<?php

use App\Actions\Schedule\ListTodo;
use App\Models\Account;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Issue 123 · Task-vyn pagineras, se [[M19 Dashboarden]] och
 * App\Actions\Schedule\ListTodo::page().
 *
 * **Pagineringen är en markör över `(due_at, ulid)`** — samma deterministiska
 * ordning som listan hade före issuen — med femtio rader per sida, och
 * markören står i querysträngen. Issue 64 § Beslut 3 och [[ADR-0005 Schema och
 * förekomst]] motiverade den opaginerade listan med att *"i april förfaller allt
 * samtidigt"*; den premissen är båtpärmen och inte produkten ([[ADR-0033
 * Produktens omfång]]), och omprövningen hör hit.
 *
 * Filen prövar de sex gränser issuen är byggd kring:
 *
 * 1. **Högst femtio rader per sida**, och nästa sida börjar EXAKT efter den
 *    förra sidans sista rad — också när två rader delar `due_at`, vilket är
 *    vad den andra nyckeln, `ulid`, finns till för.
 * 2. **Grupperingen räknas fortfarande på servern, per rad.** En sida kan
 *    börja mitt i en grupp, gruppens rubrik upprepas då på nästa sida, och en
 *    rad hamnar i samma grupp vilken sida den än står på. Vyn räknar aldrig en
 *    grupp själv.
 * 3. **Frågekostnaden per sida är konstant**, mätt med `DB::listen`.
 * 4. **En omfångsbegränsad mottagare får inga rader utanför omfånget på någon
 *    sida** — sidräkningen görs över hennes rader, inte över allas.
 * 5. **En oläslig markör ger första sidan**, inte ett fel: den kommer ur ett
 *    adressfält någon klistrat i.
 * 6. **Ingen kommentar säger längre att listan är opaginerad**, i de filer
 *    issuen pekar ut.
 *
 * Hjälparna med prefixet `todovy` kommer ur tests/Feature/Frontend/TodovyTest.php
 * — Pest lägger alla testfiler i samma namnrymd, samma grepp som `sokvy`-hjälparna
 * i SokvyTest. Bara hjälparna med prefixet `paginering` är nya här.
 *
 * Datumen är relativa till `Carbon::today()` av samma skäl som i TodovyTest:
 * urvalet kräver `visible_from <= idag`, och en fast dag hade gjort filen
 * tidsberoende.
 *
 * **Klockan pinnas till mitt på dagen UTC** ([[ADR-0044 Användarens dag]]).
 * Servern går i UTC och användaren i `Europe/Stockholm`, och mellan klockan 22
 * och 24 UTC är hennes datum redan i morgondagen. Listan grupperas mot HENNES
 * dag sedan issue 135 medan `todovyDatum()` räknar ur serverns klocka — utan
 * pinnen är filen alltså väggklockeberoende, grön på dagen och röd på natten.
 * Vid tolv UTC sammanfaller de två datumen, så proven är oförändrade.
 *
 * **M26 · issue 234 lade en andra sektion i markören.** Daterade rader kommer
 * före odaterade, och markören bär sin sektion: `d_{due_at}_{ulid}` för en
 * daterad rad och `n_{ulid}` för en odaterad ([[ADR-0052 Uppgifternas listor
 * och uppgifter utan datum]] § Konsekvenser). Sektionen `// --- de två
 * sektionerna ---` nedan prövar de fyra gränserna den ordningen är byggd
 * kring: framåt från daterade in i `no_date`, bakåt från `no_date` till
 * daterade, tre sidor inom `no_date`, och den gamla markörformen
 * `{due_at}_{ulid}`, som fortfarande läses som `d_…`. Hjälparna med prefixet
 * `paginering` delas av alla proven.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::today()->setTime(12, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * $antal uppgifter på samma item med samma förfallodag, i skapelseordning.
 *
 * ULID:n är monoton i tiden (Symfony Ulid::generate()), så
 * skapelseordningen ÄR `ulid`-ordningen — och `ulid` är frågans andra nyckel.
 * Sidgränsen kan därför prövas mot en känd följd i stället för mot en
 * sortering provet självt gör.
 *
 * @return list<string> förekomsternas ULID:n, i `ulid`-ordning
 */
function pagineringRader(Item $item, string $due, int $antal, string $namn): array
{
    $ulider = [];

    foreach (range(1, $antal) as $i) {
        [, $rad] = todovyUppgift($item, $due, "{$namn} {$i}");

        $ulider[] = $rad->ulid;
    }

    return $ulider;
}

/**
 * Markören för en rad, som den står i querysträngen — formen ListTodo::cursor()
 * läser.
 */
function pagineringMark(string $due, string $ulid): string
{
    return "{$due}_{$ulid}";
}

/**
 * Förekomsternas ULID:n i gruppernas ritningsordning, ur en testsidas props.
 *
 * @return list<string>
 */
function pagineringUlider(array $rader): array
{
    return array_column($rader, 'ulid');
}

/**
 * $antal öppna förekomster UTAN datum på samma item, i skapelseordning
 * (M26 · issue 234).
 *
 * Schemat är `none` utan `anchor_date` — den enda vägen till en odaterad rad
 * (ADR-0052 § 3) — och förekomsten byggs ur fabrikens `dateless()`. ULID:n är
 * monoton i tiden, så skapelseordningen ÄR `ulid`-ordningen, precis som i
 * `pagineringRader()`.
 *
 * @return list<string> förekomsternas ULID:n, i `ulid`-ordning
 */
function pagineringUtanDatum(Item $item, int $antal, string $namn): array
{
    $ulider = [];

    foreach (range(1, $antal) as $i) {
        $schema = Schedule::factory()->for($item, 'item')->create([
            'title' => "{$namn} {$i}",
            'recurrence_type' => 'none',
            'interval_unit' => null,
            'interval_count' => null,
            'anchor_date' => null,
        ]);

        $ulider[] = ScheduleOccurrence::factory()
            ->for($schema, 'schedule')
            ->dateless()
            ->create()
            ->ulid;
    }

    return $ulider;
}

/**
 * Alla rader i svaret, i gruppernas ritningsordning — de FEM öppna grupperna,
 * med *No date* sist.
 *
 * `todovyRader()` i TodovyTest samlar de fyra daterade grupperna; den här
 * filen behöver hela följden för att kunna pröva att ingen rad tappas mellan
 * två sidor och att sektionen byter plats baklänges.
 *
 * @return list<array<string, mixed>>
 */
function pagineringAlla(TestResponse $svar): array
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

// --- sidan -----------------------------------------------------------------

/*
 * Klart när: `/tasks` visar högst femtio rader; nästa sida börjar exakt efter
 * föregående sidas sista rad, även när två rader har samma `due_at`.
 *
 * Åttio rader i tre grupper: trettio försenade, fyrtio som förfaller idag och
 * tio kommande. Sidgränsen hamnar därför mitt i `today`, där FYRTIO rader
 * delar `due_at` — det är den andra nyckeln, `ulid`, och ingenting annat, som
 * avgör vilka tjugo som står först respektive sist.
 *
 * Helheten prövas genom att lägga sida ett och sida två efter varandra och
 * jämföra med den förväntade följden: en rad för mycket, en rad för lite
 * eller en överlappning faller på samma rad.
 */
it('visar högst femtio rader och låter nästa sida börja exakt efter den sista', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    $försenade = pagineringRader($item, todovyDatum(-10), 30, 'Försenad');
    $idag = pagineringRader($item, todovyDatum(0), 40, 'Idag');
    $kommande = pagineringRader($item, todovyDatum(10), 10, 'Kommande');

    $alla = [...$försenade, ...$idag, ...$kommande];

    $sida1 = actingAs($anvandare)->get('/tasks')->assertOk();

    $rad1 = todovyRader($sida1);

    expect($rad1)->toHaveCount(50)
        ->and($sida1->inertiaProps()['previousUrl'])->toBeNull()
        ->and($sida1->inertiaProps()['nextUrl'])->toStartWith('/tasks?'.ListTodo::CURSOR_AFTER.'=');

    $sida2 = actingAs($anvandare)->get($sida1->inertiaProps()['nextUrl'])->assertOk();

    $rad2 = todovyRader($sida2);

    expect($rad2)->toHaveCount(30)
        ->and($sida2->inertiaProps()['nextUrl'])->toBeNull()
        ->and($sida2->inertiaProps()['previousUrl'])->toStartWith('/tasks?'.ListTodo::CURSOR_BEFORE.'=');

    // Gränsen ligger i det döda loppet: sida ett slutar på tjugonde `idag`-raden
    // och sida två börjar på den tjugoförsta — med SAMMA `due_at`.
    expect($rad1[49]['due_at'])->toBe($rad2[0]['due_at']);

    // Ingen rad tappas, ingen rad syns två gånger, och ordningen står kvar.
    expect(pagineringUlider($rad1))->toBe(array_slice($alla, 0, 50))
        ->and(pagineringUlider($rad2))->toBe(array_slice($alla, 50));
});

/*
 * Klart när: en grupp som delas mellan två sidor har sin rubrik på båda.
 *
 * `today` är gruppen som delas: sidan ett bär tjugo av dess fyrtio rader,
 * sidan två de tjugo återstående. Båda sidorna har därför en icke-tom
 * `today`-lista — och vyn ritar rubriken för varje icke-tom grupp, den ritar
 * ingenting för en tom. Strukturkontrollen binder de två ihop: propsen säger
 * att gruppen finns, mallen säger att den då får en rubrik.
 *
 * Sidan ett bär de trettio försenade, sidan två de tio kommande: grupperna
 * byter plats men ingen rad byter grupp.
 */
it('upprepar rubriken för en grupp som delas mellan två sidor', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    $försenade = pagineringRader($item, todovyDatum(-10), 30, 'Försenad');
    $idag = pagineringRader($item, todovyDatum(0), 40, 'Idag');
    $kommande = pagineringRader($item, todovyDatum(10), 10, 'Kommande');

    $sida1 = actingAs($anvandare)->get('/tasks')->assertOk();
    $sida2 = actingAs($anvandare)->get($sida1->inertiaProps()['nextUrl'])->assertOk();

    // Klart när: en rad hamnar i samma grupp oavsett vilken sida den står på.
    // Gränsraderna — den sista på sida ett och den första på sida två — ligger
    // båda i `today`, och `idag`-gruppen är delad på mitten och inte någon
    // annanstans.
    expect(array_column(todovyGrupp($sida1, 'today'), 'ulid'))->toBe(array_slice($idag, 0, 20))
        ->and(array_column(todovyGrupp($sida2, 'today'), 'ulid'))->toBe(array_slice($idag, 20));

    expect(array_column(todovyGrupp($sida1, 'overdue'), 'ulid'))->toBe($försenade)
        ->and(todovyGrupp($sida1, 'upcoming'))->toBe([])
        ->and(array_column(todovyGrupp($sida2, 'upcoming'), 'ulid'))->toBe($kommande)
        ->and(todovyGrupp($sida2, 'overdue'))->toBe([]);

    // Båda sidorna visar alltså rubriken *Today* — en gång var.
    expect(todovyGrupp($sida1, 'today'))->not->toBeEmpty()
        ->and(todovyGrupp($sida2, 'today'))->not->toBeEmpty();

    // Och mallen ritar rubriken för precis de grupper som bär rader.
    $vy = File::get(resource_path('js/pages/Tasks/Index.vue'));

    expect($vy)->toContain('v-if="entries.length > 0"')
        ->toContain('t(`todo.group.${group}`)');
});

/*
 * Klart när: föregående-länken leder tillbaka till samma sida.
 *
 * Sidan två:s `previousUrl` pekar på raden före dess första rad — alltså sista
 * raden på sida ett — och sidan som slutar där är sida ett, ordagrant.
 */
it('leder föregående-länken tillbaka till samma sida', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    pagineringRader($item, todovyDatum(-10), 30, 'Försenad');
    pagineringRader($item, todovyDatum(10), 30, 'Kommande');

    $sida1 = actingAs($anvandare)->get('/tasks')->assertOk();
    $sida2 = actingAs($anvandare)->get($sida1->inertiaProps()['nextUrl'])->assertOk();

    $tillbaka = actingAs($anvandare)->get($sida2->inertiaProps()['previousUrl'])->assertOk();

    expect(pagineringUlider(todovyRader($tillbaka)))->toBe(pagineringUlider(todovyRader($sida1)));
});

/*
 * Klart när: en markör som pekar förbi slutet ger en tom sida som GÅR ATT
 * LÄMNA.
 *
 * Adressen kan bli gammal: uppgifterna hann bli avbockade mellan två
 * sidladdningar, och webbläsarens bakåtknapp eller en bokmärkt adress pekar
 * då på en rad som inte längre har några grannar framåt. Sidan blir tom, och
 * `before` är inklusiv — den pekar på en rad som FINNS — just för det här
 * fallet: föregående-länken leder tillbaka till den sista sidan i stället för
 * att peka på en rad som försvunnit.
 */
it('ger en väg tillbaka från en sida som blivit tom', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    $alla = [
        ...pagineringRader($item, todovyDatum(-10), 30, 'Försenad'),
        ...pagineringRader($item, todovyDatum(10), 30, 'Kommande'),
    ];

    $sista = end($alla);

    $tom = actingAs($anvandare)
        ->get('/tasks?'.ListTodo::CURSOR_AFTER.'='.pagineringMark(todovyDatum(10), $sista))
        ->assertOk();

    expect(todovyRader($tom))->toBe([])
        ->and($tom->inertiaProps()['nextUrl'])->toBeNull();

    $tillbaka = actingAs($anvandare)->get($tom->inertiaProps()['previousUrl'])->assertOk();

    expect(pagineringUlider(todovyRader($tillbaka)))->toBe(array_slice($alla, -50));
});

/*
 * Klart när: en oläslig markör ger första sidan.
 *
 * Markören kommer ur ett adressfält, och ett adressfält kan innehålla vad som
 * helst. Ett 500 hade varit ett svar på fel fråga; första sidan är ett svar på
 * rätt fråga med ett svar som går att komma vidare från.
 */
it('förbigår en markör som inte går att läsa', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    $första = pagineringRader($item, todovyDatum(10), 3, 'Kommande');

    $svar = actingAs($anvandare)
        ->get('/tasks?'.ListTodo::CURSOR_AFTER.'=inte-en-markor')
        ->assertOk();

    expect(pagineringUlider(todovyRader($svar)))->toBe($första)
        ->and($svar->inertiaProps()['previousUrl'])->toBeNull();
});

// --- omfånget --------------------------------------------------------------

/*
 * Klart när: en omfångsbegränsad mottagare får inga rader utanför omfånget på
 * någon sida.
 *
 * Hon når containern genom en itemgrant men bara sitt eget item
 * ([[ADR-0028 Åtkomst på itemnivå]] § Beslut regel 3). Båda itemen bär
 * femtiofem uppgifter, så en sida räcker inte för någon av dem: hade urvalet
 * räknats över alla rader i containern hade den hemliga itemens rader trängt
 * sig in på hennes sidor, och hade sidräkningen gjorts före omfånget hade
 * hennes lista slutat mitt i.
 *
 * Femtiofem i stället för femtio är hela poängen: den andra sidan FINNS, och
 * den är hennes egen femte rad — inte den hemliga itemens.
 */
it('ger en omfångsbegränsad mottagare sina egna rader på varje sida', function () {
    withoutVite();

    $pärm = todovyPärm(Account::factory()->create());

    $mitt = todovyItem($pärm, 'Motorn');
    $dolt = todovyItem($pärm, 'Hemlig motor');

    $mina = pagineringRader($mitt, todovyDatum(10), 55, 'Min uppgift');
    pagineringRader($dolt, todovyDatum(10), 55, 'Hemlig uppgift');

    $mottagare = todovyMottagare($pärm, $mitt, 'read');

    $sida1 = actingAs($mottagare)->get('/tasks')->assertOk();
    $sida2 = actingAs($mottagare)->get($sida1->inertiaProps()['nextUrl'])->assertOk();

    expect(pagineringUlider(todovyRader($sida1)))->toBe(array_slice($mina, 0, 50))
        ->and(pagineringUlider(todovyRader($sida2)))->toBe(array_slice($mina, 50))
        ->and($sida2->inertiaProps()['nextUrl'])->toBeNull();

    // Namnet prövas också i svarskroppen: ett svar som ser rätt ut men bär en
    // rad för mycket är precis felet, och en glömd `where` ger inget larm.
    expect($sida1->getContent())->not->toContain('Hemlig')
        ->and($sida2->getContent())->not->toContain('Hemlig');
});

// --- kostnaden -------------------------------------------------------------

/*
 * Klart när: frågekostnaden per sida är konstant.
 *
 * Sidan hämtar `PER_PAGE + 1` rader i EN fråga, och den extra raden är svaret
 * på "finns det mer". Femtio rader får alltså inte kosta mer än en — samma
 * mätning som TodovyTest gör över antal rader, men nu över en full sida
 * (Beslut 8).
 *
 * Klockan fryses av skälet i FragerakningTest: `UpdateLastActiveAt` skriver
 * `user.last_active_at` med sekundupplösning, och faller en sekundgräns mellan
 * det värmande anropet och mätningen blir det en UPDATE extra. Frysningen rör
 * inte påståendet, bara klockan.
 */
it('kostar ett konstant antal frågor oavsett hur många rader sidan bär', function () {
    withoutVite();

    Carbon::setTestNow('2026-06-15 10:00:00');

    [, $anvandare, , $item] = todovyKontext();

    pagineringRader($item, todovyDatum(10), 1, 'Enda');

    actingAs($anvandare);

    $medEn = todovyFrågor(function () {
        get('/tasks')->assertOk();
    });

    // Fyrtionio till: femtio rader, alltså exakt en full sida och ingen nästa.
    pagineringRader($item, todovyDatum(10), 49, 'Fyllnad');

    $medFemtio = todovyFrågor(function () {
        get('/tasks')->assertOk()->assertInertia(
            fn ($page) => $page->has('groups.upcoming', ListTodo::PER_PAGE)
        );
    });

    expect($medFemtio)->toBe($medEn);

    Carbon::setTestNow();
});

// --- kommentarerna ---------------------------------------------------------

/*
 * Klart när: ingen kommentar i de tre filerna säger längre att listan är
 * opaginerad.
 *
 * Ordagrant `Ingen paginering` stod i kontrollern, i vyn och i todovyns test
 * fram till den här issuen, med motiveringen att *"i april förfaller allt
 * samtidigt"*. Frasen prövas i stället för andemeningen: en kommentar som
 * förklarar VARFÖR en äldre motivering föll är inte samma fel som en som
 * påstår att listan är opaginerad.
 *
 * Alla filerna läses HELA, kommentarer inräknade — det är kommentaren som
 * prövas, och en kontroll som strök den först hade prövat ingenting.
 */
it('säger inte längre att listan är opaginerad', function () {
    $filer = [
        app_path('Http/Controllers/TodoController.php'),
        app_path('Actions/Schedule/ListTodo.php'),
        resource_path('js/pages/Tasks/Index.vue'),
        __DIR__.'/TodovyTest.php',
    ];

    foreach ($filer as $fil) {
        expect(File::get($fil))->not->toContain('Ingen paginering', "{$fil} säger att listan är opaginerad");
    }

    // Och vyn vet vad den ska rita: två adresser och två ord, ingenting eget.
    $vy = File::get(resource_path('js/pages/Tasks/Index.vue'));

    expect($vy)->toContain("t('todo.pagination.previous')")
        ->toContain("t('todo.pagination.next')");

    $en = require lang_path('en/ui.php');

    expect(trim($en['todo']['pagination']['previous']))->not->toBe('')
        ->and(trim($en['todo']['pagination']['next']))->not->toBe('');
});

// --- de två sektionerna (M26 · issue 234) ----------------------------------

/*
 * Klart när: `bläddrar framåt från daterade in i no_date` — 60 daterade och
 * 5 odaterade, med `PER_PAGE` 50: sidan 2 har 10 daterade och 5 odaterade,
 * och ingen rad saknas eller står två gånger.
 *
 * Sextio daterade på samma förfallodag ligger i `overdue`, i `ulid`-ordning.
 * Sidan ett bär de femtio första och INGEN odaterad rad — daterade kommer
 * före odaterade. Markören den lämnar efter sig är `d_…` för den femtionde
 * daterade raden; framåt från den kommer de tio daterade som är kvar, och
 * därefter alla fem odaterade. Hela följden fogas samman och jämförs med den
 * förväntade: en rad för mycket, en rad för lite eller en överlappning faller
 * på samma rad.
 */
it('bläddrar framåt från daterade in i no_date', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    $daterade = pagineringRader($item, todovyDatum(-1), 60, 'Daterad');
    $odaterade = pagineringUtanDatum($item, 5, 'Utan datum');

    $sida1 = actingAs($anvandare)->get('/tasks')->assertOk();

    expect(pagineringUlider(pagineringAlla($sida1)))->toBe(array_slice($daterade, 0, 50))
        ->and(todovyGrupp($sida1, 'no_date'))->toBe([])
        ->and($sida1->inertiaProps()['nextUrl'])->toStartWith('/tasks?'.ListTodo::CURSOR_AFTER.'=');

    $sida2 = actingAs($anvandare)->get($sida1->inertiaProps()['nextUrl'])->assertOk();

    // Tio daterade kvar, och alla fem odaterade — ingen rad tappas, ingen står
    // två gånger.
    expect(pagineringUlider(todovyRader($sida2)))->toBe(array_slice($daterade, 50))
        ->and(pagineringUlider(todovyGrupp($sida2, 'no_date')))->toBe($odaterade)
        ->and(pagineringUlider(pagineringAlla($sida2)))
        ->toBe([...array_slice($daterade, 50), ...$odaterade])
        ->and($sida2->inertiaProps()['nextUrl'])->toBeNull();

    // Gränsen ligger MELLAN sektionerna: sidan ett slutar på en daterad rad,
    // och sidan tvås sista rad är odaterad.
    expect(todovyRader($sida2))->toHaveCount(10)
        ->and(todovyGrupp($sida2, 'no_date'))->toHaveCount(5);
});

/*
 * Klart när: `bläddrar bakåt från no_date till daterade`.
 *
 * Markören är `n_…` för den sista odaterade raden och är inklusiv, så sidan
 * slutar där. Bakåt gäller spegelregeln: först de odaterade med mindre eller
 * lika `ulid`, sedan alla daterade — och den ordningen vänds till
 * ritningsordning, alltså daterade först, odaterade sist. Sidan bär därför
 * fyrtiofem daterade och fem odaterade.
 *
 * Föregående sida ligger helt i den daterade sektionen: markören dit är
 * `d_…` för den äldsta daterade raden i den hämtade sviten, och bakåt från en
 * daterad markör kommer bara daterade.
 */
it('bläddrar bakåt från no_date till daterade', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    $daterade = pagineringRader($item, todovyDatum(-1), 60, 'Daterad');
    $odaterade = pagineringUtanDatum($item, 5, 'Utan datum');

    $sista = end($odaterade);

    $sida = actingAs($anvandare)
        ->get('/tasks?'.ListTodo::CURSOR_BEFORE.'=n_'.$sista)
        ->assertOk();

    // Fyrtiofem daterade (de som ryms efter de fem odaterade i femtioett-
    // sviten) och alla fem odaterade — daterade först, odaterade sist.
    expect(pagineringUlider(pagineringAlla($sida)))
        ->toBe([...array_slice($daterade, 15), ...$odaterade])
        ->and($sida->inertiaProps()['nextUrl'])->toStartWith('/tasks?'.ListTodo::CURSOR_AFTER.'=n_'.$sista);

    $tillbaka = actingAs($anvandare)->get($sida->inertiaProps()['previousUrl'])->assertOk();

    expect(pagineringUlider(pagineringAlla($tillbaka)))->toBe(array_slice($daterade, 0, 15))
        ->and(todovyGrupp($tillbaka, 'no_date'))->toBe([])
        ->and($tillbaka->inertiaProps()['previousUrl'])->toBeNull();
});

/*
 * Klart när: `bläddrar inom no_date` — 120 odaterade ger tre sidor utan
 * dubbletter.
 *
 * Ingen daterad rad finns, så hela listan är en enda sektion sorterad på
 * `ulid`. Markören framåt är `n_…` för sidans sista rad, och framåt från en
 * odaterad markör kommer bara odaterade med större `ulid` — ingen daterad rad
 * kan smyga in, för det finns ingen. De tre sidorna är 50, 50 och 20 rader,
 * och sammanfogade är de exakt de 120, utan en enda dubblett.
 */
it('bläddrar inom no_date', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    $odaterade = pagineringUtanDatum($item, 120, 'Utan datum');

    $sida1 = actingAs($anvandare)->get('/tasks')->assertOk();
    $sida2 = actingAs($anvandare)->get($sida1->inertiaProps()['nextUrl'])->assertOk();
    $sida3 = actingAs($anvandare)->get($sida2->inertiaProps()['nextUrl'])->assertOk();

    $alla = [
        ...pagineringUlider(pagineringAlla($sida1)),
        ...pagineringUlider(pagineringAlla($sida2)),
        ...pagineringUlider(pagineringAlla($sida3)),
    ];

    expect(pagineringUlider(pagineringAlla($sida1)))->toHaveCount(50)
        ->and(pagineringUlider(pagineringAlla($sida2)))->toHaveCount(50)
        ->and(pagineringUlider(pagineringAlla($sida3)))->toHaveCount(20)
        ->and($sida3->inertiaProps()['nextUrl'])->toBeNull()
        ->and($alla)->toBe($odaterade)
        ->and(array_unique($alla))->toHaveCount(120);

    // Bakåt från sida tre landar på sida två, ordagrant.
    $tillbaka = actingAs($anvandare)->get($sida3->inertiaProps()['previousUrl'])->assertOk();

    expect(pagineringUlider(pagineringAlla($tillbaka)))->toBe(array_slice($odaterade, 50, 50));
});

/*
 * Klart när: `läser en markör i den gamla formen`.
 *
 * Före issue 234 var formen `{due_at}_{ulid}`, utan sektion. En adress som
 * redan är sparad — i en bokmärkesrad, i webbläsarens historik — ska fortsatt
 * fungera, och `cursor()` läser därför den gamla formen som `d_…`. Provet
 * bygger markören med `pagineringMark()`, som skriver just den gamla formen.
 */
it('läser en markör i den gamla formen', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    $daterade = pagineringRader($item, todovyDatum(-1), 60, 'Daterad');

    $sida = actingAs($anvandare)
        ->get('/tasks?'.ListTodo::CURSOR_AFTER.'='.pagineringMark(todovyDatum(-1), $daterade[49]))
        ->assertOk();

    // Samma svar som `d_…` hade gett: de tio daterade som är kvar.
    expect(pagineringUlider(todovyRader($sida)))->toBe(array_slice($daterade, 50))
        ->and($sida->inertiaProps()['previousUrl'])->not->toBeNull();
});
