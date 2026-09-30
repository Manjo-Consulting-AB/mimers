<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 179 · Historikflitens filter. Se App\Http\Controllers\
 * ContainerHistoryController, App\Actions\Audit\ListAuditEvents,
 * App\Http\Requests\Audit\ContainerHistoryFilterRequest,
 * resources/js/components/HistoryFilterBar.vue och [[ADR-0050
 * Desktopdesignen]] § 17.
 *
 * **Filtren bor i läsregeln och prövas därför genom den.** Varje prov går via
 * rutten och jämför de RADER som kommer ut — ett svar som ser rätt ut men bär
 * en rad för mycket är precis felet, och det syns bara om svaret jämförs rad
 * för rad. Att bara räkna rader hade godtagit vilken rad som helst.
 *
 * **Den viktigaste gränsen är att ett filter inte får vidga läsregeln** (Beslut
 * 1 och 2): filtren läggs OVANPÅ de tre leden i samma fråga, och ett filter på
 * en användare vars rader man inte får läsa ger en TOM lista — samma svar som
 * en användare som inte finns, aldrig ett fel och aldrig en rad till.
 * Läsregeln själv prövas rad för rad i LasregelTest och är oförändrad av den
 * här issuen.
 *
 * **Datumgränsen räknas i användarens tidszon** ([[ADR-0044 Användarens dag]]):
 * en händelse 23:30 UTC hör till nästa dygn i Stockholm, och provet fäster
 * båda sidor om midnatt så att en jämförelse mot UTC-dygn faller.
 *
 * Hjälparna har prefixet `historikfilter` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem som äger en container.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function historikfilterKontext(?string $tidszon = null): array
{
    $konto = Account::factory()->create();
    $ägare = User::factory()->create(['name' => 'Anna', 'timezone' => $tidszon]);
    $konto->users()->attach($ägare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $ägare, $container];
}

/**
 * En loggrad skriven DIREKT i loggen, med en tid vi väljer — samma form som
 * historikRad() i HistorikflikTest, men med `subject_type` (filtrets `type`).
 */
function historikfilterRad(
    Container $container,
    Account $account,
    ?User $handlande,
    string $action,
    ?Item $item = null,
    ?string $subjectType = null,
    ?Carbon $när = null,
): AuditLog {
    return AuditLog::factory()->create([
        'container_id' => $container->id,
        'account_id' => $account->id,
        'user_id' => $handlande?->id,
        'item_id' => $item?->id,
        'action' => $action,
        'subject_type' => $subjectType,
        'created_at' => $när ?? now(),
    ]);
}

/**
 * En containerbred åtkomst på `write` för en gäst — samma form som
 * historikGast() i HistorikflikTest: den som får skriva i containern är den
 * läsare historiken finns för, och hon ser sina egna rader och inget annat.
 */
function historikfilterGast(Container $container, string $namn): User
{
    $gäst = User::factory()->create(['name' => $namn]);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => null,
        'grantee_type' => 'user',
        'grantee_id' => $gäst->id,
        'level' => 'write',
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $gäst;
}

/**
 * Containerns historikflik som URL, med filtret i querysträngen.
 *
 * @param  array<string, string>  $filter
 */
function historikfilterUrl(Container $container, array $filter = []): string
{
    $url = "/containers/{$container->ulid}/history";

    return $filter === [] ? $url : $url.'?'.http_build_query($filter);
}

/**
 * Sidans proppar, ur Inertias rotvy.
 *
 * @return array<string, mixed>
 */
function historikfilterProps(TestResponse $svar): array
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
function historikfilterRader(array $dagar): array
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
 * ULID:erna i en lista av val, i ordning.
 *
 * @param  list<array{ulid: string, name: string}>  $val
 * @return list<string>
 */
function historikfilterUlids(array $val): array
{
    return array_map(static fn (array $post): string => $post['ulid'], $val);
}

/*
 * Klart när: filtret på typ visar bara händelser med den subject_type.
 *
 * `type` är `audit_log.subject_type` ([[ADR-0043 Tre loggar]]
 * § Händelseloggen) och jämförs rakt av. Provet lägger två rader med var sin
 * typ i loggen och bevisar att bara den ena kommer med — en lista som visade
 * allt hade sett rätt ut i en logg med en enda typ.
 *
 * Valen (`options.types`) räknas ur de LÄSBARA raderna och inte ur det
 * filtrerade svaret: en typ som filtrerats bort ska gå att välja tillbaka.
 */
it('filtret på typ visar bara händelser med den subject_type', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = historikfilterKontext();

    $kostnad = historikfilterRad($pärm, $konto, $ägare, AuditLog::ACTION_COST_ENTRY_CREATED, subjectType: 'cost_entry');
    $lån = historikfilterRad($pärm, $konto, $ägare, AuditLog::ACTION_LOAN_CREATED, subjectType: 'loan');

    $proppar = historikfilterProps(
        actingAs($ägare)->get(historikfilterUrl($pärm, ['type' => 'cost_entry']))->assertOk(),
    );

    $rader = historikfilterRader($proppar['days']);

    expect($rader)->toHaveCount(1)
        ->and($rader[0]['ulid'])->toBe($kostnad->ulid)
        // Filtret så som servern tillämpade det — vyn ritar fälten ur det.
        ->and($proppar['filter']['type'])->toBe('cost_entry')
        // Och typen finns kvar som val, trots att den egna raden är borta.
        ->and($proppar['options']['types'])->toBe(['cost_entry', 'loan']);

    // Utan filter är båda raderna med.
    $alla = historikfilterProps(actingAs($ägare)->get(historikfilterUrl($pärm))->assertOk());

    expect(historikfilterRader($alla['days']))->toHaveCount(2)
        ->and($alla['filter']['type'])->toBeNull();

    expect($lån->ulid)->not->toBe($kostnad->ulid);
});

/*
 * Klart när: filtren på användare, item och datum kan kombineras.
 *
 * Fyra rader i samma container, och bara EN möter alla tre villkoren: rätt
 * användare, rätt item, rätt dag. Kombinationen är det som prövas — var för
 * sig hade varje filter kunnat vara rätt och svaret ändå fel, och en lista som
 * bara sållade på ett av dem hade sett rätt ut med tre av fyra rader kvar.
 */
it('filtren på användare, item och datum kan kombineras', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = historikfilterKontext();
    $gäst = historikfilterGast($pärm, 'Bo');
    $motorn = Item::factory()->for($pärm, 'container')->create(['name' => 'Motorn']);

    $träff = historikfilterRad($pärm, $konto, $ägare, AuditLog::ACTION_COST_ENTRY_CREATED, $motorn, när: Carbon::parse('2026-05-10 10:00:00'));
    historikfilterRad($pärm, $konto, $gäst, AuditLog::ACTION_COST_ENTRY_CREATED, $motorn, när: Carbon::parse('2026-05-10 11:00:00'));
    historikfilterRad($pärm, $konto, $ägare, AuditLog::ACTION_COST_ENTRY_CREATED, $motorn, när: Carbon::parse('2026-05-11 10:00:00'));
    historikfilterRad($pärm, $konto, $ägare, AuditLog::ACTION_CONTAINER_UPDATED, när: Carbon::parse('2026-05-10 12:00:00'));

    $proppar = historikfilterProps(actingAs($ägare)->get(historikfilterUrl($pärm, [
        'user' => $ägare->ulid,
        'item' => $motorn->ulid,
        'from' => '2026-05-10',
        'to' => '2026-05-10',
    ]))->assertOk());

    $rader = historikfilterRader($proppar['days']);

    expect($rader)->toHaveCount(1)
        ->and($rader[0]['ulid'])->toBe($träff->ulid)
        ->and($proppar['filter'])->toBe([
            'type' => null,
            'user' => $ägare->ulid,
            'item' => $motorn->ulid,
            'from' => '2026-05-10',
            'to' => '2026-05-10',
        ]);
});

/*
 * Klart när: datumfiltret räknar dagen i användarens tidszon.
 *
 * Två händelser på var sin sida om midnatt i Stockholm, och båda skrivna den
 * 26 september i UTC: 23:30 lokal tid (21:30 UTC) hör till den 26:e, och
 * 23:30 UTC hör till den 27:e — klockan är då 01:30 hos användaren
 * ([[ADR-0044 Användarens dag]]). En jämförelse mot UTC-dygn hade lagt båda på
 * samma dag, och provet fäster därför båda riktningarna.
 */
it('datumfiltret räknar dagen i användarens tidszon', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = historikfilterKontext('Europe/Stockholm');

    // 23:30 lokal tid den 26:e.
    $sentPåDagen = historikfilterRad($pärm, $konto, $ägare, AuditLog::ACTION_CONTAINER_UPDATED, när: Carbon::parse('2026-09-26 21:30:00'));
    // 23:30 UTC — 01:30 lokal tid den 27:e.
    $efterMidnatt = historikfilterRad($pärm, $konto, $ägare, AuditLog::ACTION_CONTAINER_UPDATED, när: Carbon::parse('2026-09-26 23:30:00'));

    $den26 = historikfilterProps(actingAs($ägare)->get(historikfilterUrl($pärm, [
        'from' => '2026-09-26',
        'to' => '2026-09-26',
    ]))->assertOk());

    $den27 = historikfilterProps(actingAs($ägare)->get(historikfilterUrl($pärm, [
        'from' => '2026-09-27',
        'to' => '2026-09-27',
    ]))->assertOk());

    expect(historikfilterRader($den26['days']))->toHaveCount(1)
        ->and(historikfilterRader($den26['days'])[0]['ulid'])->toBe($sentPåDagen->ulid)
        ->and($den26['days'][0]['date'])->toBe('2026-09-26');

    expect(historikfilterRader($den27['days']))->toHaveCount(1)
        ->and(historikfilterRader($den27['days'])[0]['ulid'])->toBe($efterMidnatt->ulid)
        ->and($den27['days'][0]['date'])->toBe('2026-09-27');
});

/*
 * Klart när: ett filter kan inte vidga läsregeln.
 *
 * Gästen ser sina egna rader och ingenting annat ([[ADR-0043 Tre loggar]]
 * § Händelseloggen, led 2). Ett filter på ägaren — vars rad hon inte får läsa
 * — ger därför en TOM lista, och exakt samma svar som ett filter på en
 * användare som inte finns (Beslut 2): svaret får inte berätta vilket av de
 * två det var. Hade filtret lagts FÖRE läsregeln hade ägarens rad kommit med,
 * och det är precis felet provet letar efter.
 */
it('ett filter kan inte vidga läsregeln', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = historikfilterKontext();
    $gäst = historikfilterGast($pärm, 'Bo');

    historikfilterRad($pärm, $konto, $ägare, AuditLog::ACTION_CONTAINER_UPDATED);
    $gästensRad = historikfilterRad($pärm, $konto, $gäst, AuditLog::ACTION_ITEM_CREATED);

    // Utan filter: bara hennes egen rad.
    $utanFilter = historikfilterProps(actingAs($gäst)->get(historikfilterUrl($pärm))->assertOk());

    expect(historikfilterRader($utanFilter['days']))->toHaveCount(1)
        ->and(historikfilterRader($utanFilter['days'])[0]['ulid'])->toBe($gästensRad->ulid);

    // Filter på ägaren: tomt svar, inte ägarens rad.
    $påÄgaren = historikfilterProps(actingAs($gäst)->get(historikfilterUrl($pärm, [
        'user' => $ägare->ulid,
    ]))->assertOk());

    expect($påÄgaren['days'])->toBe([]);

    // Och ett ULID som inte finns ger ordagrant samma svar.
    $påFrämmande = historikfilterProps(actingAs($gäst)->get(historikfilterUrl($pärm, [
        'user' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
    ]))->assertOk());

    expect($påFrämmande['days'])->toBe($påÄgaren['days']);
});

/*
 * Klart när: användarfiltret erbjuder bara användare i läsbara rader.
 *
 * Valen räknas ur LÄSREGELN och aldrig ur tabellen (Beslut 2). Ägaren ser båda
 * sina medlemmars rader och får båda som val; en användare som bara handlat i
 * en ANNAN container förekommer inte i listan, för hon förekommer inte i en
 * läsbar rad här. Gästen ser bara sina egna rader och får därför bara sig
 * själv som val — hennes meny avslöjar ingenting om vilka fler som skrivit.
 */
it('användarfiltret erbjuder bara användare i läsbara rader', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = historikfilterKontext();
    $gäst = historikfilterGast($pärm, 'Bo');
    $främmande = User::factory()->create(['name' => 'Cissi']);

    historikfilterRad($pärm, $konto, $ägare, AuditLog::ACTION_CONTAINER_UPDATED);
    historikfilterRad($pärm, $konto, $gäst, AuditLog::ACTION_ITEM_CREATED);

    // Cissi handlar, men i en annan container — hennes rad är inte läsbar här.
    $annan = Container::factory()->for($konto, 'account')->create();
    historikfilterRad($annan, $konto, $främmande, AuditLog::ACTION_CONTAINER_UPDATED);

    $ägarens = historikfilterProps(actingAs($ägare)->get(historikfilterUrl($pärm))->assertOk());
    $gästens = historikfilterProps(actingAs($gäst)->get(historikfilterUrl($pärm))->assertOk());

    expect(historikfilterUlids($ägarens['options']['users']))->toBe([$ägare->ulid, $gäst->ulid])
        ->and(historikfilterUlids($gästens['options']['users']))->toBe([$gäst->ulid])
        ->and(historikfilterUlids($ägarens['options']['users']))->not->toContain($främmande->ulid);
});

/*
 * Klart när: ett ogiltigt filter är ett formulärfel.
 *
 * Webben kör Inertia och behåller Laravels vanliga valideringsfel
 * ([[ADR-0020 Plattformsidentitet och frontendgräns]] § Konsekvenser) — svaret
 * är en redirect tillbaka med felet på fältet, inte API:ets `validation.failed`
 * och inte en tyst tom sida. Det som prövas är FORMEN (Beslut 4): ett datum
 * utan form, ett `to` som ligger före `from`, ett ULID som inte är ett ULID,
 * och ett typnamn som inte är ett domännamn. Ett värde som HAR formen men inte
 * finns är däremot ingen felaktig fråga — den får ett tomt svar, som provet
 * ovan bevisar.
 */
it('ett ogiltigt filter är ett formulärfel', function () {
    withoutVite();

    [, $ägare, $pärm] = historikfilterKontext();

    actingAs($ägare)->get(historikfilterUrl($pärm, ['from' => 'inte-ett-datum']))
        ->assertSessionHasErrors('from');

    actingAs($ägare)->get(historikfilterUrl($pärm, ['from' => '2026-05-10', 'to' => '2026-05-01']))
        ->assertSessionHasErrors('to');

    actingAs($ägare)->get(historikfilterUrl($pärm, ['user' => 'inte-en-ulid']))
        ->assertSessionHasErrors('user');

    actingAs($ägare)->get(historikfilterUrl($pärm, ['item' => 'inte-en-ulid']))
        ->assertSessionHasErrors('item');

    actingAs($ägare)->get(historikfilterUrl($pärm, ['type' => 'Inte Ett Domännamn']))
        ->assertSessionHasErrors('type');

    // En giltig fråga är inget formulärfel — också när den inte ger några rader.
    actingAs($ägare)->get(historikfilterUrl($pärm, ['type' => 'cost_entry', 'from' => '2026-05-10']))
        ->assertOk()
        ->assertSessionHasNoErrors();
});
