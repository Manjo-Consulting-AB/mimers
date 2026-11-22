<?php

use App\Jobs\BuildContainerExport;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Export;
use App\Models\Item;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 446 · Beställningen av PDF-pärmen på exportsidan — App\Http\Controllers\
 * BinderController, ExportController::index() och
 * resources/js/components/BinderForm.vue. Varje "Klart när"-punkt har ett prov.
 */

beforeEach(function () {
    Storage::fake('files');
    config(['files.internal_redirect' => false]);
});

/**
 * @return array{0: Account, 1: User, 2: Container}
 */
function binderBestKontext(bool $pro): array
{
    [$konto, $ägare] = kontoMedMedlem();

    if ($pro) {
        Subscription::factory()->for($konto)->for(Plan::where('code', 'pro')->firstOrFail())->create();
    }

    return [$konto, $ägare, Container::factory()->for($konto, 'account')->create()];
}

function binderBestVal(array $över = []): array
{
    return array_merge([
        'sections' => ['items', 'history', 'costs'],
        'attachment_mode' => 'merge',
        'attachments' => [],
    ], $över);
}

it('skapar en binder-rad med valen i options och köar jobbet för en Pro-container', function () {
    Queue::fake();
    [$konto, $ägare, $container] = binderBestKontext(true);

    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $ägare->id,
        'created_by_account_id' => $konto->id,
    ]);
    $bilaga = Attachment::factory()->for($item, 'item')->create();

    actingAs($ägare)
        ->post("/containers/{$container->ulid}/binders", binderBestVal(['attachments' => [$bilaga->ulid]]))
        ->assertRedirect("/containers/{$container->ulid}/export");

    $rad = Export::query()->where('container_id', $container->id)->sole();

    expect($rad->kind)->toBe(Export::KIND_BINDER)
        ->and($rad->status)->toBe(Export::STATUS_PENDING)
        ->and($rad->requested_by_user_id)->toBe($ägare->id)
        ->and($rad->options)->toBe([
            'sections' => ['items', 'history'],
            'costs' => true,
            'attachment_mode' => 'merge',
            'attachments' => [$bilaga->ulid],
        ]);

    Queue::assertPushed(BuildContainerExport::class, fn ($j) => $j->export->is($rad));
});

it('nekar en gratiscontainer med plan.feature_unavailable och skapar ingen rad', function () {
    Queue::fake();
    [, $ägare, $container] = binderBestKontext(false);

    actingAs($ägare)
        ->postJson("/containers/{$container->ulid}/binders", binderBestVal())
        ->assertForbidden()
        ->assertJsonPath('error.code', 'plan.feature_unavailable');

    expect(Export::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('visar Pro-raden och ritar inget formulär på en gratiscontainer', function () {
    withoutVite();
    [, $ägare, $container] = binderBestKontext(false);

    actingAs($ägare)
        ->get("/containers/{$container->ulid}/export")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('binder.available', false)
            ->where('binder.items', []));

    $vy = file_get_contents(resource_path('js/pages/Containers/Export.vue'));
    expect($vy)->toContain("t('export.pdf_binder_free')")->toContain('v-if="binder.available"');
    expect(trans('ui.export.pdf_binder_free', [], 'en'))->toContain('Pro');
});

it('ger en Pro-container formuläret med items som har bilagor', function () {
    withoutVite();
    [$konto, $ägare, $container] = binderBestKontext(true);

    $med = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn', 'created_by_user_id' => $ägare->id, 'created_by_account_id' => $konto->id,
    ]);
    Item::factory()->for($container, 'container')->create([
        'name' => 'Utan', 'created_by_user_id' => $ägare->id, 'created_by_account_id' => $konto->id,
    ]);
    $bilaga = Attachment::factory()->for($med, 'item')->create();

    actingAs($ägare)
        ->get("/containers/{$container->ulid}/export")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('binder.available', true)
            ->has('binder.items', 1)
            ->where('binder.items.0.ulid', $med->ulid)
            ->where('binder.items.0.attachments.0.ulid', $bilaga->ulid));
});

it('nekar den som saknar view på containern', function () {
    Queue::fake();
    [, , $container] = binderBestKontext(true);
    [, $främling] = kontoMedMedlem();

    actingAs($främling)->postJson("/containers/{$container->ulid}/binders", binderBestVal())->assertForbidden();
    actingAs($främling)->get("/containers/{$container->ulid}/export")->assertForbidden();

    expect(Export::count())->toBe(0);
});

it('spärrar en andra beställning medan en export packas, även mellan slagen', function () {
    Queue::fake();
    [, $ägare, $container] = binderBestKontext(true);

    actingAs($ägare)->post("/containers/{$container->ulid}/export")->assertRedirect();
    actingAs($ägare)
        ->post("/containers/{$container->ulid}/binders", binderBestVal())
        ->assertSessionHasErrors('export');

    expect(Export::count())->toBe(1);
});

it('validerar valen', function () {
    Queue::fake();
    [, $ägare, $container] = binderBestKontext(true);

    actingAs($ägare)
        ->postJson("/containers/{$container->ulid}/binders", binderBestVal(['sections' => ['x'], 'attachment_mode' => 'zip']))
        ->assertUnprocessable();

    expect(Export::count())->toBe(0);
});

it('listar ZIP och pärm med etikett och laddar ner båda', function () {
    withoutVite();
    [, $ägare, $container] = binderBestKontext(true);

    $zip = Export::factory()->create([
        'container_id' => $container->id, 'requested_by_user_id' => $ägare->id,
        'status' => Export::STATUS_READY, 'byte_size' => 5, 'expires_at' => now()->addDays(7),
    ]);
    $pärm = Export::factory()->create([
        'container_id' => $container->id, 'requested_by_user_id' => $ägare->id, 'kind' => Export::KIND_BINDER,
        'status' => Export::STATUS_READY, 'byte_size' => 5, 'expires_at' => now()->addDays(7),
    ]);

    foreach ([[$zip, 'zip', 'application/zip'], [$pärm, 'pdf', 'application/pdf']] as [$rad, $ändelse, $typ]) {
        $rad->storage_path = "exports/{$container->ulid}/{$rad->ulid}.{$ändelse}";
        $rad->save();
        Storage::disk('files')->put($rad->storage_path, 'byten');
    }

    actingAs($ägare)
        ->get("/containers/{$container->ulid}/export")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('exports', 2)
            ->where('exports.0.kind', fn ($k) => in_array($k, ['zip', 'binder'], true)));

    expect(trans('ui.export.kind.zip', [], 'en'))->toBe('ZIP export')
        ->and(trans('ui.export.kind.pdf_binder', [], 'en'))->toBe('PDF binder');

    foreach ([[$zip, 'application/zip', '.zip'], [$pärm, 'application/pdf', '.pdf']] as [$rad, $typ, $ändelse]) {
        $svar = actingAs($ägare)->get("/exports/{$rad->ulid}/download")->assertOk();
        expect($svar->headers->get('Content-Type'))->toContain($typ)
            ->and($svar->headers->get('Content-Disposition'))->toContain($ändelse);
    }
});
