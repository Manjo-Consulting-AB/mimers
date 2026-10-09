<?php

use App\Actions\Audit\ListAuditEvents;
use App\Actions\Inbox\ResolveInbox;
use App\Http\Controllers\DashboardController;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Issue 271 · Den globala historiken, servern. Se App\Http\Controllers\
 * HistoryController, App\Http\Requests\Audit\HistoryFilterRequest,
 * App\Actions\Audit\ListAuditEvents, App\Actions\Audit\PresentAuditEvents och
 * [[ADR-0043 Tre loggar]] § Händelseloggen.
 *
 * **Läsregeln är oförändrad och prövas genom ytan.** Varje prov går via
 * `GET /history` och jämför de RADER som kommer ut: ett svar som ser rätt ut
 * men bär en rad för mycket är precis felet, och det syns bara om svaret
 * jämförs rad för rad. Läsregeln själv prövas i LasregelTest och rörs inte av
 * den här issuen — det som är nytt är startpunkten (alla containrar, plus
 * kontohändelserna utan container) och containervillkoret i filtret.
 *
 * **Det viktigaste provet är att en container användaren inte når ger en TOM
 * lista** och inte 403: `/history` har ingen grind (Beslut 1), och ett filter
 * får aldrig vidga läsregeln (Beslut 3). Ett filter på en container hon inte
 * når svarar därför som ett filter på en container som inte finns.
 *
 * **Containervillkoret är nytt och prövas i båda ändar**: `account` ger bara
 * raderna utan container (led 3), ett ULID ger bara den containerns rader.
 *
 * Hjälparna har prefixet `globalhistorik` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem som äger en container.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function globalhistorikKontext(?string $tidszon = null): array
{
    $konto = Account::factory()->create();
    $ägare = User::factory()->create(['name' => 'Anna', 'timezone' => $tidszon]);
    $konto->users()->attach($ägare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create(['name' => 'Pärmen']);

    return [$konto, $ägare, $container];
}

/**
 * En loggrad med en tid vi väljer, i en container eller — med `null` — utan
 * (kontoraden, led 3).
 */
function globalhistorikRad(
    ?Container $container,
    Account $account,
    ?User $handlande,
    string $action = AuditLog::ACTION_ITEM_UPDATED,
    ?Item $item = null,
    ?string $subjectType = null,
    ?Carbon $när = null,
): AuditLog {
    return AuditLog::factory()->create([
        'container_id' => $container?->id,
        'account_id' => $account->id,
        'user_id' => $handlande?->id,
        'item_id' => $item?->id,
        'action' => $action,
        'subject_type' => $subjectType,
        'created_at' => $när ?? now(),
    ]);
}

/**
 * En containerbred åtkomst på `read` för en gäst — den läsare historiken
 * finns för: hon ser sina egna rader och inget annat, samma utfall som på
 * containerns flik.
 */
function globalhistorikGast(Container $container, string $namn): User
{
    $gäst = User::factory()->create(['name' => $namn]);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => null,
        'grantee_type' => 'user',
        'grantee_id' => $gäst->id,
        'level' => 'read',
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $gäst;
}

/**
 * En gäst med åtkomst bara på ETT item — den begränsade sidan av omfånget
 * ([[ADR-0028 Åtkomst på itemnivå]]).
 */
function globalhistorikItemGast(Container $container, Item $item, string $namn): User
{
    $gäst = User::factory()->create(['name' => $namn]);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $gäst->id,
        'level' => 'read',
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $gäst;
}

/**
 * Personens inbox-container, skapad om den saknas.
 */
function globalhistorikInbox(User $person): Container
{
    app(ResolveInbox::class)->handle($person);

    return Container::query()->where('inbox_user_id', $person->id)->firstOrFail();
}

/**
 * `GET /history` som URL, med filtret i querysträngen.
 *
 * @param  array<string, string>  $filter
 */
function globalhistorikUrl(array $filter = []): string
{
    return $filter === [] ? '/history' : '/history?'.http_build_query($filter);
}

/**
 * Sidans proppar, ur Inertias rotvy.
 *
 * @param  array<string, string>  $filter
 * @return array<string, mixed>
 */
function globalhistorikProps(User $anvandare, array $filter = []): array
{
    /** @var array{props: array<string, mixed>} $sida */
    $sida = actingAs($anvandare)->get(globalhistorikUrl($filter))->assertOk()->viewData('page');

    return $sida['props'];
}

/**
 * Svarets proppar för ett svar som redan hämtats — samma vikning som
 * `globalhistorikProps()`, för proven som själva vill pröva statuskoden.
 *
 * @return array<string, mixed>
 */
function globalhistorikSvar(TestResponse $svar): array
{
    /** @var array{props: array<string, mixed>} $sida */
    $sida = $svar->viewData('page');

    return $sida['props'];
}

/**
 * Dagarnas rader, i den ordning grupperna och raderna står — så att ett prov
 * kan jämföra den platta listan utan att bry sig om var dagsgränsen hamnade.
 *
 * @param  list<array{date: string, rows: list<array<string, mixed>>}>  $dagar
 * @return list<array<string, mixed>>
 */
function globalhistorikRader(array $dagar): array
{
    $rader = [];

    foreach ($dagar as $dag) {
        foreach ($dag['rows'] as $rad) {
            $rader[] = $rad;
        }
    }

    return $rader;
}

/**
 * ULID:erna i en lista, i ordning — rader eller val.
 *
 * @param  list<array<string, mixed>>  $poster
 * @return list<string>
 */
function globalhistorikUlids(array $poster): array
{
    return array_map(static fn (array $post): string => (string) $post['ulid'], $poster);
}

/**
 * Summan av `perType` — antalet händelser diagrammen räknar.
 *
 * @param  list<array{type: string|null, count: int}>  $perType
 */
function globalhistorikTypsumma(array $perType): int
{
    return array_sum(array_map(static fn (array $typ): int => $typ['count'], $perType));
}

/*
 * Klart när: en gäst omdirigeras till inloggningen.
 *
 * Rutten bär `auth` och ingenting mer (Beslut 1) — ingen grind, för varje
 * inloggad användare har en historik. En utloggad har ingen, och möts av
 * inloggningen precis som på varje annan sida i skalet.
 */
it('en gäst omdirigeras till inloggningen', function () {
    get('/history')->assertRedirect('/login');
});

/*
 * Klart när: listan bär rader ur flera containrar användaren når.
 *
 * Ägaren når två containrar på samma konto, och raderna kommer från båda —
 * i `created_at` fallande ordning, som på fliken. Provet jämför den platta
 * listan rad för rad: en lista som tappade den ena containern hade sett rätt
 * ut med bara den andra kvar.
 */
it('listan bär rader ur flera containrar användaren når', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = globalhistorikKontext();
    $vinden = Container::factory()->for($konto, 'account')->create(['name' => 'Vinden']);

    $äldst = globalhistorikRad($pärm, $konto, $ägare, när: now()->subMinutes(3));
    $mellan = globalhistorikRad($vinden, $konto, $ägare, när: now()->subMinutes(2));
    $nyast = globalhistorikRad($vinden, $konto, $ägare, när: now()->subMinute());

    $rader = globalhistorikRader(globalhistorikProps($ägare)['days']);

    expect(globalhistorikUlids($rader))->toBe([$nyast->ulid, $mellan->ulid, $äldst->ulid]);
});

/*
 * Klart när: rader ur en container användaren inte når syns inte — varken i
 * `days`, `stats` eller `options.users`.
 *
 * Läsregeln är oförändrad (Beslut 3): en främmande containers rader är inte
 * läsbara, och den globala historiken är därför ingen väg runt den. Provet
 * fäster alla tre ytorna, för ett aggregat som räknade tabellen i stället för
 * läsregeln hade sett rätt ut i listan och fel i talen.
 */
it('rader ur en container användaren inte når syns inte', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = globalhistorikKontext();

    $främmandeKonto = Account::factory()->create();
    $främmande = User::factory()->create(['name' => 'Cissi']);
    $främmandeKonto->users()->attach($främmande, ['role' => 'owner']);
    $främmandePärm = Container::factory()->for($främmandeKonto, 'account')->create();

    $egen = globalhistorikRad($pärm, $konto, $ägare, subjectType: 'cost_entry');
    globalhistorikRad($främmandePärm, $främmandeKonto, $främmande, subjectType: 'cost_entry');
    globalhistorikRad($främmandePärm, $främmandeKonto, $främmande, subjectType: 'loan');

    $proppar = globalhistorikProps($ägare);

    expect(globalhistorikUlids(globalhistorikRader($proppar['days'])))->toBe([$egen->ulid])
        ->and(globalhistorikTypsumma($proppar['stats']['perType']))->toBe(1)
        ->and(globalhistorikUlids($proppar['options']['users']))->toBe([$ägare->ulid])
        ->and(globalhistorikUlids($proppar['options']['users']))->not->toContain($främmande->ulid);
});

/*
 * Klart när: en gäst med read ser sina egna rader men inte ägarens — samma
 * utfall som på containerns flik.
 *
 * Led 2 i läsregeln ([[ADR-0043 Tre loggar]] § Händelseloggen): hennes EGNA
 * rad inom det omfång hon når. Ägarens rad i samma container är inte hennes,
 * och den syns därför varken i listan eller bland användarvalen — en meny som
 * bjöd på ägaren hade avslöjat vem mer som skrivit.
 */
it('en gäst med read ser sina egna rader men inte ägarens', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = globalhistorikKontext();
    $gäst = globalhistorikGast($pärm, 'Bo');

    $ägarensRad = globalhistorikRad($pärm, $konto, $ägare, när: now()->subMinute());
    $gästensRad = globalhistorikRad($pärm, $konto, $gäst);

    $proppar = globalhistorikProps($gäst);

    expect(globalhistorikUlids(globalhistorikRader($proppar['days'])))->toBe([$gästensRad->ulid])
        ->and(globalhistorikUlids(globalhistorikRader($proppar['days'])))->not->toContain($ägarensRad->ulid)
        ->and(globalhistorikUlids($proppar['options']['users']))->toBe([$gäst->ulid]);
});

/*
 * Klart när: kontohändelser utan container ingår.
 *
 * Led 3 i läsregeln: raden saknar container och hör till ett av hennes konton
 * (`account.deleted` skrivs av jobbet på kontot). Den syns på /history, som
 * är den enda ytan över alla containrar, och `container` är `null` i den
 * presenterade raden — kontoraden gäller ingen container och ska inte låtsas
 * göra det (issue 126).
 */
it('kontohändelser utan container ingår', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = globalhistorikKontext();

    $kontoraden = globalhistorikRad(null, $konto, null, AuditLog::ACTION_ACCOUNT_DELETED, när: now()->subMinute());
    $pärmsrad = globalhistorikRad($pärm, $konto, $ägare);

    $rader = globalhistorikRader(globalhistorikProps($ägare)['days']);

    expect(globalhistorikUlids($rader))->toBe([$pärmsrad->ulid, $kontoraden->ulid])
        ->and($rader[1]['container'])->toBeNull();
});

/*
 * Klart när: containerfiltret account ger bara rader utan container.
 *
 * `account` är kontohändelserna, alltså `whereNull('container_id')` (Beslut
 * 3), och motsatsen prövas i samma prov: containerns rader faller bort.
 * Provet är symmetriskt av samma skäl som filterproven i 179 — ett filter som
 * bara SÅLLADE hade sett rätt ut när allt utom en rad försvann.
 */
it('containerfiltret account ger bara rader utan container', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = globalhistorikKontext();

    globalhistorikRad($pärm, $konto, $ägare, när: now()->subMinute());
    $kontoraden = globalhistorikRad(null, $konto, null, AuditLog::ACTION_ACCOUNT_DELETED);

    $proppar = globalhistorikProps($ägare, ['container' => 'account']);

    expect(globalhistorikUlids(globalhistorikRader($proppar['days'])))->toBe([$kontoraden->ulid])
        ->and($proppar['filter']['container'])->toBe('account');
});

/*
 * Klart när: containerfiltret med ett ulid ger bara den containerns rader.
 *
 * ULID:et slås upp till ett löpnummer och läggs som `container_id` (Beslut
 * 3). Provet lägger rader i två containrar på SAMMA konto, så att läsregeln
 * släpper igenom båda och det bara är filtret som skiljer dem — annars hade
 * provet bevisat läsregeln och inte filtret.
 */
it('containerfiltret med ett ulid ger bara den containerns rader', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = globalhistorikKontext();
    $vinden = Container::factory()->for($konto, 'account')->create(['name' => 'Vinden']);

    $pärmsrad = globalhistorikRad($pärm, $konto, $ägare, när: now()->subMinute());
    globalhistorikRad($vinden, $konto, $ägare);

    $proppar = globalhistorikProps($ägare, ['container' => $pärm->ulid]);

    expect(globalhistorikUlids(globalhistorikRader($proppar['days'])))->toBe([$pärmsrad->ulid])
        ->and($proppar['filter']['container'])->toBe($pärm->ulid);
});

/*
 * Klart när: en container användaren inte når ger en tom lista — 200 och tom
 * `days`, inte 403.
 *
 * `/history` har ingen grind (Beslut 1), och containervillkoret prövar ingen
 * existens och ingen åtkomst (Beslut 3): en främmande container ger samma
 * svar som en som inte finns — `container_id = 0` och inga rader. Ett 403
 * hade berättat att containern finns, och det är precis vad regeln för `user`
 * och `item` undviker.
 */
it('en container användaren inte når ger en tom lista', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = globalhistorikKontext();

    // En rad hon FÅR läsa, så att provet bevisar att filtret sållade och inte
    // att loggen var tom.
    globalhistorikRad($pärm, $konto, $ägare);

    $främmandeKonto = Account::factory()->create();
    $främmandePärm = Container::factory()->for($främmandeKonto, 'account')->create();

    $svar = actingAs($ägare)->get(globalhistorikUrl(['container' => $främmandePärm->ulid]))->assertOk();

    expect(globalhistorikSvar($svar)['days'])->toBe([]);

    // Och exakt samma svar för ett ULID som inte finns någonstans.
    $påhittad = actingAs($ägare)->get(globalhistorikUrl(['container' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']))->assertOk();

    expect(globalhistorikSvar($påhittad)['days'])->toBe([]);
});

/*
 * Klart när: ett ogiltigt containervärde ger valideringsfel — `container=foo`
 * ger fel på `container`.
 *
 * Webben kör Inertia och behåller Laravels vanliga valideringsfel
 * ([[ADR-0020 Plattformsidentitet och frontendgräns]] § Konsekvenser). Det som
 * prövas är FORMEN (Beslut 2): ett ULID eller ordet `account`. Ett värde som
 * HAR formen men inte finns är däremot ingen felaktig fråga — den får ett
 * tomt svar, som provet ovan bevisar.
 */
it('ett ogiltigt containervärde ger valideringsfel', function () {
    withoutVite();

    [, $ägare, $pärm] = globalhistorikKontext();

    actingAs($ägare)->get(globalhistorikUrl(['container' => 'foo']))
        ->assertSessionHasErrors('container');

    actingAs($ägare)->get(globalhistorikUrl(['container' => 'inte-en-ulid']))
        ->assertSessionHasErrors('container');

    // Båda de giltiga formerna är inga formulärfel — också när de ger tomt.
    actingAs($ägare)->get(globalhistorikUrl(['container' => $pärm->ulid]))
        ->assertOk()
        ->assertSessionHasNoErrors();

    actingAs($ägare)->get(globalhistorikUrl(['container' => 'account']))
        ->assertOk()
        ->assertSessionHasNoErrors();
});

/*
 * Klart när: user-, type- och datumfiltren verkar som på containerns flik.
 *
 * Filtren är förälderns, oförändrade (Beslut 2), och läggs genom samma
 * `filtered()` — den här gången ovanpå en fråga utan containervillkor. Provet
 * kombinerar dem över TVÅ containrar, så att ett filter som bara gällde den
 * ena hade fallit: bara EN av fyra rader möter alla fyra villkoren.
 */
it('user-, type- och datumfiltren verkar som på containerns flik', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = globalhistorikKontext();
    $vinden = Container::factory()->for($konto, 'account')->create(['name' => 'Vinden']);
    $gäst = globalhistorikGast($pärm, 'Bo');
    $motorn = Item::factory()->for($pärm, 'container')->create(['name' => 'Motorn']);

    $träff = globalhistorikRad($pärm, $konto, $ägare, AuditLog::ACTION_COST_ENTRY_CREATED, $motorn, 'cost_entry', Carbon::parse('2026-05-10 10:00:00'));
    globalhistorikRad($vinden, $konto, $ägare, AuditLog::ACTION_COST_ENTRY_CREATED, null, 'cost_entry', Carbon::parse('2026-05-10 10:00:00'));
    globalhistorikRad($pärm, $konto, $gäst, AuditLog::ACTION_COST_ENTRY_CREATED, $motorn, 'cost_entry', Carbon::parse('2026-05-10 10:00:00'));
    globalhistorikRad($pärm, $konto, $ägare, AuditLog::ACTION_COST_ENTRY_CREATED, $motorn, 'cost_entry', Carbon::parse('2026-05-12 10:00:00'));

    $proppar = globalhistorikProps($ägare, [
        'user' => $ägare->ulid,
        'type' => 'cost_entry',
        'item' => $motorn->ulid,
        'from' => '2026-05-10',
        'to' => '2026-05-10',
    ]);

    expect(globalhistorikUlids(globalhistorikRader($proppar['days'])))->toBe([$träff->ulid])
        ->and($proppar['filter'])->toBe([
            'container' => null,
            'type' => 'cost_entry',
            'user' => $ägare->ulid,
            'item' => $motorn->ulid,
            'from' => '2026-05-10',
            'to' => '2026-05-10',
        ]);
});

/*
 * Klart när: diagrammen räknar samma mängd som listan — med `container=account`
 * och med ett ulid är summan av `stats.perType` lika med antalet rader.
 *
 * Talen kommer ur samma läsregel och samma filter som listan (Beslut 4), och
 * skiljer sig bara på gränsen: listan visar de hundra senaste, talen räknar
 * hela mängden. Med färre än hundra rader är de därför exakt lika — och
 * provet fäster båda containerlägena, för ett aggregat som glömde
 * containervillkoret hade räknat fler än listan visade.
 */
it('diagrammen räknar samma mängd som listan', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = globalhistorikKontext();

    globalhistorikRad($pärm, $konto, $ägare, subjectType: 'cost_entry', när: now()->subMinute());
    globalhistorikRad($pärm, $konto, $ägare, subjectType: 'loan');
    globalhistorikRad(null, $konto, null, AuditLog::ACTION_ACCOUNT_DELETED);

    $kontot = globalhistorikProps($ägare, ['container' => 'account']);

    expect($kontot['days'])->toHaveCount(1)
        ->and(globalhistorikTypsumma($kontot['stats']['perType']))->toBe(1);

    $pärmen = globalhistorikProps($ägare, ['container' => $pärm->ulid]);

    expect(globalhistorikRader($pärmen['days']))->toHaveCount(2)
        ->and(globalhistorikTypsumma($pärmen['stats']['perType']))->toBe(2);

    // Utan filter räknas allt tre: listan visar dem, och talen stämmer.
    $allt = globalhistorikProps($ägare);

    expect(globalhistorikRader($allt['days']))->toHaveCount(3)
        ->and(globalhistorikTypsumma($allt['stats']['perType']))->toBe(3);
});

/*
 * Klart när: topplistan bär varje items container — `container_ulid` är
 * itemets containers ULID, för items i två olika containrar.
 *
 * Fältet kom med issue 271 (Beslut 4): raderna kommer från flera containrar
 * här, och sidan ska kunna länka rätt. Containerns flik bär det inte — den
 * känner sin container, och fältet hade upprepat sidans egen rubrik.
 */
it('topplistan bär varje items container', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = globalhistorikKontext();
    $vinden = Container::factory()->for($konto, 'account')->create(['name' => 'Vinden']);

    $motorn = Item::factory()->for($pärm, 'container')->create(['name' => 'Motorn']);
    $seglet = Item::factory()->for($vinden, 'container')->create(['name' => 'Seglet']);

    globalhistorikRad($pärm, $konto, $ägare, item: $motorn, när: now()->subMinutes(2));
    globalhistorikRad($pärm, $konto, $ägare, item: $motorn, när: now()->subMinute());
    globalhistorikRad($vinden, $konto, $ägare, item: $seglet);

    $toppar = globalhistorikProps($ägare)['stats']['topItems'];

    $karta = [];

    foreach ($toppar as $post) {
        $karta[$post['ulid']] = $post['container_ulid'];
    }

    expect($karta)->toBe([
        $motorn->ulid => $pärm->ulid,
        $seglet->ulid => $vinden->ulid,
    ]);
});

/*
 * Klart när: topplistan utelämnar items utanför omfånget — ett item i en
 * container där användaren bara har en itemgrant på ett annat item.
 *
 * Omfånget kommer ur `reachable()`, läsregelns egen klassificering (Beslut
 * 4), så åtkomsten formuleras inte en andra gång. Provet jämför mot ägarens
 * svar, som HAR det främmande itemet med: hade båda svarat lika hade provet
 * inte bevisat något.
 */
it('topplistan utelämnar items utanför omfånget', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = globalhistorikKontext();
    $gästensItem = Item::factory()->for($pärm, 'container')->create(['name' => 'Motorn']);
    $gäst = globalhistorikItemGast($pärm, $gästensItem, 'Bo');
    $främmandeItem = Item::factory()->for($pärm, 'container')->create(['name' => 'Seglet']);

    foreach (range(1, 5) as $ignorera) {
        globalhistorikRad($pärm, $konto, $ägare, item: $främmandeItem);
    }

    globalhistorikRad($pärm, $konto, $gäst, item: $gästensItem);

    $gästens = globalhistorikProps($gäst)['stats']['topItems'];

    expect(globalhistorikUlids($gästens))->toBe([$gästensItem->ulid])
        ->and(globalhistorikUlids($gästens))->not->toContain($främmandeItem->ulid);

    $ägarens = globalhistorikProps($ägare)['stats']['topItems'];

    expect(globalhistorikUlids($ägarens))->toBe([$främmandeItem->ulid, $gästensItem->ulid]);
});

/*
 * Klart när: itemvalen finns bara när en container är vald — tom
 * `options.items` utan filter och med `account`, ifylld med en nådd
 * containers ULID.
 *
 * En itemlista över ALLA containrar hade varit en meny utan sammanhang
 * (Beslut 6). Valen är containerns items inom omfånget, och en container
 * användaren inte når ger inga — samma svar som `account`.
 */
it('itemvalen finns bara när en container är vald', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = globalhistorikKontext();
    $motorn = Item::factory()->for($pärm, 'container')->create(['name' => 'Motorn']);
    $ankaret = Item::factory()->for($pärm, 'container')->create(['name' => 'Ankaret']);

    $främmandePärm = Container::factory()
        ->for(Account::factory()->create(), 'account')
        ->create();

    expect(globalhistorikProps($ägare)['options']['items'])->toBe([])
        ->and(globalhistorikProps($ägare, ['container' => 'account'])['options']['items'])->toBe([])
        ->and(globalhistorikProps($ägare, ['container' => $främmandePärm->ulid])['options']['items'])->toBe([])
        // Ordnade på namn, som på containerns flik.
        ->and(globalhistorikUlids(globalhistorikProps($ägare, ['container' => $pärm->ulid])['options']['items']))
        ->toBe([$ankaret->ulid, $motorn->ulid]);
});

/*
 * Klart när: `options.containers` markerar inboxen.
 *
 * Väljaren bär varje container användaren når, sorterad på namn, och `inbox`
 * är `$container->isInbox()` (Beslut 6) — så sidan kan skriva *Inbox* i
 * stället för containerns namn, precis som `/documents` gör för en inboxfil.
 * Den egna inboxen är åtkomlig men inte listbar ([[ADR-0054 Inboxen]] § 2);
 * se `Frågor och antaganden` i PR:en.
 */
it('options.containers markerar inboxen', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = globalhistorikKontext();
    $inbox = globalhistorikInbox($ägare);

    $val = globalhistorikProps($ägare)['options']['containers'];

    $karta = [];

    foreach ($val as $post) {
        $karta[$post['ulid']] = $post['inbox'];
    }

    // Ordningen är namnordningen och prövas inte här — det är flaggan som är
    // poängen, och ett prov som hängde på vad inboxen råkar heta hade fallit
    // på ett namnbyte.
    expect($karta)->toHaveCount(2)
        ->and($karta[$pärm->ulid])->toBeFalse()
        ->and($karta[$inbox->ulid])->toBeTrue();
});

/*
 * Klart när: dashboardens händelsepanel är oförändrad.
 *
 * `forUser()` fick ett filterargument (Beslut 4), och dashboardens anrop utan
 * filter ska ge exakt samma svar som förut: de fem senaste läsbara raderna,
 * över alla användarens containrar. Provet fäster båda egenskaperna i ett —
 * gränsen OCH att raderna kommer från fler än en container — för en ändring
 * som råkade lägga på ett tomt filter hade tystat panelen.
 */
it('dashboardens händelsepanel är oförändrad', function () {
    [$konto, $ägare, $pärm] = globalhistorikKontext();
    $vinden = Container::factory()->for($konto, 'account')->create(['name' => 'Vinden']);

    foreach (range(1, 3) as $i) {
        globalhistorikRad($pärm, $konto, $ägare, när: now()->subMinutes($i));
        globalhistorikRad($vinden, $konto, $ägare, när: now()->subMinutes($i + 10));
    }

    $rader = app(ListAuditEvents::class)->forUser($ägare, DashboardController::ACTIVITY_LIMIT);

    expect($rader)->toHaveCount(DashboardController::ACTIVITY_LIMIT)
        ->and($rader->pluck('container_id')->unique())->toHaveCount(2);
});
