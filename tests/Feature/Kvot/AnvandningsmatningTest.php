<?php

use App\Actions\Usage\CountFeatureUse;
use App\Console\AggregatesUsageMetrics;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use App\Support\Usage\Features;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
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
