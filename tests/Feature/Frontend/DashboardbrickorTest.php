<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Issue 124 · Brickorna och containerkorten, se
 * App\Http\Controllers\DashboardController,
 * App\Actions\Container\ListContainerSummaries,
 * resources/js/pages/Dashboard.vue,
 * resources/js/components/DashboardStats.vue,
 * resources/js/components/ContainerCard.vue och lang/en/ui.php.
 *
 * Filen bevisar de tre talen och den ena grupperingsregeln:
 *
 * 1. **Containerbrickan räknar samma urval som containerlistan** — antalet
 *    containrar användaren NÅR, alltså även en container som bara en grant
 *    öppnar.
 * 2. **Uppgiftsbrickan är antalet rader på `/tasks`.** Inte ett liknande tal:
 *    provet hämtar båda sidorna och jämför. Underraden är antalet försenade,
 *    och det är serverns gruppering — inte klientens klocka.
 * 3. **Varje kort räknar det användaren SJÄLV når** ([[ADR-0039 Containerns
 *    översikt]] § Beslut). Provet är en omfångsbegränsad mottagare: hon ser
 *    sitt item och sitt tal, och varken det dolda itemets namn eller dess tal.
 * 4. **Grupperingen är ADR-0036:s regel** — en art med minst två containrar
 *    får en egen rubrik, en art med en container hamnar i högen.
 * 5. **Frågekostnaden är konstant oberoende av antalet containrar** (issue 70
 *    § Beslut 2).
 *
 * Hjälparna har prefixet `bricka` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * En dag relativt serverns idag, som DATE-sträng.
 */
function brickaDatum(int $dagar): string
{
    return Carbon::today()->addDays($dagar)->toDateString();
}

/**
 * Ett ägarkonto med en medlem i.
 *
 * @return array{0: Account, 1: User}
 */
function brickaKonto(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare];
}

/**
 * En container under $konto, med frivillig art.
 */
function brickaPärm(Account $konto, ?string $art = null): Container
{
    return Container::factory()->for($konto, 'account')->create(['kind' => $art]);
}

/**
 * Ett item i containern. `created_by_*` sätts sammanhängande, som i
 * tests/Feature/Frontend/DashboardTest.php.
 */
function brickaItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En öppen uppgift på itemet, med känd förfallodag.
 *
 * Raden byggs direkt i stället för genom ett jobb, av samma skäl som i
 * DashboardTest: förfallodagen ska vara känd utan att räkna kalender.
 */
function brickaUppgift(Item $item, string $due, string $titel): ScheduleOccurrence
{
    $schema = Schedule::factory()->for($item, 'item')->create([
        'title' => $titel,
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => $due,
        'lead_days' => 0,
        'is_active' => true,
    ]);

    return ScheduleOccurrence::factory()->create([
        'schedule_id' => $schema->id,
        'due_at' => $due,
        'visible_from' => brickaDatum(-30),
        'status' => 'open',
    ]);
}

/**
 * En mottagare UTANFÖR ägarkontot, med en grant på angiven nivå — item-bred
 * när $item ges, container-bred annars.
 */
function brickaMottagare(Container $container, ?Item $item, string $niva): User
{
    $mottagare = User::factory()->create(['locale' => 'sv_SE']);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $niva,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/**
 * Brickorna ur svaret.
 *
 * @return array{containers: int, tasks: int, overdue: int}
 */
function brickaTal(TestResponse $svar): array
{
    /** @var array{containers: int, tasks: int, overdue: int} $tal */
    $tal = $svar->inertiaProps()['stats'];

    return $tal;
}

/**
 * Containergrupperna ur svaret.
 *
 * @return list<array{kind: string|null, containers: list<array<string, mixed>>}>
 */
function brickaGrupper(TestResponse $svar): array
{
    /** @var list<array{kind: string|null, containers: list<array<string, mixed>>}> $grupper */
    $grupper = $svar->inertiaProps()['containerGroups'];

    return $grupper;
}

/**
 * Korten ur en grupp, som container-ULID → `{items, todos}`.
 *
 * @param  list<array<string, mixed>>  $kort
 * @return array<string, array{items: int, todos: int}>
 */
function brickaPerUlid(array $kort): array
{
    $tal = [];

    foreach ($kort as $rad) {
        $tal[$rad['ulid']] = ['items' => $rad['items'], 'todos' => $rad['todos']];
    }

    return $tal;
}

/**
 * Antalet frågor $anrop ställer, mätt i en KALL request — samma mätning som
 * DashboardTest gör och av samma skäl: det första anropet värmer guarderna,
 * kontocachen, texten och `last_active_at`, och `ResolveItemScope` memoiserar
 * per `{user, container}` i en `scoped`-bindning som överlever mellan
 * HTTP-anropen i testsviten.
 */
function brickaFrågor(Closure $anrop): int
{
    $anrop();

    app()->forgetScopedInstances();

    $frågor = 0;

    DB::listen(function ($query) use (&$frågor) {
        if (! str_contains($query->sql, 'last_active_at')) {
            $frågor++;
        }
    });

    $anrop();

    return $frågor;
}

// --- containerbrickan ------------------------------------------------------

/*
 * Klart när: containerbrickan visar antalet containrar användaren når.
 *
 * Urvalet är `Container::scopeAccessibleBy()` — samma villkor som
 * containerlistan ställer — så en container som bara en grant öppnar räknas,
 * och en främmandes container gör det inte. Provet jämför med listan i stället
 * för att räkna upp ett tal: en bricka med ett eget urval hade kunnat ge rätt
 * antal i ett enkelt fall och fel i nästa.
 */
it('räknar samma containrar som containerlistan', function () {
    withoutVite();

    [$konto, $agaren] = brickaKonto();

    $egna = brickaPärm($konto, 'Båt');

    // En container utanför kontot, som bara en grant öppnar.
    $delad = brickaPärm(Account::factory()->create(), 'Hus');
    $mottagare = brickaMottagare($delad, null, 'read');

    // Och en container ingen av dem når.
    brickaPärm(Account::factory()->create(), 'Bil');

    $agarens = actingAs($agaren)->get('/dashboard')->assertOk();
    $mottagarens = actingAs($mottagare)->get('/dashboard')->assertOk();

    expect(brickaTal($agarens)['containers'])->toBe(1)
        ->and(brickaTal($mottagarens)['containers'])->toBe(1);

    $lista = actingAs($agaren)->get('/containers')->assertOk();

    expect($lista->inertiaProps()['containers'])->toHaveCount(brickaTal($agarens)['containers']);

    // Den container som bara granten öppnar ligger i mottagarens grupp.
    $ulids = array_column(brickaGrupper($mottagarens)[0]['containers'], 'ulid');

    expect($ulids)->toBe([$delad->ulid])
        ->and(array_column(brickaGrupper($agarens)[0]['containers'], 'ulid'))->toBe([$egna->ulid]);
});

it('visar noll och inga grupper för en användare utan containrar', function () {
    withoutVite();

    [, $anvandare] = brickaKonto();

    $svar = actingAs($anvandare)->get('/dashboard')->assertOk();

    expect(brickaTal($svar))->toBe(['containers' => 0, 'tasks' => 0, 'overdue' => 0])
        ->and(brickaGrupper($svar))->toBe([]);
});

// --- uppgiftsbrickan -------------------------------------------------------

/*
 * Klart när: uppgiftsbrickan visar samma tal som antalet rader på `/tasks`,
 * med antalet försenade som underrad.
 *
 * Provet hämtar båda sidorna och jämför talen mot listans rader. En bricka som
 * ställde en egen fråga hade kunnat ge rätt antal i dag och fel så snart
 * `scopeTodoFor()` ändrades — och underraden är serverns gruppering, räknad mot
 * serverns datum, aldrig klientens klocka.
 */
it('visar antalet rader på /tasks med de försenade som underrad', function () {
    withoutVite();

    [$konto, $anvandare] = brickaKonto();

    $container = brickaPärm($konto);
    $item = brickaItem($container, 'Motorn');

    // Två försenade och tre framåt: underraden är två, brickan fem.
    brickaUppgift($item, brickaDatum(-10), 'Försenad ett');
    brickaUppgift($item, brickaDatum(-1), 'Försenad två');

    foreach (range(1, 3) as $i) {
        brickaUppgift($item, brickaDatum(10 * $i), "Framåt {$i}");
    }

    $bricka = actingAs($anvandare)->get('/dashboard')->assertOk();
    $lista = actingAs($anvandare)->get('/tasks')->assertOk();

    /** @var array<string, list<array<string, mixed>>> $grupper */
    $grupper = $lista->inertiaProps()['groups'];
    $rader = array_merge($grupper['overdue'], $grupper['today'], $grupper['upcoming']);

    expect($rader)->toHaveCount(5)
        ->and(brickaTal($bricka)['tasks'])->toBe(count($rader))
        ->and(brickaTal($bricka)['tasks'])->toBe(5)
        ->and(brickaTal($bricka)['overdue'])->toBe(count($grupper['overdue']))
        ->and(brickaTal($bricka)['overdue'])->toBe(2);
});

it('räknar de försenade mot serverns datum och inte mot klientens', function () {
    withoutVite();

    [$konto, $anvandare] = brickaKonto();

    $container = brickaPärm($konto);
    $item = brickaItem($container, 'Motorn');

    brickaUppgift($item, brickaDatum(-1), 'Igår');
    brickaUppgift($item, brickaDatum(0), 'Idag');

    $svar = actingAs($anvandare)->get('/dashboard')->assertOk();

    // Raden som förfaller i dag är inte försenad: jämförelsen är `due_at`
    // mindre än serverns idag, samma som ListTodo::group().
    expect(brickaTal($svar))->toBe(['containers' => 1, 'tasks' => 2, 'overdue' => 1]);
});

// --- korten ----------------------------------------------------------------

/*
 * Klart när: varje kort visar antalet items och öppna uppgifter inom
 * användarens omfång, bevisat med en omfångsbegränsad mottagare.
 *
 * Det är läckagetestet. Mottagaren har en itemgrant på ett av två items, och
 * båda talen ska räkna hennes item och ingenting annat: ett kort som räknade
 * containerns alla hade avslöjat exakt det [[ADR-0028 Åtkomst på itemnivå]]
 * § Konsekvenser stänger — att det finns något hon inte får se.
 */
it('räknar kortets tal inom mottagarens omfång', function () {
    withoutVite();

    [$konto, $agare] = brickaKonto();

    $pärm = brickaPärm($konto);

    $mitt = brickaItem($pärm, 'Motorn');
    $dolt = brickaItem($pärm, 'Hemlig motor');

    brickaUppgift($mitt, brickaDatum(30), 'Byt impeller');
    brickaUppgift($dolt, brickaDatum(31), 'Hemlig uppgift');

    $mottagare = brickaMottagare($pärm, $mitt, 'read');

    $mottagarens = actingAs($mottagare)->get('/dashboard')->assertOk();
    $agarens = actingAs($agare)->get('/dashboard')->assertOk();

    expect(brickaPerUlid(brickaGrupper($mottagarens)[0]['containers']))
        ->toBe([$pärm->ulid => ['items' => 1, 'todos' => 1]]);

    // Ägaren ser båda itemsen — talet är längden på HENNES lista, inte
    // containerns, och de två svaren skiljer sig därför åt.
    expect(brickaPerUlid(brickaGrupper($agarens)[0]['containers']))
        ->toBe([$pärm->ulid => ['items' => 2, 'todos' => 2]]);

    expect($mottagarens->getContent())->not->toContain('Hemlig motor')
        ->and($mottagarens->getContent())->not->toContain('Hemlig uppgift');
});

/*
 * Klart när: korten länkar till containerns översikt.
 *
 * Adressen är `containers.show` — samma URL som itemlistan låg på före issue
 * 89 — och provet binder den i Vue-filen till ruttabellen: en flyttad rutt
 * faller här och inte först i webbläsaren.
 */
it('länkar varje kort till containerns översikt', function () {
    withoutVite();

    [$konto] = brickaKonto();
    $container = brickaPärm($konto);

    expect(route('containers.show', $container, false))->toBe("/containers/{$container->ulid}");

    $kort = File::get(resource_path('js/components/ContainerCard.vue'));

    expect($kort)->toContain('`/containers/${props.container.ulid}`')
        // Ramen är UiCard och namnet är rubrikraden (issue 99).
        ->toContain("import UiCard from './UiCard.vue'")
        ->toContain('<template #heading>');

    // Foto, undertitel och framdriftsstapel har ingen datakälla och ritas inte.
    expect($kort)->not->toContain('<img');
    expect($kort)->not->toContain('progress');
});

// --- grupperingen ----------------------------------------------------------

/*
 * Klart när: en art med två containrar får en egen rubrik, och en art med en
 * container hamnar under *My containers*.
 *
 * Regeln är [[ADR-0036 Containerns art]] § Beslut: rubriken är artens namn
 * skrivet ordagrant, och högen bär mockupens namn. Att en art med en enda
 * container ligger löst är hela poängen — tre rubriker med ett kort under
 * varje ser ut som ett fel i programmet.
 */
it('ger en art med två containrar en egen rubrik och lägger resten i högen', function () {
    withoutVite();

    [$konto, $anvandare] = brickaKonto();

    $projektA = brickaPärm($konto, 'Projekt');
    $projektB = brickaPärm($konto, 'Projekt');
    $baten = brickaPärm($konto, 'Båt');
    $utanArt = brickaPärm($konto);

    $svar = actingAs($anvandare)->get('/dashboard')->assertOk();
    $grupper = brickaGrupper($svar);

    // Högen först, som mockupen, sedan arterna i bokstavsordning.
    expect($grupper)->toHaveCount(2)
        ->and(array_column($grupper, 'kind'))->toBe([null, 'Projekt']);

    // En art med EN container ligger i högen — båten och den utan art. Korten
    // ligger i namnordning inom gruppen, och fabrikens namn är slumpade, så
    // jämförelsen är mängden och inte ordningen.
    $hog = array_column($grupper[0]['containers'], 'ulid');
    sort($hog);

    $forvantad = [$utanArt->ulid, $baten->ulid];
    sort($forvantad);

    expect($hog)->toBe($forvantad);

    $projekt = array_column($grupper[1]['containers'], 'ulid');
    sort($projekt);

    $bada = [$projektA->ulid, $projektB->ulid];
    sort($bada);

    expect($projekt)->toBe($bada);
});

it('lägger allt i högen när ingen art bär två containrar', function () {
    withoutVite();

    [$konto, $anvandare] = brickaKonto();

    brickaPärm($konto, 'Båt');
    brickaPärm($konto, 'Hus');
    brickaPärm($konto, 'Bil');

    $grupper = brickaGrupper(actingAs($anvandare)->get('/dashboard')->assertOk());

    expect($grupper)->toHaveCount(1)
        ->and($grupper[0]['kind'])->toBeNull()
        ->and($grupper[0]['containers'])->toHaveCount(3);
});

// --- kostnaden -------------------------------------------------------------

/*
 * Klart när: frågekostnaden är konstant oberoende av antalet containrar.
 *
 * Containrarna läggs i NYA containers med items och uppgifter, ett värstingfall
 * för en omfångsupplösning per kort. Omfånget värms i ETT anrop för alla kort
 * genom `ResolveItemScope::forContainers()`, itemtalen är EN grupperad fråga,
 * och uppgiftstalen kommer ur todo-svaret som redan är hämtat (issue 70
 * § Beslut 2).
 */
it('kostar ett konstant antal frågor oberoende av antalet containrar', function () {
    withoutVite();

    [$konto, $anvandare] = brickaKonto();

    $container = brickaPärm($konto, 'Projekt');
    brickaUppgift(brickaItem($container, 'Motorn'), brickaDatum(30), 'Byt impeller');

    actingAs($anvandare);

    // Tiden fryst runt mätningarna: UpdateLastActiveAt skriver
    // `user.last_active_at` vid varje autentiserat anrop, och faller en
    // sekundgräns mellan det värmande anropet och mätningen blir det en UPDATE
    // extra (issue 477, se tests/Feature/Testinfrastruktur/FragerakningTest.php).
    Carbon::setTestNow(now());

    $medEn = brickaFrågor(function () {
        get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->has('containerGroups', 1));
    });

    // Nio containers till, var och en med ett item och en öppen uppgift.
    foreach (range(2, 10) as $i) {
        $pärm = brickaPärm($konto, 'Projekt');
        brickaUppgift(brickaItem($pärm, "Item {$i}"), brickaDatum(30), "Uppgift {$i}");
    }

    $medTio = brickaFrågor(function () {
        get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('stats.containers', 10));
    });

    expect($medTio)->toBe($medEn);

    Carbon::setTestNow();
});
