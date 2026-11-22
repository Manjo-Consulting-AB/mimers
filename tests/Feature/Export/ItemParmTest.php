<?php

use App\Jobs\BuildContainerExport;
use App\Models\Account;
use App\Models\Container;
use App\Models\Export;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Export\BinderBuilder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use setasign\Fpdi\Fpdi;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 447 · PDF-pärmen om ett enda item — BinderBuilder med `item_id`,
 * BinderController::storeForItem(), menyraden i itemvyn och etiketten i
 * exportlistan. Varje "Klart när"-punkt har ett prov.
 */

beforeEach(function () {
    Storage::fake('files');
    config(['files.internal_redirect' => false]);
});

/**
 * @return array{0: Account, 1: User, 2: Container}
 */
function itemParmKontext(bool $pro): array
{
    [$konto, $ägare] = kontoMedMedlem();

    if ($pro) {
        Subscription::factory()->for($konto)->for(Plan::where('code', 'pro')->firstOrFail())->create();
    }

    return [$konto, $ägare, Container::factory()->for($konto, 'account')->create(['name' => 'Båten'])];
}

function itemParmItem(Container $container, Account $konto, User $ägare, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn, 'created_by_user_id' => $ägare->id, 'created_by_account_id' => $konto->id,
    ]);
}

function itemParmVal(array $över = []): array
{
    return array_merge(['sections' => ['items', 'history'], 'attachment_mode' => 'link', 'attachments' => []], $över);
}

function itemParmSpion(): object
{
    $builder = new class extends BinderBuilder
    {
        /** @var array<int, array{0: string, 1: array<string, mixed>}> */
        public array $rendered = [];

        protected function renderPart(FilesystemAdapter $disk, string $path, string $view, array $data): string
        {
            $this->rendered[] = [$view, $data];

            return parent::renderPart($disk, $path, $view, $data);
        }
    };

    app()->instance(BinderBuilder::class, $builder);

    return $builder;
}

function itemParmText(string $bytes): string
{
    $text = $bytes;

    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $m)) {
        foreach ($m[1] as $stream) {
            $uppackad = @gzuncompress($stream);

            if ($uppackad !== false) {
                $text .= $uppackad;
            }
        }
    }

    return $text;
}

it('skapar en binder-rad med item_id för en Pro-container och köar jobbet', function () {
    Queue::fake();
    [$konto, $ägare, $container] = itemParmKontext(true);
    $item = itemParmItem($container, $konto, $ägare, 'Motorn');

    actingAs($ägare)
        ->post("/containers/{$container->ulid}/items/{$item->ulid}/binders", itemParmVal())
        ->assertRedirect();

    $rad = Export::query()->sole();

    expect($rad->kind)->toBe(Export::KIND_BINDER)
        ->and($rad->item_id)->toBe($item->id)
        ->and($rad->container_id)->toBe($container->id)
        ->and($rad->requested_by_user_id)->toBe($ägare->id)
        ->and($rad->options['sections'])->toBe(['items', 'history']);

    Queue::assertPushed(BuildContainerExport::class, fn ($j) => $j->export->is($rad));
});

it('pärmen har itemets försättsblad, inga barn, ingen förteckning och sidnummer', function () {
    [$konto, $ägare, $container] = itemParmKontext(true);
    $item = itemParmItem($container, $konto, $ägare, 'Motorn');
    $barn = itemParmItem($container, $konto, $ägare, 'Impellern');
    $annat = itemParmItem($container, $konto, $ägare, 'Rodret');
    ItemLink::factory()->create(['from_item_id' => $item->id, 'to_item_id' => $barn->id, 'relation' => 'parent']);

    $spion = itemParmSpion();

    $export = Export::factory()->create([
        'container_id' => $container->id,
        'requested_by_user_id' => $ägare->id,
        'kind' => Export::KIND_BINDER,
        'item_id' => $item->id,
    ]);
    (new BuildContainerExport($export))->handle();
    $export->refresh();

    expect($export->status)->toBe(Export::STATUS_READY);

    $vyer = array_column($spion->rendered, 0);
    expect($vyer)->toBe(['export.binder.item_cover', 'export.binder.item'])
        ->and($spion->rendered[0][1]['title'])->toBe('Motorn')
        ->and($spion->rendered[1][1]['item']->name)->toBe('Motorn');

    $sokvag = Storage::disk('files')->path($export->storage_path);
    $antal = (new Fpdi)->setSourceFile($sokvag);
    $text = itemParmText((string) file_get_contents($sokvag));

    expect($antal)->toBe(2)
        ->and($text)->toContain('Page 1 of 2')->toContain('Page 2 of 2');

    expect(str_contains($text, 'Impellern'))->toBeFalse()
        ->and(str_contains($text, 'Rodret'))->toBeFalse();

    expect($annat->name)->toBe('Rodret');
});

it('försättsbladet bär itemets namn och containerns namn', function () {
    [$konto, $ägare, $container] = itemParmKontext(true);
    $item = itemParmItem($container, $konto, $ägare, 'Motorn');

    $html = view('export.binder.item_cover', [
        'container' => $container->load('account'),
        'title' => $item->name,
        'owner' => $konto->name,
        'image' => 'data:image/png;base64,AAAA',
        'date' => '2026-11-23',
    ])->render();

    expect($html)->toContain('Motorn')->toContain('Båten')->toContain('data:image/png;base64,AAAA');
});

it('ett item utanför beställarens omfång ger ingen item-del i pärmen', function () {
    [$konto, $ägare, $container] = itemParmKontext(true);
    $item = itemParmItem($container, $konto, $ägare, 'Motorn');

    $spion = itemParmSpion();
    $export = Export::factory()->create([
        'container_id' => $container->id,
        'requested_by_user_id' => null,
        'kind' => Export::KIND_BINDER,
        'item_id' => $item->id,
    ]);
    (new BuildContainerExport($export))->handle();

    expect(array_column($spion->rendered, 0))->toBe(['export.binder.item_cover']);
});

it('exportsidans lista visar PDF binder · {item}', function () {
    withoutVite();
    [$konto, $ägare, $container] = itemParmKontext(true);
    $item = itemParmItem($container, $konto, $ägare, 'Motorn');

    Export::factory()->create([
        'container_id' => $container->id,
        'requested_by_user_id' => $ägare->id,
        'kind' => Export::KIND_BINDER,
        'item_id' => $item->id,
    ]);

    actingAs($ägare)
        ->get("/containers/{$container->ulid}/export")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('exports.0.kind', 'binder')
            ->where('exports.0.item.name', 'Motorn'));

    expect(trans('ui.export.kind.pdf_binder_item', ['item' => 'Motorn'], 'en'))->toBe('PDF binder · Motorn');

    $rad = file_get_contents(resource_path('js/components/ExportRow.vue'));
    expect($rad)->toContain("t('export.kind.pdf_binder_item'");
});

it('en gratisplan nekas med plan.feature_unavailable och har ingen menyrad', function () {
    withoutVite();
    Queue::fake();
    [$konto, $ägare, $container] = itemParmKontext(false);
    $item = itemParmItem($container, $konto, $ägare, 'Motorn');

    actingAs($ägare)
        ->postJson("/containers/{$container->ulid}/items/{$item->ulid}/binders", itemParmVal())
        ->assertForbidden()
        ->assertJsonPath('error.code', 'plan.feature_unavailable');

    expect(Export::count())->toBe(0);
    Queue::assertNothingPushed();

    actingAs($ägare)
        ->get("/containers/{$container->ulid}/items/{$item->ulid}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('binderAvailable', false));
});

it('itemvyn har Create PDF binder för Pro', function () {
    withoutVite();
    [$konto, $ägare, $container] = itemParmKontext(true);
    $item = itemParmItem($container, $konto, $ägare, 'Motorn');

    actingAs($ägare)
        ->get("/containers/{$container->ulid}/items/{$item->ulid}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('binderAvailable', true));

    expect(trans('ui.item.menu.pdf_binder', [], 'en'))->toBe('Create PDF binder')
        ->and(trans('ui.export.pdf_binder_item_created', [], 'en'))->toBe('Your binder is being created');

    $meny = file_get_contents(resource_path('js/components/ItemActionMenu.vue'));
    $vy = file_get_contents(resource_path('js/pages/Containers/Items/Show.vue'));
    $dialog = file_get_contents(resource_path('js/components/BinderItemDialog.vue'));

    expect($meny)->toContain("t('item.menu.pdf_binder')")->toContain('v-if="canBinder"')
        ->and($vy)->toContain(':can-binder="binderAvailable"')->toContain('<BinderItemDialog')
        ->and($dialog)->toContain("t('export.pdf_binder_item_created')")->toContain('<BinderForm')
        ->and($dialog)->toContain('/export`');
});

it('nekar den som saknar view på itemet och ett item i en annan container', function () {
    Queue::fake();
    [$konto, $ägare, $container] = itemParmKontext(true);
    $item = itemParmItem($container, $konto, $ägare, 'Motorn');
    [, $främling] = kontoMedMedlem();

    actingAs($främling)->postJson("/containers/{$container->ulid}/items/{$item->ulid}/binders", itemParmVal())->assertForbidden();

    $annan = Container::factory()->for($konto, 'account')->create();

    actingAs($ägare)->postJson("/containers/{$annan->ulid}/items/{$item->ulid}/binders", itemParmVal())->assertNotFound();

    expect(Export::count())->toBe(0);
});
