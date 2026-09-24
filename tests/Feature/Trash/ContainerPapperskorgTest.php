<?php

use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Trash\PurgeContainer;
use App\Actions\Trash\PurgeContent;
use App\Console\PurgesExpiredTrash;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 20c · Papperskorgen för hela containers: listan (toppnivå),
 * återställningen och gallringen som tar med sig hela innehållet. Se
 * App\Http\Controllers\Api\ContainerTrashController,
 * App\Http\Requests\Trash\RestoreContainerRequest,
 * App\Actions\Trash\PurgeContainer, App\Console\PurgesExpiredTrash (den
 * femte grenen), routes/api.php, [[ADR-0008 Soft delete och papperskorg]] §
 * Retentionstiden i MVP och config/files.php § trash_retention_days.
 *
 * 20a byggde papperskorgen för innehåll i en container — den rutten är
 * nästlad under en levande container och ser därför aldrig en raderad
 * container. Den här ytan ligger på toppnivå och gäller containers:
 * vem som får se och återställa (Beslut 2 — bara ägarkontots medlemmar),
 * att återställning rör bara container-raden (Beslut 4) och att gallringen
 * tar med sig allt (Beslut 5–8).
 *
 * kontoMedMedlem(), beviljaAccess(), papperskorgsItem(),
 * papperskorgsBilaga() och gallringItem/gallringBilaga/gallringKategori/
 * gallringTagg/gallringStoredFil/gallringKör() är globala testhjälpare i
 * tests/Support/Testhjalpare.php, som Composers autoloader laddar före varje
 * körning.
 *
 * Testerna sätter papperskorgstillståndet direkt med modellerna
 * (containerKorgMjukradera()) i stället för via raderingsrutten — det är
 * själva papperskorgen som är ytan under test, och raderingens egna regler
 * hör hemma i issue 8.
 *
 * "Klart när" (ContainerPapperskorgTest):
 * - listar raderade containers för konton användaren är medlem i
 * - en container ur ett annat konto kommer aldrig med
 * - en delegerad åtkomst ger inte rätt att se listan
 * - svaret bär label, deleted_at och expires_at i samma form som 20a
 * - en utgången container listas inte och kan inte återställas
 * - en container återställs
 * - åtkomster och inbjudningar gäller igen efter återställning
 * - en write-deltagare kan inte återställa
 * - oautentiserad begäran ger 401
 * - en gallrad container tar med sig hela sitt innehåll
 * - gallringen går genom PurgeAttachment
 * - gallringen faller aldrig på ett främmandenyckelfel
 * - en container yngre än retentionen gallras inte
 * - en levande container rörs aldrig
 * - kontot finns kvar efter att dess sista container gallrats
 * - en misslyckad gallring lämnar containern orörd
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Mjukraderar en container genom att sätta deleted_at — samma sluttillstånd
 * som raderingsrutten (SoftDeletes) men med kontrollerad tidpunkt.
 */
function containerKorgMjukradera(Container $container, ?Carbon $deletedAt = null): void
{
    $container->deleted_at = $deletedAt ?? now();
    $container->save();
}

it('listar raderade containers för konton användaren är medlem i', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Havsörnen']);
    containerKorgMjukradera($container);

    $response = getJson('/api/trash/containers', $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.ulid'))->toBe($container->ulid);
});

it('en container ur ett annat konto kommer aldrig med', function () {
    [$account, , $headers] = kontoMedMedlem();
    $min = Container::factory()->for($account, 'account')->create(['name' => 'Min']);
    containerKorgMjukradera($min);

    $deras = Container::factory()->for(Account::factory()->create(), 'account')->create(['name' => 'Deras']);
    containerKorgMjukradera($deras);

    $response = getJson('/api/trash/containers', $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.ulid'))->toBe($min->ulid);
});

it('en delegerad åtkomst ger inte rätt att se listan', function () {
    [, $gäst, $headers] = kontoMedMedlem();
    $annatKonto = Account::factory()->create();
    $container = Container::factory()->for($annatKonto, 'account')->create(['name' => 'Deras']);
    containerKorgMjukradera($container);
    beviljaAccess($container, $gäst, 'write', 'guest');

    $response = getJson('/api/trash/containers', $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

it('svaret bär label, deleted_at och expires_at i samma form som 20a', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');

    try {
        [$account, , $headers] = kontoMedMedlem();
        $container = Container::factory()->for($account, 'account')->create(['name' => 'Havsörnen']);
        $deletedAt = Carbon::parse('2026-08-20 10:00:00', 'UTC');
        containerKorgMjukradera($container, $deletedAt);

        $response = getJson('/api/trash/containers', $headers);

        $response->assertOk();
        $rad = $response->json('data.0');

        expect(array_keys($rad))->toBe(['type', 'ulid', 'label', 'context', 'deleted_at', 'expires_at']);
        expect($rad['type'])->toBe('container');
        expect($rad['ulid'])->toBe($container->ulid);
        expect($rad['label'])->toBe('Havsörnen');
        expect($rad['context'])->toBeNull();

        $retention = (int) config('files.trash_retention_days');
        expect($rad['deleted_at'])->toBe($deletedAt->toIso8601String());
        expect($rad['expires_at'])->toBe($deletedAt->copy()->addDays($retention)->toIso8601String());
    } finally {
        Carbon::setTestNow();
    }
});

it('en utgången container listas inte och kan inte återställas', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');

    try {
        [$account, , $headers] = kontoMedMedlem();
        $retention = (int) config('files.trash_retention_days');

        $utgången = Container::factory()->for($account, 'account')->create(['name' => 'Utgången']);
        containerKorgMjukradera($utgången, now()->subDays($retention + 1));

        $listning = getJson('/api/trash/containers', $headers);
        $listning->assertOk();
        expect($listning->json('data'))->toHaveCount(0);

        $återställning = postJson('/api/trash/containers/restore', [
            'ulid' => $utgången->ulid,
        ], $headers);

        $återställning->assertStatus(404);
        expect($återställning->json('error.code'))->toBe('resource.not_found');
    } finally {
        Carbon::setTestNow();
    }
});

it('en container återställs', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Havsörnen']);

    $item = papperskorgsItem($container, $account, $user, ['name' => 'Motor']);
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'El']);
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);
    $item->tags()->attach($tagg);
    $bilaga = papperskorgsBilaga($item, $account, $user, ['filename' => 'faktura.pdf']);

    containerKorgMjukradera($container);

    $response = postJson('/api/trash/containers/restore', [
        'ulid' => $container->ulid,
    ], $headers);

    $response->assertOk();
    expect($response->json('data'))->toMatchArray([
        'type' => 'container',
        'ulid' => $container->ulid,
        'label' => 'Havsörnen',
        'context' => null,
        'deleted_at' => null,
        'expires_at' => null,
    ]);

    expect(DB::table('container')->where('id', $container->id)->value('deleted_at'))->toBeNull();

    // Innehållet mjukraderades aldrig (Beslut 4) — allt ligger kvar orört
    // och är åtkomligt igen genom den levande containern.
    expect(Item::query()->whereKey($item->id)->exists())->toBeTrue();
    expect(DB::table('item')->where('id', $item->id)->value('deleted_at'))->toBeNull();
    expect(Category::query()->whereKey($kategori->id)->exists())->toBeTrue();
    expect(Tag::query()->whereKey($tagg->id)->exists())->toBeTrue();
    expect(Attachment::query()->whereKey($bilaga->id)->exists())->toBeTrue();
    expect(DB::table('item_tag')->where('item_id', $item->id)->where('tag_id', $tagg->id)->exists())->toBeTrue();

    $visning = getJson("/api/containers/{$container->ulid}", $headers);
    $visning->assertOk();

    $items = getJson("/api/containers/{$container->ulid}/items", $headers);
    $items->assertOk();
    expect($items->json('data.*.ulid'))->toContain($item->ulid);
});

it('åtkomster och inbjudningar gäller igen efter återställning', function () {
    [$ägarkonto, $ägare, $ägareHeaders] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create(['name' => 'Havsörnen']);

    [, $gäst, $gästHeaders] = kontoMedMedlem();
    beviljaAccess($container, $gäst, 'write', 'guest');
    Invitation::factory()->for($container, 'container')->create([
        'invited_by_user_id' => $ägare->id,
    ]);

    containerKorgMjukradera($container);

    // Medan containern ligger i papperskorgen når gästen den inte — rutten
    // ser bara levande rader.
    getJson("/api/containers/{$container->ulid}", $gästHeaders)->assertStatus(404);

    $response = somAnvandare($ägare)
        ->postJson('/api/trash/containers/restore', [
            'ulid' => $container->ulid,
        ], $ägareHeaders);

    $response->assertOk();

    // Raderna rördes aldrig (Beslut 4) och gäller igen: gästen når
    // containern och inbjudan ligger kvar.
    somAnvandare($gäst)->getJson("/api/containers/{$container->ulid}", $gästHeaders)->assertOk();
    expect(ContainerAccess::query()->where('container_id', $container->id)->whereNull('revoked_at')->exists())->toBeTrue();
    expect(Invitation::query()->where('container_id', $container->id)->exists())->toBeTrue();
});

it('en write-deltagare kan inte återställa', function () {
    [$ägarkonto, $ägare, $ägareHeaders] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create(['name' => 'Havsörnen']);
    containerKorgMjukradera($container);

    [, $deltagare, $deltagareHeaders] = kontoMedMedlem();
    beviljaAccess($container, $deltagare, 'write', 'guest');

    $response = postJson('/api/trash/containers/restore', [
        'ulid' => $container->ulid,
    ], $deltagareHeaders);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');

    // Återställningen gick inte hem — containern ligger kvar i papperskorgen.
    expect(DB::table('container')->where('id', $container->id)->whereNotNull('deleted_at')->exists())->toBeTrue();

    // Ägarkontot kan fortfarande återställa.
    $ägarens = somAnvandare($ägare)
        ->postJson('/api/trash/containers/restore', [
            'ulid' => $container->ulid,
        ], $ägareHeaders);
    $ägarens->assertOk();
});

it('oautentiserad begäran ger 401', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    containerKorgMjukradera($container);

    $response = getJson('/api/trash/containers');

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
});

it('en gallrad container tar med sig hela sitt innehåll', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    $kategori = gallringKategori($container, ['name' => 'El']);
    $underKategori = gallringKategori($container, ['name' => 'Startmotor', 'parent_id' => $kategori->id]);
    $tagg = gallringTagg($container);

    $item = gallringItem($container, $account, $user, ['name' => 'Motor', 'category_id' => $kategori->id]);
    $item->tags()->attach($tagg);
    $bilaga = gallringBilaga($item, $account, $user);

    $levandeItem = gallringItem($container, $account, $user, ['name' => 'Levande']);
    ItemLink::factory()->create(['from_item_id' => $item->id, 'to_item_id' => $levandeItem->id, 'relation' => 'parent']);
    ItemLink::factory()->create(['from_item_id' => $levandeItem->id, 'to_item_id' => $item->id, 'relation' => 'parent']);

    $access = beviljaAccess($container, $user, 'read', 'member');
    $inbjudan = Invitation::factory()->for($container, 'container')->create([
        'invited_by_user_id' => $user->id,
    ]);

    containerKorgMjukradera($container, Carbon::parse('2026-08-01 12:00:00'));

    expect(fn () => gallringKör())->not->toThrow(Throwable::class);

    expect(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse();
    expect(Item::withTrashed()->whereKey($item->id)->exists())->toBeFalse();
    expect(Item::withTrashed()->whereKey($levandeItem->id)->exists())->toBeFalse();
    expect(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeFalse();
    expect(Category::withTrashed()->whereKey($kategori->id)->exists())->toBeFalse();
    expect(Category::withTrashed()->whereKey($underKategori->id)->exists())->toBeFalse();
    expect(Tag::withTrashed()->whereKey($tagg->id)->exists())->toBeFalse();
    expect(ContainerAccess::query()->whereKey($access->id)->exists())->toBeFalse();
    expect(Invitation::query()->whereKey($inbjudan->id)->exists())->toBeFalse();
    expect(DB::table('item_tag')->where('item_id', $item->id)->exists())->toBeFalse();
    expect(DB::table('item_tag')->where('tag_id', $tagg->id)->exists())->toBeFalse();
    expect(DB::table('item_link')
        ->where('from_item_id', $item->id)
        ->orWhere('to_item_id', $item->id)
        ->exists())->toBeFalse();
});

it('gallringen går genom PurgeAttachment', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    $item = gallringItem($container, $account, $user, ['name' => 'Gallras']);
    $storedFile = gallringStoredFil(1);
    $bilaga = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'uploaded_by_user_id' => $user->id,
        'billed_account_id' => $account->id,
    ]);

    containerKorgMjukradera($container, Carbon::parse('2026-08-01 12:00:00'));

    gallringKör();

    // Vägen går genom PurgeAttachment (Beslut 5): raden bort på riktigt och
    // reference_count minskas, och vid noll sätts purge_after för 17b.
    expect(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse();
    expect(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeFalse();
    $storedFile->refresh();
    expect($storedFile->reference_count)->toBe(0);
    expect($storedFile->purge_after)->not->toBeNull();
});

it('gallringen faller aldrig på ett främmandenyckelfel', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    // En container med allt påhängt — en bilaga som aldrig mjukraderades,
    // en underkategori, taggar och åtkomster — ska gallras i en enda körning.
    // Fel ordning ger ett främmandenyckelfel som stoppar allt (Beslut 5).
    $kategori = gallringKategori($container, ['name' => 'El']);
    $barn = gallringKategori($container, ['name' => 'Startmotor', 'parent_id' => $kategori->id]);

    $item = gallringItem($container, $account, $user, ['name' => 'Motor', 'category_id' => $kategori->id]);
    $tagg = gallringTagg($container);
    $item->tags()->attach($tagg);
    gallringBilaga($item, $account, $user);

    $annatKonto = Account::factory()->create();
    $annanContainer = Container::factory()->for($annatKonto, 'account')->create(['name' => 'Annan']);
    $levande = gallringItem($annanContainer, $annatKonto, User::factory()->create(), ['name' => 'Levande']);
    ItemLink::factory()->create(['from_item_id' => $item->id, 'to_item_id' => $levande->id, 'relation' => 'parent']);

    beviljaAccess($container, $user, 'read', 'member');

    containerKorgMjukradera($container, Carbon::parse('2026-08-01 12:00:00'));

    expect(fn () => gallringKör())->not->toThrow(Throwable::class);

    expect(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse();
    expect(Item::withTrashed()->whereKey($item->id)->exists())->toBeFalse();
    expect(Category::withTrashed()->whereKey($kategori->id)->exists())->toBeFalse();
    expect(Category::withTrashed()->whereKey($barn->id)->exists())->toBeFalse();
    expect(Tag::withTrashed()->whereKey($tagg->id)->exists())->toBeFalse();
    expect(Attachment::withTrashed()->where('item_id', $item->id)->exists())->toBeFalse();
    expect(DB::table('item_link')
        ->where('from_item_id', $item->id)
        ->orWhere('to_item_id', $item->id)
        ->exists())->toBeFalse();

    // Länkens andra ände och dess container rörs aldrig.
    expect(Item::query()->whereKey($levande->id)->exists())->toBeTrue();
    expect(Container::query()->whereKey($annanContainer->id)->exists())->toBeTrue();
});

it('en container yngre än retentionen gallras inte', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    $item = gallringItem($container, $account, $user, ['name' => 'Kvar']);
    containerKorgMjukradera($container, Carbon::parse('2026-08-20 12:00:00'));

    gallringKör();

    expect(Container::withTrashed()->whereKey($container->id)->exists())->toBeTrue();
    expect(Item::query()->whereKey($item->id)->exists())->toBeTrue();
});

it('en levande container rörs aldrig', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    $item = gallringItem($container, $account, $user, ['name' => 'Levande']);

    gallringKör();

    expect(Container::query()->whereKey($container->id)->exists())->toBeTrue();
    expect(DB::table('container')->where('id', $container->id)->value('deleted_at'))->toBeNull();
    expect(Item::query()->whereKey($item->id)->exists())->toBeTrue();
});

it('kontot finns kvar efter att dess sista container gallrats', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    $account = Account::factory()->create();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Sista']);
    containerKorgMjukradera($container, Carbon::parse('2026-08-01 12:00:00'));

    gallringKör();

    expect(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse();
    expect(Account::query()->whereKey($account->id)->exists())->toBeTrue();
});

it('en misslyckad gallring lämnar containern orörd', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = gallringContainer();

    $första = gallringItem($container, $account, $user, ['name' => 'Först']);
    $trasig = gallringItem($container, $account, $user, ['name' => 'Trasig']);
    containerKorgMjukradera($container, Carbon::parse('2026-08-01 12:00:00'));

    // Ett PurgeContent som kastar på den andra raden — efter att den första
    // hunnit gallras. Beslut 7: containerns transaktion rullar tillbaka ALLT,
    // containern ligger kvar och nästa natt tar om den.
    $purgeContent = new class(new PurgeAttachment) extends PurgeContent
    {
        public function item(Item $item): void
        {
            if ($item->name === 'Trasig') {
                throw new RuntimeException('trasig rad');
            }

            parent::item($item);
        }
    };

    (new PurgesExpiredTrash($purgeContent, new PurgeContainer($purgeContent)))->handle();

    expect(Container::withTrashed()->whereKey($container->id)->exists())->toBeTrue();
    expect(DB::table('container')->where('id', $container->id)->value('deleted_at'))->not->toBeNull();
    expect(Item::withTrashed()->whereKey($första->id)->exists())->toBeTrue();
    expect(Item::withTrashed()->whereKey($trasig->id)->exists())->toBeTrue();
});
