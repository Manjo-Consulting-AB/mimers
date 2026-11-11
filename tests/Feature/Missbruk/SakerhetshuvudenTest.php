<?php

use App\Http\Middleware\SetSecurityHeaders;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Export;
use App\Models\Item;
use App\Models\StoredFile;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

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
