<?php

use App\Jobs\BuildAccountExport;
use App\Models\Container;
use App\Models\Export;
use App\Models\SecurityLog;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 1702 · Kontoexportens API. Se App\Http\Controllers\Api\
 * AccountExportController och [[ADR-0098 Kontoexporten]] § 5.
 */

beforeEach(function () {
    Queue::fake();
});

it('beställer en kontoexport via /api med kind account och container null', function () {
    [$konto, $user, $headers] = kontoMedMedlem();

    $svar = postJson('/api/account/exports', [], $headers)
        ->assertStatus(202)
        ->assertJsonPath('data.kind', 'account')
        ->assertJsonPath('data.container', null)
        ->assertJsonPath('data.status', 'pending');

    expect($svar->json('data'))->toHaveKey('container');

    $export = Export::query()->sole();

    expect($svar->json('data.ulid'))->toBe($export->ulid)
        ->and($export->account_id)->toBe($konto->id);

    Queue::assertPushed(BuildAccountExport::class);

    $rad = SecurityLog::query()->where('action', SecurityLog::ACTION_ACCOUNT_EXPORT_REQUESTED)->sole();
    expect($rad->user_id)->toBe($user->id);
});

it('ger 409 account_export.in_progress medan en export pågår', function () {
    [, , $headers] = kontoMedMedlem();

    postJson('/api/account/exports', [], $headers)->assertStatus(202);

    postJson('/api/account/exports', [], $headers)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'account_export.in_progress');

    expect(Export::query()->count())->toBe(1);
});

it('ger 429 i felformatet på den fjärde beställningen på ett dygn', function () {
    [, , $headers] = kontoMedMedlem();

    foreach (range(1, 3) as $i) {
        postJson('/api/account/exports', [], $headers)->assertStatus(202);
        Export::query()->update(['status' => Export::STATUS_READY]);
    }

    postJson('/api/account/exports', [], $headers)
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'auth.too_many_attempts')
        ->assertJsonStructure(['error' => ['code', 'data' => ['retry_after_seconds']]]);

    expect(Export::query()->count())->toBe(3);
});

it('listar och visar bara den egna personens kontoexporter', function () {
    [$konto, $user, $headers] = kontoMedMedlem();
    [$annatKonto, $annan, $annanHeaders] = kontoMedMedlem();

    $min = Export::factory()->account($konto)->create(['requested_by_user_id' => $user->id]);
    $deras = Export::factory()->account($annatKonto)->create(['requested_by_user_id' => $annan->id]);

    $container = Container::factory()->for($konto, 'account')->create();
    Export::factory()->create(['container_id' => $container->id, 'requested_by_user_id' => $user->id]);

    $lista = getJson('/api/account/exports', $headers)->assertOk();

    expect(collect($lista->json('data'))->pluck('ulid')->all())->toBe([$min->ulid]);

    getJson("/api/account/exports/{$min->ulid}", $headers)
        ->assertOk()
        ->assertJsonPath('data.kind', 'account')
        ->assertJsonPath('data.container', null);

    getJson("/api/account/exports/{$deras->ulid}", $headers)->assertNotFound();
    // Vaktens cachade användare byts ut mellan två personer i samma test.
    app('auth')->forgetGuards();

    getJson("/api/account/exports/{$min->ulid}", $annanHeaders)->assertNotFound();
});

it('låter containerexportens resurs vara utan container-nyckel', function () {
    [$konto, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();
    $zip = Export::factory()->create(['container_id' => $container->id, 'requested_by_user_id' => $user->id]);

    $svar = getJson("/api/containers/{$container->ulid}/exports/{$zip->ulid}", $headers)->assertOk();

    expect($svar->json('data'))->not->toHaveKey('container');

    getJson("/api/account/exports/{$zip->ulid}", $headers)->assertNotFound();
});

it('kräver inloggning', function () {
    postJson('/api/account/exports')->assertUnauthorized();
    getJson('/api/account/exports')->assertUnauthorized();
});
