<?php

namespace App\Actions\Container;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\User;
use App\Support\Auth\TwoFactorRequirement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Slår containerns tvåfaktorkrav på eller av, se [[ADR-0092 Tvåfaktor som
 * krav för delade containrar]] och issue 450.
 *
 * **Grinden är `manageAccess`**, samma som inbjudningar och åtkomster. Att slå
 * PÅ kräver dessutom att den som gör det själv har bekräftad tvåfaktor
 * (`two_factor.owner_not_enrolled`); att slå AV kräver ingenting. Gäller
 * kontokravet redan ändras inget och ingen rad skrivs. Ändras inget
 * skrivs ingen rad — en ikryssad ruta som sparas igen är ingen händelse.
 */
class SetContainerRequireTwoFactor
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    public function handle(Container $container, User $actor, bool $require): Container
    {
        Gate::forUser($actor)->authorize('manageAccess', $container);

        return DB::transaction(function () use ($container, $actor, $require): Container {
            // Under lås, så att ett samtidigt DELETE /totp inte hinner förbi.
            if ($require) {
                TwoFactorRequirement::assertOwnerEnrolledLocked($actor);
            }

            $row = Container::query()->whereKey($container->getKey())->lockForUpdate()->firstOrFail();

            if ((bool) $row->require_two_factor === $require) {
                return $container;
            }

            // Kontokravet gäller redan alla containrar: flaggan på containern
            // ändrar ingenting och ger ingen historikrad.
            if ($row->account->require_two_factor) {
                return $container;
            }

            $row->require_two_factor = $require;
            $row->save();

            $this->recordAuditEvent->handle(
                action: $require
                    ? AuditLog::ACTION_CONTAINER_TWO_FACTOR_REQUIRED
                    : AuditLog::ACTION_CONTAINER_TWO_FACTOR_RELEASED,
                account: $row->account,
                user: $actor,
                container: $row,
                subjectType: 'container',
                subjectUlid: $row->ulid,
            );

            return $container->refresh();
        });
    }
}
