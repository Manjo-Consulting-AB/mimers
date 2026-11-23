<?php

use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Export;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

/*
 * Issue 1564 · Radera en export på exportsidan. Se
 * App\Http\Controllers\ExportController::destroy(), routes/web.php,
 * resources/js/components/ExportRow.vue och exportPresentation.js.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 *
 * Hjälparna heter exportRadering* för att inte krocka med hjälparna i de
 * andra exporttesterna (tests/Support/Testhjalpare.php-doktrinen).
 */

beforeEach(function () {
    Storage::fake('files');
});

/**
 * @return array{0: User, 1: Container}
 */
function exportRaderingKontext(): array
{
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    return [$user, $container];
}

/**
 * En export under $container, beställd av $user. Har den en fil ligger
 * bytena på den fejkade disken under den sökväg produktionen bygger.
 *
 * @param  array<string, mixed>  $attribut
 */
function exportRaderingRad(Container $container, User $user, array $attribut = [], bool $medFil = true): Export
{
    $export = Export::factory()->create(array_merge([
        'container_id' => $container->id,
        'requested_by_user_id' => $user->id,
        'status' => Export::STATUS_READY,
        'expires_at' => now()->addDays(7),
    ], $attribut));

    if ($medFil) {
        $export->storage_path = 'exports/'.$container->ulid.'/'.$export->ulid.'.zip';
        $export->byte_size = 9;
        $export->save();

        Storage::disk('files')->put($export->storage_path, 'zip-byten');
    }

    return $export;
}

function exportRaderingUrl(Container $container, Export $export): string
{
    return "/containers/{$container->ulid}/exports/{$export->ulid}";
}

function exportRaderingLoggrader(): int
{
    return AuditLog::query()->where('action', AuditLog::ACTION_EXPORT_DELETED)->count();
}

it('raderar en aktiv export med filen och loggar export.deleted i historiken', function () {
    [$user, $container] = exportRaderingKontext();
    $export = exportRaderingRad($container, $user, ['kind' => Export::KIND_BINDER]);
    $sökväg = $export->storage_path;

    actingAs($user)
        ->delete(exportRaderingUrl($container, $export))
        ->assertRedirect("/containers/{$container->ulid}/export")
        ->assertSessionHas('status', 'export-deleted');

    Storage::disk('files')->assertMissing($sökväg);
    expect(Export::query()->whereKey($export->id)->exists())->toBeFalse();

    $rad = AuditLog::query()->where('action', AuditLog::ACTION_EXPORT_DELETED)->sole();
    expect($rad->container_id)->toBe($container->id);
    expect($rad->account_id)->toBe($container->account_id);
    expect($rad->user_id)->toBe($user->id);
    expect($rad->subject_type)->toBe('export');
    expect($rad->subject_id)->toBe($export->ulid);
    expect($rad->meta)->toBe(['kind' => Export::KIND_BINDER]);
});

it('raderar en utgången export utan rad i historiken', function () {
    [$user, $container] = exportRaderingKontext();
    $utgången = exportRaderingRad($container, $user, ['status' => Export::STATUS_EXPIRED, 'expires_at' => now()->subDay()], medFil: false);

    // `ready` med passerad `expires_at` är utgången för läsaren redan innan
    // gallringen satt statusen, och raderingen behandlar den likadant.
    $passerad = exportRaderingRad($container, $user, ['expires_at' => now()->subHour()]);
    $sökväg = $passerad->storage_path;

    actingAs($user)->delete(exportRaderingUrl($container, $utgången))->assertRedirect();
    actingAs($user)->delete(exportRaderingUrl($container, $passerad))->assertRedirect();

    expect(Export::query()->whereKey([$utgången->id, $passerad->id])->exists())->toBeFalse();
    Storage::disk('files')->assertMissing($sökväg);
    expect(exportRaderingLoggrader())->toBe(0);
});

it('raderar en misslyckad export och tar bort en kvarglömd fil', function () {
    [$user, $container] = exportRaderingKontext();
    $export = exportRaderingRad($container, $user, ['status' => Export::STATUS_FAILED, 'expires_at' => null]);
    $sökväg = $export->storage_path;

    actingAs($user)->delete(exportRaderingUrl($container, $export))->assertRedirect();

    expect(Export::query()->whereKey($export->id)->exists())->toBeFalse();
    Storage::disk('files')->assertMissing($sökväg);
    expect(exportRaderingLoggrader())->toBe(0);
});

it('raderar inte en export som packas, och svarar med ett formulärfel på export', function (string $status) {
    [$user, $container] = exportRaderingKontext();
    $export = exportRaderingRad($container, $user, ['status' => $status, 'expires_at' => null], medFil: false);

    actingAs($user)
        ->from("/containers/{$container->ulid}/export")
        ->delete(exportRaderingUrl($container, $export))
        ->assertRedirect("/containers/{$container->ulid}/export")
        ->assertSessionHasErrors('export');

    expect(Export::query()->whereKey($export->id)->exists())->toBeTrue();
    expect(exportRaderingLoggrader())->toBe(0);
})->with([Export::STATUS_PENDING, Export::STATUS_RUNNING]);

it('svarar 404 på en annan användares export och på en export i en annan container', function () {
    [$ägare, $container] = exportRaderingKontext();
    $ägarens = exportRaderingRad($container, $ägare);

    $deltagare = User::factory()->create();
    beviljaAccess($container, $deltagare, 'read', 'member');

    actingAs($deltagare)->delete(exportRaderingUrl($container, $ägarens))->assertNotFound();

    $annan = Container::factory()->for($container->account, 'account')->create();
    $iAnnan = exportRaderingRad($annan, $ägare);

    actingAs($ägare)->delete(exportRaderingUrl($container, $iAnnan))->assertNotFound();

    expect(Export::query()->whereKey([$ägarens->id, $iAnnan->id])->count())->toBe(2);
    Storage::disk('files')->assertExists($ägarens->storage_path);
    expect(exportRaderingLoggrader())->toBe(0);
});

it('nekar en användare utan view på containern', function () {
    [$ägare, $container] = exportRaderingKontext();
    $export = exportRaderingRad($container, $ägare);

    // Beställaren har förlorat åtkomsten: raden är hens, men grinden är
    // containerns `view`, och den prövas först.
    $tidigare = User::factory()->create();
    $access = beviljaAccess($container, $tidigare, 'read', 'member');
    $egen = exportRaderingRad($container, $tidigare);
    $access->delete();

    actingAs($tidigare)->delete(exportRaderingUrl($container, $egen))->assertForbidden();

    [$främling] = exportRaderingKontext();
    actingAs($främling)->delete(exportRaderingUrl($container, $export))->assertForbidden();

    expect(Export::query()->whereKey([$export->id, $egen->id])->count())->toBe(2);
});

it('raderar exporten även när filen redan saknas på disken', function () {
    [$user, $container] = exportRaderingKontext();
    $export = exportRaderingRad($container, $user);
    Storage::disk('files')->delete($export->storage_path);

    actingAs($user)->delete(exportRaderingUrl($container, $export))->assertRedirect();

    expect(Export::query()->whereKey($export->id)->exists())->toBeFalse();
    expect(exportRaderingLoggrader())->toBe(1);
});

it('ritar en raderingsknapp utom när raden packas, och historiken har en mening för export.deleted', function () {
    $modul = File::get(resource_path('js/components/exportPresentation.js'));
    expect($modul)->toContain('export function canDeleteExport(row)');
    expect($modul)->toContain('return ! isOpenExport(row);');

    $rad = File::get(resource_path('js/components/ExportRow.vue'));
    expect($rad)->toContain('const deletable = computed(() => canDeleteExport(props.row));');
    expect($rad)->toContain('v-if="deletable"');
    expect($rad)->toContain('router.delete(`/containers/${props.containerUlid}/exports/${props.row.ulid}`');
    expect($rad)->toContain('<UiConfirmDialog');

    $sida = File::get(resource_path('js/pages/Containers/Export.vue'));
    expect($sida)->toContain(':container-ulid="container.ulid"');

    $ui = require lang_path('en/ui.php');
    expect($ui['audit']['action']['export']['deleted'])->toBe(':user deleted an export');
    expect($ui['export']['delete'])->toBe('Delete');
    expect($ui['error']['export']['still_running'])->toBeString()->not->toBe('');
});
