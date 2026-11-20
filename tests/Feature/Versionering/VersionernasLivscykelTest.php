<?php

use App\Actions\Account\DeleteAccount;
use App\Actions\Container\CreateContainer;
use App\Actions\Item\CreateItem;
use App\Actions\Trash\PurgeContainer;
use App\Actions\Trash\PurgeContent;
use App\Console\ReconcilesUsageCounters;
use App\Jobs\TrimItemVersions;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemVersion;
use App\Models\LegalHold;
use App\Models\User;
use App\Support\Usage\CountedRows;
use App\Support\Usage\MetadataSize;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 414 · Versionernas livscykel, se [[ADR-0088 Versionshistorik för
 * items]] Riktning 6.
 *
 * Ett sänkt tak trimmar historiken och frigör kvoten, ett slängt item
 * behåller sina versioner till dess att det raderas för gott, en höjning
 * rör ingenting, och en rättslig spärr stoppar raderingen medan listan
 * fortsätter visa bara taket.
 *
 * Hjälparna har prefixet `livs` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en medlem och ett valt tak för versionshistoriken.
 *
 * @return array{0: Account, 1: User}
 */
function livsKonto(int $tak = 5): array
{
    $konto = Account::factory()->create(['versioning_max_versions' => $tak]);
    $användare = User::factory()->create();
    $konto->users()->attach($användare, ['role' => 'owner']);

    return [$konto, $användare];
}

/**
 * En container (skapad genom CreateContainer) i kontot.
 */
function livsContainer(Account $konto, User $användare, ?int $tak = null): Container
{
    $container = app(CreateContainer::class)->handle($användare, $konto, 'Båten', 'boat', null);

    if ($tak !== null) {
        $container->versioning_max_versions = $tak;
        $container->save();
    }

    return $container;
}

/**
 * Ett item skapat genom CreateItem, så att räknaren följer med.
 */
function livsItem(Container $container, Account $konto, User $användare, string $namn = 'Motor'): Item
{
    return app(CreateItem::class)->handle(
        $container,
        $konto,
        $användare,
        new Item(['name' => $namn]),
        null,
        collect(),
        null,
    );
}

/**
 * Skapar versioner med unika `created_at`, äldst först — fabriken rör inte
 * räknaren, så livsRakna() stämmer av den efteråt.
 *
 * @return list<ItemVersion> Äldst först.
 */
function livsVersioner(Item $item, int $antal): array
{
    $start = now();
    $versioner = [];

    for ($i = 0; $i < $antal; $i++) {
        Carbon::setTestNow($start->copy()->subMinutes($antal - $i));

        $versioner[] = ItemVersion::factory()->for($item, 'item')->create([
            'name' => 'Version '.($i + 1),
            'replaced_at' => now(),
        ]);
    }

    Carbon::setTestNow();

    return $versioner;
}

/**
 * Räknar om `usage_counter` så att raderna (och de fabriksskapade
 * versionerna) är sanningen — samma vakt som ItemVersionKvotTest använder.
 */
function livsRakna(): void
{
    (new ReconcilesUsageCounters)->handle();
}

/**
 * Kontots `metadata_bytes` ur räknaren.
 */
function livsMetadata(Account $konto): int
{
    return (int) DB::table('usage_counter')
        ->where('account_id', $konto->id)
        ->value('metadata_bytes');
}

/**
 * Räknaren är lika med avstämningens tal för kontona.
 */
function livsStammer(Account ...$konton): void
{
    $räknat = CountedRows::bytesPerAccount();

    foreach ($konton as $konto) {
        expect(livsMetadata($konto))->toBe($räknat[$konto->id] ?? 0);
    }
}

/**
 * En API-token för användaren.
 *
 * @return array<string, string>
 */
function livsHeaders(User $användare): array
{
    return ['Authorization' => 'Bearer '.$användare->createToken('api')->plainTextToken];
}

it('trimmar varje item när kontots tak sänks och frigör kvoten', function () {
    [$konto, $användare] = livsKonto(5);
    $container = livsContainer($konto, $användare);
    $första = livsItem($container, $konto, $användare, 'Första');
    $andra = livsItem($container, $konto, $användare, 'Andra');

    $förstaVersioner = livsVersioner($första, 5);
    $andraVersioner = livsVersioner($andra, 4);
    livsRakna();

    $före = livsMetadata($konto);

    // Första itemet behåller de två nyaste, andra också — så de tre äldsta
    // av fem och de två äldsta av fyra tas bort.
    $borttagnaVikt = MetadataSize::of($förstaVersioner[0])
        + MetadataSize::of($förstaVersioner[1])
        + MetadataSize::of($förstaVersioner[2])
        + MetadataSize::of($andraVersioner[0])
        + MetadataSize::of($andraVersioner[1]);

    Queue::fake();

    actingAs($användare)
        ->patch('/settings/storage/versioning', [
            'account' => $konto->ulid,
            'versioning_max_versions' => 2,
        ])
        ->assertRedirect(route('settings.storage', ['account' => $konto->ulid]));

    Queue::assertPushed(
        TrimItemVersions::class,
        fn (TrimItemVersions $jobb): bool => $jobb->accountId === $konto->id,
    );

    TrimItemVersions::forAccount($konto->id)->handle();

    expect(ItemVersion::query()->where('item_id', $första->id)->count())->toBe(2)
        ->and(ItemVersion::query()->where('item_id', $första->id)->orderByDesc('created_at')->pluck('name')->all())
        ->toBe(['Version 5', 'Version 4'])
        ->and(ItemVersion::query()->where('item_id', $andra->id)->count())->toBe(2);

    expect(livsMetadata($konto))->toBe($före - $borttagnaVikt);

    // Idempotent: en omkörning hittar inget överskott.
    TrimItemVersions::forAccount($konto->id)->handle();

    expect(ItemVersion::query()->where('item_id', $första->id)->count())->toBe(2)
        ->and(livsMetadata($konto))->toBe($före - $borttagnaVikt);

    livsStammer($konto);
});

it('tar bara versionerna i den container som sätts till noll', function () {
    sättPlangräns('free', 'containers', 10);

    [$konto, $användare] = livsKonto(5);
    $a = livsContainer($konto, $användare);
    $b = livsContainer($konto, $användare);

    $itemA = livsItem($a, $konto, $användare, 'A');
    $itemB = livsItem($b, $konto, $användare, 'B');

    livsVersioner($itemA, 3);
    livsVersioner($itemB, 3);
    livsRakna();

    Queue::fake();

    actingAs($användare)
        ->patch("/containers/{$a->ulid}/versioning", ['versioning_max_versions' => 0])
        ->assertRedirect(route('containers.edit', $a));

    Queue::assertPushed(
        TrimItemVersions::class,
        fn (TrimItemVersions $jobb): bool => $jobb->containerId === $a->id,
    );

    TrimItemVersions::forContainer($a->id)->handle();

    expect(ItemVersion::query()->where('item_id', $itemA->id)->count())->toBe(0)
        ->and(ItemVersion::query()->where('item_id', $itemB->id)->count())->toBe(3);

    livsStammer($konto);
});

it('köar inget och raderar inget när taket höjs', function () {
    [$konto, $användare] = livsKonto(3);
    $container = livsContainer($konto, $användare, 2);
    $item = livsItem($container, $konto, $användare);

    livsVersioner($item, 2);
    livsRakna();

    Queue::fake();

    // Kontot 3 → 5.
    actingAs($användare)
        ->patch('/settings/storage/versioning', [
            'account' => $konto->ulid,
            'versioning_max_versions' => 5,
        ])
        ->assertRedirect();

    // Containern 2 → NULL, alltså upp till kontots 5.
    actingAs($användare)
        ->patch("/containers/{$container->ulid}/versioning", ['versioning_max_versions' => null])
        ->assertRedirect();

    Queue::assertNotPushed(TrimItemVersions::class);

    expect(ItemVersion::query()->where('item_id', $item->id)->count())->toBe(2);

    livsStammer($konto);
});

it('behåller versionerna i papperskorgen och lämnar tillbaka dem vid återställning', function () {
    [$konto, $användare] = livsKonto(3);
    $container = livsContainer($konto, $användare);
    $item = livsItem($container, $konto, $användare);

    livsVersioner($item, 3);
    livsRakna();

    $item->delete();

    expect(ItemVersion::query()->where('item_id', $item->id)->count())->toBe(3);

    // De räknas fortfarande (ADR-0081 § 3).
    livsStammer($konto);

    $item->restore();

    expect(ItemVersion::query()->where('item_id', $item->id)->count())->toBe(3);

    livsStammer($konto);
});

it('tar versionerna med sig när itemet raderas för gott', function () {
    [$konto, $användare] = livsKonto(3);
    $container = livsContainer($konto, $användare);
    $item = livsItem($container, $konto, $användare);

    livsVersioner($item, 3);
    livsRakna();

    app(PurgeContent::class)->item($item);

    expect(DB::table('item_version')->where('item_id', $item->id)->count())->toBe(0)
        ->and(Item::withTrashed()->whereKey($item->id)->exists())->toBeFalse();

    livsStammer($konto);
});

it('tar versionerna med sig när containern raderas för gott', function () {
    sättPlangräns('free', 'containers', 10);

    [$konto, $användare] = livsKonto(3);
    $a = livsContainer($konto, $användare);
    $b = livsContainer($konto, $användare);

    $itemA = livsItem($a, $konto, $användare, 'A');
    $itemB = livsItem($b, $konto, $användare, 'B');

    livsVersioner($itemA, 2);
    livsVersioner($itemB, 2);
    livsRakna();

    app(PurgeContainer::class)->handle($a);

    expect(DB::table('item_version')->where('item_id', $itemA->id)->count())->toBe(0)
        ->and(DB::table('item_version')->where('item_id', $itemB->id)->count())->toBe(2);

    livsStammer($konto);
});

it('frigör versionerna när kontot raderas och flyttar dem det betalar för i andras containrar', function () {
    // Kontot som raderas har en egen container med en version.
    [$eget, $egetUser] = livsKonto(3);
    $egetContainer = livsContainer($eget, $egetUser);
    $egetItem = livsItem($egetContainer, $eget, $egetUser);

    livsVersioner($egetItem, 2);

    // Och det betalar för en version i någon annans container (ADR-0081 § 4).
    [$ägare, $ägarUser] = livsKonto(3);
    $ägarContainer = livsContainer($ägare, $ägarUser);
    $ägarItem = livsItem($ägarContainer, $ägare, $ägarUser);

    $flyttad = ItemVersion::factory()->for($ägarItem, 'item')->create([
        'billed_account_id' => $eget->id,
        'name' => 'Betalad av det raderade kontot',
    ]);

    livsRakna();

    app(DeleteAccount::class)->handle($eget, DeleteAccount::REASON_OWNER_DELETED);

    expect(DB::table('item_version')->where('item_id', $egetItem->id)->count())->toBe(0)
        ->and(DB::table('item_version')->where('id', $flyttad->id)->value('billed_account_id'))->toBe($ägare->id);

    livsStammer($ägare);
});

it('raderar inget under rättslig spärr men visar bara så många som taket tillåter', function () {
    withoutVite();

    [$konto, $användare] = livsKonto(5);
    $container = livsContainer($konto, $användare);
    $item = livsItem($container, $konto, $användare);

    livsVersioner($item, 4);
    livsRakna();

    LegalHold::factory()->create(['account_id' => $konto->id, 'lifted_at' => null]);

    // Taket sänks till 1, och jobbet köas — men spärren stoppar det.
    $konto->versioning_max_versions = 1;
    $konto->save();

    TrimItemVersions::forAccount($konto->id)->handle();
    TrimItemVersions::forContainer($container->id)->handle();

    expect(ItemVersion::query()->where('item_id', $item->id)->count())->toBe(4);

    // Webbens lista visar den nyaste och döljer överskottet.
    actingAs($användare)
        ->get("/containers/{$container->ulid}/items/{$item->ulid}?tab=history")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Show')
            ->has('versions', 1)
            ->where('versions.0.name', 'Version 4'));

    // API:ets lista gör detsamma.
    getJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/versions",
        livsHeaders($användare),
    )
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Version 4');
});

it('pekar sidindelningen på listans egen sökväg och svarar sida 1 även för ?page=2', function () {
    [$konto, $användare] = livsKonto(5);
    $container = livsContainer($konto, $användare);
    $item = livsItem($container, $konto, $användare);

    livsVersioner($item, 3);

    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/versions";

    getJson($url, livsHeaders($användare))
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.path', url($url))
        ->assertJsonPath('meta.current_page', 1);

    // Allt som får visas ryms på en sida: `?page=2` får inte ge en falsk
    // andra sida, och sökvägen ska fortfarande vara listans.
    getJson($url.'?page=2', livsHeaders($användare))
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.path', url($url))
        ->assertJsonPath('meta.current_page', 1);
});
