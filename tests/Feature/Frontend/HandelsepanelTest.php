<?php

use App\Http\Controllers\DashboardController;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Issue 126 · Händelsepanelen på dashboarden. Se
 * app/Http/Controllers/DashboardController, app/Actions/Audit/ListAuditEvents,
 * app/Actions/Audit/PresentAuditEvents,
 * resources/js/components/DashboardActivityPanel.vue,
 * resources/js/components/HistoryRow.vue och [[ADR-0043 Tre loggar]]
 * § Händelseloggen och § Konsekvenser.
 *
 * **Panelen är händelseloggen och ingenting annat.** Den visar de fem senaste
 * raderna som användaren får läsa, över ALLA hennes konton — läsregeln är
 * ListAuditEvents och panelen filtrerar inte själv. Det är därför filen prövar
 * läsregeln GENOM ytan: en gäst ser sina egna rader, ägarkontots medlem ser
 * allas i sina containrar, och den som förlorat åtkomsten ser ingenting. Att
 * regeln själv är riktig prövas rad för rad i
 * tests/Feature/Revision/LasregelTest.php (issue 108); här bevisas att
 * dashboarden läser genom den och inte runt den.
 *
 * **Raderna skrivs med fabriken och inte genom RecordAuditEvent**, samma grepp
 * och samma skäl som i HistorikflikTest: proven handlar om LÄSNINGEN, och
 * ordningen "nyast först" går inte att bevisa utan ett `created_at` vi väljer.
 *
 * **Det som INTE prövas här** är det som kräver en webbläsare: att raden ser
 * ut som panelen i docs/Design/main.jpeg och att datumet skrivs i användarens
 * tidszon. Datumregeln prövas i DatumregelTest, radens form i YtornaTest, och
 * handprovet står i PR-kroppen.
 *
 * Hjälparna har prefixet `handelse` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs, och grannfilerna har `historik`, `panelvy`,
 * `bricka` och `kostnad`.
 */

/**
 * En loggrad skriven DIREKT i loggen, med en tid vi väljer.
 *
 * @param  array<string, mixed>  $meta
 */
function handelseRad(
    Container $container,
    Account $account,
    ?User $handlande,
    string $action,
    ?Carbon $när = null,
    ?Item $item = null,
    array $meta = [],
): AuditLog {
    return AuditLog::factory()->create([
        'container_id' => $container->id,
        'account_id' => $account->id,
        'user_id' => $handlande?->id,
        'item_id' => $item?->id,
        'action' => $action,
        'meta' => $meta,
        'created_at' => $när ?? now(),
    ]);
}

/**
 * En gäst med en grant på ETT item — den smala granten är vad som gör
 * omfånget begränsat, och det är skillnaden mot historikGast() i
 * HistorikflikTest: en itembred rad är läsbar även när radens container är
 * borta (issue 126).
 */
function handelseItemgast(Container $container, Item $item): User
{
    $gäst = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $gäst->id,
        'level' => 'write',
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $gäst;
}

/**
 * Panelens rader ur svaret.
 *
 * @return list<array<string, mixed>>
 */
function handelseRader(TestResponse $svar): array
{
    /** @var list<array<string, mixed>> $rader */
    $rader = $svar->inertiaProps()['events'];

    return $rader;
}

/**
 * Källkoden med kommentarer borta. Samma tre slag som historikKod() rensar,
 * och av samma skäl: docblocken är svenska med flit, och en regel som letar
 * efter en markup eller en nyckel ska inte kunna nöjas av en mening i ett
 * docblock — panelens egen docblock nämner både `show-container` och
 * nycklarna.
 */
function handelseKod(string $sokvag): string
{
    $kod = File::get(resource_path($sokvag));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Antalet frågor $anrop ställer, mätt i en KALL request — samma mätning som
 * panelvyFrågor() i DashboardTest och av samma skäl: det första anropet värmer
 * guarderna, kontocachen, texten och `last_active_at`, och `ResolveItemScope`
 * memoiserar per `{user, container}` i en `scoped`-bindning som överlever
 * mellan HTTP-anropen i testsviten. Utan rensningen hade jämförelsen varit
 * konstant vad kontrollern än gjorde.
 */
function handelseFrågor(Closure $anrop): int
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

// --- urvalet ---------------------------------------------------------------

/*
 * Klart när: panelen visar högst fem rader, nyast först.
 *
 * Sju rader i loggen och fem i panelen: gränsen är DashboardController::
 * ACTIVITY_LIMIT och går in i anropet till ListAuditEvents. Ordningen är
 * Actionens — `created_at` fallande med `id` fallande som sekundär — och
 * provet jämför ULID för ULID: ett prov som bara räknade raderna hade godtagit
 * vilken ordning som helst, och "nyast först" är vad en aktivitetslista är
 * till för.
 */
it('visar högst fem rader, nyast först', function () {
    withoutVite();

    expect(DashboardController::ACTIVITY_LIMIT)->toBe(5);

    [$konto, $ägare] = kontoMedMedlem();
    $pärm = Container::factory()->for($konto, 'account')->create();

    $rader = [];

    foreach (range(1, 7) as $i) {
        $rader[] = handelseRad(
            $pärm,
            $konto,
            $ägare,
            AuditLog::ACTION_CONTAINER_UPDATED,
            när: now()->subDays(8 - $i),
        );
    }

    $visade = handelseRader(actingAs($ägare)->get('/dashboard')->assertOk());

    // De fem NYASTE, i omvänd kronologisk ordning: rad 3–7 av sju.
    $förväntade = array_reverse(array_slice($rader, 2));

    expect(array_column($visade, 'ulid'))
        ->toBe(array_map(fn (AuditLog $rad): string => $rad->ulid, $förväntade));
});

/*
 * Klart när: ägarkontots medlem ser andra användares rader i sina containrar.
 *
 * Led 1 i läsregeln: den som är medlem i kontot som ÄGER containern ser allt i
 * den, vem som än handlade. Gästen här har skrivit sin rad genom en grant —
 * medlemmen ska se den, och den bär gästens namn och inte medlemmens.
 */
it('visar andra användares rader för ägarkontots medlem', function () {
    withoutVite();

    [$konto, $ägare] = kontoMedMedlem();
    $pärm = Container::factory()->for($konto, 'account')->create();

    $motorn = Item::factory()->for($pärm, 'container')->create();
    $gäst = handelseItemgast($pärm, $motorn);

    $ägarensRad = handelseRad($pärm, $konto, $ägare, AuditLog::ACTION_CONTAINER_UPDATED, när: now()->subDay());
    $gästensRad = handelseRad($pärm, $konto, $gäst, AuditLog::ACTION_ITEM_CREATED, när: now(), item: $motorn);

    $visade = handelseRader(actingAs($ägare)->get('/dashboard')->assertOk());

    expect(array_column($visade, 'ulid'))->toBe([$gästensRad->ulid, $ägarensRad->ulid])
        ->and($visade[0]['user'])->toBe($gäst->name);
});

/*
 * Klart när: en gäst ser bara sina egna rader.
 *
 * Led 2: hennes EGEN rad, inom det omfång hon når. Ägarens rad i samma
 * container är osynlig för henne — samma svar, samma sida, ingen egen
 * filtrering i vyn eller i kontrollern.
 */
it('visar bara gästens egna rader för en gäst', function () {
    withoutVite();

    [$konto, $ägare] = kontoMedMedlem();
    $pärm = Container::factory()->for($konto, 'account')->create();

    $motorn = Item::factory()->for($pärm, 'container')->create();
    $gäst = handelseItemgast($pärm, $motorn);

    $ägarensRad = handelseRad($pärm, $konto, $ägare, AuditLog::ACTION_CONTAINER_CREATED, när: now()->subDay());
    $gästensRad = handelseRad($pärm, $konto, $gäst, AuditLog::ACTION_ITEM_CREATED, när: now(), item: $motorn);

    $svar = actingAs($gäst)->get('/dashboard')->assertOk();

    expect(array_column(handelseRader($svar), 'ulid'))->toBe([$gästensRad->ulid]);

    // Ägarens rad syns inte någonstans i svaret — inte ens som ULID.
    expect($svar->getContent())->not->toContain($ägarensRad->ulid);
});

/*
 * Klart när: en användare vars åtkomst återkallats ser inga rader från den
 * containern.
 *
 * Led 2 faller när omfånget är tomt: loggen får inte bli en väg tillbaka in i
 * något som stängts ([[ADR-0043 Tre loggar]] § Motivering). Provet visar
 * båda lägena — hon ser sin rad medan granten gäller, och ingen rad alls
 * efteråt. Det andra läget är det som betyder något: ett filter som bara
 * fungerade på andras rader hade sett rätt ut i den första mätningen.
 *
 * `revoked_at` sätts genom direkt egenskapstilldelning och inte `update()`:
 * kolumnen är medvetet inte `#[Fillable]` på ContainerAccess (samma grepp som
 * LasregelTest).
 */
it('visar inga rader från en container vars åtkomst återkallats', function () {
    withoutVite();

    [$ägarkonto] = kontoMedMedlem();
    $pärm = Container::factory()->for($ägarkonto, 'account')->create();

    $mottagare = User::factory()->create();
    $access = ContainerAccess::factory()->create([
        'container_id' => $pärm->id,
        'item_id' => null,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => 'write',
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    handelseRad($pärm, $ägarkonto, $mottagare, AuditLog::ACTION_ITEM_CREATED, när: now());

    actingAs($mottagare)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('events', 1));

    $access->revoked_at = now();
    $access->save();

    actingAs($mottagare)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('events', 0));
});

/*
 * Klart när: rader från två av användarens konton syns i samma panel.
 *
 * Panelen är händelseloggen över ALLA användarens konton
 * ([[ADR-0043 Tre loggar]] § Konsekvenser) — till skillnad från `/tasks`, som
 * är en lista över uppgifter i de containrar hon når. Provet ger henne två
 * konton med var sin container och bevisar att båda raderna kommer med, i en
 * ordning som är loggens och inte kontots.
 */
it('visar rader från två av användarens konton i samma panel', function () {
    withoutVite();

    $första = Account::factory()->create();
    $andra = Account::factory()->create();

    $användare = User::factory()->create();
    $första->users()->attach($användare, ['role' => 'owner']);
    $andra->users()->attach($användare, ['role' => 'member']);

    $pärmEtt = Container::factory()->for($första, 'account')->create();
    $pärmTvå = Container::factory()->for($andra, 'account')->create();

    $radEtt = handelseRad($pärmEtt, $första, $användare, AuditLog::ACTION_CONTAINER_CREATED, när: now()->subDay());
    $radTvå = handelseRad($pärmTvå, $andra, $användare, AuditLog::ACTION_CONTAINER_CREATED, när: now());

    $visade = handelseRader(actingAs($användare)->get('/dashboard')->assertOk());

    expect(array_column($visade, 'ulid'))->toBe([$radTvå->ulid, $radEtt->ulid]);
});

/*
 * Klart när: panelen finns på dashboarden.
 *
 * Sidan är monteringspunkten och äger ingenting själv: den skickar vidare vad
 * servern gav den. Panelen är sin egen komponent med sin egen propp — samma
 * form som gör att M19:s paneler kan byggas parallellt (issue 122), och en
 * panel som byggdes men aldrig monterades hade varit en osynlig yta.
 */
it('monterar panelen i Dashboard.vue med sin egen propp', function () {
    expect(File::get(resource_path('js/pages/Dashboard.vue')))
        ->toContain("import DashboardActivityPanel from '../components/DashboardActivityPanel.vue'")
        ->toContain('<DashboardActivityPanel :events="props.events" />');
});

// --- containerraden --------------------------------------------------------

/*
 * Klart när: varje rad säger vilken container den gäller.
 *
 * Raden står utanför sin container här, till skillnad från i historikflikarna,
 * och containernamnet slås upp i EN fråga för hela listan — samma uppslag som
 * itemnamnen (App\Actions\Audit\PresentAuditEvents).
 *
 * Provet fäster båda ändarna: namnet kommer fram oförändrat i svaret, och
 * panelen ber HistoryRow rita containerraden. Historikflikarnas rad ritar den
 * inte, och det prövas i HistorikflikTest.
 */
it('låter varje rad säga vilken container den gäller', function () {
    withoutVite();

    [$konto, $ägare] = kontoMedMedlem();
    $pärm = Container::factory()->for($konto, 'account')->create(['name' => 'Havsörnen']);

    handelseRad($pärm, $konto, $ägare, AuditLog::ACTION_CONTAINER_CREATED);

    $visade = handelseRader(actingAs($ägare)->get('/dashboard')->assertOk());

    expect($visade[0]['container'])->toBe(['name' => 'Havsörnen']);

    $panel = handelseKod('js/components/DashboardActivityPanel.vue');

    expect($panel)->toContain('show-container')
        ->toContain("import HistoryRow from './HistoryRow.vue'")
        ->toContain('<HistoryRow');

    // Panelen är hela ytan: ingen *Visa alla*, för sidan med alla händelser
    // finns inte.
    expect($panel)->not->toContain('view_all');
    expect($panel)->not->toContain('<Link');
});

/*
 * Klart när: en rad om en gallrad container visas med ersättaren.
 *
 * Loggen överlever det den handlar om: containern kan gallras medan raden står
 * kvar, och namnet slås upp när raden LÄSES. Uppslaget följer SoftDeletes'
 * globala scope, så en container i papperskorgen är raderad för läsaren — *
 * a deleted container*, som *a deleted item* och *a former user*.
 *
 * Raden är läsbar genom ITEMET och inte genom containern: en itembred grant
 * ger ett begränsat omfång, och led 2 följer itemet även när radens
 * `container_id` pekar på något som inte finns (ett item som flyttats mellan
 * containrar bär gamla rader med den gamlas id). Utan det ledet hade provet
 * inte gått att skriva — en rad vars container är borta är osynlig för den som
 * bara når containern.
 *
 * Ett tomt fält är felet provet letar efter, precis som i HistorikflikTest:
 * `null` i svaret OCH en mening i katalogen, för det är tillsammans de ger en
 * rad som går att läsa.
 */
it('visar ersättaren för en rad om en gallrad container', function () {
    withoutVite();

    [$konto] = kontoMedMedlem();
    $pärm = Container::factory()->for($konto, 'account')->create();
    $motorn = Item::factory()->for($pärm, 'container')->create(['name' => 'Motorn']);

    // Containern raden pekar på har gallrats. Raden skrivs direkt i loggen med
    // den gamlas löpnummer — samma läge som en rad vars container hunnit
    // försvinna, och det enda sättet att ställa upp det.
    $borta = Container::factory()->for(Account::factory(), 'account')->create();
    $bortaLöpnummer = $borta->id;
    $borta->forceDelete();

    $gäst = handelseItemgast($pärm, $motorn);

    $rad = AuditLog::factory()->create([
        'container_id' => $bortaLöpnummer,
        'account_id' => $konto->id,
        'user_id' => $gäst->id,
        'item_id' => $motorn->id,
        'action' => AuditLog::ACTION_ITEM_CREATED,
        'created_at' => now(),
    ]);

    $visade = handelseRader(actingAs($gäst)->get('/dashboard')->assertOk());

    expect($visade)->toHaveCount(1)
        ->and($visade[0]['ulid'])->toBe($rad->ulid)
        ->and($visade[0]['container'])->toBe(['name' => null])
        // Itemet finns kvar och namnges: ersättaren gäller containern och
        // ingenting annat.
        ->and($visade[0]['item'])->toBe('Motorn');

    $kod = handelseKod('js/components/HistoryRow.vue');

    expect($kod)->toContain("?? t('audit.fallback.container')");

    expect(trans('ui.audit.fallback.container', [], 'en'))->toBe('a deleted container');
});

/*
 * Klart när: varje rad säger vilken container den gäller — och en rad som inte
 * gäller någon säger ingenting.
 *
 * Kontoraderna (`account.deleted`) hör till kontot och inte till en container
 * (led 3). De är läsbara på dashboarden, och de får INTE bära ersättaren: en
 * rad om ett konto har ingen container att namnge, och *a deleted container*
 * under den hade varit en osann rad om något som aldrig funnits.
 *
 * Det är därför `container` är `null` för en sådan rad och `{name: null}` för
 * en rad vars container gallrats — de två lägena är olika, och panelen ritar
 * bara den ena.
 */
it('ger ingen containerrad åt en rad utan container', function () {
    withoutVite();

    [$konto, $ägare] = kontoMedMedlem();

    $rad = AuditLog::factory()->create([
        'account_id' => $konto->id,
        'user_id' => null,
        'container_id' => null,
        'item_id' => null,
        'action' => AuditLog::ACTION_ACCOUNT_DELETED,
        'created_at' => now(),
    ]);

    $visade = handelseRader(actingAs($ägare)->get('/dashboard')->assertOk());

    expect($visade)->toHaveCount(1)
        ->and($visade[0]['ulid'])->toBe($rad->ulid)
        ->and($visade[0]['container'])->toBeNull();
});

// --- strängarna ------------------------------------------------------------

/*
 * Klart när: strängarna ligger i `lang/en/ui.php`.
 *
 * Panelens rubrik och tomma läge är hennes egna; containerns ersättare delas
 * med historikflikarna och bor därför under `audit.fallback.*`. Nycklarna
 * prövas mot katalogen och inte mot en avskrift av texten — SprakTest läser
 * dessutom nycklarna ur panelens källkod, så en mening som glöms i katalogen
 * faller där.
 */
it('hämtar panelens strängar ur ui.php', function () {
    expect(trans('ui.dashboard.activity.heading', [], 'en'))->toBe('Recent activity');

    foreach (['ui.dashboard.activity.empty', 'ui.audit.fallback.container'] as $nyckel) {
        expect(trans($nyckel, [], 'en'))->not->toBe($nyckel, "{$nyckel} saknas");
    }

    $panel = handelseKod('js/components/DashboardActivityPanel.vue');

    expect($panel)->toContain("t('dashboard.activity.heading')")
        ->toContain("t('dashboard.activity.empty')");
});

// --- kostnaden -------------------------------------------------------------

/*
 * Klart när: frågekostnaden är konstant oberoende av antalet rader och
 * containrar.
 *
 * Namnuppslagen är ETT anrop över hela listan och inte ett per rad: en fråga
 * per rad hade varit den N+1 [[ADR-0043 Tre loggar]] § Händelseloggen varnar
 * för. Provet lägger nio containrar och nio rader till — panelen visar
 * fortfarande fem, och frågan ska vara den samma.
 *
 * Tiden fryst runt mätningarna: UpdateLastActiveAt skriver
 * `user.last_active_at` vid varje autentiserat anrop, och faller en
 * sekundgräns mellan det värmande anropet och mätningen blir det en UPDATE
 * extra (issue 477, se tests/Feature/Testinfrastruktur/FragerakningTest.php).
 */
it('kostar ett konstant antal frågor oberoende av antalet rader och containrar', function () {
    withoutVite();

    [$konto, $ägare] = kontoMedMedlem();
    $första = Container::factory()->for($konto, 'account')->create();

    handelseRad($första, $konto, $ägare, AuditLog::ACTION_CONTAINER_CREATED);

    actingAs($ägare);

    Carbon::setTestNow(now());

    $medEn = handelseFrågor(function () {
        get('/dashboard')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('events', 1));
    });

    foreach (range(2, 10) as $i) {
        $pärm = Container::factory()->for($konto, 'account')->create();

        handelseRad($pärm, $konto, $ägare, AuditLog::ACTION_CONTAINER_CREATED, när: now()->addMinutes($i));
    }

    $medTio = handelseFrågor(function () {
        get('/dashboard')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('events', 5));
    });

    expect($medTio)->toBe($medEn);

    Carbon::setTestNow();
});
