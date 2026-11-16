<?php

use App\Actions\Usage\CountFeatureUse;
use App\Console\AggregatesUsageMetrics;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Usage\Features;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 391 (M61) · Sidvisningar och funktioner i den anonyma mätningen,
 * [[ADR-0079 Övervakning]] § 4. Råtabellen `usage_event_daily` räknas upp av
 * App\Http\Middleware\CountRouteView (terminate) och
 * App\Actions\Usage\CountFeatureUse; nattjobbet flyttar den till `usage_metric`.
 */

afterEach(function () {
    Carbon::setTestNow();
});

function mattAnvandare(): User
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return $anvandare;
}

function mattRader(string $kalla): array
{
    return DB::table('usage_event_daily')->where('source', $kalla)->orderBy('name')->get()
        ->map(fn ($rad) => (array) $rad)->all();
}

function mattRaw(string $kalla, string $dag, string $namn, string $plan, int $antal): void
{
    DB::table('usage_event_daily')->insert([
        'date' => $dag, 'source' => $kalla, 'name' => $namn, 'plan' => $plan,
        'count' => $antal, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('räknar upp en sidvisning med rätt rutt och plan', function () {
    withoutVite();

    actingAs(mattAnvandare())->get('/dashboard')->assertOk();

    $rader = mattRader('route');

    expect($rader)->toHaveCount(1)
        ->and($rader[0]['name'])->toBe('dashboard')
        ->and($rader[0]['plan'])->toBe('free')
        ->and($rader[0]['count'])->toBe(1);
});

it('räknar en gäst som guest', function () {
    withoutVite();

    get('/login')->assertOk();

    expect(DB::table('usage_event_daily')->where('name', 'login.create')->value('plan'))->toBe('guest');
});

it('ger count 2 efter två visningar', function () {
    withoutVite();

    $anvandare = mattAnvandare();

    actingAs($anvandare)->get('/dashboard')->assertOk();
    actingAs($anvandare)->get('/dashboard')->assertOk();

    expect(mattRader('route'))->toHaveCount(1)
        ->and(mattRader('route')[0]['count'])->toBe(2);
});

it('räknar inte en partiell omladdning', function () {
    withoutVite();

    actingAs(mattAnvandare())->get('/dashboard', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => 'Dashboard',
        'X-Inertia-Partial-Data' => 'tips',
    ]);

    expect(mattRader('route'))->toBe([]);
});

it('räknar inte en omdirigering', function () {
    withoutVite();

    get('/dashboard')->assertRedirect();

    expect(mattRader('route'))->toBe([]);
});

it('räknar inte JSON och inte /up', function () {
    withoutVite();

    Route::middleware('web')->get('/matt-json', fn () => response()->json(['ok' => true]))->name('matt.json');

    get('/matt-json')->assertOk();
    get('/up')->assertOk();

    expect(mattRader('route'))->toBe([]);
});

it('räknar en Inertia-besökning som kommer via X-Inertia', function () {
    withoutVite();

    actingAs(mattAnvandare())->get('/dashboard', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')),
    ])->assertOk();

    expect(mattRader('route'))->toHaveCount(1);
});

it('sväljer ett fel i uppräkningen', function () {
    withoutVite();

    Schema::drop('usage_event_daily');

    actingAs(mattAnvandare())->get('/dashboard')->assertOk();
});

it('har ingen kolumn för användare, konto eller IP i råtabellen', function () {
    expect(Schema::getColumnListing('usage_event_daily'))
        ->toEqualCanonicalizing(['id', 'date', 'source', 'name', 'plan', 'count', 'created_at', 'updated_at']);
});

it('räknar upp en funktion och tar bara namn ur Features', function () {
    $anvandare = mattAnvandare();

    app(CountFeatureUse::class)->handle(Features::COST_CREATE, $anvandare);
    app(CountFeatureUse::class)->handle(Features::COST_CREATE, $anvandare);

    $rader = mattRader('feature');

    expect($rader)->toHaveCount(1)
        ->and($rader[0]['name'])->toBe('cost.create')
        ->and($rader[0]['plan'])->toBe('free')
        ->and($rader[0]['count'])->toBe(2);

    expect(fn () => app(CountFeatureUse::class)->handle('cost.fritt_namn', $anvandare))
        ->toThrow(InvalidArgumentException::class);
});

it('räknar upp växeln när den stängs av', function () {
    withoutVite();

    $anvandare = mattAnvandare();
    $konto = $anvandare->accounts()->first();
    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    actingAs($anvandare)->get("/containers/{$container->ulid}/items/{$item->ulid}")->assertOk();
    expect(mattRader('feature'))->toBe([]);

    actingAs($anvandare)->get("/containers/{$container->ulid}/items/{$item->ulid}?children=0")->assertOk();
    expect(mattRader('feature')[0]['name'])->toBe('item.include_children_off');
});

it('räknar snabbraden för uppgifter i inboxen', function () {
    $anvandare = mattAnvandare();

    actingAs($anvandare)->post('/inbox/tasks', ['title' => 'Ring'])->assertRedirect();

    expect(mattRader('feature')[0]['name'])->toBe('task.quick_create');
});

it('skriver grupper om minst fem till usage_metric, slår ihop de små och tömmer råraderna', function () {
    Carbon::setTestNow('2026-11-16 03:00:00');

    // Route: dashboard/free = 6 (egen rad), två små på olika planer = 3 + 2 → other/mixed = 5.
    mattRaw('route', '2026-11-15', 'dashboard', 'free', 6);
    mattRaw('route', '2026-11-15', 'tasks', 'free', 3);
    mattRaw('route', '2026-11-15', 'inbox', 'pro', 2);
    // Feature: en grupp under fem som inte når fem ens sammanslagen → kastas.
    mattRaw('feature', '2026-11-15', 'cost.create', 'free', 4);
    // Idag räknas aldrig.
    mattRaw('route', '2026-11-16', 'dashboard', 'free', 9);

    app(AggregatesUsageMetrics::class)->handle();

    $route = DB::table('usage_metric')->where('source', 'route')->orderBy('action')->get()
        ->map(fn ($r) => [$r->action, $r->plan, $r->count])->all();

    expect($route)->toBe([['dashboard', 'free', 6], ['other', 'mixed', 5]])
        ->and(DB::table('usage_metric')->where('source', 'feature')->count())->toBe(0)
        ->and(DB::table('usage_event_daily')->where('date', '2026-11-15')->count())->toBe(0)
        ->and(DB::table('usage_event_daily')->where('date', '2026-11-16')->count())->toBe(1);
});

it('räknar en missad natt i efterhand så länge råraderna finns', function () {
    Carbon::setTestNow('2026-11-18 03:00:00');

    mattRaw('feature', '2026-11-15', 'cost.create', 'free', 7);
    mattRaw('feature', '2026-11-16', 'cost.create', 'free', 8);

    app(AggregatesUsageMetrics::class)->handle();

    expect(DB::table('usage_metric')->where('source', 'feature')->orderBy('date')->pluck('count')->map(fn ($c) => (int) $c)->all())
        ->toBe([7, 8]);

    // En andra körning ger inga dubbletter.
    app(AggregatesUsageMetrics::class)->handle();

    expect(DB::table('usage_metric')->where('source', 'feature')->count())->toBe(2);
});

/*
 * Per funktion och yta (webb och API): handlingen lyckas → raden räknas upp
 * med rätt namn; handlingen misslyckas (valideringsfel eller nekad åtkomst)
 * → ingen rad.
 */

function mattApiKonto(): array
{
    [$konto, $anvandare, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $headers, $container, $item];
}

function mattBilaga(Item $item, Account $konto, User $anvandare): Attachment
{
    return Attachment::factory()->create([
        'item_id' => $item->id,
        'stored_file_id' => StoredFile::factory()->create(['byte_size' => 1500])->id,
        'filename' => 'manual.pdf',
        'kind' => 'document',
        'uploaded_by_user_id' => $anvandare->id,
        'billed_account_id' => $konto->id,
    ]);
}

function mattNamn(): array
{
    return DB::table('usage_event_daily')->where('source', 'feature')->pluck('count', 'name')->map(fn ($c) => (int) $c)->all();
}

function mattBilagaUrl(Container $container, Item $item, string $efter = ''): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}/attachments{$efter}";
}

it('räknar attachment.upload på webben och i API:et, men inte vid valideringsfel', function () {
    Storage::fake('files');
    [$konto, $anvandare, $headers, $container, $item] = mattApiKonto();

    actingAs($anvandare)->post(mattBilagaUrl($container, $item), [])->assertSessionHasErrors('file');
    postJson('/api/containers/'.$container->ulid.'/items/'.$item->ulid.'/attachments', ['account' => $konto->ulid], $headers)->assertUnprocessable();
    expect(mattNamn())->toBe([]);

    actingAs($anvandare)->post(mattBilagaUrl($container, $item), [
        'file' => UploadedFile::fake()->createWithContent('a.pdf', 'webb'),
        'account' => $konto->ulid,
    ]);
    expect(mattNamn())->toBe(['attachment.upload' => 1]);

    postJson('/api/containers/'.$container->ulid.'/items/'.$item->ulid.'/attachments', [
        'file' => UploadedFile::fake()->createWithContent('b.pdf', 'api'),
        'account' => $konto->ulid,
    ], $headers)->assertCreated();
    expect(mattNamn())->toBe(['attachment.upload' => 2]);
});

it('räknar attachment.move, copy och to_inbox på webben, men inte vid nekad åtkomst', function () {
    withoutVite();
    [$konto, $anvandare, , $container, $item] = mattApiKonto();
    $mal = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);
    $bilaga = mattBilaga($item, $konto, $anvandare);

    // Nekad: främmande användare, ingen åtkomst.
    $främling = mattAnvandare();
    foreach (['/move', '/copy'] as $sluta) {
        actingAs($främling)->post(mattBilagaUrl($container, $item, "/{$bilaga->ulid}{$sluta}"), ['target' => $mal->ulid]);
    }
    actingAs($främling)->post(mattBilagaUrl($container, $item, "/{$bilaga->ulid}/inbox"));
    expect(mattNamn())->toBe([]);

    actingAs($anvandare)->post(mattBilagaUrl($container, $item, "/{$bilaga->ulid}/copy"), ['target' => $mal->ulid]);
    expect(mattNamn())->toBe(['attachment.copy' => 1]);

    actingAs($anvandare)->post(mattBilagaUrl($container, $item, "/{$bilaga->ulid}/move"), ['target' => $mal->ulid]);
    expect(mattNamn())->toBe(['attachment.copy' => 1, 'attachment.move' => 1]);

    actingAs($anvandare)->post(mattBilagaUrl($container, $mal, "/{$bilaga->ulid}/inbox"));
    expect(mattNamn())->toBe(['attachment.copy' => 1, 'attachment.move' => 1, 'attachment.to_inbox' => 1]);
});

it('räknar attachment.move, copy och to_inbox i API:et, men inte vid nekad åtkomst', function () {
    [$konto, $anvandare, $headers, $container, $item] = mattApiKonto();
    $mal = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);
    $bilaga = mattBilaga($item, $konto, $anvandare);
    $bas = "/api/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$bilaga->ulid}";

    [, , $främlingHeaders] = kontoMedMedlem();
    foreach (['/move', '/copy'] as $sluta) {
        postJson($bas.$sluta, ['target' => $mal->ulid], $främlingHeaders);
    }
    postJson($bas.'/inbox', [], $främlingHeaders);
    expect(mattNamn())->toBe([]);

    // Sanctum-vakten cachar användaren inom ett test.
    app('auth')->forgetGuards();

    postJson($bas.'/copy', ['target' => $mal->ulid], $headers)->assertSuccessful();
    postJson($bas.'/move', ['target' => $mal->ulid], $headers)->assertSuccessful();
    postJson("/api/containers/{$container->ulid}/items/{$mal->ulid}/attachments/{$bilaga->ulid}/inbox", [], $headers)->assertSuccessful();

    expect(mattNamn())->toBe(['attachment.copy' => 1, 'attachment.move' => 1, 'attachment.to_inbox' => 1]);
});

it('räknar cost.create via webben och API:et, men inte vid valideringsfel', function () {
    [$konto, $anvandare, $headers, $container, $item] = mattApiKonto();
    $kropp = ['incurred_on' => '2026-04-12', 'amount' => '1200,50', 'currency' => 'EUR', 'description' => 'Impeller', 'supplier' => null];
    $webb = "/containers/{$container->ulid}/items/{$item->ulid}/costs";
    $api = "/api{$webb}";

    actingAs($anvandare)->post($webb, [])->assertSessionHasErrors();
    postJson($api, [], $headers)->assertUnprocessable();
    expect(mattNamn())->toBe([]);

    actingAs($anvandare)->post($webb, $kropp);
    postJson($api, $kropp, $headers)->assertCreated();

    expect(mattNamn())->toBe(['cost.create' => 2]);
});

it('räknar task.quick_create och task.form_create med samma regel på webben och i API:et', function () {
    [$konto, $anvandare, $headers, $container, $item] = mattApiKonto();
    $snabb = ['title' => 'Ring', 'recurrence_type' => 'none', 'gtd_list' => 'next'];
    $formular = $snabb + ['notes' => 'Mer text'];
    $webb = "/containers/{$container->ulid}/items/{$item->ulid}/schedules";
    $api = "/api{$webb}";

    // Misslyckade anrop räknas inte.
    actingAs($anvandare)->post($webb, [])->assertSessionHasErrors();
    postJson($api, [], $headers)->assertUnprocessable();
    actingAs($anvandare)->post('/inbox/tasks', [])->assertSessionHasErrors();
    postJson('/api/inbox/tasks', [], $headers)->assertUnprocessable();
    expect(mattNamn())->toBe([]);

    actingAs($anvandare)->post($webb, $snabb);
    postJson($api, $snabb, $headers)->assertCreated();
    expect(mattNamn())->toBe(['task.quick_create' => 2]);

    actingAs($anvandare)->post($webb, $formular);
    postJson($api, $formular, $headers)->assertCreated();
    expect(mattNamn())->toBe(['task.quick_create' => 2, 'task.form_create' => 2]);

    // En klient som skickar `notes: null` är fortfarande snabbrad.
    postJson($api, $snabb + ['notes' => null], $headers)->assertCreated();
    expect(mattNamn()['task.quick_create'])->toBe(3);

    // Inboxens snabbrad, webb och API.
    actingAs($anvandare)->post('/inbox/tasks', ['title' => 'Inbox']);
    postJson('/api/inbox/tasks', ['title' => 'Inbox API'], $headers)->assertCreated();
    expect(mattNamn()['task.quick_create'])->toBe(5);
});

it('räknar item.create_child på webben och i API:et, men inte för ett item utan förälder eller vid valideringsfel', function () {
    [$konto, $anvandare, $headers, $container, $item] = mattApiKonto();
    $webb = "/containers/{$container->ulid}/items";

    actingAs($anvandare)->post($webb, ['parent' => $item->ulid, 'account' => $konto->ulid])->assertSessionHasErrors('name');
    postJson("/api{$webb}", ['parent' => $item->ulid, 'account' => $konto->ulid], $headers)->assertUnprocessable();
    actingAs($anvandare)->post($webb, ['name' => 'Rot', 'account' => $konto->ulid]);
    postJson("/api{$webb}", ['name' => 'Rot API', 'account' => $konto->ulid], $headers)->assertCreated();
    expect(mattNamn())->toBe([]);

    actingAs($anvandare)->post($webb, ['name' => 'Barn', 'parent' => $item->ulid, 'account' => $konto->ulid]);
    postJson("/api{$webb}", ['name' => 'Barn API', 'parent' => $item->ulid, 'account' => $konto->ulid], $headers)->assertCreated();

    expect(mattNamn())->toBe(['item.create_child' => 2]);
});
