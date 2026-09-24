<?php

use App\Actions\Audit\ListAuditEvents;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

/*
 * Issue 108 · Läsregeln. Se App\Actions\Audit\ListAuditEvents,
 * app/Http/Controllers/Api/AuditLogController och
 * App\Policies\ContainerPolicy::viewAuditLog. Regeln står i [[ADR-0043 Tre
 * loggar]] § Händelseloggen.
 *
 * Filen prövar LÄSREGELN — vilka rader en användare får läsa — och håller den
 * åtskild från grinden, som bara svarar på om hon når containern alls. Den
 * åtskillnaden är hela poängen med issue 108: före den fick en gäst 403 på hela
 * loggen, efter den får hon 200 med sina egna rader och ingenting annat.
 *
 * De flesta proven går genom actionen och inte genom rutten, eftersom det är
 * där raderna väljs — ett svar som ser rätt ut men bär en rad för mycket är
 * precis felet, och det syns bara om svaret jämförs rad för rad.
 *
 * Hjälparna har prefixet `lasregel` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs, och RevisionsloggTest i samma katalog har en
 * `loggRad()`.
 */

/**
 * En loggrad skriven DIREKT i loggen, förbi RecordAuditEvent — proven här
 * prövar läsningen och inte skrivningen. $container utelämnad ger en rad utan
 * container (en kontorad, `account.deleted`).
 *
 * @param  array<string, mixed>  $attribut
 */
function lasregelRad(?Container $container, Account $account, ?User $användare = null, array $attribut = []): AuditLog
{
    return AuditLog::factory()->create(array_merge([
        'container_id' => $container?->id,
        'account_id' => $account->id,
        'user_id' => $användare?->id,
    ], $attribut));
}

/**
 * Sanctum-headern för en användare, samma form som kontoMedMedlem() ger.
 *
 * @return array<string, string>
 */
function lasregelToken(User $användare): array
{
    return ['Authorization' => 'Bearer '.$användare->createToken('api')->plainTextToken];
}

/**
 * En grant på ett ENSKILT item — beviljaAccess() tar ingen itemrad, eftersom
 * den skrevs före itemåtkomsten (issue 9b mot issue 69). Samma form som
 * sokvyMottagare() i tests/Feature/Frontend/SokvyTest.php.
 */
function lasregelItemgrant(Container $container, User $mottagare, Item $item, string $nivå = 'read'): ContainerAccess
{
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $nivå,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * En `parent`-kant skriven direkt i tabellen, förbi LinkItems — samma form som
 * atkomstKant() i tests/Feature/Omfang/ItematkomstTest.php. Arvet prövas av
 * ResolveItemScope, och kanten är vad som bär det.
 */
function lasregelKant(Item $förälder, Item $barn): void
{
    ItemLink::query()->insert([
        'from_item_id' => $förälder->id,
        'to_item_id' => $barn->id,
        'relation' => 'parent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Antalet frågor $anrop ställer: värm, nollställ, mät.
 *
 * ResolveItemScope är `scoped` och memoiserar per request i drift, men i
 * testsviten överlever den mellan anropen. Glöm den därför MELLAN värmningen
 * och mätningen — annars mäter man förra anropets omfång och får noll frågor
 * för omfånget. Samma mätning som sokvyFrågor() i
 * tests/Feature/Frontend/SokvyTest.php.
 */
function lasregelFrågor(Closure $värm, Closure $anrop): int
{
    $värm();

    app()->forgetScopedInstances();

    $frågor = 0;
    DB::listen(function () use (&$frågor) {
        $frågor++;
    });

    $anrop();

    return $frågor;
}

/*
 * Klart när: ägarkontots medlem ser alla rader i containern och dess items.
 *
 * Alla tre raderna handlar om containern, men bara en av dem är skriven av
 * ägaren: loggen är ägarens, och vem som än handlade syns (regel 1). Raden på
 * itemet bevisar att "och dess items" inte är en eftertanke — itemrader bär
 * samma container_id och följer med.
 */
it('ägerkontots medlem ser alla rader i containern och dess items', function () {
    [$konto, $ägare, $headers] = kontoMedMedlem();
    $pärm = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($pärm, 'container')->create();
    $annan = User::factory()->create();

    lasregelRad($pärm, $konto, $ägare);
    lasregelRad($pärm, $konto, $annan, ['item_id' => $item->id]);
    lasregelRad($pärm, $konto, null, ['item_id' => $item->id]);

    $svar = getJson("/api/containers/{$pärm->ulid}/audit-log", $headers);

    $svar->assertOk();
    expect($svar->json('data'))->toHaveCount(3);
});

/*
 * Klart när: en gäst med write ser bara sina egna rader.
 *
 * Ägarens rad ligger i samma container och är osynlig för henne — `write` ger
 * ingen inblick i vad andra gjort ([[ADR-0043 Tre loggar]] § Motivering: "i en
 * delad container är det ägarens sak att veta vem som gjort vad"). Att svaret är
 * 200 och inte 403 är ändringen issue 108 gör i API-svaret.
 */
it('en gäst med write ser bara sina egna rader', function () {
    [$ägarkonto, $ägare] = kontoMedMedlem();
    $pärm = Container::factory()->for($ägarkonto, 'account')->create();

    $gäst = User::factory()->create();
    beviljaAccess($pärm, $gäst, 'write', 'guest');

    lasregelRad($pärm, $ägarkonto, $ägare);
    $egen = lasregelRad($pärm, $ägarkonto, $gäst);

    $svar = getJson("/api/containers/{$pärm->ulid}/audit-log", lasregelToken($gäst));

    $svar->assertOk();
    expect($svar->json('data'))->toHaveCount(1)
        ->and($svar->json('data.0.ulid'))->toBe($egen->ulid);
});

/*
 * Klart när: en mottagare av en itemgrant ser bara sina egna rader om items
 * inom grantens omfång.
 *
 * Fyra av hennes egna rader finns i containern, och bara två är läsbara:
 * itemet hon fått och dess barn (arvet går nedåt), medan grannitemet ligger
 * utanför granten och den containerbreda raden kräver att hon når hela
 * containern — det gör hon inte. Ägarens rad på samma item är osynlig: hon ser
 * sina egna handlingar, inte allas.
 */
it('en mottagare av en itemgrant ser bara sina egna rader om items inom grantens omfång', function () {
    [$ägarkonto, $ägare] = kontoMedMedlem();
    $pärm = Container::factory()->for($ägarkonto, 'account')->create();

    $mitt = Item::factory()->for($pärm, 'container')->create();
    $barn = Item::factory()->for($pärm, 'container')->create();
    $grann = Item::factory()->for($pärm, 'container')->create();
    lasregelKant($mitt, $barn);

    $mottagare = User::factory()->create();
    lasregelItemgrant($pärm, $mottagare, $mitt);

    $påMitt = lasregelRad($pärm, $ägarkonto, $mottagare, ['item_id' => $mitt->id]);
    $påBarn = lasregelRad($pärm, $ägarkonto, $mottagare, ['item_id' => $barn->id]);
    lasregelRad($pärm, $ägarkonto, $mottagare, ['item_id' => $grann->id]);
    lasregelRad($pärm, $ägarkonto, $mottagare);
    lasregelRad($pärm, $ägarkonto, $ägare, ['item_id' => $mitt->id]);

    $aktion = app(ListAuditEvents::class);
    $rader = $aktion->forContainer($mottagare, $pärm)->pluck('ulid')->all();

    expect($rader)->toHaveCount(2)
        ->and($rader)->toContain($påMitt->ulid)
        ->and($rader)->toContain($påBarn->ulid);

    // Startpunkten item ger samma svar för itemet hon fått: hennes egen rad,
    // och inte ägarens rad på samma item. Det är fliken i issue 116.
    expect($aktion->forItem($mottagare, $mitt)->pluck('ulid')->all())->toBe([$påMitt->ulid]);
});

/*
 * Ägandet prövas mot containerns NUVARANDE ägarkonto och inte mot radens
 * `account_id` — den som tagit över en container ser hela dess historia.
 *
 * Raden är skriven av en tredje användare medan säljarkontot ägde containern,
 * så bara led 1 kan nå den. Med radens `account_id` som mått hade köparen fått
 * en tom historik, och `container.transferred` — som skrivs på säljarkontot —
 * hade försvunnit ur köparens logg i samma stund den skapades.
 */
it('ser historiken från tiden före ett ägarbyte', function () {
    [$säljarkonto] = kontoMedMedlem();
    [$köparkonto, , $köparHeaders] = kontoMedMedlem();
    $pärm = Container::factory()->for($säljarkonto, 'account')->create();

    $före = lasregelRad($pärm, $säljarkonto, User::factory()->create());

    // Direkt egenskapstilldelning: `account_id` är medvetet inte `#[Fillable]`
    // på Container, se modellens docblock.
    $pärm->account_id = $köparkonto->id;
    $pärm->save();

    $svar = getJson("/api/containers/{$pärm->ulid}/audit-log", $köparHeaders);

    $svar->assertOk();
    expect($svar->json('data'))->toHaveCount(1)
        ->and($svar->json('data.0.ulid'))->toBe($före->ulid);
});

/*
 * Klart när: den som förlorat åtkomsten ser inga rader, inte heller sina egna.
 *
 * Raden skrevs medan hon nådde containern. När granten återkallas faller
 * led 2 — omfånget är tomt — och loggen blir inte en väg tillbaka in i något
 * som stängts. Rutten svarar dessutom 403: grinden prövar om hon når
 * containern alls, och det gör hon inte längre.
 */
it('den som förlorat åtkomsten ser inga rader, inte heller sina egna', function () {
    [$ägarkonto] = kontoMedMedlem();
    $pärm = Container::factory()->for($ägarkonto, 'account')->create();

    $mottagare = User::factory()->create();
    $access = beviljaAccess($pärm, $mottagare, 'write', 'guest');
    lasregelRad($pärm, $ägarkonto, $mottagare);

    // Direkt egenskapstilldelning och inte update(): `revoked_at` är medvetet
    // inte `#[Fillable]` på ContainerAccess — den sätts av återkallningen och
    // aldrig via massilldelning, se modellens docblock.
    $access->revoked_at = now();
    $access->save();

    expect(app(ListAuditEvents::class)->forContainer($mottagare, $pärm))->toBeEmpty();

    getJson("/api/containers/{$pärm->ulid}/audit-log", lasregelToken($mottagare))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'auth.forbidden');
});

/*
 * Klart när: en rad utan container syns för medlemmarna i radens konto.
 *
 * En kontorad hör till kontot och inte till en container (led 3): medlemmen ser
 * den, och en främmande användare gör det inte. Raden är skriven av en annan
 * medlem — led 3 frågar efter kontot, inte efter den handlande.
 */
it('en rad utan container syns för medlemmarna i radens konto', function () {
    [$konto, $ägare] = kontoMedMedlem();
    $medlem = User::factory()->create();
    $konto->users()->attach($medlem, ['role' => 'member']);

    $rad = lasregelRad(null, $konto, $ägare, ['action' => AuditLog::ACTION_ACCOUNT_DELETED]);
    $främling = User::factory()->create();

    $aktion = app(ListAuditEvents::class);

    expect($aktion->forUser($medlem)->pluck('ulid')->all())->toBe([$rad->ulid])
        ->and($aktion->forUser($främling))->toBeEmpty();
});

/*
 * Klart när: startpunkten användare ger användarens läsbara rader över alla
 * containrar.
 *
 * Tre containrar, tre skäl: hennes egen (led 1 ger allt i den, också ägarens
 * rad), en delad där hon bara når genom en grant (led 2 ger hennes egen rad,
 * medan ägarens rad i SAMMA container är osynlig), och en främmande container
 * hon inte når alls. Plus kontoraden utan container (led 3). Just den delade
 * containern är beviset för att startpunkten tillämpar samma regel och inte
 * "allt i de containers hon når".
 */
it('startpunkten användare ger användarens läsbara rader över alla containrar', function () {
    [$egetKonto, $användare] = kontoMedMedlem();

    $egen = Container::factory()->for($egetKonto, 'account')->create();
    $delad = Container::factory()->for(Account::factory(), 'account')->create();
    $främmande = Container::factory()->for(Account::factory(), 'account')->create();

    beviljaAccess($delad, $användare, 'read', 'guest');

    $ägarensIEgen = lasregelRad($egen, $egetKonto, User::factory()->create());
    $hennesIEgen = lasregelRad($egen, $egetKonto, $användare);
    $hennesIDelad = lasregelRad($delad, $delad->account, $användare);
    lasregelRad($delad, $delad->account, User::factory()->create());
    lasregelRad($främmande, $främmande->account, User::factory()->create());
    $kontoraden = lasregelRad(null, $egetKonto, $användare);

    $rader = app(ListAuditEvents::class)->forUser($användare)->pluck('ulid')->all();

    expect(collect($rader)->sort()->values()->all())->toBe(collect([
        $ägarensIEgen->ulid,
        $hennesIEgen->ulid,
        $hennesIDelad->ulid,
        $kontoraden->ulid,
    ])->sort()->values()->all());
});

/*
 * Klart när: antalet frågor är konstant oavsett antal rader, mätt efter
 * forgetScopedInstances().
 *
 * Fler rader får inte lägga en fråga till: omfånget löses i ett anrop över
 * containern, loggraderna hämtas i en fråga och den handlande användaren
 * eager-laddas i en. En lat `user`-relation hade gett en fråga per rad och
 * fällt provet, och det är precis den N+1 AuditLogResource varnar för.
 */
it('antalet frågor är konstant oavsett antal rader', function () {
    [$konto, $ägare, $headers] = kontoMedMedlem();
    $pärm = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($pärm, 'container')->create();

    foreach (range(1, 3) as $i) {
        lasregelRad($pärm, $konto, $ägare, ['item_id' => $item->id]);
    }

    $url = "/api/containers/{$pärm->ulid}/audit-log";
    $värm = fn () => getJson($url, $headers)->assertOk();

    $medTreRader = lasregelFrågor($värm, function () use ($url, $headers) {
        getJson($url, $headers)->assertOk()->assertJsonCount(3, 'data');
    });

    foreach (range(4, 40) as $i) {
        lasregelRad($pärm, $konto, $ägare, ['item_id' => $item->id]);
    }

    $medFyrtioRader = lasregelFrågor($värm, function () use ($url, $headers) {
        getJson($url, $headers)->assertOk()->assertJsonCount(40, 'data');
    });

    expect($medFyrtioRader)->toBe($medTreRader);
});
