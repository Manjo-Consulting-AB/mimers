<?php

use App\Jobs\BuildAccountExport;
use App\Models\Account;
use App\Models\Container;
use App\Models\Export;
use App\Models\SecurityLog;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
 * Issue 1702 · Kontoexportens beställning på webben, nedladdningen och
 * begränsningarna. Se App\Http\Controllers\Settings\AccountExportController,
 * App\Actions\Export\RequestAccountExport, App\Http\Controllers\
 * ExportDownloadController och [[ADR-0098 Kontoexporten]] § 4–7.
 *
 * Hjälparna heter kontoexportBest* — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

beforeEach(function () {
    Storage::fake('files');
    config(['files.internal_redirect' => false]);
    Queue::fake();
});

/**
 * En kontoexport åt $user med sin artefakt på den fejkade disken.
 */
function kontoexportBestFärdig(Account $konto, User $user, string $status = Export::STATUS_READY): Export
{
    $export = Export::factory()->account($konto)->create([
        'requested_by_user_id' => $user->id,
        'status' => $status,
        'expires_at' => now()->addHours(24),
    ]);

    $export->storage_path = 'exports/account-'.$konto->ulid.'/'.$export->ulid.'.zip';
    $export->byte_size = 9;
    $export->save();

    Storage::disk('files')->put($export->storage_path, 'zip-byten');

    return $export;
}

it('köar en kontoexport och skriver account.export_requested i säkerhetsloggen', function () {
    [$konto, $user] = kontoMedMedlem();

    actingAs($user)->post('/settings/account-export')
        ->assertRedirect('/settings/profile')
        ->assertSessionHas('status', 'account-export-requested');

    $export = Export::query()->sole();

    expect($export->kind)->toBe(Export::KIND_ACCOUNT)
        ->and($export->status)->toBe(Export::STATUS_PENDING)
        ->and($export->container_id)->toBeNull()
        ->and($export->account_id)->toBe($konto->id)
        ->and($export->requested_by_user_id)->toBe($user->id);

    Queue::assertPushed(BuildAccountExport::class, fn (BuildAccountExport $job) => $job->export->is($export));

    $rad = SecurityLog::query()->where('action', SecurityLog::ACTION_ACCOUNT_EXPORT_REQUESTED)->sole();

    expect($rad->user_id)->toBe($user->id)
        ->and($rad->account_id)->toBe($konto->id)
        ->and($rad->meta)->toBe(['export' => $export->ulid]);
});

it('ger account_export.in_progress när en export redan pågår, och skriver ingen rad', function () {
    [$konto, $user] = kontoMedMedlem();
    Export::factory()->account($konto)->create(['requested_by_user_id' => $user->id, 'status' => Export::STATUS_RUNNING]);

    actingAs($user)->from('/settings/profile')->post('/settings/account-export')
        ->assertRedirect('/settings/profile')
        ->assertSessionHasErrors(['export' => trans('ui.error.account_export.in_progress')]);

    expect(Export::query()->count())->toBe(1)
        ->and(SecurityLog::query()->where('action', SecurityLog::ACTION_ACCOUNT_EXPORT_REQUESTED)->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('låter en färdig export ligga kvar och tar emot en ny beställning', function () {
    [$konto, $user] = kontoMedMedlem();
    kontoexportBestFärdig($konto, $user);

    actingAs($user)->post('/settings/account-export')->assertRedirect('/settings/profile');

    expect(Export::query()->where('kind', Export::KIND_ACCOUNT)->count())->toBe(2);
});

it('stoppar den fjärde beställningen på ett dygn med 429', function () {
    [, $user] = kontoMedMedlem();

    foreach (range(1, 3) as $i) {
        actingAs($user)->post('/settings/account-export')->assertRedirect('/settings/profile');
        Export::query()->update(['status' => Export::STATUS_READY]);
    }

    actingAs($user)->post('/settings/account-export')
        ->assertRedirect('/settings/profile')
        ->assertSessionHasErrors('export');

    expect(Export::query()->count())->toBe(3);
});

it('räknar begränsningen per person och inte per IP', function () {
    [, $a] = kontoMedMedlem();
    [, $b] = kontoMedMedlem();

    RateLimiter::clear('account-export-user:'.$a->id);

    foreach (range(1, 3) as $i) {
        actingAs($a)->post('/settings/account-export');
        Export::query()->update(['status' => Export::STATUS_READY]);
    }

    actingAs($b)->post('/settings/account-export')->assertSessionDoesntHaveErrors();

    expect(Export::query()->where('requested_by_user_id', $b->id)->count())->toBe(1);
});

it('laddar ner den egna färdiga kontoexporten och loggar nedladdningen', function () {
    [$konto, $user] = kontoMedMedlem();
    $export = kontoexportBestFärdig($konto, $user);

    $svar = actingAs($user)->get("/exports/{$export->ulid}/download")->assertOk();

    expect($svar->headers->get('Content-Type'))->toBe('application/zip')
        ->and($svar->headers->get('Content-Disposition'))->toContain('attachment')
        ->and(SecurityLog::query()->where('action', SecurityLog::ACTION_EXPORT_DOWNLOADED)->count())->toBe(1);
});

it('ger en annan inloggad person 403 på nedladdningen', function () {
    [$konto, $user] = kontoMedMedlem();
    [, $annan] = kontoMedMedlem();
    $export = kontoexportBestFärdig($konto, $user);

    actingAs($annan)->get("/exports/{$export->ulid}/download")->assertForbidden();

    expect(SecurityLog::query()->where('action', SecurityLog::ACTION_EXPORT_DOWNLOADED)->count())->toBe(0);
});

it('ger en medlem av kontot som inte beställde exporten 403', function () {
    [$konto, $user] = kontoMedMedlem();
    $kollega = User::factory()->create();
    $konto->users()->attach($kollega, ['role' => 'member']);
    $export = kontoexportBestFärdig($konto, $user);

    actingAs($kollega)->get("/exports/{$export->ulid}/download")->assertForbidden();
});

it('ger 404 på en kontoexport som inte är klar', function () {
    [$konto, $user] = kontoMedMedlem();
    $export = kontoexportBestFärdig($konto, $user, Export::STATUS_PENDING);

    actingAs($user)->get("/exports/{$export->ulid}/download")->assertNotFound();
});

it('kräver inloggning på nedladdningen och beställningen', function () {
    [$konto, $user] = kontoMedMedlem();
    $export = kontoexportBestFärdig($konto, $user);

    get("/exports/{$export->ulid}/download")->assertRedirect();
    post('/settings/account-export')->assertRedirect('/login');
});

it('låter containerexporternas grind bete sig som förut', function () {
    [$konto, $user] = kontoMedMedlem();
    [, $främling] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    $export = Export::factory()->create([
        'container_id' => $container->id,
        'requested_by_user_id' => $user->id,
        'status' => Export::STATUS_READY,
        'expires_at' => now()->addDays(7),
    ]);
    $export->storage_path = 'exports/'.$container->ulid.'/'.$export->ulid.'.zip';
    $export->save();
    Storage::disk('files')->put($export->storage_path, 'zip-byten');

    actingAs($främling)->get("/exports/{$export->ulid}/download")->assertForbidden();
    actingAs($user)->get("/exports/{$export->ulid}/download")->assertOk();
});

it('raderar en egen kontoexport med filen, och nekar en annans', function () {
    [$konto, $user] = kontoMedMedlem();
    [, $annan] = kontoMedMedlem();
    $export = kontoexportBestFärdig($konto, $user);

    actingAs($annan)->delete("/settings/account-exports/{$export->ulid}")->assertNotFound();
    expect(Export::query()->count())->toBe(1);

    actingAs($user)->delete("/settings/account-exports/{$export->ulid}")
        ->assertRedirect('/settings/profile')
        ->assertSessionHas('status', 'account-export-deleted');

    expect(Export::query()->count())->toBe(0);
    Storage::disk('files')->assertMissing('exports/account-'.$konto->ulid.'/'.$export->ulid.'.zip');
});

it('raderar inte en kontoexport som packas, och inte en containerexport', function () {
    [$konto, $user] = kontoMedMedlem();
    $packas = Export::factory()->account($konto)->create(['requested_by_user_id' => $user->id, 'status' => Export::STATUS_RUNNING]);

    actingAs($user)->from('/settings/profile')->delete("/settings/account-exports/{$packas->ulid}")
        ->assertSessionHasErrors('export');
    expect(Export::query()->count())->toBe(1);

    $container = Container::factory()->for($konto, 'account')->create();
    $zip = Export::factory()->create(['container_id' => $container->id, 'requested_by_user_id' => $user->id, 'status' => Export::STATUS_FAILED]);

    actingAs($user)->delete("/settings/account-exports/{$zip->ulid}")->assertNotFound();
});
