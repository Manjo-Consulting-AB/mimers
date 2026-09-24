<?php

namespace App\Actions\Access;

use App\Actions\Audit\RecordAuditEvent;
use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use App\Support\Plan\Entitlements;
use Illuminate\Support\Facades\DB;

/**
 * Beviljar en åtkomst direkt, utan inbjudan — dubblettspärren, delningstaket
 * och `access.granted` i EN transaktion. Se issue 9b, issue 72 § Beslut 3 och
 * 6, och [[ADR-0043 Tre loggar]] § Händelseloggen.
 *
 * Bryts ut ur App\Http\Controllers\Api\ContainerAccessController::store() i
 * issue 111. **Webben har ingen `store()`** — den bjuder in, se
 * App\Http\Controllers\ContainerAccessController § Beslut 2 — så den här
 * actionen har en enda anropare i dag. Den skrivs ändå som en Action: raden i
 * `audit_log` ska skrivas i handlingens transaktion, och `meta` för en åtkomst
 * har redan en förlaga i App\Actions\Access\RevokeContainerAccess, som den
 * här speglar fält för fält. En kopia inne i kontrollern hade varit en andra
 * sanning om vad en åtkomst ÄR.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen
 * (`manageAccess()`), precis som för de två andra Actionerna i den här
 * mappen — se issue 54 § Beslut 3 och issue 55a § Beslut 8.
 *
 * **Ingen e-postadress i `meta`.** `grantee` är mottagarens ULID, och en
 * adress är det enda `meta` aldrig får bära (issue 40 § Beslut 10).
 */
class GrantContainerAccess
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * @param  User  $actor  Den som beviljar. Behörigheten är redan prövad av
     *                       anroparen; hen blir `user_id` på loggraden och
     *                       `granted_by_user_id` på raden.
     * @param  User|Account  $grantee  Mottagaren, redan uppslagen på sin ULID
     *                                 av anroparen.
     * @param  string  $granteeType  `user` eller `account`.
     * @param  Item|null  $item  Itemet åtkomsten avgränsas till, eller `null`
     *                           för hela containern. Anroparen har redan
     *                           bevisat att det finns i DEN HÄR containern och
     *                           är levande (`StoreContainerAccessRequest`).
     * @param  array<string, mixed>  $attributes  `level`, `kind` och
     *                                            `expires_at`, redan validerade.
     *
     * @throws ApiException 422 `container_access.already_granted`, 403
     *                      `quota.shared_users_exceeded`.
     */
    public function handle(
        Container $container,
        User $actor,
        User|Account $grantee,
        string $granteeType,
        ?Item $item,
        array $attributes,
    ): ContainerAccess {
        // Dubblettspärren gäller `(container, item, mottagare)` och inte
        // `(container, mottagare)` sedan issue 72 § Beslut 3: `where('item_id',
        // null)` blir `is null` för en container-bred grant, så samma rad bär
        // båda fallen. Det ger tre svar — två container-breda rader för samma
        // mottagare avvisas, två rader för samma mottagare OCH samma item
        // avvisas, medan en container-bred rad PLUS en itemrad tillåts. Det
        // sista är hela poängen med [[ADR-0028 Åtkomst på itemnivå]] § Beslut
        // regel 4: mottagaren får `read` på containern och `write` på motorn,
        // och upplösningen tar max.
        $existing = $container->accesses()
            ->where('grantee_type', $granteeType)
            ->where('grantee_id', $grantee->id)
            ->where('item_id', $item?->id)
            ->validFor(
                $grantee instanceof User ? $grantee : $actor,
                $grantee instanceof Account ? [$grantee->id] : [],
            )
            ->first();

        if ($existing !== null) {
            throw ApiException::make('container_access.already_granted', ['access' => $existing->ulid], 422);
        }

        // Kvotkontrollen efter dubblettspärren: att bevilja någon som redan har
        // en giltig åtkomst är inte en ny delning. Båda ingångarna till delning
        // — direkt åtkomst här och inbjudan i CreateInvitation — delar samma
        // tak (issue 27 § Beslut 5), och taket räknar distinkta mottagare, inte
        // rader (issue 72 § Beslut 6).
        $this->entitlements->assertCanShareContainer($container);

        return DB::transaction(function () use ($container, $actor, $grantee, $granteeType, $item, $attributes): ContainerAccess {
            $access = new ContainerAccess($attributes);
            $access->grantee_type = $granteeType;
            $access->grantee_id = $grantee->id;
            $access->container_id = $container->id;
            $access->item_id = $item?->id;
            $access->granted_by_user_id = $actor->id;
            $access->save();

            // `meta` speglar App\Actions\Access\RevokeContainerAccess fält för
            // fält: mottagarens typ, ULID, nivå och sort — och itemet när raden
            // gäller ett. Aldrig en e-postadress (issue 40 § Beslut 10).
            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_ACCESS_GRANTED,
                account: $container->account,
                user: $actor,
                container: $container,
                item: $item,
                subjectType: 'container_access',
                subjectUlid: $access->ulid,
                meta: [
                    'grantee_type' => $granteeType,
                    'grantee' => $grantee->ulid,
                    'item' => $item?->ulid,
                    'level' => $access->level,
                    'kind' => $access->kind,
                ],
            );

            return $access;
        });
    }
}
