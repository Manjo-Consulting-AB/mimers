<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Number;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 66b · Lagringsytan — nedgraderingens steg 2, se
 * App\Http\Controllers\Settings\StorageController,
 * resources/js/pages/Settings/Storage.vue,
 * resources/js/components/StorageCleanupSection.vue,
 * resources/js/components/storageSelection.js, [[Planer och kvoter]]
 * § Nedgradering och [[ADR-0009 Kvoter och livscykel]].
 *
 * Filen prövar SIDAN ovanpå rättighetslagret. API-ytan
 * (GET/DELETE /api/accounts/{account}/storage), rensningens atomiket och
 * TrashAttachments parighet prövas av tests/Feature/Kvot/NedgraderingTest.php —
 * det enda den här filen gör mot `/api` är att bevisa att ytan svarar som förut
 * (issuens sista "Klart när"), medan den här issuen lägger sin webbyta ovanpå
 * samma action.
 *
 * "Klart när" i issuen motsvaras var sitt test nedan, med undantag för "ingen
 * svensk sträng står kvar i en .vue-fil; varje ny nyckel finns på sv och en",
 * som vaktas av tests/Feature/Frontend/SprakTest.php — den läser varje fil
 * under resources/js/ och jämför språkfilerna nyckel för nyckel.
 *
 * Hjälparna har prefixet `lagringsvy` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs, och tests/Feature/Frontend/PlanvyTest.php har
 * redan `planvyKonto`, `planvyBilaga` och liknande.
 */

/**
 * Ett konto och en medlem i angiven roll.
 *
 * @return array{0: Account, 1: User}
 */
function lagringsvyKonto(string $roll = 'owner'): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => $roll]);

    return [$konto, $anvandare];
}

/**
 * En container, ett item och en bilaga av exakt storlek.
 *
 * `$konto` är kontot som BELASTAS (`billed_account_id`) — precis som på
 * `/api` kan containern tillhöra någon annan (28 § Beslut 2), och då skickas den
 * in. Listan sorterar på `stored_file.byte_size`, så testerna måste kunna
 * styra den.
 *
 * @param  array<string, mixed>  $attribut
 */
function lagringsvyBilaga(
    Account $konto,
    User $anvandare,
    int $byteSize,
    ?Container $container = null,
    array $attribut = [],
): Attachment {
    $container ??= Container::factory()->for($konto, 'account')->create();

    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    $storedFile = StoredFile::factory()->create(['byte_size' => $byteSize]);

    return Attachment::factory()->for($item, 'item')->create(array_merge([
        'stored_file_id' => $storedFile->id,
        'filename' => 'servicebild.jpg',
        'kind' => 'image',
        'uploaded_by_user_id' => $anvandare->id,
        'billed_account_id' => $konto->id,
    ], $attribut));
}

/**
 * Räknarraden för ett konto, satt direkt — sidan läser `usage_counter` och
 * ingenting annat, så testerna måste kunna styra talet utan en uppladdning.
 */
function lagringsvyRaknare(Account $konto, int $storageBytes): void
{
    UsageCounter::factory()->create([
        'account_id' => $konto->id,
        'storage_bytes' => $storageBytes,
    ]);
}

/**
 * Kontots lagrade byten i räknaren — samma avläsning som rensningsrutten gör
 * när den bygger sitt svar.
 */
function lagringsvyForbrukning(Account $konto): int
{
    return (int) (DB::table('usage_counter')->where('account_id', $konto->id)->value('storage_bytes') ?? 0);
}

/**
 * Sidans props, så att ett test kan läsa dem som en array i stället för genom
 * AssertableInertia — samma teknik som planvyProps() i PlanvyTest.
 *
 * @return array<string, mixed>
 */
function lagringsvyProps(User $anvandare, ?Account $konto = null): array
{
    $url = '/settings/storage'.($konto === null ? '' : "?account={$konto->ulid}");

    /** @var array{props: array<string, mixed>} $sida */
    $sida = actingAs($anvandare)->get($url)->assertOk()->viewData('page');

    return $sida['props'];
}

/**
 * Kör en sökväg ur resources/js/components/storageSelection.js i node.
 *
 * Urvalets matematik är klientens — förhandsvisningen räknas medan man
 * kryssar — och den enda ärliga vägen att pröva den är att köra modulen.
 * Samma teknik som bilagevyKör() i BilagevyTest gör för formatByteSize.
 */
function lagringsvyKor(string $anrop): string
{
    $kod = implode("\n", [
        "const { pathToFileURL } = await import('node:url');",
        'const m = await import(pathToFileURL('
            .json_encode(resource_path('js/components/storageSelection.js'), JSON_UNESCAPED_SLASHES).').href);',
        "process.stdout.write(String({$anrop}));",
    ]);

    $rader = [];
    $status = 0;

    exec('node --input-type=module -e '.escapeshellarg($kod).' 2>&1', $rader, $status);

    expect($status)->toBe(0, implode("\n", $rader));

    return implode("\n", $rader);
}

/**
 * En nyckel finns på båda språken och är inte tom — samma kontroll som
 * planvyNyckel() i PlanvyTest gör: en nyckel som bara finns på svenska syns
 * som en nyckel i den engelska vyn.
 */
function lagringsvyNyckel(string $nyckel): void
{
    foreach (['sv', 'en'] as $locale) {
        $mening = trans($nyckel, [], $locale);

        expect($mening)->not->toBe($nyckel, "{$nyckel} saknas på {$locale}");
        expect(trim((string) $mening))->not->toBe('', "{$nyckel} är tom på {$locale}");
    }
}

/*
 * Beslut 1: listningen ligger bakom `auth`.
 */
it('skickar en utloggad besökare till inloggningen från lagringsytan', function () {
    withoutVite();

    get('/settings/storage')->assertRedirect('/login');
});

/*
 * Klart när: `/settings/storage` listar det valda kontots levande bilagor,
 * störst först, och varje rad visar filnamn, storlek, container och item
 * (Beslut 2).
 *
 * Två bilagor delar byte_size med flit: sorteringen är `byte_size` fallande med
 * `attachment.id` fallande som andrasortering — samma ordning som `/api`
 * svarar med, och den ordning som gör valet snabbast. Vid lika storlek står
 * alltså den SIST skapade raden först.
 */
it('listar kontots levande bilagor störst först med filnamn, storlek, container och item', function () {
    withoutVite();

    [$konto, $anvandare] = lagringsvyKonto();

    $litenForst = lagringsvyBilaga($konto, $anvandare, 1024, attribut: ['filename' => 'detalj.jpg']);
    $stor = lagringsvyBilaga($konto, $anvandare, 4 * 1024 * 1024, attribut: ['filename' => 'servicebild.jpg']);
    $litenSist = lagringsvyBilaga($konto, $anvandare, 1024, attribut: ['filename' => 'kvitto.pdf']);

    $props = lagringsvyProps($anvandare, $konto);

    expect(array_column($props['attachments'], 'ulid'))->toBe([
        $stor->ulid,
        $litenSist->ulid,
        $litenForst->ulid,
    ]);

    expect($props['attachments'][0]['filename'])->toBe('servicebild.jpg')
        ->and($props['attachments'][0]['byte_size'])->toBe(4 * 1024 * 1024)
        ->and($props['attachments'][0]['container']['name'])->toBe($stor->item->container->name)
        ->and($props['attachments'][0]['item']['name'])->toBe($stor->item->name)
        ->and($props['attachments'][0]['inTrash'])->toBeFalse();

    actingAs($anvandare)->get("/settings/storage?account={$konto->ulid}")->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Settings/Storage')
            ->has('accounts', 1)
            ->has('account')
            ->has('attachments', 3)
            ->has('usage')
            ->where('removed', null)
    );
});

/*
 * Klart när: en mjukraderad bilaga syns inte i listan.
 *
 * En bilaga som själv ligger i papperskorgen räknas inte mot kontot längre
 * (issue 26a), och den som redan är borta ska inte gå att välja.
 */
it('visar inte mjukraderade bilagor', function () {
    withoutVite();

    [$konto, $anvandare] = lagringsvyKonto();

    $kvar = lagringsvyBilaga($konto, $anvandare, 1024);
    $borta = lagringsvyBilaga($konto, $anvandare, 2048);
    $borta->delete();

    expect(array_column(lagringsvyProps($anvandare, $konto)['attachments'], 'ulid'))->toBe([$kvar->ulid]);
});

/*
 * Klart när: en bilaga vars item eller container ligger i papperskorgen syns,
 * markerad som sådan (Beslut 3).
 *
 * Item-mjukraderingen rör INTE bilagan (issue 26a), och en container i
 * papperskorgen tar inte bort det som ligger i den — bilagan belastar kontot
 * tills den själv lämnar papperskorgen. Utan markeringen ser summan ut att
 * vara fel, och därför är `inTrash` ett fält vyn får ur kontrollern.
 */
it('visar och markerar en bilaga vars item eller container ligger i papperskorgen', function () {
    withoutVite();

    [$konto, $anvandare] = lagringsvyKonto();

    $iItemet = lagringsvyBilaga($konto, $anvandare, 2048);
    $iParmen = lagringsvyBilaga($konto, $anvandare, 4096);
    $hel = lagringsvyBilaga($konto, $anvandare, 1024);

    $iItemet->item->delete();
    $iParmen->item->container->delete();

    $props = lagringsvyProps($anvandare, $konto);

    expect(array_column($props['attachments'], 'ulid'))->toBe([
        $iParmen->ulid,
        $iItemet->ulid,
        $hel->ulid,
    ]);

    expect($props['attachments'][0]['inTrash'])->toBeTrue()
        ->and($props['attachments'][1]['inTrash'])->toBeTrue()
        ->and($props['attachments'][2]['inTrash'])->toBeFalse();

    // Namnen står kvar: raden ska gå att känna igen och välja bort.
    expect($props['attachments'][1]['container']['name'])->not->toBe('')
        ->and($props['attachments'][1]['item']['name'])->not->toBe('');
});

/*
 * Klart när: listan visar bilagor i containers kontot inte äger, så länge kontot
 * belastas för dem (Beslut 2).
 *
 * Båda riktningarna prövas: en bilaga som BELASTAR kontot syns även om containern
 * är någon annans, och en bilaga som belastar ett ANNAT konto syns inte ens om
 * den ligger i en av kontots containers. `attachment.billed_account_id` är det som
 * avgör.
 */
it('visar bilagor i containers kontot inte äger men belastas för, och inga andra', function () {
    withoutVite();

    [$konto, $anvandare] = lagringsvyKonto();
    [$annatKonto, $annanAnvandare] = lagringsvyKonto();

    $egna = Container::factory()->for($konto, 'account')->create(['name' => 'Havsörnen']);
    $frammande = Container::factory()->for($annatKonto, 'account')->create(['name' => 'Andra varvet']);

    $iFrammande = lagringsvyBilaga($konto, $anvandare, 2048, container: $frammande);
    $iEgen = lagringsvyBilaga($konto, $anvandare, 1024, container: $egna);
    $annans = lagringsvyBilaga($annatKonto, $annanAnvandare, 8192, container: $egna);

    $props = lagringsvyProps($anvandare, $konto);

    expect(array_column($props['attachments'], 'ulid'))->toBe([$iFrammande->ulid, $iEgen->ulid])
        ->and($props['attachments'][0]['container']['name'])->toBe('Andra varvet')
        ->and(array_column($props['attachments'], 'ulid'))->not->toContain($annans->ulid);
});

/*
 * Klart när: en användare som är medlem i flera konton kan byta konto, och
 * listan och siffrorna följer med (Beslut 1).
 *
 * Väljaren listar ALLA hennes konton — att utelämna ett vore att dölja en
 * knapp — och `account` säger alltid vilket konto listan gäller.
 */
it('byter konto och låter listan och förbrukningen följa med', function () {
    withoutVite();

    [$ena, $anvandare] = lagringsvyKonto();
    $andra = Account::factory()->create(['name' => 'Andra varvet']);
    $andra->users()->attach($anvandare, ['role' => 'member']);

    lagringsvyRaknare($ena, 1024);
    lagringsvyRaknare($andra, 2 * 1024 * 1024);
    lagringsvyBilaga($ena, $anvandare, 1024);
    lagringsvyBilaga($andra, $anvandare, 2 * 1024 * 1024);

    $props = lagringsvyProps($anvandare, $ena);

    expect($props['accounts'])->toHaveCount(2)
        ->and($props['account']['ulid'])->toBe($ena->ulid)
        ->and($props['attachments'])->toHaveCount(1)
        ->and($props['usage']['usedLabel'])->toBe('1 KB');

    $props = lagringsvyProps($anvandare, $andra);

    expect($props['account']['ulid'])->toBe($andra->ulid)
        ->and($props['usage']['usedLabel'])->toBe('2 MB');
});

/*
 * Klart när: urvalets frigjorda utrymme och den återstående förbrukningen
 * räknas medan man väljer (Beslut 4).
 *
 * Talet är klientens och bara klientens — det är en förhandsvisning av ett
 * urval som ändrar sig med varje kryss, och därför bor matematiken i
 * storageSelection.js där den går att köra. Summan är en delmängd av raderna
 * som visas, och den återstående förbrukningen är klampad vid noll.
 */
it('räknar urvalets byten och det som återstår i klienten', function () {
    $rader = json_encode([
        ['ulid' => 'A', 'byte_size' => 5],
        ['ulid' => 'B', 'byte_size' => 7],
        ['ulid' => 'C', 'byte_size' => 11],
    ]);

    expect(lagringsvyKor("m.selectedBytes({$rader}, [])"))->toBe('0')
        ->and(lagringsvyKor("m.selectedBytes({$rader}, ['A', 'C'])"))->toBe('16')
        // En ULID som inte finns i listan kan inte räknas in: urvalet är en
        // delmängd av serverns rader.
        ->and(lagringsvyKor("m.selectedBytes({$rader}, ['A', 'Z'])"))->toBe('5')
        ->and(lagringsvyKor('m.remainingBytes(100, 16)'))->toBe('84')
        ->and(lagringsvyKor('m.remainingBytes(10, 16)'))->toBe('0');
});

/*
 * Klart när: fler än 100 valda avvisas begripligt, före eller efter
 * skickandet — aldrig som en halv rensning (Beslut 5).
 *
 * Vyn RESPEKTERAR taket: knappen stängs av och meningen säger både taket och
 * vad hon har valt. Servern nekar ändå hela begäran — `max:100` i den delade
 * RemoveStorageRequest — och ingenting raderas.
 */
it('respekterar taket på 100 valda i vyn och nekar fler på servern', function () {
    withoutVite();

    expect(lagringsvyKor('m.MAX_SELECTION'))->toBe('100')
        ->and(lagringsvyKor('m.exceedsLimit(new Array(100))'))->toBe('false')
        ->and(lagringsvyKor('m.exceedsLimit(new Array(101))'))->toBe('true');

    [$konto, $anvandare] = lagringsvyKonto();
    lagringsvyRaknare($konto, 4096);
    $bilaga = lagringsvyBilaga($konto, $anvandare, 2048);

    // Etthundraen poster i kroppen: taket räknar poster, inte unika ULID:er.
    actingAs($anvandare)
        ->delete("/settings/storage/{$konto->ulid}", ['attachments' => array_fill(0, 101, $bilaga->ulid)])
        ->assertSessionHasErrors('attachments');

    expect($bilaga->fresh()->trashed())->toBeFalse()
        ->and(lagringsvyForbrukning($konto))->toBe(4096);

    $vy = File::get(resource_path('js/components/StorageCleanupSection.vue'));

    expect($vy)->toContain('storage.limit_exceeded')
        ->and($vy)->toContain('MAX_SELECTION');

    lagringsvyNyckel('ui.storage.limit_exceeded');
});

/*
 * Klart när: en rensning mjukraderar de valda bilagorna och minskar kontots
 * förbrukning (Beslut 5 och 8).
 *
 * Bilagorna hamnar i papperskorgen — ingen fysisk radering och ingen tömning —
 * och ITEMEN står kvar: nedgraderingen rör aldrig items, bara bilagor.
 */
it('rensningen mjukraderar de valda bilagorna och minskar kontots förbrukning', function () {
    withoutVite();

    [$konto, $anvandare] = lagringsvyKonto();
    lagringsvyRaknare($konto, 5 * 1024 * 1024);

    $vald = lagringsvyBilaga($konto, $anvandare, 4 * 1024 * 1024);
    $kvar = lagringsvyBilaga($konto, $anvandare, 1024 * 1024);

    actingAs($anvandare)
        ->delete("/settings/storage/{$konto->ulid}", ['attachments' => [$vald->ulid]])
        ->assertRedirect(route('settings.storage', ['account' => $konto->ulid]));

    expect($vald->fresh()->trashed())->toBeTrue()
        ->and($kvar->fresh()->trashed())->toBeFalse()
        ->and(lagringsvyForbrukning($konto))->toBe(1024 * 1024);

    // Itemet finns kvar, och därmed loggen.
    expect(Item::query()->whereKey($vald->item_id)->exists())->toBeTrue();

    // Sidan ritas om ur serverns svar: den valda raden är borta ur listan och
    // sammanfattningen är serverns.
    $props = lagringsvyProps($anvandare, $konto);

    expect(array_column($props['attachments'], 'ulid'))->toBe([$kvar->ulid])
        ->and($props['removed'])->toBe([
            'removed' => 1,
            'storageLabel' => Number::fileSize(1024 * 1024),
        ])
        ->and($props['usage']['usedLabel'])->toBe('1 MB');
});

/*
 * Klart när: förbrukningen efter rensningen kommer ur serverns svar, inte ur
 * klientens subtraktion (Beslut 4 och 8).
 *
 * Någon annan laddar upp 5 MB mellan sidvisningen och rensningen. Klientens
 * förhandsvisning — 10 MB minus de valda 4 — hade svarat 6 MB, och det svaret
 * är fel: räknaren står på 15 MB, och efter rensningen är sanningen 11 MB.
 * Testet bevisar att talet i svaret kommer ur `usage_counter` efter
 * transaktionen.
 */
it('lämnar serverns förbrukning efter rensningen, inte klientens subtraktion', function () {
    withoutVite();

    [$konto, $anvandare] = lagringsvyKonto();
    lagringsvyRaknare($konto, 10 * 1024 * 1024);

    $vald = lagringsvyBilaga($konto, $anvandare, 4 * 1024 * 1024);

    expect(lagringsvyProps($anvandare, $konto)['usage']['usedBytes'])->toBe(10 * 1024 * 1024);

    UsageCounter::query()
        ->where('account_id', $konto->id)
        ->update(['storage_bytes' => 15 * 1024 * 1024]);

    actingAs($anvandare)
        ->delete("/settings/storage/{$konto->ulid}", ['attachments' => [$vald->ulid]])
        ->assertRedirect();

    expect(lagringsvyProps($anvandare, $konto)['removed'])->toBe([
        'removed' => 1,
        'storageLabel' => '11 MB',
    ]);
});

/*
 * Klart när: ett `read_only`-konto kan rensa här (Beslut 7).
 *
 * `AccountPolicy::manageStorage()` har medvetet INGEN read_only-kontroll (29a
 * § Beslut 4): att radera egna bilagor för att komma under kvoten minskar
 * exponeringen i stället för att öka den, och utan den är nedgraderingens steg
 * 2 omöjligt. Det här är den regel som är lättast att "rätta" fel — därför
 * prövas både att handlingen går igenom och att vyn inte har någon grind.
 */
it('låter ett read_only-konto rensa', function () {
    withoutVite();

    [$konto, $anvandare] = lagringsvyKonto();
    lagringsvyRaknare($konto, 3 * 1024 * 1024);
    $bilaga = lagringsvyBilaga($konto, $anvandare, 1024 * 1024);

    $konto->update(['status' => 'read_only', 'read_only_reason' => 'over_quota']);

    // Sidan ritas för ett fryst konto — raden och knappen finns.
    expect(lagringsvyProps($anvandare, $konto)['attachments'])->toHaveCount(1);

    actingAs($anvandare)
        ->delete("/settings/storage/{$konto->ulid}", ['attachments' => [$bilaga->ulid]])
        ->assertRedirect();

    expect($bilaga->fresh()->trashed())->toBeTrue()
        ->and(lagringsvyForbrukning($konto))->toBe(2 * 1024 * 1024);

    // Vyn döljer ingen knapp för ett fruset konto: ingen `read_only`-grind i
    // någon av de två filerna.
    foreach ([
        resource_path('js/pages/Settings/Storage.vue'),
        resource_path('js/components/StorageCleanupSection.vue'),
    ] as $fil) {
        expect(File::get($fil))->not->toContain('read_only');
    }
});

/*
 * Klart när: en användare som inte är medlem i kontot får 403 på båda
 * rutterna (Beslut 1).
 *
 * Grinden prövas mot DET valda kontot i båda fallen — `viewStorage` för
 * listningen, `manageStorage` för rensningen — och ett ULID som inte finns
 * alls är 404, som varje annan rutt med en `{account}`-parameter.
 *
 * Kroppen i DELETE-anropet är giltig med flit: `RemoveStorageRequest`
 * validerar före `Gate::authorize()` i kontrollern, precis som på `/api`, så
 * en begäran som faller på valideringen svarar 422 och aldrig 403.
 */
it('ger 403 för ett konto användaren inte är medlem i, på båda rutterna', function () {
    withoutVite();

    [$mitt, $anvandare] = lagringsvyKonto();
    [$annans, $annansAgare] = lagringsvyKonto();
    $annansBilaga = lagringsvyBilaga($annans, $annansAgare, 2048);

    actingAs($anvandare)
        ->get("/settings/storage?account={$annans->ulid}")
        ->assertForbidden();

    actingAs($anvandare)
        ->delete("/settings/storage/{$annans->ulid}", ['attachments' => [$annansBilaga->ulid]])
        ->assertForbidden();

    expect($annansBilaga->fresh()->trashed())->toBeFalse();

    actingAs($anvandare)
        ->get('/settings/storage?account=01JZZZZZZZZZZZZZZZZZZZZZZZ')
        ->assertNotFound();

    actingAs($anvandare)
        ->delete('/settings/storage/01JZZZZZZZZZZZZZZZZZZZZZZZ', ['attachments' => [$annansBilaga->ulid]])
        ->assertNotFound();

    // Och hennes eget konto svarar som vanligt.
    actingAs($anvandare)
        ->get("/settings/storage?account={$mitt->ulid}")
        ->assertOk();
});

/*
 * Klart när: en ULID som tillhör ett annat konto avvisas av valideringen och
 * raderar ingenting (Beslut 5 och 8).
 *
 * Hela begäran är 422 — aldrig en tyst halv rensning. Den egna ULID:en i samma
 * anrop räddas alltså inte, och räknaren står still.
 */
it('avvisar en ULID som tillhör ett annat konto och raderar ingenting', function () {
    withoutVite();

    [$konto, $anvandare] = lagringsvyKonto();
    [$annatKonto, $annanAnvandare] = lagringsvyKonto();

    lagringsvyRaknare($konto, 4096);
    $min = lagringsvyBilaga($konto, $anvandare, 2048);
    $annans = lagringsvyBilaga($annatKonto, $annanAnvandare, 2048);

    actingAs($anvandare)
        ->delete("/settings/storage/{$konto->ulid}", ['attachments' => [$min->ulid, $annans->ulid]])
        ->assertSessionHasErrors('attachments.1');

    expect($min->fresh()->trashed())->toBeFalse()
        ->and($annans->fresh()->trashed())->toBeFalse()
        ->and(lagringsvyForbrukning($konto))->toBe(4096);

    // En mjukraderad bilaga är inte levande och avvisas på samma sätt: annars
    // vore rensningen en no-op som ändå svarade lyckat.
    $min->delete();

    actingAs($anvandare)
        ->delete("/settings/storage/{$konto->ulid}", ['attachments' => [$min->ulid]])
        ->assertSessionHasErrors('attachments.0');
});

/*
 * Klart när: bekräftelsen nämner papperskorgen och de 30 dagarna, inte
 * permanent radering (Beslut 6), och svaret efteråt gör detsamma (Beslut 8).
 *
 * Bilagorna mjukraderas och kan återställas ur containerns papperskorg (62a);
 * bytena frigörs direkt, och det säger bekräftelsen med antalet filer och det
 * frigjorda utrymmet.
 */
it('säger papperskorgen och de 30 dagarna, aldrig permanent radering', function () {
    foreach (['one', 'many'] as $form) {
        lagringsvyNyckel("ui.storage.confirm.{$form}");
    }

    foreach (['one', 'many', 'none', 'usage'] as $form) {
        lagringsvyNyckel("ui.storage.result.{$form}");
    }

    foreach (['sv' => 'sv', 'en' => 'en'] as $locale) {
        foreach (['confirm.one', 'confirm.many', 'result.one', 'result.many'] as $nyckel) {
            $mening = (string) trans("ui.storage.{$nyckel}", [], $locale);

            expect($mening)->toContain($locale === 'sv' ? '30 dagar' : '30 days')
                ->and(mb_strtolower($mening))->not->toContain('permanent');
        }
    }

    // Och vyn läser dem: bekräftelsen är webbläsarens egen dialog, och raden
    // om papperskorgen ritas i samma komponent.
    $vy = File::get(resource_path('js/components/StorageCleanupSection.vue'));

    expect($vy)->toContain('window.confirm')
        ->and($vy)->toContain('storage.confirm.one')
        ->and($vy)->toContain('storage.confirm.many');
});

/*
 * Klart när: sidan syns i inställningsnavigeringen och länkas från plansidan
 * (Beslut 1).
 *
 * Navigationen renderas ur resources/js/layouts/settingsSections.js — en rad
 * där och ingen ändring i layouten — och etiketten är formulerad på båda
 * språken. Plansidan (66a) länkar hit ur sin förhandsvisning.
 */
it('syns i inställningsnavigationen och länkas från plansidan', function () {
    $sektioner = File::get(resource_path('js/layouts/settingsSections.js'));

    expect($sektioner)->toContain("key: 'storage', href: '/settings/storage'");

    lagringsvyNyckel('ui.settings.nav.storage');

    expect(File::get(resource_path('js/pages/Settings/Plan.vue')))->toContain('href="/settings/storage"');
    expect(File::get(resource_path('js/pages/Settings/Storage.vue')))->toContain('<SettingsLayout>');
});

/*
 * Klart när: varje ny nyckel finns på `sv` och `en` (Beslut 9).
 *
 * Nycklarna under `storage.*` läses av vyn — en nyckel som finns men inte
 * används är en varning ingen ser — och `settings.nav.storage` är radens
 * etikett. Att ingen svensk sträng står kvar i en .vue-fil vaktas av
 * SprakTest.
 */
it('har varje ny nyckel på båda språken och läser dem i vyn', function () {
    foreach ([
        'title', 'heading', 'intro', 'account_label', 'usage_heading',
        'list_heading', 'list_intro', 'empty', 'row.location', 'row.trashed',
        'preview.one', 'preview.many', 'limit_exceeded', 'submit',
        'confirm.one', 'confirm.many',
        'result.one', 'result.many', 'result.none', 'result.usage',
    ] as $nyckel) {
        lagringsvyNyckel("ui.storage.{$nyckel}");
    }

    $vy = File::get(resource_path('js/pages/Settings/Storage.vue'));
    $lista = File::get(resource_path('js/components/StorageCleanupSection.vue'));

    foreach (['title', 'heading', 'intro', 'account_label', 'usage_heading'] as $nyckel) {
        expect($vy)->toContain("storage.{$nyckel}");
    }

    foreach ([
        'list_heading', 'list_intro', 'empty', 'row.location', 'row.trashed',
        'preview.one', 'preview.many', 'submit',
    ] as $nyckel) {
        expect($lista)->toContain("storage.{$nyckel}");
    }

    foreach (['one', 'many', 'none', 'usage'] as $form) {
        expect($vy)->toContain("storage.result.{$form}");
    }

    // Förbrukningen på sidan är serverns tal: den läses ur samma handling som
    // plansidan, och bytena är färdigformaterade.
    expect($vy)->toContain('plan.of')
        ->and($vy)->toContain('plan.limits.storage_bytes');
});

/*
 * Klart när: `/api/accounts/{account}/storage` svarar som förut.
 *
 * Den här issuen lägger sin yta ovanpå samma action och samma resurs, och rör
 * varken API-kontrollern eller rutten. Svitet i
 * tests/Feature/Kvot/NedgraderingTest.php prövar ytan i sin helhet; det här är
 * kvittot på att den fortfarande svarar efter den här ändringen.
 */
it('lämnar api-ytan oförändrad', function () {
    [$konto, $anvandare, $headers] = kontoMedMedlem();
    lagringsvyRaknare($konto, 2048);
    $bilaga = lagringsvyBilaga($konto, $anvandare, 2048);

    $lista = getJson("/api/accounts/{$konto->ulid}/storage", $headers);

    $lista->assertOk();
    expect($lista->json('data.0.ulid'))->toBe($bilaga->ulid)
        ->and($lista->json('data.0.byte_size'))->toBe(2048);
});
