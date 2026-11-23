<?php

use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Notification;

use function Pest\Laravel\postJson;

/*
 * Issue 1529 (M75) · POST /api/containers/{container}/leave.
 */

function lamnaApiBas(): array
{
    [$konto, $agare, $agarHuvuden] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();
    [, $mottagare, $huvuden] = kontoMedMedlem();
    $rad = beviljaAccess($container, $mottagare, 'read', 'guest');

    return [$container, $mottagare, $huvuden, $rad, $agarHuvuden];
}

it('svarar 204, återkallar raden med personen som aktör och ger ägaren en notis', function () {
    [$container, $mottagare, $huvuden, $rad] = lamnaApiBas();

    postJson("/api/containers/{$container->ulid}/leave", [], $huvuden)->assertNoContent();

    expect($rad->refresh()->revoked_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', AuditLog::ACTION_ACCESS_REVOKED)->sole()->user_id)->toBe($mottagare->id)
        ->and(Notification::query()->where('type', Notification::TYPE_ACCESS_LEFT)->count())->toBe(1);
});

it('ett andra anrop ger 204 utan ny loggrad eller notis', function () {
    [$container, , $huvuden] = lamnaApiBas();

    postJson("/api/containers/{$container->ulid}/leave", [], $huvuden)->assertNoContent();
    postJson("/api/containers/{$container->ulid}/leave", [], $huvuden)->assertNoContent();

    expect(AuditLog::query()->where('action', AuditLog::ACTION_ACCESS_REVOKED)->count())->toBe(1)
        ->and(Notification::query()->where('type', Notification::TYPE_ACCESS_LEFT)->count())->toBe(1);
});

it('ägarkontots medlem och en person utan rad får 403 med den vanliga felkoden', function () {
    [$container, , , , $agarHuvuden] = lamnaApiBas();
    [, , $annanHuvuden] = kontoMedMedlem();

    foreach ([$agarHuvuden, $annanHuvuden] as $huvuden) {
        $svar = postJson("/api/containers/{$container->ulid}/leave", [], $huvuden)->assertForbidden();
        expect($svar->json('error.code'))->toBe('auth.forbidden');
    }

    expect(ContainerAccess::query()->whereNull('revoked_at')->count())->toBe(1);
});
