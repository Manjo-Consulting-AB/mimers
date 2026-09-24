<?php

namespace App\Actions\Container;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Usage\AdjustUsage;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mjukraderar en container och minskar ägarkontots containerantal — paret på
 * ett ställe (issue 62b § Beslut 2), samma resonemang som gjorde
 * App\Actions\Attachment\TrashAttachment till en Action (issue 28 § Beslut 5).
 *
 * Två anropare gör EXAKT samma sak: `DELETE /api/containers/{container}`
 * (App\Http\Controllers\Api\ContainerController::destroy) och webbens
 * raderingsknapp på containerns inställningssida
 * (App\Http\Controllers\ContainerController::destroy). Hade paret legat i två
 * filer skulle de glida isär första gången någon ändrar den ena.
 *
 * Mjukraderingen rör BARA container-raden (issue 8): ingenting kaskaderar.
 * Items, kategorier, taggar, bilagor, åtkomster och inbjudningar ligger kvar
 * orörda och gäller igen om containern återställs.
 *
 * Radlåset och beslutet att räkna grundat på radens tillstånd UNDER låset
 * följer med ordagrant ur `Api\ContainerController::destroy()`: två samtidiga
 * raderingar av samma container skulle annars båda se en levande rad och dra
 * av en gång var. `Container::query()` bär SoftDeletes' globala scope, så en
 * redan mjukraderad rad är inte levande och transaktionen lämnar räknaren i
 * fred.
 *
 * Actionen är verktyget, inte grinden: den tar en container och prövar inte
 * vem som får radera den. Anroparen har redan låst upp vägen genom
 * `Gate::authorize('delete', ...)` mot App\Policies\ContainerPolicy —
 * grindarna stannar i kontrollerna ([[ADR-0024 Tunna controllers och
 * actions]]).
 *
 * `AdjustUsage` anropas här med `new`, inte konstruktorinjicering — medvetet,
 * samma val som TrashAttachment gör och av samma skäl, se [[ADR-0024 Tunna
 * controllers och actions]]. `RecordAuditEvent` (issue 111) anropas på samma
 * sätt, av samma skäl.
 */
class TrashContainer
{
    /**
     * @param  User  $actor  Den som raderar; blir `user_id` på loggraden.
     *                       Behörigheten är redan prövad av anroparen.
     */
    public function handle(Container $container, User $actor): void
    {
        $accountId = $container->account_id;

        DB::transaction(function () use ($container, $actor, $accountId): void {
            $levande = Container::query()
                ->whereKey($container->getKey())
                ->lockForUpdate()
                ->exists();

            if (! $levande) {
                return;
            }

            $container->delete();

            // Mjukraderingen och minskningen i en transaktion (issue 26a) —
            // containern slutar vara levande och lämnar ägarkontots räknare.
            (new AdjustUsage)->handle($accountId, containersDelta: -1);

            // `container.deleted` i samma transaktion (issue 111), och bara
            // på grenen där raden faktiskt var levande: en andra radering är
            // ingen handling och lämnar ingen rad.
            (new RecordAuditEvent)->handle(
                action: AuditLog::ACTION_CONTAINER_DELETED,
                account: $container->account,
                user: $actor,
                container: $container,
                subjectType: 'container',
                subjectUlid: $container->ulid,
            );
        });
    }
}
