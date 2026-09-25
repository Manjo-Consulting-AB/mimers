<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\put;
use function Pest\Laravel\withoutVite;

/*
 * Issue 134 · Växeln för framtida uppgifter, se [[M21 Uppgifterna i
 * vardagen]] § 134, App\Http\Controllers\Settings\TaskPreferenceController,
 * App\Actions\Schedule\ListTodo, App\Models\ScheduleOccurrence (scope
 * `dueTodayOrEarlier`), resources/js/components/UpcomingTasksToggle.vue,
 * resources/js/components/DashboardTasksPanel.vue,
 * resources/js/pages/Tasks/Index.vue, routes/web.php och lang/en/ui.php.
 *
 * Filen bevisar de gränser issuen är byggd kring:
 *
 * 1. **Standardvärdet är dagens beteende.** `show_upcoming_tasks` är `true`
 *    för en ny användare, och då visar `/tasks` och panelen samma rader som
 *    förut — samma urval, samma ordning.
 * 2. **Med växeln av visas bara det som är aktuellt nu** — försenat och i
 *    dag — på BÅDA ytorna. Ingen rad med `due_at` efter i dag syns.
 * 3. **I dag är användarens dag** ([[ADR-0044 Användarens dag]]): klockan
 *    23:30 UTC är det redan nästa dygn i `Europe/Stockholm`, och en rad som
 *    förfaller då stannar kvar.
 * 4. **Valet följer användaren** — det sparas på `user` och gäller i en ny
 *    session; ingenting bor i webbläsaren.
 * 5. **Pagineringen på `/tasks` går över samma fråga** och påverkas inte av
 *    växeln.
 * 6. **Brickorna räknar samma tal oavsett växeln** — de mäter vad som finns,
 *    inte vad hon valt att visa.
 * 7. **Rutten kräver inloggning och ett värde som ÄR en boolean.**
 *
 * Hjälparna med prefixet `todovy` kommer ur
 * tests/Feature/Frontend/TodovyTest.php — Pest lägger alla testfiler i samma
 * namnrymd, samma grepp som `paginering`-hjälparna i TaskpagineringTest. Bara
 * hjälparna med prefixet `vaxel` är nya här.
 *
 * Datumen är relativa till `Carbon::today()` av samma skäl som i TodovyTest:
 * urvalet kräver `visible_from <= idag`, och en fast dag hade gjort filen
 * tidsberoende.
 */

/**
 * Stänger av växeln på användaren — samma skrivning som
 * TaskPreferenceController gör, men utan en request emellan.
 */
function vaxelStangAv(User $anvandare): User
{
    $anvandare->update(['show_upcoming_tasks' => false]);

    return $anvandare;
}

/**
 * ULID:n ur en lista rader, i den ordning de kommer.
 *
 * @param  list<array<string, mixed>>  $rader
 * @return list<string>
 */
function vaxelUlider(array $rader): array
{
    return array_column($rader, 'ulid');
}

/**
 * Titlarna ur en lista rader, i den ordning de kommer.
 *
 * @param  list<array<string, mixed>>  $rader
 * @return list<string>
 */
function vaxelTitlar(array $rader): array
{
    return array_map(fn (array $rad): string => $rad['schedule']['title'], $rader);
}

// --- standardvärdet --------------------------------------------------------

/*
 * Klart när: med växeln på visar `/tasks` och panelen samma rader som i dag.
 *
 * En ny användare har `show_upcoming_tasks = true`, och svaren är desamma som
 * före issuen: alla synliga rader, i `due_at`-ordning, och panelen visar de av
 * dem som ryms i dess femtal. Provet jämför de två ytorna rad för rad i
 * stället för att räkna rader — en panel som byggde sin egen fråga hade kunnat
 * ge rätt antal och fel rader.
 */
it('visar samma rader som i dag när växeln är på', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    // Standardvärdet, utan att någon rört kolumnen.
    expect($anvandare->show_upcoming_tasks)->toBeTrue();

    todovyUppgift($item, todovyDatum(-5), 'Försenad');
    todovyUppgift($item, todovyDatum(0), 'I dag');
    todovyUppgift($item, todovyDatum(10), 'Framtida');

    $lista = actingAs($anvandare)->get('/tasks')->assertOk();
    $panel = actingAs($anvandare)->get('/dashboard')->assertOk();

    $listaRader = todovyRader($lista);
    $panelRader = $panel->inertiaProps()['tasks'];

    expect($listaRader)->toHaveCount(3)
        ->and(vaxelTitlar($listaRader))->toBe(['Försenad', 'I dag', 'Framtida'])
        ->and(vaxelUlider($panelRader))->toBe(vaxelUlider($listaRader))
        // Växelns läge följer med som propp på båda sidorna, så att
        // komponenten kan rita sitt eget tillstånd.
        ->and($lista->inertiaProps()['showUpcomingTasks'])->toBeTrue()
        ->and($panel->inertiaProps()['showUpcomingTasks'])->toBeTrue();
});

// --- växeln av -------------------------------------------------------------

/*
 * Klart när: med växeln av visas ingen rad med `due_at` efter i dag, varken
 * på `/tasks` eller på panelen.
 *
 * Både ULID:n och titeln prövas i svarskroppen — ett svar som ser rätt ut men
 * bär en rad för mycket är precis felet, och en glömd `where` ger inget larm.
 * Den framtida radens `visible_from` ligger i det förflutna, så det ENDA som
 * håller den borta är växeln.
 */
it('döljer rader efter i dag på båda ytorna när växeln är av', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    todovyUppgift($item, todovyDatum(-5), 'Försenad');
    todovyUppgift($item, todovyDatum(0), 'I dag');
    [, $framtida] = todovyUppgift($item, todovyDatum(10), 'Framtida');

    vaxelStangAv($anvandare);

    $lista = actingAs($anvandare)->get('/tasks')->assertOk();
    $panel = actingAs($anvandare)->get('/dashboard')->assertOk();

    expect(todovyGrupp($lista, 'upcoming'))->toBe([])
        ->and($panel->inertiaProps()['tasks'])->not->toBeEmpty();

    foreach ([$lista, $panel] as $svar) {
        expect($svar->getContent())->not->toContain('Framtida')
            ->and($svar->getContent())->not->toContain($framtida->ulid);
    }

    // Ingen rad i något av svaren har ett `due_at` efter i dag — det är
    // villkoret, prövat på SVARET och inte bara på titeln.
    foreach ([todovyRader($lista), $panel->inertiaProps()['tasks']] as $rader) {
        foreach (array_column($rader, 'due_at') as $due) {
            expect($due <= todovyDatum(0))->toBeTrue("due_at {$due} ligger efter i dag");
        }
    }
});

/*
 * Klart när: med växeln av visas försenade rader och rader som förfaller i
 * dag.
 *
 * Växeln tar bort det som ligger framåt och ingenting annat: de två grupperna
 * som ÄR aktuella nu står kvar, i samma ordning som förut, på båda ytorna.
 */
it('visar försenade och dagens rader när växeln är av', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    todovyUppgift($item, todovyDatum(-5), 'Försenad');
    todovyUppgift($item, todovyDatum(0), 'I dag');
    todovyUppgift($item, todovyDatum(10), 'Framtida');

    vaxelStangAv($anvandare);

    $lista = actingAs($anvandare)->get('/tasks')->assertOk();
    $panel = actingAs($anvandare)->get('/dashboard')->assertOk();

    expect(vaxelTitlar(todovyRader($lista)))->toBe(['Försenad', 'I dag'])
        ->and(vaxelTitlar($panel->inertiaProps()['tasks']))->toBe(['Försenad', 'I dag'])
        ->and(array_column(todovyGrupp($lista, 'overdue'), 'schedule'))
        ->toHaveCount(1);
});

/*
 * Klart när: klockan 23:30 UTC visas en rad som förfaller på användarens dag
 * (`Europe/Stockholm`) men inte en som förfaller dagen efter.
 *
 * Vid 23:30 UTC är klockan 01:30 i Stockholm, alltså nästa dygn. Serverns
 * datum hade dömt den första raden som framtida och fällt den; användarens
 * dag gör den till dagens uppgift. Jämförelsen är densamma som `overdue`,
 * grupperingen och `scopeTodoFor` gör sedan issue 135 ([[ADR-0044
 * Användarens dag]]).
 */
it('räknar i dag i användarens tidszon när växeln är av', function () {
    withoutVite();

    Carbon::setTestNow('2026-06-15 23:30:00');

    [, $anvandare, , $item] = todovyKontext();

    $anvandare->update(['timezone' => 'Europe/Stockholm', 'show_upcoming_tasks' => false]);

    expect($anvandare->today()->toDateString())->toBe('2026-06-16');

    todovyUppgift($item, '2026-06-16', 'I dag i Stockholm', ['visible_from' => '2026-06-01']);
    todovyUppgift($item, '2026-06-17', 'I morgon', ['visible_from' => '2026-06-01']);

    $svar = actingAs($anvandare)->get('/tasks')->assertOk();

    expect(array_column(todovyRader($svar), 'due_at'))->toBe(['2026-06-16'])
        ->and($svar->getContent())->toContain('I dag i Stockholm')
        ->and($svar->getContent())->not->toContain('I morgon');

    Carbon::setTestNow();
});

// --- skrivningen -----------------------------------------------------------

/*
 * Klart när: flaggan sparas på användaren och gäller i en ny session.
 *
 * Sessionen är hela poängen med en kolumn: valet följer personen och inte
 * webbläsaren. Provet läser därför tillbaka raden ur databasen och gör nästa
 * anrop med en FRISK modell — samma sak som en ny session gör — i stället för
 * med den instans som råkade ligga i minnet.
 */
it('sparar växeln på användaren och låter den gälla i en ny session', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    todovyUppgift($item, todovyDatum(10), 'Framtida');

    actingAs($anvandare)
        ->from('/tasks')
        ->put('/settings/tasks', ['show_upcoming_tasks' => false])
        ->assertRedirect('/tasks');

    expect($anvandare->fresh()->show_upcoming_tasks)->toBeFalse();

    // Ny session: modellen läses om ur databasen och bär det sparade värdet.
    $nySession = $anvandare->fresh();

    $svar = actingAs($nySession)->get('/tasks')->assertOk();

    expect(todovyRader($svar))->toBe([])
        ->and($svar->inertiaProps()['showUpcomingTasks'])->toBeFalse()
        ->and($svar->getContent())->not->toContain('Framtida');

    // Och tillbaka igen: växeln går att slå på från vilken yta som helst, och
    // raden kommer tillbaka.
    actingAs($nySession)->from('/dashboard')->put('/settings/tasks', ['show_upcoming_tasks' => true]);

    expect($nySession->fresh()->show_upcoming_tasks)->toBeTrue()
        ->and(todovyRader(actingAs($anvandare->fresh())->get('/tasks')->assertOk()))->toHaveCount(1);
});

/*
 * Klart när: `PUT /settings/tasks` kräver inloggning och avvisar ett värde
 * som inte är boolean.
 *
 * Rutten ligger bakom `auth`, som resten av webben. Kroppen bär ett fält, och
 * regeln är `boolean`: en sträng ur en handskriven request blir ett
 * valideringsfel i stället för en tyst `(bool)`-kastning — `(bool) 'nej'` är
 * sant, och en växel som svarar på fel fråga är värre än ett felmeddelande.
 * Ett avvisat värde skriver ingenting.
 */
it('kräver inloggning och avvisar ett värde som inte är en boolean', function () {
    withoutVite();

    expect(route('settings.tasks.update', [], false))->toBe('/settings/tasks');

    put('/settings/tasks', ['show_upcoming_tasks' => false])->assertRedirect('/login');

    [, $anvandare] = todovyKonto();

    actingAs($anvandare)
        ->put('/settings/tasks', ['show_upcoming_tasks' => 'inte-en-boolean'])
        ->assertSessionHasErrors('show_upcoming_tasks');

    actingAs($anvandare)
        ->put('/settings/tasks', [])
        ->assertSessionHasErrors('show_upcoming_tasks');

    expect($anvandare->fresh()->show_upcoming_tasks)->toBeTrue();
});

// --- pagineringen ----------------------------------------------------------

/*
 * Klart när: pagineringen på `/tasks` fungerar med växeln av.
 *
 * Sextio rader lämnas av växeln — trettio försenade och trettio som förfaller
 * i dag — och femtiofem framtida ligger i urvalet när växeln är på men ska
 * aldrig synas här. Sidräkningen görs alltså över de sextio, och markören över
 * `(due_at, ulid)` är orörd: sida ett slutar på den tjugonde `idag`-raden och
 * sida två börjar på den tjugoförsta, med SAMMA `due_at`.
 */
it('paginerar listan när växeln är av', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    $försenade = [];
    $idag = [];

    foreach (range(1, 30) as $i) {
        [, $rad] = todovyUppgift($item, todovyDatum(-10), "Försenad {$i}");
        $försenade[] = $rad->ulid;
    }

    foreach (range(1, 30) as $i) {
        [, $rad] = todovyUppgift($item, todovyDatum(0), "I dag {$i}");
        $idag[] = $rad->ulid;
    }

    foreach (range(1, 55) as $i) {
        todovyUppgift($item, todovyDatum(10), "Framtida {$i}");
    }

    vaxelStangAv($anvandare);

    $alla = [...$försenade, ...$idag];

    $sida1 = actingAs($anvandare)->get('/tasks')->assertOk();

    expect($sida1->inertiaProps()['previousUrl'])->toBeNull()
        ->and($sida1->inertiaProps()['nextUrl'])->not->toBeNull();

    $sida2 = actingAs($anvandare)->get($sida1->inertiaProps()['nextUrl'])->assertOk();

    expect(vaxelUlider(todovyRader($sida1)))->toBe(array_slice($alla, 0, 50))
        ->and(vaxelUlider(todovyRader($sida2)))->toBe(array_slice($alla, 50))
        ->and($sida2->inertiaProps()['nextUrl'])->toBeNull()
        // Gränsen ligger i det döda loppet: sida ett slutar på den tjugonde
        // `idag`-raden och sida två börjar på den tjugoförsta.
        ->and(todovyRader($sida1)[49]['due_at'])->toBe(todovyRader($sida2)[0]['due_at'])
        // Ingen framtida rad på någon av sidorna.
        ->and(todovyGrupp($sida1, 'upcoming'))->toBe([])
        ->and(todovyGrupp($sida2, 'upcoming'))->toBe([]);
});

// --- brickorna -------------------------------------------------------------

/*
 * Klart när: dashboardens brickor visar samma tal med växeln av som på.
 *
 * Talen mäter vad som FINNS och inte vad hon valt att visa: en bricka som
 * krympte när hon fällde ihop listan vore ett annat tal än i går. Panelen
 * däremot följer växeln — den visar den lista hon valt.
 */
it('visar samma brickor med växeln av som på', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    todovyUppgift($item, todovyDatum(-5), 'Försenad');
    todovyUppgift($item, todovyDatum(0), 'I dag');
    todovyUppgift($item, todovyDatum(10), 'Framtida');

    $på = actingAs($anvandare)->get('/dashboard')->assertOk();

    vaxelStangAv($anvandare);

    $av = actingAs($anvandare)->get('/dashboard')->assertOk();

    expect($av->inertiaProps()['stats'])->toBe($på->inertiaProps()['stats'])
        ->and($på->inertiaProps()['stats']['tasks'])->toBe(3)
        ->and($på->inertiaProps()['stats']['overdue'])->toBe(1)
        // Panelen är den yta som följer valet.
        ->and($på->inertiaProps()['tasks'])->toHaveCount(3)
        ->and($av->inertiaProps()['tasks'])->toHaveCount(2);
});

// --- växeln som komponent --------------------------------------------------

/*
 * Klart när: växeln har `role="switch"` och `aria-checked`.
 *
 * En knapp med `aria-pressed` hade lästs som "nedtryckt" och inte som "på";
 * en switch är kontrollen som beskriver ett tillstånd. Läget kommer ur
 * `enabled`-proppen — serverns svar — och postar det motsatta till
 * `PUT /settings/tasks`, så att sidan ritas om ur det sparade värdet. Samma
 * komponent ritas på båda ytorna; en kopia hade varit den andra sanningen om
 * vad växeln gör.
 */
it('ritar växeln som en switch med aria-checked på båda ytorna', function () {
    $vaxel = File::get(resource_path('js/components/UpcomingTasksToggle.vue'));

    expect($vaxel)->toContain('role="switch"')
        ->toContain('aria-checked')
        // Läget kommer ur proppen och skrivningen går till ruttens sökväg.
        ->toContain('props.enabled')
        ->toContain("form.put('/settings/tasks'");

    // Ingen localStorage: valet följer användaren och inte webbläsaren.
    // Kommentarerna bort före kontrollen — docblocken talar med flit om vad
    // komponenten INTE gör, och det är koden som prövas.
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $vaxel);

    expect($kod)->not->toContain('localStorage');

    $sida = File::get(resource_path('js/pages/Tasks/Index.vue'));
    $panel = File::get(resource_path('js/components/DashboardTasksPanel.vue'));

    expect($sida)->toContain("import UpcomingTasksToggle from '../../components/UpcomingTasksToggle.vue'")
        ->toContain('<UpcomingTasksToggle :enabled="props.showUpcomingTasks" />')
        ->and($panel)->toContain("import UpcomingTasksToggle from './UpcomingTasksToggle.vue'")
        ->toContain('<UpcomingTasksToggle :enabled="props.showUpcomingTasks" />');

    // Panelen är fortfarande ingen fråga: den filtrerar ingenting själv.
    expect($panel)->not->toContain('sort(');
    expect($panel)->not->toContain('slice(');
});

// --- kolumnen och dokumentationen ------------------------------------------

/*
 * Klart när: migreringen följer konventionerna och går på MariaDB.
 *
 * Kolumnen är additiv med ett standardvärde (AGENTS.md
 * § Databaskonventioner), och standardvärdet ÄR dagens beteende — en
 * befintlig användare ska inte se någon skillnad förrän hon slår av växeln.
 * Att migreringen GÅR på MariaDB prövas av CI-jobbet `Migreringar`, som kör
 * `up()` mot mariadb:10.6; testsviten kör sqlite och kan inte svara på det.
 *
 * Modellens standardvärde prövas också, och det är inte samma sak: Eloquent
 * läser inte databasens DEFAULT in i en ny modell, och utan `$attributes` på
 * App\Models\User hade en färsk instans läst null och filtrerat listan för en
 * användare som aldrig rört växeln.
 */
it('har kolumnen på user med standardvärdet true', function () {
    expect(Schema::hasColumn('user', 'show_upcoming_tasks'))->toBeTrue();

    $kolumn = collect(Schema::getColumns('user'))->firstWhere('name', 'show_upcoming_tasks');

    expect($kolumn)->not->toBeNull()
        ->and($kolumn['nullable'])->toBeFalse();

    $ny = User::factory()->create();

    expect($ny->show_upcoming_tasks)->toBeTrue()
        ->and($ny->fresh()->show_upcoming_tasks)->toBeTrue();
});

/*
 * Klart när: [[Konton och åtkomst]] § user har kolumnen.
 *
 * Dokumentationen är kartan och inte koden, och en kolumn ingen beskriver är
 * en kolumn nästa läsare får gissa sig till. Provet läser filen som CLAUDE.md
 * skickar varje fråga om `user` till.
 */
it('dokumenterar kolumnen i Konton och åtkomst', function () {
    $dokument = File::get(base_path('docs/Datamodell/Konton och åtkomst.md'));

    expect($dokument)->toContain('show_upcoming_tasks');
});
