<?php

use App\Actions\Storage\ReadContainerStorageShare;
use App\Actions\Storage\ReadStorageBreakdown;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\Frontend\ActiveContainer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * Issue 459 · M79 Kvoten i toppraden — se App\Http\Middleware\
 * HandleInertiaRequests (`storageQuota`, `storageBreakdown`),
 * App\Actions\Storage\ReadContainerStorageShare, ReadStorageBreakdown och
 * resources/js/components/StorageQuota.vue. Varje "Klart när"-punkt har ett
 * prov nedan. Hjälparna har prefixet `kvotmodul`.
 */

/**
 * @return array{0: Account, 1: User}
 */
function kvotmodulKonto(string $roll = 'owner', int $storage = 0, int $text = 0): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => $roll]);
    UsageCounter::factory()->create([
        'account_id' => $konto->id,
        'storage_bytes' => $storage,
        'metadata_bytes' => $text,
    ]);

    return [$konto, $anvandare];
}

function kvotmodulBilaga(Item|Container $agare, int $bytes, Account $debiterat, array $attribut = []): Attachment
{
    $fil = StoredFile::factory()->create(['byte_size' => $bytes]);
    $nyckel = $agare instanceof Item ? 'item_id' : 'container_id';
    // Fabriken skapar ett eget item; en containerbild har inget.
    $andra = $agare instanceof Item ? 'container_id' : 'item_id';

    return Attachment::factory()->create(array_merge([
        $nyckel => $agare->id,
        $andra => null,
        'stored_file_id' => $fil->id,
        'billed_account_id' => $debiterat->id,
        'filename' => 'fil.jpg',
        'kind' => 'image',
    ], $attribut));
}

function kvotmodulItem(Container $container): Item
{
    return Item::factory()->for($container, 'container')->create();
}

/**
 * @return array<string, mixed>
 */
function kvotmodulProps(User $anvandare, string $url = '/dashboard'): array
{
    /** @var array{props: array<string, mixed>} $sida */
    $sida = actingAs($anvandare)->get($url)->assertOk()->viewData('page');

    return $sida['props'];
}

function kvotmodulReload(User $anvandare, string $url = '/dashboard', string $komponent = 'Dashboard'): TestResponse
{
    app()->forgetScopedInstances();

    return actingAs($anvandare)->get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => $komponent,
        'X-Inertia-Partial-Data' => 'storageBreakdown',
    ])->assertOk();
}

it('ger ett ägt konto med container null på dashboarden och usedBytes ur usage_counter', function () {
    [$konto, $anvandare] = kvotmodulKonto('owner', 1000, 234);

    $kvot = kvotmodulProps($anvandare)['storageQuota'];

    expect($kvot['account'])->toBe(['ulid' => $konto->ulid, 'name' => $konto->name]);
    expect($kvot['usedBytes'])->toBe(1234);
    expect($kvot['container'])->toBeNull();
    expect($kvot['otherAccounts'])->toBe([]);
});

it('väljer ägda kontot med lägst id och listar det andra i otherAccounts', function () {
    [$forsta, $anvandare] = kvotmodulKonto('owner', 10);
    $andra = Account::factory()->create();
    $andra->users()->attach($anvandare, ['role' => 'owner']);
    UsageCounter::factory()->create(['account_id' => $andra->id, 'storage_bytes' => 77, 'metadata_bytes' => 0]);

    $kvot = kvotmodulProps($anvandare)['storageQuota'];

    expect($kvot['account']['ulid'])->toBe($forsta->ulid);
    expect($kvot['otherAccounts'])->toHaveCount(1);
    expect($kvot['otherAccounts'][0]['ulid'])->toBe($andra->ulid);
    expect($kvot['otherAccounts'][0]['usedBytes'])->toBe(77);
    expect(array_keys($kvot['otherAccounts'][0]))->toBe(['ulid', 'name', 'usedBytes', 'limitBytes']);
});

it('föredrar ägarskap framför ett lägre id utan roll owner', function () {
    [$medlem, $anvandare] = kvotmodulKonto('member');
    $agt = Account::factory()->create();
    $agt->users()->attach($anvandare, ['role' => 'owner']);

    expect(kvotmodulProps($anvandare)['storageQuota']['account']['ulid'])->toBe($agt->ulid);
    expect($medlem->id)->toBeLessThan($agt->id);
});

it('ger medlemskapet med lägst id när personen inte äger något konto', function () {
    [$forsta, $anvandare] = kvotmodulKonto('member');
    $andra = Account::factory()->create();
    $andra->users()->attach($anvandare, ['role' => 'member']);

    expect(kvotmodulProps($anvandare)['storageQuota']['account']['ulid'])->toBe($forsta->ulid);
});

it('ger null för en gäst', function () {
    $sida = get('/login')->assertOk()->viewData('page');

    expect($sida['props']['storageQuota'])->toBeNull();
});

it('ger null för en inloggad utan konto', function () {
    $anvandare = User::factory()->create();
    $forfragan = Request::create('/dashboard');
    $forfragan->setUserResolver(fn () => $anvandare);

    $delade = app(HandleInertiaRequests::class)->share($forfragan);

    expect(value($delade['storageQuota']))->toBeNull();
});

it('bär ägarkontot och containerns andel på en container- och en itemsida', function () {
    [$eget, $anvandare] = kvotmodulKonto('owner');
    [$agare] = kvotmodulKonto('owner', 5000);
    $container = Container::factory()->for($agare, 'account')->create();
    $agare->users()->attach($anvandare, ['role' => 'member']);
    $item = kvotmodulItem($container);
    kvotmodulBilaga($item, 300, $agare);
    kvotmodulBilaga($container, 200, $agare);

    foreach (["/containers/{$container->ulid}", "/containers/{$container->ulid}/items/{$item->ulid}"] as $url) {
        $kvot = kvotmodulProps($anvandare, $url)['storageQuota'];

        expect($kvot['account']['ulid'])->toBe($agare->ulid);
        expect($kvot['container']['percent'])->toBe(min(100.0, round(500 / $kvot['limitBytes'] * 100, 1)));
        expect($kvot['container'])->toBe(['ulid' => $container->ulid, 'name' => $container->name, 'bytes' => 500, 'percent' => $kvot['container']['percent']]);
        expect($kvot['otherAccounts'][0]['ulid'])->toBe($eget->ulid);
    }
});

it('har container null på en sida utan container- eller itemparameter, även med aktiv container i sessionen', function () {
    [$konto, $anvandare] = kvotmodulKonto('owner');
    $container = Container::factory()->for($konto, 'account')->create();

    $sida = actingAs($anvandare)
        ->withSession([ActiveContainer::SESSION_KEY => $container->ulid])
        ->get('/dashboard')->assertOk()->viewData('page');

    expect($sida['props']['storageQuota']['container'])->toBeNull();
});

it('räknar inte en gästs debiterade bilaga men räknar papperskorgen och containerns bild', function () {
    [$konto] = kvotmodulKonto('owner');
    $gast = Account::factory()->create();
    $container = Container::factory()->for($konto, 'account')->create();
    $item = kvotmodulItem($container);
    $slangt = kvotmodulItem($container);

    kvotmodulBilaga($item, 100, $konto);
    kvotmodulBilaga($container, 40, $konto);
    kvotmodulBilaga($item, 9999, $gast);
    kvotmodulBilaga($slangt, 7, $konto);
    $slangt->delete();

    expect(app(ReadContainerStorageShare::class)->handle($container))->toBe(147);
});

it('visar samma tal i Containerdetaljer som i storageQuota.container.bytes', function () {
    [$konto, $anvandare] = kvotmodulKonto('owner', 1000);
    $container = Container::factory()->for($konto, 'account')->create();
    $item = kvotmodulItem($container);
    kvotmodulBilaga($item, 321, $konto);
    kvotmodulBilaga($item, 5000, Account::factory()->create());

    $props = kvotmodulProps($anvandare, "/containers/{$container->ulid}");

    expect($props['details']['storage']['usedBytes'])->toBe(321);
    expect($props['storageQuota']['container']['bytes'])->toBe(321);
});

it('skickar inte storageBreakdown vid en vanlig laddning men vid en partiell reload', function () {
    [$konto, $anvandare] = kvotmodulKonto('owner', 100, 20);

    expect(kvotmodulProps($anvandare))->not->toHaveKey('storageBreakdown');

    $svar = kvotmodulReload($anvandare)->json('props');

    expect($svar['storageBreakdown']['fileBytes'])->toBe(100);
    expect($svar['storageBreakdown']['textBytes'])->toBe(20);
});

it('sorterar containrar, items och filer störst först med högst fem av varje', function () {
    [$konto, $anvandare] = kvotmodulKonto('owner', 4000, 96);

    foreach (range(1, 7) as $i) {
        $container = Container::factory()->for($konto, 'account')->create();
        $item = kvotmodulItem($container);
        kvotmodulBilaga($item, $i * 100, $konto);
    }

    $uppdelning = kvotmodulReload($anvandare)->json('props.storageBreakdown');

    expect($uppdelning['containers'])->toHaveCount(5);
    expect(array_column($uppdelning['containers'], 'bytes'))->toBe([700, 600, 500, 400, 300]);
    expect(array_column($uppdelning['items'], 'bytes'))->toBe([700, 600, 500, 400, 300]);
    expect(array_column($uppdelning['files'], 'byte_size'))->toBe([700, 600, 500, 400, 300]);
    expect($uppdelning['items'][0]['container'])->toHaveKeys(['ulid', 'name']);
    expect($uppdelning['files'][0])->toHaveKeys(['ulid', 'filename', 'byte_size', 'container', 'item', 'inTrash']);

    $kvot = kvotmodulProps($anvandare)['storageQuota'];
    expect($uppdelning['fileBytes'] + $uppdelning['textBytes'])->toBe($kvot['usedBytes']);
});

it('grupperar containerns bild på containern och räknar ett item bara med sina egna bilagor', function () {
    [$konto] = kvotmodulKonto('owner');
    $container = Container::factory()->for($konto, 'account')->create();
    $item = kvotmodulItem($container);
    kvotmodulBilaga($container, 50, $konto);
    kvotmodulBilaga($item, 30, $konto);

    $uppdelning = app(ReadStorageBreakdown::class)->handle($konto);

    expect($uppdelning['containers'][0]['bytes'])->toBe(80);
    expect($uppdelning['items'])->toHaveCount(1);
    expect($uppdelning['items'][0]['bytes'])->toBe(30);
});

it('visar aldrig en bilaga på ett annat kontos fakturering, inte ens i en container personen är medlem i', function () {
    [$konto] = kvotmodulKonto('owner');
    $annat = Account::factory()->create();
    $container = Container::factory()->for($konto, 'account')->create();
    $item = kvotmodulItem($container);
    kvotmodulBilaga($item, 900, $annat);
    kvotmodulBilaga($item, 10, $konto);

    $uppdelning = app(ReadStorageBreakdown::class)->handle($konto);

    expect(array_column($uppdelning['files'], 'byte_size'))->toBe([10]);
    expect($uppdelning['containers'][0]['bytes'])->toBe(10);
    expect($uppdelning['items'][0]['bytes'])->toBe(10);
});

it('markerar en bilaga i papperskorgen och räknar med den', function () {
    [$konto] = kvotmodulKonto('owner');
    $container = Container::factory()->for($konto, 'account')->create();
    $item = kvotmodulItem($container);
    kvotmodulBilaga($item, 10, $konto);
    $item->delete();

    $uppdelning = app(ReadStorageBreakdown::class)->handle($konto);

    expect($uppdelning['files'][0]['inTrash'])->toBeTrue();
    expect($uppdelning['items'])->toHaveCount(1);
});

it('ger limitBytes och percent null för ett obegränsat tak', function () {
    [$konto, $anvandare] = kvotmodulKonto('owner', 500);
    $konto->subscription()->delete();
    $plan = $konto->currentPlan();
    $limits = $plan->limits;
    $limits['storage_bytes'] = null;
    $plan->forceFill(['limits' => $limits])->save();

    $kvot = kvotmodulProps($anvandare)['storageQuota'];

    expect($kvot['limitBytes'])->toBeNull();
    expect($kvot['percent'])->toBeNull();
    expect($kvot['usedBytes'])->toBe(500);
});

/*
 * Klart när (issue 477): toppraden ritar ingen `StorageQuota`, och
 * mobilmenyn ritar den med panelen som förut.
 *
 * Modulen flyttade ur skalets topprad när hjältarna fick lagringskortet. På
 * en telefon ritas varken `AppHero` eller containerns hjälte, så utan menyns
 * instans hade kvoten inte synts alls där. Menyns `<StorageQuota>` bär
 * `inline` och sin egen `id-suffix`, så panelen ritas i flödet där.
 */
it('ritar StorageQuota bara i mobilmenyn, med panelen kvar', function () {
    $layout = File::get(resource_path('js/layouts/AppLayout.vue'));
    $meny = File::get(resource_path('js/components/MobileMenu.vue'));

    expect($layout)->not->toContain('<StorageQuota')
        ->and($layout)->not->toContain('StorageQuota.vue')
        ->and($meny)->toContain('<StorageQuota v-if="storageQuota"')
        ->and($meny)->toContain('inline')
        ->and($meny)->toContain('id-suffix="menu"');
});

/*
 * Klart när (issue 477): containerns hjälte ritar lagringskortet med
 * containerns andel och kontots förbrukning av taket på översikten, på en
 * annan flik och i itemvyn.
 *
 * `storageQuota.container` byggs ur `{container}`-parametern, så alla tre
 * ytorna får samma andel utan en rad i respektive kontroller. Kortet läser
 * proppen självt; här prövas talen den får.
 */
it('bär containerns lagring på översikten, på en annan flik och i itemvyn', function () {
    [$konto, $agare] = kvotmodulKonto('owner');
    $container = Container::factory()->for($konto, 'account')->create();
    $item = kvotmodulItem($container);
    kvotmodulBilaga($item, 300, $konto);
    kvotmodulBilaga($container, 200, $konto);

    foreach ([
        "/containers/{$container->ulid}",
        "/containers/{$container->ulid}/items",
        "/containers/{$container->ulid}/items/{$item->ulid}",
    ] as $url) {
        $kvot = kvotmodulProps($agare, $url)['storageQuota'];

        expect($kvot['container'])->not->toBeNull()
            ->and($kvot['container']['ulid'])->toBe($container->ulid)
            ->and($kvot['container']['bytes'])->toBe(500)
            ->and($kvot['container']['percent'])->toBe(min(100.0, round(500 / $kvot['limitBytes'] * 100, 1)));
    }
});

it('har varje quota-nyckel i katalogen', function () {
    $katalog = require lang_path('en/ui.php');

    foreach (['StorageQuota', 'StorageQuotaPanel', 'StorageBreakdown'] as $fil) {
        $kod = File::get(resource_path("js/components/{$fil}.vue"));
        preg_match_all("/t\('(quota\.[a-z_]+)'/", $kod, $traffar);

        foreach ($traffar[1] as $nyckel) {
            expect(data_get($katalog, $nyckel))->toBeString("{$nyckel} saknas i lang/en/ui.php");
        }
    }
});

it('ger en grant-gäst utan medlemskap sitt eget konto och ingen uppdelning på container- och itemsidan', function () {
    [$eget, $gast] = kvotmodulKonto('owner', 11);
    [$agare] = kvotmodulKonto('owner', 5000);
    $container = Container::factory()->for($agare, 'account')->create();
    $item = kvotmodulItem($container);
    kvotmodulBilaga($item, 300, $agare);
    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => null,
        'grantee_type' => 'user',
        'grantee_id' => $gast->id,
        'level' => 'read',
        'kind' => 'member',
        'expires_at' => null,
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    foreach (["/containers/{$container->ulid}", "/containers/{$container->ulid}/items/{$item->ulid}"] as $url) {
        $props = kvotmodulProps($gast, $url);

        expect($props['storageQuota']['account']['ulid'])->toBe($eget->ulid);
        expect($props['storageQuota']['usedBytes'])->toBe(11);
        expect($props['storageQuota']['container'])->toBeNull();
        expect($props['storageQuota']['otherAccounts'])->toBe([]);

        $uppdelning = kvotmodulReload($gast, $url, 'Containers/Show')->json('props.storageBreakdown');
        expect(json_encode($uppdelning))->not->toContain($agare->ulid);
    }
});
