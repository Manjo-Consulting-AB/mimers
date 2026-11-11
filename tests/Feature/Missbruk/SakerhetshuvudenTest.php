<?php

use App\Http\Middleware\SetSecurityHeaders;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Export;
use App\Models\Item;
use App\Models\StoredFile;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

/*
 * Issue 1043 · Appens svar bär grundläggande säkerhetshuvuden. Se
 * App\Http\Middleware\SetSecurityHeaders och bootstrap/app.php. Varje
 * "Klart när"-punkt i issuen motsvaras av ett namngivet test här.
 */

it('GET /login bär nosniff, Referrer-Policy och X-Frame-Options DENY', function () {
    $response = get('/login');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('X-Frame-Options', 'DENY');
});

it('ett autentiserat GET /api/containers bär samma tre huvuden', function () {
    [, , $headers] = kontoMedMedlem();

    $response = getJson('/api/containers', $headers)->assertOk();

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('X-Frame-Options', 'DENY');
});

it('ett 404 på webben och ett 404 på API:et bär huvudena', function () {
    $web = get('/den-har-sokvagen-finns-inte')->assertNotFound();
    // Ett 404 ur kontrollern (någon annans export), efter routbindningen. Ett
    // 404 från bindningen själv (okänd ulid) kastas av SubstituteBindings, som
    // ligger FÖRE de tillagda middlewarena och passerar därför aldrig
    // SetSecurityHeaders.
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $export = Export::factory()->create(['container_id' => $container->id]);
    $api = getJson("/api/containers/{$container->ulid}/exports/{$export->ulid}", $headers)->assertNotFound();

    expect($api->json('error.code'))->toBe('resource.not_found');

    foreach ([$web, $api] as $response) {
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }
});

it('HSTS finns över https och saknas över http', function () {
    get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
});

it('nedladdningen utan filorigin saknar X-Frame-Options men bär nosniff och Referrer-Policy', function () {
    Storage::fake('files');
    config(['files.internal_redirect' => false, 'files.url' => null]);

    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $storedFile = StoredFile::factory()->create(['mime_type' => 'application/pdf', 'byte_size' => 5]);
    Storage::disk('files')->put($storedFile->storage_path, 'bytes');
    $item = Item::factory()->for($container, 'container')->create();
    $attachment = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'filename' => 'manual.pdf',
        'kind' => 'document',
    ]);

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertOk();
    expect($response->headers->has('Content-Security-Policy'))->toBeTrue();
    $response->assertHeaderMissing('X-Frame-Options');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('ett svar som redan satt X-Content-Type-Options får det varken dubblerat eller ändrat', function () {
    $response = (new SetSecurityHeaders)->handle(
        Request::create('/x'),
        fn () => (new Response('ok'))->header('X-Content-Type-Options', 'annat-värde'),
    );

    expect($response->headers->all('x-content-type-options'))->toBe(['annat-värde']);
});

/*
 * Issue 1082 · Middlewaren ligger globalt, så svar som ett tidigare steg i
 * kedjan gett själva bär också huvudena.
 */

function bärTreHuvuden($response): void
{
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('X-Frame-Options', 'DENY');
}

it('GET /api/finns-inte utan rutt ger 404 med huvudena', function () {
    bärTreHuvuden(getJson('/api/finns-inte')->assertNotFound());
});

it('ett 404 från routbindningen (okänd ulid) bär huvudena', function () {
    [, , $headers] = kontoMedMedlem();

    $response = getJson('/api/containers/01JZZZZZZZZZZZZZZZZZZZZZZZ', $headers)->assertNotFound();

    expect($response->json('error.code'))->toBe('resource.not_found');
    bärTreHuvuden($response);
});

it('ett 401 utan token bär huvudena', function () {
    bärTreHuvuden(getJson('/api/containers')->assertUnauthorized());
});

it('en utloggad GET /dashboard ger 302 med huvudena', function () {
    bärTreHuvuden(get('/dashboard')->assertRedirect());
});

it('ett webbformulär utan giltig CSRF-token ger 419 med huvudena', function () {
    // CSRF-kontrollen hoppar över sig själv i testmiljön; låtsas att vi inte är i test.
    app()->detectEnvironment(fn () => 'production');

    bärTreHuvuden(post('/tips/dismiss')->assertStatus(419));
});

it('ett 429 från anropsbegränsningen bär huvudena', function () {
    foreach (range(1, 10) as $ignore) {
        postJson('/api/register', []);
    }

    bärTreHuvuden(postJson('/api/register', [])->assertStatus(429));
});

it('HSTS finns på ett 401 över https och saknas över http', function () {
    getJson('https://localhost/api/containers')->assertUnauthorized()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    getJson('http://localhost/api/containers')->assertUnauthorized()
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('SetSecurityHeaders står globalt och inte i web- eller api-gruppen', function () {
    expect(app(Kernel::class)->hasMiddleware(SetSecurityHeaders::class))->toBeTrue();

    $groups = app('router')->getMiddlewareGroups();
    expect($groups['web'])->not->toContain(SetSecurityHeaders::class);
    expect($groups['api'])->not->toContain(SetSecurityHeaders::class);
});
