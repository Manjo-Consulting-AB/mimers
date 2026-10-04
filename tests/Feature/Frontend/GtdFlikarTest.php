<?php

use App\Actions\Schedule\ListTodo;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\withoutVite;

/*
 * M26 · issue 237 · Flikarna per lista och GTD-panelen, se
 * app/Actions/Schedule/ListTodo.php, app/Models/ScheduleOccurrence.php
 * (`scopeInGtdList`), app/Http/Controllers/TodoController.php,
 * app/Http/Controllers/ContainerTaskController.php,
 * resources/js/pages/Tasks/Index.vue, resources/js/pages/Containers/Tasks.vue,
 * resources/js/components/GtdListPanel.vue och
 * [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 1 och § 5.
 *
 * Filen prövar de numrerade besluten:
 *
 * 1. **Filtret står i querysträngen** (Beslut 1): `?list=` väljer lista, ett
 *    okänt värde blir *Active*, och *Done* är en egen väg med egen
 *    paginering. *Calendar* är härlett — `due_at IS NOT NULL` — och aldrig
 *    ett värde i `gtd_list`.
 * 2. **Urvalet bor i ListTodo** (Beslut 2): kontrollerna skickar `list`
 *    vidare utan att tolka den, och villkoret är modellens `scopeInGtdList`.
 *    Provet läser svaret, inte en avskrift på klientsidan.
 * 3. **Panelen** (Beslut 3): `gtdCounts()` — tre frågor, samma omfång som
 *    listan, och de 30 dagarna gäller bara TALET.
 * 4. **Flikraden och *Done*** (Beslut 4): fliken ersätter *Done*-gruppen på
 *    containern, och vyn ritar `UiTabs` och `GtdListPanel`.
 *
 * Hjälparna med prefixet `todovy` kommer ur tests/Feature/Frontend/TodovyTest.php
 * — Pest lägger alla testfiler i samma namnrymd, samma grepp som
 * tests/Feature/Frontend/TaskpagineringTest.php gör. Bara hjälparna med
 * prefixet `gtdFlik` är nya här.
 *
 * **Klockan pinnas till mitt på dagen UTC** ([[ADR-0044 Användarens dag]]),
 * samma skäl och samma grepp som TodovyTest: servern går i UTC och användaren
 * i Europe/Stockholm, och mellan klockan 22 och 24 UTC är hennes datum redan i
 * morgondagen.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::today()->setTime(12, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En öppen förekomst i vald lista, med ett datum $dagar fram (eller bak).
 */
function gtdFlikDaterad(Item $item, string $titel, int $dagar, string $lista): ScheduleOccurrence
{
    [, $rad] = todovyUppgift($item, todovyDatum($dagar), $titel, ['gtd_list' => $lista]);

    return $rad;
}

/**
 * En öppen förekomst i vald lista UTAN datum (ADR-0052 § 3). Schemat är
 * `none` utan `anchor_date` — den enda vägen till en odaterad rad.
 */
function gtdFlikOdaterad(Item $item, string $titel, string $lista): ScheduleOccurrence
{
    $schema = Schedule::factory()->for($item, 'item')->create([
        'title' => $titel,
        'recurrence_type' => 'none',
        'interval_unit' => null,
        'interval_count' => null,
        'anchor_date' => null,
        'lead_days' => 0,
        'is_active' => true,
    ]);

    return ScheduleOccurrence::factory()->for($schema, 'schedule')->dateless()->create([
        'status' => 'open',
        'gtd_list' => $lista,
    ]);
}

/**
 * En avbockad förekomst, stängd vid en tid vi väljer — "nyast först" går inte
 * att bevisa utan ett `completed_at` vi bestämmer.
 */
function gtdFlikAvbockad(Item $item, string $titel, Carbon $nar, string $lista = 'next'): ScheduleOccurrence
{
    $schema = todovySchema($item, $nar->toDateString(), ['title' => $titel]);

    return ScheduleOccurrence::factory()->completed()->create([
        'schedule_id' => $schema->id,
        'due_at' => $nar->toDateString(),
        'visible_from' => todovyDatum(-60),
        'completed_at' => $nar,
        'gtd_list' => $lista,
    ]);
}

/**
 * Schematitlarna i en grupp ur svaret.
 *
 * @return list<string>
 */
function gtdFlikTitlar(TestResponse $svar, string $grupp): array
{
    return array_map(fn (array $rad): string => $rad['schedule']['title'], todovyGrupp($svar, $grupp));
}

/**
 * Förekomsternas ULID:n ur en lista av rader.
 *
 * @param  list<array<string, mixed>>  $rader
 * @return list<string>
 */
function gtdFlikUlider(array $rader): array
{
    return array_column($rader, 'ulid');
}

// --- filtret (Beslut 1) ----------------------------------------------------

/*
 * Klart när: `list=next visar bara next i datumgrupperna` — på `/tasks` och
 * på containern.
 *
 * Tre aktiva förekomster i var sin lista, alla långt fram (så gruppen är
 * `upcoming` oavsett veckodag). Med `?list=next` ska bara den ena synas — och
 * de två andra inte finnas någon annanstans i svaret heller.
 */
it('visar bara den valda listan i datumgrupperna, på båda ytorna', function () {
    withoutVite();

    [, $anvandare, $container, $item] = todovyKontext();

    gtdFlikDaterad($item, 'Inbox-uppgift', 10, 'inbox');
    gtdFlikDaterad($item, 'Next-uppgift', 11, 'next');
    gtdFlikDaterad($item, 'Waiting-uppgift', 12, 'waiting');

    $flik = "/containers/{$container->ulid}/tasks?list=next";

    foreach (['/tasks?list=next', $flik] as $url) {
        $svar = actingAs($anvandare)->get($url)->assertOk();

        expect($svar->inertiaProps()['list'])->toBe('next')
            ->and(gtdFlikTitlar($svar, 'upcoming'))->toBe(['Next-uppgift'])
            ->and($svar->getContent())->not->toContain('Inbox-uppgift')
            ->and($svar->getContent())->not->toContain('Waiting-uppgift');
    }
});

/*
 * Klart när: `list=calendar visar bara daterade` — en rad utan datum i `next`
 * saknas.
 *
 * *Calendar* är `aktiva och due_at IS NOT NULL` (ADR-0052 § 1), alltså en
 * härledd vy och inte ett värde i `gtd_list`. Den odaterade raden står i
 * *No date* under `?list=next` men inte under *Calendar*.
 */
it('visar bara daterade under Calendar', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    gtdFlikDaterad($item, 'Med datum', 10, 'next');
    gtdFlikOdaterad($item, 'Utan datum', 'next');

    $next = actingAs($anvandare)->get('/tasks?list=next')->assertOk();

    expect(gtdFlikTitlar($next, 'upcoming'))->toBe(['Med datum'])
        ->and(gtdFlikTitlar($next, 'no_date'))->toBe(['Utan datum']);

    $calendar = actingAs($anvandare)->get('/tasks?list=calendar')->assertOk();

    expect(gtdFlikTitlar($calendar, 'upcoming'))->toBe(['Med datum'])
        ->and(todovyGrupp($calendar, 'no_date'))->toBe([])
        ->and($calendar->getContent())->not->toContain('Utan datum');
});

/*
 * Klart när: `ett okänt list-värde ger Active`.
 *
 * Filtret kommer ur ett adressfält någon klistrat i. Ett värde servern inte
 * känner igen behandlas som inget värde (Beslut 1): svaret är *Active*,
 * ordagrant som en adress utan `list`.
 */
it('ger Active för ett okänt list-värde', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    gtdFlikDaterad($item, 'Inbox-uppgift', 10, 'inbox');
    gtdFlikDaterad($item, 'Next-uppgift', 11, 'next');

    $aktiv = actingAs($anvandare)->get('/tasks')->assertOk();
    $okand = actingAs($anvandare)->get('/tasks?list=gjort-eller-nagot')->assertOk();

    expect($okand->inertiaProps()['list'])->toBeNull()
        ->and(gtdFlikTitlar($okand, 'upcoming'))->toBe(gtdFlikTitlar($aktiv, 'upcoming'))
        ->and(gtdFlikTitlar($okand, 'upcoming'))->toBe(['Inbox-uppgift', 'Next-uppgift']);
});

// --- Done (Beslut 1 och 4) -------------------------------------------------

/*
 * Klart när: `list=done visar avbockade nyast först och paginerar` — 60
 * avbockade ger två sidor utan dubbletter.
 *
 * Markören är `c_{sekunder}_{ulid}` över `(completed_at, ulid)`, och `PER_PAGE`
 * är femtio: sidan ett bär de femtio NYASTE, sidan två de tio äldsta. Hela
 * följden fogas samman och jämförs med den förväntade: en rad för mycket, en
 * rad för lite eller en överlappning faller på samma rad.
 */
it('visar avbockade nyast först och paginerar', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    $klara = [];

    foreach (range(0, 59) as $i) {
        $klara[] = gtdFlikAvbockad($item, "Klar {$i}", now()->subMinutes($i));
    }

    $sida1 = actingAs($anvandare)->get('/tasks?list=done')->assertOk();

    $rad1 = $sida1->inertiaProps()['completed'];

    expect($rad1)->toHaveCount(ListTodo::PER_PAGE)
        ->and($sida1->inertiaProps()['list'])->toBe('done')
        // *Done* har inga datumgrupper: raderna ligger i `completed`.
        ->and($sida1->inertiaProps()['groups']['upcoming'])->toBe([])
        ->and($sida1->inertiaProps()['previousUrl'])->toBeNull()
        ->and($sida1->inertiaProps()['nextUrl'])->not->toBeNull();

    $sida2 = actingAs($anvandare)->get($sida1->inertiaProps()['nextUrl'])->assertOk();

    $rad2 = $sida2->inertiaProps()['completed'];

    $förväntad = array_map(fn (ScheduleOccurrence $rad): string => $rad->ulid, $klara);

    expect([...gtdFlikUlider($rad1), ...gtdFlikUlider($rad2)])->toBe($förväntad)
        ->and(gtdFlikUlider($rad2))->toHaveCount(10)
        ->and(array_intersect(gtdFlikUlider($rad1), gtdFlikUlider($rad2)))->toBe([])
        // Nyast först, och tidsstämpeln följer med raden — det är den vyn
        // ritar i stället för förfallodagen.
        ->and($rad1[0]['completed_at'])->toBe($klara[0]->completed_at->toIso8601String());
});

/*
 * Klart när: `Active på containern har ingen Done-grupp`.
 *
 * Beslut 4: *Done*-fliken ERSÄTTER den *Done*-grupp som förr låg sist på
 * containerns flik. På *Active* är `completed` alltså tom, och den avbockade
 * raden syns bara under `?list=done`.
 */
it('har ingen Done-grupp på Active, varken på containern eller /tasks', function () {
    withoutVite();

    [, $anvandare, $container, $item] = todovyKontext();

    gtdFlikDaterad($item, 'Kvar att göra', 10, 'next');
    gtdFlikAvbockad($item, 'Redan gjord', now()->subHour());

    foreach (["/containers/{$container->ulid}/tasks", '/tasks'] as $url) {
        $svar = actingAs($anvandare)->get($url)->assertOk();

        expect($svar->inertiaProps()['completed'])->toBe([])
            ->and($svar->getContent())->not->toContain('Redan gjord');
    }

    // Och den finns kvar under fliken — gruppen är flyttad, inte borttagen.
    $done = actingAs($anvandare)->get("/containers/{$container->ulid}/tasks?list=done")->assertOk();

    expect($done->inertiaProps()['completed'][0]['schedule']['title'])->toBe('Redan gjord');
});

/*
 * Klart när: `fliken Done visar avbockade äldre än 30 dagar` — en avbockning
 * för 45 dagar sedan finns i fliken men räknas inte i panelen.
 *
 * Beslut 3: gränsen på 30 dagar gäller BARA panelens tal. En förekomst är
 * `completed` oavsett ålder, och fliken visar alla.
 */
it('visar avbockade äldre än 30 dagar i fliken men inte i panelens tal', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    gtdFlikAvbockad($item, 'I går', now()->subDay());
    gtdFlikAvbockad($item, 'För 45 dagar sedan', now()->subDays(45));

    $svar = actingAs($anvandare)->get('/tasks?list=done')->assertOk();

    expect(array_map(
        fn (array $rad): string => $rad['schedule']['title'],
        $svar->inertiaProps()['completed'],
    ))->toBe(['I går', 'För 45 dagar sedan']);

    // Panelen räknar bara den senaste månaden.
    expect($svar->inertiaProps()['counts']['done'])->toBe(1);
});

// --- Calendar är inte en lista (Beslut 1) ----------------------------------

/*
 * Klart när: `calendar är inte en giltig lista` — PATCH på förekomsten med
 * `gtd_list = calendar` ger 422.
 *
 * ADR-0052 § 1: *Calendar* och *Done* lagras aldrig, för de följer av
 * `due_at` och `status`. Kolumnen får därför inte ta emot ordet, och
 * valideringen (`Rule::in(ScheduleOccurrence::GTD_LISTS)`) svarar 422 i
 * stället för att skriva ett värde läsarna aldrig letar efter.
 */
it('avvisar calendar som gtd_list med 422', function () {
    withoutVite();

    [, $anvandare, $container, $item] = todovyKontext();

    $rad = gtdFlikDaterad($item, 'Byt olja', 10, 'next');

    $url = "/containers/{$container->ulid}/items/{$item->ulid}"
        ."/schedules/{$rad->schedule->ulid}/occurrences/{$rad->ulid}";

    actingAs($anvandare)
        ->from('/tasks')
        ->patch($url, ['gtd_list' => 'calendar'])
        ->assertSessionHasErrors('gtd_list');

    expect($rad->fresh()->gtd_list)->toBe('next');
});

// --- panelen (Beslut 3) ----------------------------------------------------

/*
 * Klart när: `panelen räknar per lista` — 2 inbox, 3 next, 1 waiting, 0
 * someday, 2 med datum och 1 avbockad i går ger rätt tal.
 *
 * Talet per lista är aktiva förekomster, och *Calendar* är de av dem som har
 * ett datum — de två axlarna är olika frågor och summerar därför inte till
 * samma tal (ADR-0052 § 1). *Done* är avbockade de senaste 30 dagarna.
 */
it('räknar per lista i panelen', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    // Två inbox MED datum — de är panelens *Calendar*.
    gtdFlikDaterad($item, 'Inbox ett', 10, 'inbox');
    gtdFlikDaterad($item, 'Inbox två', 11, 'inbox');

    // Tre next och en waiting UTAN datum — de räknas i sin lista men inte i
    // *Calendar*.
    gtdFlikOdaterad($item, 'Next ett', 'next');
    gtdFlikOdaterad($item, 'Next två', 'next');
    gtdFlikOdaterad($item, 'Next tre', 'next');
    gtdFlikOdaterad($item, 'Waiting ett', 'waiting');

    gtdFlikAvbockad($item, 'Klar i går', now()->subDay(), 'inbox');

    $counts = actingAs($anvandare)->get('/tasks')->assertOk()->inertiaProps()['counts'];

    expect($counts)->toBe([
        'inbox' => 2,
        'next' => 3,
        'waiting' => 1,
        'calendar' => 2,
        'someday' => 0,
        'done' => 1,
    ]);
});

/*
 * Klart när: `panelen räknar inte det mottagaren inte når` — en itemgrant ger
 * bara det itemets uppgifter.
 *
 * Samma omfång som listan (Beslut 3). En gäst med en itemgrant når containern
 * och ETT item; panelen får inte bli en bakväg till antalet på de items hon
 * inte ser.
 */
it('räknar inte det mottagaren inte når', function () {
    withoutVite();

    [, , $container, $mitt] = todovyKontext();

    $dolt = todovyItem($container, 'Hemlig motor');

    gtdFlikOdaterad($mitt, 'Min uppgift', 'inbox');
    gtdFlikDaterad($mitt, 'Min daterade', 10, 'next');

    gtdFlikOdaterad($dolt, 'Hemlig ett', 'inbox');
    gtdFlikOdaterad($dolt, 'Hemlig två', 'inbox');
    gtdFlikDaterad($dolt, 'Hemlig tre', 10, 'next');

    $gast = todovyMottagare($container, $mitt, 'read');

    $counts = actingAs($gast)->get('/tasks')->assertOk()->inertiaProps()['counts'];

    expect($counts['inbox'])->toBe(1)
        ->and($counts['next'])->toBe(1)
        ->and($counts['calendar'])->toBe(1)
        ->and($counts['someday'])->toBe(0);
});

/*
 * Klart när: `panelen följer inte växeln`.
 *
 * Beslut 3: talen är användarens hela GTD-läge och inte det hon valt att visa
 * just nu. Är `show_upcoming_tasks` av döljer LISTAN det som ligger framåt —
 * panelen räknar det ändå.
 */
it('följer inte växeln för framtida uppgifter', function () {
    withoutVite();

    [$konto, $anvandare, , $item] = todovyKontext();

    $anvandare->update(['show_upcoming_tasks' => false]);

    gtdFlikDaterad($item, 'I dag', 0, 'next');
    gtdFlikDaterad($item, 'Långt fram', 30, 'next');

    $svar = actingAs($anvandare)->get('/tasks')->assertOk();

    // Listan följer växeln: den framtida raden syns inte.
    expect(gtdFlikTitlar($svar, 'upcoming'))->toBe([])
        ->and(gtdFlikTitlar($svar, 'today'))->toBe(['I dag']);

    // Panelen gör det inte: båda next-uppgifterna räknas.
    expect($svar->inertiaProps()['counts']['next'])->toBe(2);
});

// --- kostnaden -------------------------------------------------------------

/*
 * Klart när: `kostar ett konstant antal frågor med panelen` — 3 och 12 rader
 * ger samma antal frågor.
 *
 * Panelen är tre frågor (`GROUP BY gtd_list`, *Calendar* och *Done*), och
 * raderna ligger i NYA containers, ett värstingfall för en omfångsupplösning
 * som hade kostat en per container. `todovyFrågor()` tömmer den
 * scoped-bundna omfångsmemon före mätningen, så jämförelsen gäller anropet
 * och inte vad processen råkade ha löst upp tidigare.
 */
it('kostar ett konstant antal frågor med panelen', function () {
    withoutVite();

    [$konto, $anvandare, , $item] = todovyKontext();

    foreach (range(1, 3) as $i) {
        gtdFlikOdaterad($item, "Uppgift $i", 'next');
    }

    actingAs($anvandare);

    $medTre = todovyFrågor(function () {
        get('/tasks')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->has('groups.no_date', 3)
        );
    });

    foreach (range(4, 12) as $i) {
        $pärm = todovyPärm($konto);
        gtdFlikOdaterad(todovyItem($pärm, "Item $i"), "Uppgift $i", 'next');
    }

    $medTolv = todovyFrågor(function () {
        get('/tasks')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->has('groups.no_date', 12)
        );
    });

    expect($medTolv)->toBe($medTre);
});

// --- vyn -------------------------------------------------------------------

/*
 * Klart när: `vyerna ritar flikarna och panelen` — `Tasks/Index.vue` och
 * `Containers/Tasks.vue` innehåller `<UiTabs`, `list=` och `<GtdListPanel`.
 *
 * Källkodsprov av samma skäl som resten av sviten: formen på flikraden och
 * panelen går inte att mäta på serversidan. Kommentarerna stryks först, så
 * bara markupen och scriptet kan nöja provet.
 */
it('ritar flikarna och panelen i båda vyerna', function () {
    foreach (['js/pages/Tasks/Index.vue', 'js/pages/Containers/Tasks.vue'] as $fil) {
        $kod = File::get(resource_path($fil));
        $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
        $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

        expect($kod)->toContain('<UiTabs')
            ->toContain('list=')
            ->toContain('<GtdListPanel');
    }

    // Panelens ord och rubrik finns i katalogen — `t()` skriver nyckeln själv
    // vid ett missat uppslag, och panelen hade då hetat `todo.gtd_panel.heading`.
    foreach (['todo.tabs.label', 'todo.tabs.active', 'todo.tabs.calendar', 'todo.tabs.done', 'todo.gtd_panel.heading'] as $nyckel) {
        expect(trans("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "{$nyckel} saknas");
    }
});
