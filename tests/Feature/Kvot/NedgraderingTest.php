<?php

use App\Actions\Attachment\StoreAttachment;
use App\Actions\Plan\StartDowngrade;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 28 · Nedgraderingen, steg 1 och 2. Se
 * App\Actions\Plan\StartDowngrade, App\Actions\Attachment\TrashAttachment,
 * App\Http\Controllers\Api\AccountStorageController, App\Policies\AccountPolicy,
 * routes/api.php, [[Planer och kvoter]] § Nedgradering och
 * [[ADR-0009 Kvoter och livscykel]].
 *
 * Förloppets fem steg delas i två sessioner. Den här bygger steg 1
 * (övergången till `read_only` med tre månaders frist) och steg 2 (listan
 * användaren väljer ur — hennes bilagor sorterade på storlek — och
 * rensningen medan kontot är fruset). Steg 3–5 (fristen som löper ut, den
 * automatiska raderingen nyast först och återgången till `active`) är issue
 * 28b.
 *
 * Det centrala beslutet: items raderas aldrig, bara bilagor (ADR-0009).
 * Rensningen är tillåten fastän kontot är `read_only` — det är hela poängen
 * (Beslut 4) — och går genom TrashAttachment, samma mjukradering som den
 * vanliga containerrutten (Beslut 5).
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 */

beforeEach(function () {
    Storage::fake('files');
});

/**
 * Skapar en container och ett item under den, med $user/$account som
 * skapare — fabrikens egna default-skapare hade annars skapat två
 * ovidkommande användare/konton per item.
 *
 * @return array{0: Container, 1: Item}
 */
function nedgraderingContainerItem(Account $account, User $user, string $containerNamn = 'Vindil'): array
{
    $container = Container::factory()->for($account, 'account')->create(['name' => $containerNamn]);
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    return [$container, $item];
}

/**
 * Skapar en bilaga direkt på itemet med en stored_file av exakt storlek —
 * listan sorterar på `stored_file.byte_size`, så testerna måste kunna styra
 * den.
 */
function nedgraderingBilagaMedStorlek(Item $item, Account $account, User $user, int $byteSize, array $attribut = []): Attachment
{
    $storedFile = StoredFile::factory()->create(['byte_size' => $byteSize]);

    return Attachment::factory()->for($item, 'item')->create(array_merge([
        'stored_file_id' => $storedFile->id,
        'filename' => 'semester-2024.jpg',
        'kind' => 'image',
        'uploaded_by_user_id' => $user->id,
        'billed_account_id' => $account->id,
    ], $attribut));
}

/**
 * En uppladdning genom den enda vägen in (StoreAttachment), med `$account`
 * som belastat konto — den väg en riktig bilaga tar. Räknaren följer med i
 * samma transaktion (issue 26a).
 */
function nedgraderingLaddaUpp(Item $item, User $user, Account $account, string $innehåll): Attachment
{
    return app(StoreAttachment::class)->handle(
        $item,
        UploadedFile::fake()->createWithContent('manual.pdf', $innehåll),
        $user,
        $account,
    );
}

/**
 * Kontots lagrade byten i räknaren — samma avläsning som rensningsrutten
 * gör när den bygger sitt svar.
 */
function nedgraderingStorageBytes(int $accountId): int
{
    return (int) (DB::table('usage_counter')->where('account_id', $accountId)->value('storage_bytes') ?? 0);
}

it('nedgradering sätter kontot i read_only med skäl', function () {
    $account = Account::factory()->create();

    (new StartDowngrade)->handle($account, 'over_quota');

    $account->refresh();
    expect($account->status)->toBe('read_only');
    expect($account->read_only_reason)->toBe('over_quota');
});

it('nedgradering ger tre månaders frist på prenumerationen', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');

    try {
        $account = Account::factory()->create();
        $subscription = Subscription::factory()->for($account, 'account')->create(['grace_until' => null]);

        (new StartDowngrade)->handle($account, 'payment_failed');

        $subscription->refresh();
        expect($subscription->grace_until?->toIso8601String())->toBe('2026-12-04T12:00:00+00:00');
    } finally {
        Carbon::setTestNow();
    }
});

it('en andra nedgradering förlänger inte fristen', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');

    try {
        $account = Account::factory()->create();
        $subscription = Subscription::factory()->for($account, 'account')->create(['grace_until' => null]);

        (new StartDowngrade)->handle($account, 'payment_failed');
        $förstaFrist = $subscription->refresh()->grace_until;

        Carbon::setTestNow('2026-10-01 12:00:00');
        (new StartDowngrade)->handle($account->refresh(), 'payment_failed');

        // Idempotensen är inte kosmetisk: ett förlopp som startas om varje
        // natt är ett förlopp som aldrig når steg 4.
        expect($subscription->refresh()->grace_until?->toIso8601String())
            ->toBe($förstaFrist?->toIso8601String());
    } finally {
        Carbon::setTestNow();
    }
});

it('ett konto utan prenumeration kan nedgraderas utan att en rad skapas', function () {
    $account = Account::factory()->create();

    (new StartDowngrade)->handle($account, 'over_quota');

    expect($account->refresh()->status)->toBe('read_only');
    expect(Subscription::query()->where('account_id', $account->id)->exists())->toBeFalse();
});

it('listan visar kontots levande bilagor sorterade på storlek fallande', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    [, $item] = nedgraderingContainerItem($account, $user, 'Mimer');

    // Två bilagor delar byte_size med flit: sorteringen är byte_size fallande
    // och vid lika storlek attachment.id fallande — andrasorteringen som gör
    // ordningen deterministisk (granskningsfynd 6). Utan den vore ordningen
    // mellan $äldreLiten och $yngreLiten odefinierad och testet flaxigt.
    $äldreLiten = nedgraderingBilagaMedStorlek($item, $account, $user, 100);
    $stor = nedgraderingBilagaMedStorlek($item, $account, $user, 300);
    $yngreLiten = nedgraderingBilagaMedStorlek($item, $account, $user, 100);

    $response = getJson("/api/accounts/{$account->ulid}/storage", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
    expect(collect($response->json('data'))->pluck('ulid')->all())->toBe([
        $stor->ulid,
        $yngreLiten->ulid,
        $äldreLiten->ulid,
    ]);
});

it('listan går över containergränser', function () {
    [$konto, $user, $headers] = kontoMedMedlem();
    [, $egetItem] = nedgraderingContainerItem($konto, $user, 'Egen');

    $annanÄgare = Account::factory()->create();
    $annanAnvandare = User::factory()->create();
    [, $annansItem] = nedgraderingContainerItem($annanÄgare, $annanAnvandare, 'Deras båt');

    $egen = nedgraderingBilagaMedStorlek($egetItem, $konto, $user, 100);
    $iDeras = nedgraderingBilagaMedStorlek($annansItem, $konto, $user, 250);

    $response = getJson("/api/accounts/{$konto->ulid}/storage", $headers);

    // Kontot är det som belastas, och bilagor kan ligga i containers kontot
    // inte äger — båda är med i urvalslistan.
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    expect(collect($response->json('data'))->pluck('ulid')->all())->toContain($egen->ulid);
    expect(collect($response->json('data'))->pluck('ulid')->all())->toContain($iDeras->ulid);
    expect(collect($response->json('data'))->firstWhere('ulid', $iDeras->ulid)['container']['name'])->toBe('Deras båt');
});

it('listan visar inte bilagor betalda av ett annat konto', function () {
    [$konto, $user, $headers] = kontoMedMedlem();
    [, $item] = nedgraderingContainerItem($konto, $user, 'Egen');

    $annatKonto = Account::factory()->create();
    $annanAnvandare = User::factory()->create();
    [, $annatItem] = nedgraderingContainerItem($annatKonto, $annanAnvandare, 'Deras');

    $egen = nedgraderingBilagaMedStorlek($item, $konto, $user, 100);
    nedgraderingBilagaMedStorlek($annatItem, $annatKonto, $annanAnvandare, 400);

    $response = getJson("/api/accounts/{$konto->ulid}/storage", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.ulid'))->toBe($egen->ulid);
});

it('listan visar inte mjukraderade bilagor', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    [, $item] = nedgraderingContainerItem($account, $user, 'Mimer');

    $levande = nedgraderingBilagaMedStorlek($item, $account, $user, 100);
    $raderad = nedgraderingBilagaMedStorlek($item, $account, $user, 200);
    $raderad->delete();

    $response = getJson("/api/accounts/{$account->ulid}/storage", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.ulid'))->toBe($levande->ulid);
});

it('listan bär container och item per rad och aldrig ett löpnummer', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    [$container, $item] = nedgraderingContainerItem($account, $user, 'Mimer');
    nedgraderingBilagaMedStorlek($item, $account, $user, 100, ['filename' => 'semester-2024-0412.jpg']);

    $response = getJson("/api/accounts/{$account->ulid}/storage", $headers);

    $response->assertOk();
    $rad = $response->json('data.0');

    expect(array_keys($rad))->toBe(['ulid', 'filename', 'byte_size', 'kind', 'container', 'item', 'created_at']);
    expect($rad['kind'])->toBe('image');
    expect($rad['container'])->toMatchArray([
        'ulid' => $container->ulid,
        'name' => 'Mimer',
    ]);
    expect($rad['item'])->toMatchArray([
        'ulid' => $item->ulid,
        'name' => $item->name,
    ]);
    expect($rad)->not->toHaveKey('id');
    expect($rad)->not->toHaveKey('item_id');
    expect($rad)->not->toHaveKey('stored_file_id');
    expect($rad)->not->toHaveKey('billed_account_id');
});

it('listningen gör ett konstant antal frågor', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');

    try {
        [$account, $user, $headers] = kontoMedMedlem();
        [, $item] = nedgraderingContainerItem($account, $user, 'Mimer');

        foreach (range(1, 3) as $i) {
            nedgraderingBilagaMedStorlek($item, $account, $user, $i);
        }

        $url = "/api/accounts/{$account->ulid}/storage";

        // Frys tiden runt mätningarna så UpdateLastActiveAt skriver
        // deterministiskt (issue 80), samma teknik som DeltagarlistaTest.
        getJson($url, $headers)->assertOk();

        DB::enableQueryLog();
        $förstaSvaret = getJson($url, $headers);
        $frågorMedTrePoster = count(DB::getQueryLog());
        DB::flushQueryLog();

        $förstaSvaret->assertOk();
        expect($förstaSvaret->json('data'))->toHaveCount(3);

        foreach (range(1, 10) as $i) {
            nedgraderingBilagaMedStorlek($item, $account, $user, $i + 100);
        }
        DB::flushQueryLog(); // fabrikernas egna INSERT-frågor ska inte mätas

        $andraSvaret = getJson($url, $headers);
        $frågorMedTrettonPoster = count(DB::getQueryLog());
        DB::disableQueryLog();

        $andraSvaret->assertOk();
        expect($andraSvaret->json('data'))->toHaveCount(13);

        expect($frågorMedTrettonPoster)->toBe($frågorMedTrePoster);
    } finally {
        Carbon::setTestNow();
    }
});

it('en användare utan medlemskap i kontot nekas', function () {
    [$främmande, $främmandeUser] = kontoMedMedlem();
    [, $item] = nedgraderingContainerItem($främmande, $främmandeUser, 'Deras');
    $bilaga = nedgraderingBilagaMedStorlek($item, $främmande, $främmandeUser, 100);

    // En användare som ÄR medlem i ett annat konto, men inte i det här.
    [, , $inkräktareHeaders] = kontoMedMedlem();

    $lista = getJson("/api/accounts/{$främmande->ulid}/storage", $inkräktareHeaders);
    $lista->assertStatus(403);
    expect($lista->json('error.code'))->toBe('auth.forbidden');

    $rensning = deleteJson("/api/accounts/{$främmande->ulid}/storage", [
        'attachments' => [$bilaga->ulid],
    ], $inkräktareHeaders);
    $rensning->assertStatus(403);
    expect($rensning->json('error.code'))->toBe('auth.forbidden');
});

it('oautentiserad begäran ger 401', function () {
    $account = Account::factory()->create();

    $lista = getJson("/api/accounts/{$account->ulid}/storage");
    $lista->assertStatus(401);
    expect($lista->json('error.code'))->toBe('auth.unauthenticated');

    $rensning = deleteJson("/api/accounts/{$account->ulid}/storage", [
        'attachments' => ['01J00000000000000000000000'],
    ]);
    $rensning->assertStatus(401);
    expect($rensning->json('error.code'))->toBe('auth.unauthenticated');
});

it('ett read_only-konto får rensa sina bilagor', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    [$container, $item] = nedgraderingContainerItem($account, $user, 'Vindil');
    $bilaga = nedgraderingLaddaUpp($item, $user, $account, 'semesterbilder 2024');
    $byteSize = $bilaga->storedFile->byte_size;
    expect(nedgraderingStorageBytes($account->id))->toBe($byteSize);

    $account->status = 'read_only';
    $account->read_only_reason = 'payment_failed';
    $account->save();

    // Regel 4 spärrar containervägen: den vanliga raderingen nekas för ett
    // fruset konto.
    $containervägen = deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$bilaga->ulid}", [], $headers);
    $containervägen->assertStatus(403);

    // Men storage-ytan är ett andra dokumenterat undantag till regel 4 —
    // att rensa egna bilagor minskar exponeringen i stället för att öka den.
    $rensning = deleteJson("/api/accounts/{$account->ulid}/storage", [
        'attachments' => [$bilaga->ulid],
    ], $headers);

    $rensning->assertOk();
    expect(DB::table('attachment')->where('id', $bilaga->id)->value('deleted_at'))->not->toBeNull();
    expect(nedgraderingStorageBytes($account->id))->toBe(0);
});

it('rensningen mjukraderar och minskar förbrukningen', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    [, $item] = nedgraderingContainerItem($account, $user, 'Vindil');
    $bilaga = nedgraderingLaddaUpp($item, $user, $account, 'kvitto på impellerbytet');
    $byteSize = $bilaga->storedFile->byte_size;
    expect(nedgraderingStorageBytes($account->id))->toBe($byteSize);

    $response = deleteJson("/api/accounts/{$account->ulid}/storage", [
        'attachments' => [$bilaga->ulid],
    ], $headers);

    $response->assertOk();
    expect(DB::table('attachment')->where('id', $bilaga->id)->value('deleted_at'))->not->toBeNull();
    expect(nedgraderingStorageBytes($account->id))->toBe(0);
});

it('rensade bilagor hamnar i papperskorgen och kan återställas', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    [$container, $item] = nedgraderingContainerItem($account, $user, 'Vindil');
    $bilaga = nedgraderingLaddaUpp($item, $user, $account, 'fick ångra mig');
    $byteSize = $bilaga->storedFile->byte_size;

    deleteJson("/api/accounts/{$account->ulid}/storage", [
        'attachments' => [$bilaga->ulid],
    ], $headers)->assertOk();

    expect(DB::table('attachment')->where('id', $bilaga->id)->value('deleted_at'))->not->toBeNull();
    expect(nedgraderingStorageBytes($account->id))->toBe(0);

    // Papperskorgen (20a) kan återställa den — ett förhastat val under en
    // fryst månad ska gå att ångra (Beslut 5).
    $återställning = postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'attachment',
        'ulid' => $bilaga->ulid,
    ], $headers);

    $återställning->assertOk();
    expect(DB::table('attachment')->where('id', $bilaga->id)->value('deleted_at'))->toBeNull();
    expect(nedgraderingStorageBytes($account->id))->toBe($byteSize);
});

it('rensningen raderar aldrig ett item', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    [$container, $item] = nedgraderingContainerItem($account, $user, 'Vindil');
    $bilaga = nedgraderingLaddaUpp($item, $user, $account, 'loggen över impellerbyte');

    deleteJson("/api/accounts/{$account->ulid}/storage", [
        'attachments' => [$bilaga->ulid],
    ], $headers)->assertOk();

    // Metadatan är mikroskopisk, filerna är det som kostar — itemet står
    // kvar med "manual saknas", och användaren har en anledning att komma
    // tillbaka (ADR-0009).
    expect(Item::query()->whereKey($item->id)->exists())->toBeTrue();
    expect(DB::table('item')->where('id', $item->id)->value('deleted_at'))->toBeNull();
    expect(Container::query()->whereKey($container->id)->exists())->toBeTrue();
});

it('en bilaga som tillhör ett annat konto avvisas', function () {
    [$konto, $kontoUser, $kontoHeaders] = kontoMedMedlem();
    [, $kontoItem] = nedgraderingContainerItem($konto, $kontoUser, 'Eget');

    $annatKonto = Account::factory()->create();
    $annanAnvandare = User::factory()->create();
    [, $annatItem] = nedgraderingContainerItem($annatKonto, $annanAnvandare, 'Deras');
    $främmande = nedgraderingBilagaMedStorlek($annatItem, $annatKonto, $annanAnvandare, 100);

    $response = deleteJson("/api/accounts/{$konto->ulid}/storage", [
        'attachments' => [$främmande->ulid],
    ], $kontoHeaders);

    // 422 för HELA begäran — aldrig en tyst delvis radering (Beslut 6).
    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect(DB::table('attachment')->where('id', $främmande->id)->value('deleted_at'))->toBeNull();
});

it('svaret bär antalet borttagna och kontots förbrukning efteråt', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    [, $item] = nedgraderingContainerItem($account, $user, 'Vindil');

    $första = nedgraderingLaddaUpp($item, $user, $account, 'fyrtio semesterbilder');
    $andra = nedgraderingLaddaUpp($item, $user, $account, 'besiktningsprotokollet');
    $tredje = nedgraderingLaddaUpp($item, $user, $account, 'får ligga kvar');

    $response = deleteJson("/api/accounts/{$account->ulid}/storage", [
        'attachments' => [$första->ulid, $andra->ulid],
    ], $headers);

    $response->assertOk();
    expect($response->json('data'))->toMatchArray([
        'removed' => 2,
        'storage_bytes' => $tredje->storedFile->byte_size,
    ]);
});

it('den vanliga bilageraderingen fungerar oförändrat', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    [$container, $item] = nedgraderingContainerItem($account, $user, 'Vindil');
    $bilaga = nedgraderingLaddaUpp($item, $user, $account, 'raderas den gamla vägen');
    $byteSize = $bilaga->storedFile->byte_size;

    $response = deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$bilaga->ulid}", [], $headers);

    // 204 och samma räkning som före omskrivningen (Beslut 5) — beteendet
    // för den befintliga rutten är oförändrat.
    $response->assertNoContent();
    expect(DB::table('attachment')->where('id', $bilaga->id)->value('deleted_at'))->not->toBeNull();
    expect(nedgraderingStorageBytes($account->id))->toBe(0);
});
