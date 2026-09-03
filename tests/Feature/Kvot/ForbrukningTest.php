<?php

use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Attachment\StoreAttachment;
use App\Actions\Trash\PurgeContainer;
use App\Actions\Trash\PurgeContent;
use App\Actions\Usage\AdjustUsage;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\postJson;

/*
 * Issue 26a · Förbrukningen räknas transaktionellt, aldrig i efterhand. Se
 * App\Actions\Usage\AdjustUsage, App\Models\UsageCounter, [[Planer och
 * kvoter]] § usage_counter och [[Filer och lagring]] § Kvot kontra faktisk
 * lagring.
 *
 * Räknaren är en cache av två frågor (Beslut 2): summan av bytena för
 * kontots levande bilagor och antalet levande containers. Den uppdateras i
 * SAMMA transaktion som den radändring som föranleder det (Beslut 4) — varje
 * gång en bilaga eller container blir levande eller slutar vara levande.
 * Inga kontroller här; de läser räknaren i 27a/27b. Avstämningen som fångar
 * att räknaren driver isär är 26b.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 */

beforeEach(function () {
    Storage::fake('files');
});

/**
 * Ett konto med en ägarmedlem och ett Sanctum-headerpar för henne. Inga
 * containers — tester som rör containerantalet skapar sina containers genom
 * API:et så att räknaren aldrig glider isär från verkligheten.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>}
 */
function forbrukningKonto(): array
{
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $account->users()->attach($user, ['role' => 'owner']);

    $token = $user->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    return [$account, $user, $headers];
}

/**
 * Ett konto, en container och ett item under den, plus headerpar. Containern
 * är skapad med fabriken och har alltså INTE räknats av
 * ContainerController::store — tester som rör containerantalet använder
 * forbrukningKonto() i stället.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item, 4: array<string, string>}
 */
function forbrukningSetup(): array
{
    [$account, $user, $headers] = forbrukningKonto();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    return [$account, $user, $container, $item, $headers];
}

/**
 * En uppladdning genom den enda vägen in (StoreAttachment), med `$account`
 * som belastat konto — den väg en riktig bilaga tar.
 */
function forbrukningLaddaUpp(Item $item, User $user, Account $account, string $innehåll): Attachment
{
    return (new StoreAttachment)->handle(
        $item,
        UploadedFile::fake()->createWithContent('manual.pdf', $innehåll),
        $user,
        $account,
    );
}

/**
 * Räknarraden för ett konto, eller null om kontot aldrig fått en rad.
 */
function forbrukningRad(int $accountId): ?object
{
    return DB::table('usage_counter')->where('account_id', $accountId)->first();
}

it('en uppladdning ökar räknaren med filens byten', function () {
    [$account, $user, $container, $item] = forbrukningSetup();

    $bilaga = forbrukningLaddaUpp($item, $user, $account, 'min manual');

    // Bytena är LOGISK storlek — filens byte_size på stored_file (Beslut 2).
    expect(forbrukningRad($account->id)->storage_bytes)->toBe($bilaga->storedFile->byte_size);
    expect(forbrukningRad($account->id)->container_count)->toBe(0);
});

it('räknarraden skapas vid första uppladdningen', function () {
    [$account, $user, , $item] = forbrukningSetup();

    expect(forbrukningRad($account->id))->toBeNull();

    forbrukningLaddaUpp($item, $user, $account, 'x');

    expect(forbrukningRad($account->id)->storage_bytes)->toBe(1);
});

it('bytena belastar det uppladdande kontot, inte containerns ägare', function () {
    [$kund, $kundUser, , $item] = forbrukningSetup();
    $varv = Account::factory()->create();
    $varvUser = User::factory()->create();

    forbrukningLaddaUpp($item, $varvUser, $varv, 'tio sidor');

    // Varvets räknare växer; kunden har ingen rad — containerns ägare bär
    // aldrig någon annans bilagor (Beslut 4, AGENTS.md § Sådant som är lätt
    // att göra fel).
    expect(forbrukningRad($varv->id)->storage_bytes)->toBe(strlen('tio sidor'));
    expect(forbrukningRad($kund->id))->toBeNull();
});

it('en varvsuppladdning fyller inte kundens gratiskvot', function () {
    [$kund, $kundUser, , $item] = forbrukningSetup();
    // Kunden har redan en räknarrad (den kom med hennes egen första
    // container), med noll bytes.
    UsageCounter::factory()->create(['account_id' => $kund->id]);
    $varv = Account::factory()->create();
    $varvUser = User::factory()->create();

    foreach (range(1, 10) as $i) {
        forbrukningLaddaUpp($item, $varvUser, $varv, "varvets manual $i");
    }

    expect(forbrukningRad($varv->id)->storage_bytes)->toBeGreaterThan(0);
    expect(forbrukningRad($kund->id)->storage_bytes)->toBe(0);
});

it('två bilagor på samma innehåll räknas båda', function () {
    [$account, $user, , $item] = forbrukningSetup();

    $första = forbrukningLaddaUpp($item, $user, $account, 'samma victron-manual');
    $andra = forbrukningLaddaUpp($item, $user, $account, 'samma victron-manual');

    // Dedupen ger en stored_file med reference_count 2 — men kvoten mäter
    // LOGISK storlek: två bilagor räknas båda (Beslut 2).
    expect(StoredFile::count())->toBe(1);
    expect($första->stored_file_id)->toBe($andra->stored_file_id);
    expect(forbrukningRad($account->id)->storage_bytes)->toBe($första->storedFile->byte_size * 2);
});

it('en mjukraderad bilaga räknas inte längre', function () {
    [$account, $user, $container, $item, $headers] = forbrukningSetup();
    $bilaga = forbrukningLaddaUpp($item, $user, $account, 'ska bort');
    $byteSize = $bilaga->storedFile->byte_size;
    expect(forbrukningRad($account->id)->storage_bytes)->toBe($byteSize);

    deleteJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$bilaga->ulid}",
        [],
        $headers,
    )->assertNoContent();

    // Mjukraderingen och minskningen i samma transaktion (Beslut 4).
    expect(forbrukningRad($account->id)->storage_bytes)->toBe(0);
});

it('en återställd bilaga räknas igen', function () {
    [$account, $user, $container, $item, $headers] = forbrukningSetup();
    $bilaga = forbrukningLaddaUpp($item, $user, $account, 'kommer tillbaka');
    $byteSize = $bilaga->storedFile->byte_size;

    deleteJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$bilaga->ulid}",
        [],
        $headers,
    )->assertNoContent();
    expect(forbrukningRad($account->id)->storage_bytes)->toBe(0);

    postJson(
        "/api/containers/{$container->ulid}/trash/restore",
        ['type' => 'attachment', 'ulid' => $bilaga->ulid],
        $headers,
    )->assertOk();

    expect(forbrukningRad($account->id)->storage_bytes)->toBe($byteSize);
});

it('en gallrad bilaga som redan var mjukraderad minskar inte räknaren en andra gång', function () {
    [$account, $user, $container, $item, $headers] = forbrukningSetup();
    $bilaga = forbrukningLaddaUpp($item, $user, $account, 'ska gallras');
    $byteSize = $bilaga->storedFile->byte_size;

    deleteJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$bilaga->ulid}",
        [],
        $headers,
    )->assertNoContent();
    expect(forbrukningRad($account->id)->storage_bytes)->toBe(0);

    // 30 dagar senare gallras bilagan — minskningen skedde redan vid
    // mjukraderingen, ett andra avdrag vore dubbelräkning (Beslut 6).
    (new PurgeAttachment)->handle($bilaga);

    expect(forbrukningRad($account->id)->storage_bytes)->toBe(0);
    expect(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeFalse();
});

it('en gallrad container tar bort sina levande bilagors byten ur räkningen', function () {
    [$account, $user, $headers] = forbrukningKonto();

    $created = postJson('/api/containers', [
        'name' => 'Vindil',
        'kind' => 'boat',
        'account' => $account->ulid,
    ], $headers)->assertCreated();
    $container = Container::where('ulid', $created->json('data.ulid'))->firstOrFail();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $bilaga = forbrukningLaddaUpp($item, $user, $account, 'lever tills containern gallras');
    $byteSize = $bilaga->storedFile->byte_size;
    expect(forbrukningRad($account->id)->container_count)->toBe(1);
    expect(forbrukningRad($account->id)->storage_bytes)->toBe($byteSize);

    // PurgeContainer på en LEVANDE container: bilagan mjukraderades aldrig,
    // så hennes byten finns bara i räkningen genom den här gallringen (Beslut
    // 6). Containern själv var också levande — samma regel för containerantalet.
    $purgeContent = new PurgeContent(new PurgeAttachment);
    (new PurgeContainer($purgeContent))->handle($container);

    expect(forbrukningRad($account->id)->storage_bytes)->toBe(0);
    expect(forbrukningRad($account->id)->container_count)->toBe(0);
    expect(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeFalse();
});

it('ett mjukraderat item ändrar inte räknaren', function () {
    [$account, $user, $container, $item, $headers] = forbrukningSetup();
    $bilaga = forbrukningLaddaUpp($item, $user, $account, 'lever under itemet');
    $byteSize = $bilaga->storedFile->byte_size;

    deleteJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}",
        [],
        $headers,
    )->assertNoContent();

    // Att radera itemet sätter bara itemets deleted_at — bilagan är
    // fortfarande levande och räknas fortfarande (Beslut 5).
    expect(forbrukningRad($account->id)->storage_bytes)->toBe($byteSize);
    expect($item->refresh()->trashed())->toBeTrue();
    expect($bilaga->refresh()->trashed())->toBeFalse();
});

it('containerräknaren följer skapande, mjukradering och återställning', function () {
    [$account, $user, $headers] = forbrukningKonto();

    $created = postJson('/api/containers', [
        'name' => 'Vindil',
        'kind' => 'boat',
        'account' => $account->ulid,
    ], $headers);
    $created->assertCreated();
    $containerUlid = $created->json('data.ulid');

    expect(forbrukningRad($account->id)->container_count)->toBe(1);

    deleteJson("/api/containers/{$containerUlid}", [], $headers)->assertNoContent();
    expect(forbrukningRad($account->id)->container_count)->toBe(0);

    postJson('/api/trash/containers/restore', ['ulid' => $containerUlid], $headers)->assertOk();
    expect(forbrukningRad($account->id)->container_count)->toBe(1);
});

it('räknaren går aldrig under noll', function () {
    $account = Account::factory()->create();
    (new AdjustUsage)->handle($account->id, bytesDelta: 100);

    // En minskning större än saldot klampar vid noll — den wrappar inte till
    // ett gigantiskt UNSIGNED-tal (Beslut 3).
    (new AdjustUsage)->handle($account->id, bytesDelta: -1000);
    expect(forbrukningRad($account->id)->storage_bytes)->toBe(0);

    // Samma sak för ett konto utan rad och för containerantalet.
    $nytt = Account::factory()->create();
    (new AdjustUsage)->handle($nytt->id, containersDelta: -3);
    expect(forbrukningRad($nytt->id)->storage_bytes)->toBe(0);
    expect(forbrukningRad($nytt->id)->container_count)->toBe(0);
});

it('räknaren ändras i samma transaktion som raden', function () {
    [$account, $user, $container, $item] = forbrukningSetup();

    try {
        DB::transaction(function () use ($account, $user, $item): void {
            forbrukningLaddaUpp($item, $user, $account, 'rullas tillbaka');

            // Simulerar att den som anropar StoreAttachment avbryts efter
            // uppladdningen: raden och räknaren hänger ihop och måste rullas
            // tillbaka tillsammans (Beslut 3). StoreAttachments egen
            // DB::transaction är en sparad punkt i den här yttre.
            throw new RuntimeException('avbryt efter uppladdningen');
        });
    } catch (RuntimeException) {
        // Förväntat — transaktionen avbröts.
    }

    expect(forbrukningRad($account->id))->toBeNull();
    expect(Attachment::count())->toBe(0);
    expect(StoredFile::count())->toBe(0);
});

it('räknaren stämmer med sanningsfrågan efter en blandad följd av händelser', function () {
    [$account, $user, $headers] = forbrukningKonto();

    $created = postJson('/api/containers', [
        'name' => 'Vindil',
        'kind' => 'boat',
        'account' => $account->ulid,
    ], $headers)->assertCreated();
    $container = Container::where('ulid', $created->json('data.ulid'))->firstOrFail();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    $attA = forbrukningLaddaUpp($item, $user, $account, 'a');
    $attB = forbrukningLaddaUpp($item, $user, $account, 'a'); // dedup, räknas ändå
    $attC = forbrukningLaddaUpp($item, $user, $account, 'ccc');
    expect(forbrukningRad($account->id)->storage_bytes)->toBe(5);

    // Mjukradera attA, återställ den.
    deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$attA->ulid}", [], $headers)->assertNoContent();
    expect(forbrukningRad($account->id)->storage_bytes)->toBe(4);
    postJson("/api/containers/{$container->ulid}/trash/restore", ['type' => 'attachment', 'ulid' => $attA->ulid], $headers)->assertOk();
    expect(forbrukningRad($account->id)->storage_bytes)->toBe(5);

    // Mjukradera attC och gallra den — minskningen sker en gång.
    deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$attC->ulid}", [], $headers)->assertNoContent();
    expect(forbrukningRad($account->id)->storage_bytes)->toBe(2);
    (new PurgeAttachment)->handle($attC);
    expect(forbrukningRad($account->id)->storage_bytes)->toBe(2);

    // Mjukradera och återställ containern — innehållet påverkas inte.
    deleteJson("/api/containers/{$container->ulid}", [], $headers)->assertNoContent();
    expect(forbrukningRad($account->id)->container_count)->toBe(0);
    expect(forbrukningRad($account->id)->storage_bytes)->toBe(2);
    postJson('/api/trash/containers/restore', ['ulid' => $container->ulid], $headers)->assertOk();
    expect(forbrukningRad($account->id)->container_count)->toBe(1);

    // Mjukradera itemet — bilagorna under det är fortfarande levande.
    deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [], $headers)->assertNoContent();
    expect(forbrukningRad($account->id)->storage_bytes)->toBe(2);

    // Räknaren står på exakt samma tal som sanningsfrågorna formulerar
    // (Beslut 2) — attA och attB lever, attC är borta, containern lever.
    expect(forbrukningRad($account->id)->storage_bytes)->toBe(UsageCounter::calculateStorageBytes($account->id));
    expect(forbrukningRad($account->id)->container_count)->toBe(UsageCounter::calculateContainerCount($account->id));
    expect(UsageCounter::calculateStorageBytes($account->id))->toBe(2);
    expect(UsageCounter::calculateContainerCount($account->id))->toBe(1);
});
