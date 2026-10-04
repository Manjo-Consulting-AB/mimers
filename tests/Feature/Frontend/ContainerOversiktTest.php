<?php

use App\Http\Controllers\ContainerController;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 172 · Containerns översikt får sina paneler, se
 * app/Http/Controllers/ContainerController::show(),
 * app/Actions/Schedule/ListTodo::forContainer(),
 * app/Actions/Audit/ListAuditEvents::forContainer(),
 * resources/js/pages/Containers/Overview.vue,
 * resources/js/components/ContainerTasksPanel.vue,
 * resources/js/components/ContainerDetailsPanel.vue och
 * [[ADR-0050 Desktopdesignen]] § 7.
 *
 * Filen bevisar panelerna och den enda regel de alla lyder under — att varje
 * tal räknar det användaren SJÄLV når ([[ADR-0039 Containerns översikt]]
 * § Beslut, [[ADR-0028 Åtkomst på itemnivå]]):
 *
 * 1. **Uppgiftspanelen är containerns todo-urval**, samma rader och samma
 *    ordning som `/tasks`, avgränsat till containern och klippt till fem.
 *    Växeln för framtida uppgifter gäller raderna — och INTE brickan.
 * 2. **Kostnadspanelen är den fasta summeringen** och nedbrytningen per item,
 *    utan period och utan plangrind ([[ADR-0038 Gränsen för Pro i
 *    kostnaderna]]), och panelen ritas inte alls när containern saknar rader.
 * 3. **Itempanelen är samma `ListItems`-svar som itembrickan räknar**, klippt
 *    till sex.
 * 4. **Aktivitetspanelen följer händelseloggens läsregel** och visar högst
 *    fem rader ([[ADR-0043 Tre loggar]]).
 * 5. **En gäst med en itemgrant ser bara sitt item** — i items, uppgifter och
 *    kostnader, och i svarets råa innehåll.
 * 6. **`counts` är oförändrade.** Talen kommer ur samma anrop som panelerna,
 *    men panelernas tak är inte brickornas tak.
 *
 * Att `/tasks` och dashboarden visar samma rader som före issuen prövas av
 * tests/Feature/Uppgift/TodoTest.php och tests/Feature/Frontend/DashboardTest.php
 * — båda gröna utan ändring, vilket är beviset för att `forContainer()` bara
 * lade en avgränsning och en gräns ovanpå (`handle()` är orörd).
 *
 * **Klockan pinnas till mitt på dagen UTC** ([[ADR-0044 Användarens dag]]),
 * samma skäl och samma grepp som DashboardTest: servern går i UTC och
 * användaren i Europe/Stockholm, och mellan klockan 22 och 24 UTC är hennes
 * datum redan i morgondagen. Vid tolv sammanfaller de två, och proven är
 * oförändrade.
 *
 * Hjälparna har prefixet `oversikt` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::today()->setTime(12, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En dag relativt serverns idag, som DATE-sträng.
 */
function oversiktDatum(int $dagar): string
{
    return Carbon::today()->addDays($dagar)->toDateString();
}

/**
 * Ett ägarkonto med en medlem i.
 *
 * @return array{0: Account, 1: User}
 */
function oversiktKonto(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare];
}

/**
 * En container under $konto, med ett bestämt namn så panelerna går att läsa.
 */
function oversiktParm(Account $konto, string $namn = 'Havsörnen'): Container
{
    return Container::factory()->for($konto, 'account')->create(['name' => $namn]);
}

/**
 * Ett item i containern, med kända `created_by_*` — samma form som
 * tests/Feature/Frontend/DashboardTest.php använder.
 */
function oversiktItem(Container $container, string $namn): Item
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
 * Raden byggs direkt i stället för genom App\Actions\Schedule\
 * OpenNextOccurrence, av samma skäl som i DashboardTest: förfallodagen ska
 * vara känd utan att räkna kalender.
 */
function oversiktUppgift(Item $item, string $due, string $titel): ScheduleOccurrence
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
        'visible_from' => oversiktDatum(-30),
        'status' => 'open',
    ]);
}

/**
 * En kostnadsrad på itemet, med känt belopp och valuta.
 */
function oversiktKostnad(Item $item, int $belopp, string $valuta = 'SEK', ?string $datum = null): CostEntry
{
    return CostEntry::factory()->for($item, 'item')->create([
        'amount' => $belopp,
        'currency' => $valuta,
        'incurred_on' => $datum ?? oversiktDatum(0),
        'created_by_user_id' => $item->created_by_user_id,
        'created_by_account_id' => $item->created_by_account_id,
    ]);
}

/**
 * En mottagare UTANFÖR ägarkontot, med en grant på ETT item — den smala
 * granten är vad som gör omfånget begränsat.
 */
function oversiktGast(Container $container, Item $item, string $niva = 'read'): User
{
    $gast = User::factory()->create(['locale' => 'sv_SE']);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $gast->id,
        'level' => $niva,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $gast;
}

/**
 * En loggrad skriven DIREKT i loggen, med en tid vi väljer — samma grepp som
 * handelseRad() i HandelsepanelTest och av samma skäl: proven handlar om
 * LÄSNINGEN, och "nyast först" går inte att bevisa utan ett `created_at` vi
 * väljer.
 */
function oversiktHandelse(
    Container $container,
    Account $account,
    ?User $handlande,
    ?Carbon $nar = null,
    ?Item $item = null,
): AuditLog {
    return AuditLog::factory()->create([
        'container_id' => $container->id,
        'account_id' => $account->id,
        'user_id' => $handlande?->id,
        'item_id' => $item?->id,
        'action' => AuditLog::ACTION_CONTAINER_CREATED,
        'created_at' => $nar ?? now(),
    ]);
}

/**
 * Översiktens proppar ur svaret.
 *
 * @return array<string, mixed>
 */
function oversiktProps(TestResponse $svar): array
{
    return $svar->inertiaProps();
}

/**
 * Uppgiftsradernas schematitlar, i serverns ordning.
 *
 * @param  list<array<string, mixed>>  $rader
 * @return list<string>
 */
function oversiktTitlar(array $rader): array
{
    return array_map(fn (array $rad): string => $rad['schedule']['title'], $rader);
}

/**
 * Källkoden med kommentarer borta. Samma tre slag som panelvyUtanKommentarer()
 * i DashboardTest rensar, och av samma skäl: ett prov som letar efter en klass
 * eller en markup ska inte kunna nöjas av en mening i en docblock.
 */
function oversiktUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

// --- uppgiftspanelen -------------------------------------------------------

/*
 * Klart när: `uppgiftspanelen visar containerns todo-rader i todo-listans
 * ordning, högst fem`.
 *
 * Provet jämför panelen med `/tasks` rad för rad. En panel med en egen fråga
 * hade kunnat ge fem rader och ändå fel fem — samma antal, annan ordning,
 * eller rader som listan inte visar. ULID:na är jämförelsen, och de två sista
 * av sju får inte synas i panelen.
 */
it('visar containerns todo-rader i todo-listans ordning, högst fem', function () {
    withoutVite();

    expect(ContainerController::TASK_LIMIT)->toBe(5);

    [$konto, $agare] = oversiktKonto();
    $container = oversiktParm($konto);
    $motorn = oversiktItem($container, 'Motorn');

    // Sju uppgifter i stigande förfallodag — varje ny rad hamnar sist i
    // listan, så en panel som klippte FÖRE sorteringen hade visat fel fem.
    $rader = [];

    foreach (range(1, 7) as $i) {
        $rader[] = oversiktUppgift($motorn, oversiktDatum(10 * $i), "Uppgift {$i}");
    }

    $panel = oversiktProps(actingAs($agare)->get("/containers/{$container->ulid}")->assertOk())['tasks'];

    $lista = oversiktProps(actingAs($agare)->get('/tasks')->assertOk())['groups'];
    $urval = array_column(array_merge($lista['overdue'], $lista['today'], $lista['upcoming']), 'ulid');

    expect($urval)->toHaveCount(7)
        ->and($panel)->toHaveCount(5)
        ->and(array_column($panel, 'ulid'))->toBe(array_slice($urval, 0, 5));

    // Den sjätte och sjunde raden finns i listan och inte i panelen.
    $svar = actingAs($agare)->get("/containers/{$container->ulid}")->assertOk();

    foreach (array_slice($rader, 5) as $utanfor) {
        expect($svar->getContent())->not->toContain($utanfor->ulid);
    }

    // Samma rad är samma sak: itemets namn och schemats titel följer med.
    expect($panel[0]['item']['name'])->toBe('Motorn')
        ->and($panel[0]['schedule']['title'])->toBe('Uppgift 1');
});

/*
 * Klart när: `uppgiftspanelen följer växeln för framtida uppgifter`.
 *
 * Växeln (`user.show_upcoming_tasks`, issue 134) gäller panelen precis som på
 * dashboarden — och INTE brickan: `counts.todos` är det som är aktuellt nu,
 * försenat plus i dag (issue 697), och inte vad användaren valt att se. Utan
 * den skillnaden hade talet krympt i samma stund hon fällde ihop listan.
 */
it('följer växeln för framtida uppgifter men räknar brickan oberoende av den', function () {
    withoutVite();

    [$konto, $agare] = oversiktKonto();
    $container = oversiktParm($konto);
    $motorn = oversiktItem($container, 'Motorn');

    oversiktUppgift($motorn, oversiktDatum(-5), 'Försenad');
    oversiktUppgift($motorn, oversiktDatum(0), 'I dag');
    oversiktUppgift($motorn, oversiktDatum(30), 'Framtida');

    $pa = oversiktProps(actingAs($agare)->get("/containers/{$container->ulid}")->assertOk());

    expect(oversiktTitlar($pa['tasks']))->toBe(['Försenad', 'I dag', 'Framtida'])
        ->and($pa['counts']['todos'])->toBe(2);

    $agare->update(['show_upcoming_tasks' => false]);

    $av = oversiktProps(actingAs($agare)->get("/containers/{$container->ulid}")->assertOk());

    // Raderna följer växeln — bara försenat och i dag.
    expect(oversiktTitlar($av['tasks']))->toBe(['Försenad', 'I dag']);

    // Talet gör det inte: det räknar det som är aktuellt nu (issue 697), och
    // är oförändrat.
    expect($av['counts']['todos'])->toBe(2);
});

/*
 * Klart när: `en uppgift i en annan container visas inte`.
 *
 * Avgränsningen är `schedule.item.container_id`, samma väg till containern som
 * uppgiftsbrickan alltid gått. Användaren NÅR den andra containern — den
 * ligger i samma konto — så ett prov som bara räknade rader hade kunnat se
 * rätt ut av fel skäl.
 */
it('visar inte en uppgift i en annan container', function () {
    withoutVite();

    [$konto, $agare] = oversiktKonto();
    $container = oversiktParm($konto);
    $motorn = oversiktItem($container, 'Motorn');
    // Förfallodagen ligger i det förflutna: `counts.todos` räknar det som är
    // aktuellt nu (issue 697), och det är avgränsningen — inte datumet —
    // provet gäller.
    oversiktUppgift($motorn, oversiktDatum(-5), 'Byt impeller');

    $annan = oversiktParm($konto, 'Trailern');
    oversiktUppgift(oversiktItem($annan, 'Hjulen'), oversiktDatum(-6), 'Byt däck');

    $svar = actingAs($agare)->get("/containers/{$container->ulid}")->assertOk();
    $props = oversiktProps($svar);

    expect(oversiktTitlar($props['tasks']))->toBe(['Byt impeller'])
        ->and($props['counts']['todos'])->toBe(1);

    expect($svar->getContent())->not->toContain('Byt däck');
});

// --- kostnadspanelen -------------------------------------------------------

/*
 * Klart när: `kostnadspanelen visar containerns fasta summering och nedbrytning
 * per item`.
 *
 * Den fasta summeringen har ingen period ([[ADR-0038 Gränsen för Pro i
 * kostnaderna]]): en rad från i fjol räknas som en rad från i dag, och det är
 * skillnaden mot dashboardens månadssumma. Nedbrytningen är items och inte
 * items i underträd — `cost_entry` bär sitt `item_id`, ogrupperat
 * ([[ADR-0040 Underträdets summor]] § Beslut, [[ADR-0041 Itemets vy]]
 * § Rättelsen).
 */
it('visar containerns fasta summering och nedbrytning per item', function () {
    withoutVite();

    [$konto, $agare] = oversiktKonto();
    $container = oversiktParm($konto);

    $motorn = oversiktItem($container, 'Motorn');
    $riggen = oversiktItem($container, 'Riggen');

    oversiktKostnad($motorn, 1200);
    oversiktKostnad($riggen, 800);
    // Ingen period: raden från i fjol räknas också.
    oversiktKostnad($motorn, 500, 'SEK', '2020-01-05');

    $kostnader = oversiktProps(actingAs($agare)->get("/containers/{$container->ulid}")->assertOk())['costs'];

    expect($kostnader['totals'])->toBe([['currency' => 'SEK', 'amount' => 2500, 'count' => 3]]);

    // Bitarna är de items som BÄR rader, i namnordning (formatGroups).
    expect(array_column(array_column($kostnader['breakdown'], 'key'), 'name'))->toBe(['Motorn', 'Riggen']);

    // Och de summerar exakt till totalen — samma Builder, samma radmängd.
    $bitar = 0;

    foreach ($kostnader['breakdown'] as $bit) {
        foreach ($bit['totals'] as $total) {
            $bitar += $total['amount'];
        }
    }

    expect($bitar)->toBe($kostnader['totals'][0]['amount']);
});

/*
 * Klart när: `kostnadspanelen saknas när containern inte har några rader`.
 *
 * Panelen är tom propp och ingen rubrik: en rubrik över en tom ring är en yta
 * som påstår att det finns något att visa. Vyn avgör det ur `costs.totals`, så
 * provet fäster båda ändarna — svaret och regeln i filen.
 */
it('saknar kostnadspanelen när containern inte har några rader', function () {
    withoutVite();

    [$konto, $agare] = oversiktKonto();
    $container = oversiktParm($konto);
    oversiktItem($container, 'Motorn');

    $kostnader = oversiktProps(actingAs($agare)->get("/containers/{$container->ulid}")->assertOk())['costs'];

    expect($kostnader)->toBe(['totals' => [], 'breakdown' => []]);

    $vy = oversiktUtanKommentarer(File::get(resource_path('js/pages/Containers/Overview.vue')));

    expect($vy)->toContain('v-if="props.costs.totals.length"')
        // Ringens bildtext är anroparens, för mängderna är olika: containern
        // visar hela containern och dashboarden en månad.
        ->toContain(':label="t(\'container.overview.costs_total\')"');
});

// --- itempanelen -----------------------------------------------------------

/*
 * Klart när: `itempanelen visar högst sex items användaren når`.
 *
 * Panelen och itembrickan kommer ur SAMMA anrop — `ListItems::handle()` — och
 * skiljer sig bara i klippningen. Provet jämför därför de två talen: brickan
 * är listans längd, panelen är dess sex första, och det sjunde itemets namn
 * finns inte i svaret.
 */
it('visar högst sex items användaren når', function () {
    withoutVite();

    expect(ContainerController::ITEM_LIMIT)->toBe(6);

    [$konto, $agare] = oversiktKonto();
    $container = oversiktParm($konto);

    // Namnen sorteras stigande av ListItems, så det sjunde är känt på förhand.
    foreach (range(1, 6) as $i) {
        oversiktItem($container, "Item {$i}");
    }

    $sjunde = oversiktItem($container, 'Item 7');

    $svar = actingAs($agare)->get("/containers/{$container->ulid}")->assertOk();
    $props = oversiktProps($svar);

    expect($props['counts']['items'])->toBe(7)
        ->and($props['items'])->toHaveCount(6)
        ->and(array_column($props['items'], 'name'))->toBe(['Item 1', 'Item 2', 'Item 3', 'Item 4', 'Item 5', 'Item 6']);

    expect($svar->getContent())->not->toContain($sjunde->ulid);
});

// --- aktivitetspanelen -----------------------------------------------------

/*
 * Klart när: `aktivitetspanelen följer läsregeln och visar högst fem
 * händelser`.
 *
 * Läsregeln bor i App\Actions\Audit\ListAuditEvents och prövas här GENOM ytan
 * ([[ADR-0043 Tre loggar]] § Händelseloggen): ägarkontots medlem ser allt i sin
 * container, vem som än handlade, och gästen ser bara sina egna rader.
 *
 * Gränsen är `ContainerController::ACTIVITY_LIMIT` och går in i anropet till
 * Actionen — kontrollern filtrerar ingenting själv. En rad i en annan container
 * hör inte hit, även om användaren når den.
 */
it('följer läsregeln och visar högst fem händelser', function () {
    withoutVite();

    expect(ContainerController::ACTIVITY_LIMIT)->toBe(5);

    [$konto, $agare] = oversiktKonto();
    $container = oversiktParm($konto);
    $motorn = oversiktItem($container, 'Motorn');
    $gast = oversiktGast($container, $motorn);

    // Sju rader i containern, i stigande ålder: den äldsta faller utanför.
    $rader = [];

    foreach (range(1, 7) as $i) {
        $rader[] = oversiktHandelse($container, $konto, $agare, now()->subDays(8 - $i));
    }

    // En rad i en annan container, som användaren når men inte står i.
    $annan = oversiktParm($konto, 'Trailern');
    $utanfor = oversiktHandelse($annan, $konto, $agare, now());

    $agarens = oversiktProps(actingAs($agare)->get("/containers/{$container->ulid}")->assertOk());

    // De fem NYASTE, nyast först.
    $forvantade = array_reverse(array_slice($rader, 2));

    expect(array_column($agarens['events'], 'ulid'))
        ->toBe(array_map(fn (AuditLog $rad): string => $rad->ulid, $forvantade));

    // Raden i den andra containern syns inte i den här containerns panel.
    expect($agarens['events'])->not->toContain($utanfor->ulid);

    // Gästen ser bara sina egna rader — led 2 i läsregeln.
    $gastensRad = oversiktHandelse($container, $konto, $gast, now(), $motorn);

    $gastens = oversiktProps(actingAs($gast)->get("/containers/{$container->ulid}")->assertOk());

    expect(array_column($gastens['events'], 'ulid'))->toBe([$gastensRad->ulid]);
});

// --- containerdetaljerna ---------------------------------------------------

/*
 * Klart när: `containerdetaljerna visar art, valuta, ägarkonto och skapad`.
 *
 * Valutan är containerns EGEN eller ÄRVDA ([[ADR-0037 Valutans arv]]), och
 * svaret bär den upplösta — `effectiveCurrency()` — så vyn slipper känna till
 * arvsregeln. Arten är ett FRITT fält och går orörd genom svaret
 * ([[ADR-0036 Containerns art]]).
 */
it('visar containerdetaljerna', function () {
    withoutVite();

    [$konto, $agare] = oversiktKonto();
    $container = oversiktParm($konto);

    // Ärver kontots valuta: containerns egen är null.
    $arva = oversiktProps(actingAs($agare)->get("/containers/{$container->ulid}")->assertOk())['details'];

    expect($arva)->toBe([
        'kind' => $container->kind,
        'currency' => 'SEK',
        'account' => $konto->name,
        'created_at' => $container->created_at->toIso8601String(),
    ]);

    // Egen valuta och egen art vinner.
    $container->update(['kind' => 'Segelbåt', 'currency' => 'NOK']);

    $egen = oversiktProps(actingAs($agare)->get("/containers/{$container->ulid}")->assertOk())['details'];

    expect($egen['kind'])->toBe('Segelbåt')
        ->and($egen['currency'])->toBe('NOK');

    // Etiketterna finns och läses ur `lang/`; vyn formaterar inget datum själv
    // ([[ADR-0042 Designsystemet]] § Konsekvenser, datumregeln i issue 104).
    $panel = oversiktUtanKommentarer(File::get(resource_path('js/components/ContainerDetailsPanel.vue')));

    foreach (['container.overview.details', 'container.overview.currency', 'container.overview.account', 'container.overview.created'] as $nyckel) {
        expect($panel)->toContain($nyckel);
    }

    expect($panel)->toContain('useRelativeDate')
        ->toContain('{{ props.details.kind }}');

    // Ingen egen datumformatering i panelen — datumregeln äger formen
    // (issue 104).
    expect($panel)->not->toContain('toLocaleDateString');
});

// --- counts ----------------------------------------------------------------

/*
 * Klart när: `counts är oförändrade`.
 *
 * Talen är desamma som före issue 172 — kontrollern räknar dem ur samma anrop
 * som panelerna, men panelernas tak är inte brickornas: sju uppgifter ger
 * `tasks` fem rader och `counts.todos` sju. Nycklarna är exakt de två issue 89
 * lade dit, i samma ordning.
 */
it('räknar samma tal som före panelerna', function () {
    withoutVite();

    [$konto, $agare] = oversiktKonto();
    $container = oversiktParm($konto);
    $motorn = oversiktItem($container, 'Motorn');

    // Förfallodagarna ligger i det förflutna: `counts.todos` räknar det som är
    // aktuellt nu (issue 697), och talet ska vara sju.
    foreach (range(1, 7) as $i) {
        oversiktUppgift($motorn, oversiktDatum(-10 * $i), "Uppgift {$i}");
        oversiktItem($container, "Item {$i}");
    }

    $props = oversiktProps(actingAs($agare)->get("/containers/{$container->ulid}")->assertOk());

    expect(array_keys($props['counts']))->toBe(['items', 'todos'])
        // Åtta items: motorn plus sju. Sex av dem ritas i panelen.
        ->and($props['counts']['items'])->toBe(8)
        ->and($props['items'])->toHaveCount(6)
        // Sju uppgifter: fem ritas i panelen, och brickan räknar alla sju.
        ->and($props['counts']['todos'])->toBe(7)
        ->and($props['tasks'])->toHaveCount(5);
});

// --- omfånget --------------------------------------------------------------

/*
 * Klart när: `en gäst med itemgrant räknar bara sitt items kostnader, items
 * och uppgifter`.
 *
 * Det är den enda regeln alla panelerna lyder under ([[ADR-0039 Containerns
 * översikt]] § Beslut): varje tal räknar det användaren SJÄLV når. En gäst med
 * en grant på motorn ser motorn — och varken hemmens item, dess uppgift eller
 * dess kostnad, inte ens som ett tal eller ett ULID i svarets råa innehåll.
 */
it('räknar bara gästens item i kostnader, items och uppgifter', function () {
    withoutVite();

    [$konto] = oversiktKonto();
    $container = oversiktParm($konto);

    $motorn = oversiktItem($container, 'Motorn');
    $hemlig = oversiktItem($container, 'Hemlig motor');

    // Förfallodagarna ligger i det förflutna: `counts.todos` räknar det som är
    // aktuellt nu (issue 697), och det är omfånget — inte datumet — provet
    // gäller.
    oversiktUppgift($motorn, oversiktDatum(-10), 'Min uppgift');
    oversiktUppgift($hemlig, oversiktDatum(-11), 'Hemlig uppgift');

    oversiktKostnad($motorn, 1000);
    oversiktKostnad($hemlig, 999999);

    $gast = oversiktGast($container, $motorn);

    $svar = actingAs($gast)->get("/containers/{$container->ulid}")->assertOk();
    $props = oversiktProps($svar);

    expect($props['counts']['items'])->toBe(1)
        ->and($props['counts']['todos'])->toBe(1)
        ->and($props['items'])->toHaveCount(1)
        ->and($props['items'][0]['name'])->toBe('Motorn')
        ->and(oversiktTitlar($props['tasks']))->toBe(['Min uppgift'])
        ->and($props['costs']['totals'])->toBe([['currency' => 'SEK', 'amount' => 1000, 'count' => 1]]);

    // Ingenting av det hon inte når finns i svaret — varken namnet, beloppet
    // eller ULID:n.
    foreach (['Hemlig motor', 'Hemlig uppgift', '999999', $hemlig->ulid] as $dold) {
        expect($svar->getContent())->not->toContain($dold);
    }
});

// --- panelerna i vyn -------------------------------------------------------

/*
 * Klart när: `översikten visar de sex panelerna` (milstolpens ord; *Senaste
 * bilder* är issue 173 och ritas inte här).
 *
 * Provet är ett källkodsprov, som rutnätsprovet i DashboardTest: komponenten
 * ritas i webbläsaren och går inte att rendera här, men formen går att läsa ur
 * filen. Kommentarerna rensas bort först — annars kunde en mening om
 * `lg:grid-cols-3` nöja ett prov som letar efter klassen.
 *
 * Panelen är sin egen komponent eller sin egen rad, och rutnätet är SIDANS:
 * placeringen sker med klasser på ramen runt panelen, så panelernas egna
 * anrop står oförändrade och mobilens ordning är källans.
 */
it('monterar panelerna i rutnätet över lg', function () {
    $vy = oversiktUtanKommentarer(File::get(resource_path('js/pages/Containers/Overview.vue')));

    // Rutnätet är sidans eget: tre kolumner över `lg:`, och ingenting under.
    expect($vy)->toContain('class="lg:grid lg:grid-cols-3 lg:items-start lg:gap-x-8"');

    expect($vy)->toContain('import ContainerTasksPanel from')
        ->toContain('<ContainerTasksPanel :tasks="props.tasks" />')
        ->toContain('import ContainerDetailsPanel from')
        ->toContain('<ContainerDetailsPanel :details="props.details" />')
        ->toContain('import CostDonut from')
        ->toContain('<CostDonut')
        ->toContain('import HistoryRow from')
        ->toContain('v-for="event in props.events"')
        ->toContain('v-for="item in props.items"');

    // Informationsytan står kvar — samma komponent och samma propp som förut.
    expect($vy)->toContain('<InfoPanel :tips="props.tips"');

    // Uppgiftspanelen ritar samma rad som `/tasks` och länkar dit till dess
    // att containerns uppgiftsflik finns (issue 174).
    $uppgifter = oversiktUtanKommentarer(File::get(resource_path('js/components/ContainerTasksPanel.vue')));

    expect($uppgifter)->toContain("import TodoRow from './TodoRow.vue'")
        ->toContain('<TodoRow v-for="task in props.tasks"')
        ->toContain('href="/tasks"');

    // Panelen formulerar ingen fråga och sorterar ingenting själv.
    expect($uppgifter)->not->toContain('sort(');
    expect($uppgifter)->not->toContain('slice(');

    // Itempanelen leder vidare till itemlistan, och aktivitetspanelen ber inte
    // om containerraden: raderna står inuti sin container här (issue 126).
    expect($vy)->toContain('itemListUrl');
    expect($vy)->not->toContain('show-container');
});
