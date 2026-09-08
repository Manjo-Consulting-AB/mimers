<?php

use App\Console\PurgesExpiredExports;
use App\Models\Account;
use App\Models\Container;
use App\Models\Export;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

/*
 * Issue 41b · Nedladdning av en färdig export och den nattliga gallringen. Se
 * App\Http\Controllers\ExportDownloadController, App\Console\PurgesExpiredExports,
 * routes/web.php, routes/console.php, config/files.php § export_retention_days
 * och [[ADR-0019 Filleverans]].
 *
 * Nedladdningstesterna härmar tests/Feature/Attachment/NedladdningTest.php;
 * gallringstesterna härmar tests/Feature/Attachment/FysiskRaderingTest.php.
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 *
 * Storage::fake('files') i beforeEach — inga bytes får hamna i den riktiga
 * storage/files/ när sviten körs. config('files.internal_redirect') nollställs
 * i beforeEach: testerna för den sanna grenen sätter den i testet (Beslut 6).
 *
 * Hjälparna heter exportNedladdning* för att inte krocka med exportering* i
 * ExportTest.php (tests/Support/Testhjalpare.php-doktrinen).
 */

beforeEach(function () {
    Storage::fake('files');
    config(['files.internal_redirect' => false]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En `ready`-export under $container, beställd av $user, med artefakten på
 * den fejkade disken under den sökväg produktionen skulle ha byggt.
 *
 * @param  array<string, mixed>  $attribut
 */
function exportNedladdningFärdig(Container $container, User $user, array $attribut = [], string $innehåll = 'zip-byten'): Export
{
    $export = Export::factory()->create(array_merge([
        'container_id' => $container->id,
        'requested_by_user_id' => $user->id,
        'status' => Export::STATUS_READY,
        'expires_at' => now()->addDays(7),
    ], $attribut));

    $export->storage_path = 'exports/'.$container->ulid.'/'.$export->ulid.'.zip';
    $export->byte_size = strlen($innehåll);
    $export->save();

    Storage::disk('files')->put($export->storage_path, $innehåll);

    return $export;
}

// --- Nedladdning -----------------------------------------------------------

it('en färdig export laddas ner av ägarkontots medlem med rätt headers', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $export = exportNedladdningFärdig($container, $user);

    $response = actingAs($user)->get("/exports/{$export->ulid}/download");

    $response->assertOk();
    expect($response->streamedContent())->toBe('zip-byten');
    $response->assertHeader('Content-Type', 'application/zip');
    expect($response->headers->get('content-disposition'))->toStartWith('attachment');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeaderMissing('X-LiteSpeed-Location');
});

it('en användare med giltig read-åtkomst kan ladda ner; en utan åtkomst får 403', function () {
    [$account, $owner] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $export = exportNedladdningFärdig($container, $owner);

    [, $delegated] = kontoMedMedlem();
    beviljaAccess($container, $delegated, 'read', 'member');

    actingAs($delegated)->get("/exports/{$export->ulid}/download")->assertOk();

    [, $främling] = kontoMedMedlem();
    actingAs($främling)->get("/exports/{$export->ulid}/download")->assertForbidden();
});

it('en användare vars konto är read_only kan ladda ner sin export', function () {
    $account = Account::factory()->create(['status' => 'read_only']);
    $user = User::factory()->create();
    $account->users()->attach($user, ['role' => 'owner']);
    $container = Container::factory()->for($account, 'account')->create();
    $export = exportNedladdningFärdig($container, $user);

    $response = actingAs($user)->get("/exports/{$export->ulid}/download");

    $response->assertOk();
    expect($response->streamedContent())->toBe('zip-byten');
});

it('en export i pending, running, failed eller expired ger 404', function (string $status) {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $export = Export::factory()->create([
        'container_id' => $container->id,
        'requested_by_user_id' => $user->id,
        'status' => $status,
        'storage_path' => null,
        'byte_size' => null,
        'expires_at' => null,
    ]);

    $response = actingAs($user)->get("/exports/{$export->ulid}/download");

    $response->assertNotFound();
})->with([
    'pending' => [Export::STATUS_PENDING],
    'running' => [Export::STATUS_RUNNING],
    'failed' => [Export::STATUS_FAILED],
    'expired' => [Export::STATUS_EXPIRED],
]);

it('en export vars fil saknas på disken ger 404, inte ett serverfel', function (bool $internalRedirect) {
    config(['files.internal_redirect' => $internalRedirect]);

    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $export = exportNedladdningFärdig($container, $user);
    Storage::disk('files')->delete($export->storage_path);

    $response = actingAs($user)->get("/exports/{$export->ulid}/download");

    $response->assertNotFound();
})->with([
    'strömning' => [false],
    'intern omdirigering' => [true],
]);

it('svaret bär X-LiteSpeed-Location med samma headers när intern omdirigering är på', function () {
    config(['files.internal_redirect' => true]);

    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $export = exportNedladdningFärdig($container, $user);

    $response = actingAs($user)->get("/exports/{$export->ulid}/download");

    $response->assertOk();
    expect($response->getContent())->toBe('');
    $response->assertHeader('X-LiteSpeed-Location', '/_protected/'.$export->storage_path);
    $response->assertHeader('Content-Type', 'application/zip');
    expect($response->headers->get('content-disposition'))->toStartWith('attachment');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('ett containernamn med å, citattecken och semikolon ger ett giltigt Content-Disposition', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Gård "sommar"; kvitto']);
    $export = exportNedladdningFärdig($container, $user);

    $response = actingAs($user)->get("/exports/{$export->ulid}/download");

    $response->assertOk();
    $disposition = $response->headers->get('content-disposition');
    expect($disposition)->toStartWith('attachment');
    expect($disposition)->toContain('filename=');
    expect($disposition)->toContain('filename*=');
    // å:et i Gård är procentkodat i filename*; ASCII-fallbacken finns i filename=.
    expect($disposition)->toContain('C3%A5');
});

it('en mjukraderad container ger 404', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $export = exportNedladdningFärdig($container, $user);
    $container->delete();

    $response = actingAs($user)->get("/exports/{$export->ulid}/download");

    $response->assertNotFound();
});

it('oautentiserad begäran nekas', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $export = exportNedladdningFärdig($container, $user);

    $response = get("/exports/{$export->ulid}/download");

    expect($response->getStatusCode())->toBeIn([302, 401]);
});

it('en okänd ulid ger 404', function () {
    [$account, $user] = kontoMedMedlem();
    Container::factory()->for($account, 'account')->create();

    $response = actingAs($user)->get('/exports/01ARZ3NDEKTSV4RRFFQ69G5FAV/download');

    $response->assertNotFound();
});

// --- Gallring --------------------------------------------------------------

it('gallringen tar bort filen och sätter raden till expired med storage_path null, medan raden finns kvar', function () {
    Carbon::setTestNow('2026-09-08 12:00:00');

    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $export = exportNedladdningFärdig($container, $user, ['expires_at' => now()->subDay()]);
    $path = $export->storage_path;

    $antal = (new PurgesExpiredExports)->handle();

    expect($antal)->toBe(1);
    $export->refresh();
    expect($export->status)->toBe(Export::STATUS_EXPIRED);
    expect($export->storage_path)->toBeNull();
    expect($export->byte_size)->toBeNull();
    expect(Export::count())->toBe(1);
    expect(Storage::disk('files')->exists($path))->toBeFalse();
});

it('en export vars expires_at inte passerats rörs inte av gallringen', function () {
    Carbon::setTestNow('2026-09-08 12:00:00');

    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $export = exportNedladdningFärdig($container, $user, ['expires_at' => now()->addDay()]);
    $path = $export->storage_path;

    $antal = (new PurgesExpiredExports)->handle();

    expect($antal)->toBe(0);
    $export->refresh();
    expect($export->status)->toBe(Export::STATUS_READY);
    expect($export->storage_path)->toBe($path);
    expect(Storage::disk('files')->exists($path))->toBeTrue();
});

it('en ready-rad vars fil redan är borta gallras ändå, och efterföljande rader behandlas', function () {
    Carbon::setTestNow('2026-09-08 12:00:00');

    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $utanFil = exportNedladdningFärdig($container, $user, ['expires_at' => now()->subDay()]);
    Storage::disk('files')->delete($utanFil->storage_path);
    $medFil = exportNedladdningFärdig($container, $user, ['expires_at' => now()->subDay()]);
    $medFilPath = $medFil->storage_path;

    $antal = (new PurgesExpiredExports)->handle();

    expect($antal)->toBe(2);
    expect($utanFil->refresh()->status)->toBe(Export::STATUS_EXPIRED);
    expect($medFil->refresh()->status)->toBe(Export::STATUS_EXPIRED);
    expect($medFil->refresh()->storage_path)->toBeNull();
    expect(Storage::disk('files')->exists($medFilPath))->toBeFalse();
});

it('en failed-rad äldre än retentionen gallras: .part-filen tas bort och raden sätts till expired', function () {
    Carbon::setTestNow('2026-09-08 12:00:00');

    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $export = Export::factory()->create([
        'container_id' => $container->id,
        'requested_by_user_id' => $user->id,
        'status' => Export::STATUS_FAILED,
        'created_at' => now()->subDays(30),
    ]);
    $partial = 'exports/'.$container->ulid.'/'.$export->ulid.'.zip.part';
    Storage::disk('files')->put($partial, 'halvskriven');

    $antal = (new PurgesExpiredExports)->handle();

    expect($antal)->toBe(1);
    expect($export->refresh()->status)->toBe(Export::STATUS_EXPIRED);
    expect($export->refresh()->storage_path)->toBeNull();
    expect(Storage::disk('files')->exists($partial))->toBeFalse();
    expect(Export::count())->toBe(1);
});

it('en .part-fil äldre än ett dygn tas bort, en färsk ligger kvar', function () {
    Carbon::setTestNow('2026-09-08 12:00:00');

    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $export = Export::factory()->create([
        'container_id' => $container->id,
        'requested_by_user_id' => $user->id,
        'status' => Export::STATUS_RUNNING,
    ]);
    $gammal = 'exports/'.$container->ulid.'/'.$export->ulid.'.zip.part';
    $färsk = 'exports/'.$container->ulid.'/'.$export->ulid.'-2.zip.part';

    Storage::disk('files')->put($gammal, 'gammal');
    Storage::disk('files')->put($färsk, 'färsk');

    // Backdatera bara den gamla filens mtime — den färska har "nu".
    touch(Storage::disk('files')->path($gammal), now()->subDays(2)->getTimestamp());

    (new PurgesExpiredExports)->handle();

    expect(Storage::disk('files')->exists($gammal))->toBeFalse();
    expect(Storage::disk('files')->exists($färsk))->toBeTrue();
});

it('jobbet är schemalagt dagligen med Schedule::call', function () {
    artisan('inspire');

    $händelse = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'purge-expired-exports');

    expect($händelse)->not->toBeNull();
    expect($händelse->getExpression())->toBe('0 0 * * *');
    expect($händelse->command ?? null)->toBeNull();
});
