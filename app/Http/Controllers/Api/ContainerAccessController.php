<?php

namespace App\Http\Controllers\Api;

use App\Actions\Audit\RecordAuditEvent;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ContainerAccess\StoreContainerAccessRequest;
use App\Http\Resources\ContainerAccessResource;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\User;
use App\Support\Plan\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * API-ytan för att bevilja, lista och återkalla delegerade åtkomster till
 * en container, se issue 9b. INGEN behörighetslogik bor här — varje metod
 * anropar bara `Gate::authorize()` och litar på svaret från
 * App\Policies\ContainerPolicy::viewAccesses()/manageAccess()/revokeAccess(),
 * se ADR-0024 och issue 9b § Beslut 2.
 *
 * `routes/api.php` nästlar {access} under {container} med
 * `->scopeBindings()` — en ULID från en annan container löser aldrig upp
 * här, se issue 9b § Beslut 1.
 *
 * Deltagarlistan (issue 9c) hör INTE hemma här — det är en annan yta, en
 * annan resurs och en annan grind, se issue 9b § Omfång.
 */
class ContainerAccessController extends Controller
{
    /**
     * GET /api/containers/{container}/accesses — 200. Visar ALLA rader,
     * även återkallade och utgångna (issue 9b § Beslut 10), sorterat
     * `created_at` fallande — klienten avgör presentationen ur
     * `revoked_at`/`expires_at`, resursen härleder ingen `status`.
     *
     * `viewAccesses()` är bara regel 1 (medlemskap) — inget
     * `read_only`-filter, se App\Policies\ContainerPolicy.
     */
    public function index(Container $container): JsonResponse
    {
        Gate::authorize('viewAccesses', $container);

        $accesses = $container->accesses()
            ->orderByDesc('created_at')
            ->get();

        $this->hydrateGranteeUlids($accesses);

        return ContainerAccessResource::collection($accesses)->response();
    }

    /**
     * POST /api/containers/{container}/accesses — 201.
     * `StoreContainerAccessRequest` har redan bevisat att mottagaren
     * existerar, att `kind`/`grantee_type` stämmer överens (issue 9b §
     * Beslut 4) och att mottagaren inte är ägarkontot självt (§ Beslut 6)
     * — inget av det görs om här.
     *
     * `manageAccess()` avgör behörighet (regel 1 + regel 4).
     *
     * § Beslut 7: bara EN giltig åtkomst per mottagare och container.
     * Kontrollen återanvänder `ContainerAccess::scopeValidFor()` — samma
     * villkor som policyn prövar, se § Att se upp med ("två formuleringar
     * av giltig access som kan glida isär"). Filtrerar först till exakt
     * den här mottagaren, kedjar sedan `validFor()` för
     * inte-återkallad/inte-utgången-villkoret; argumenten till
     * `validFor()` är valda så dess egen mottagar-OR redan är uppfylld av
     * det första filtret, oavsett `grantee_type`.
     *
     * Hittas en giltig rad: `ApiException` (`container_access.already_granted`,
     * 422) med den befintliga radens ULID i `data.access` — ett
     * tillståndsfel i domänen, inte ett fältfel, se issue 7 § Beslut 2.
     *
     * `container_id` och `granted_by_user_id` sätts explicit på
     * modellinstansen, aldrig via massildelning — se
     * App\Models\ContainerAccess docblock och issue 9b § Beslut 8.
     */
    public function store(StoreContainerAccessRequest $request, Container $container, Entitlements $entitlements): JsonResponse
    {
        Gate::authorize('manageAccess', $container);

        $granteeType = $request->validated('grantee_type');

        $granteeModel = $granteeType === 'user'
            ? User::where('ulid', $request->validated('grantee'))->firstOrFail()
            : Account::where('ulid', $request->validated('grantee'))->firstOrFail();

        $existing = $container->accesses()
            ->where('grantee_type', $granteeType)
            ->where('grantee_id', $granteeModel->id)
            ->validFor(
                $granteeType === 'user' ? $granteeModel : $request->user(),
                $granteeType === 'account' ? [$granteeModel->id] : [],
            )
            ->first();

        if ($existing !== null) {
            throw ApiException::make('container_access.already_granted', ['access' => $existing->ulid], 422);
        }

        // Kvotkontrollen efter Gate (Beslut 3) och efter dubblettspärren: att
        // bevilja någon som redan har en giltig åtkomst är inte en ny
        // delning. Båda ingångarna till delning — direkt åtkomst här och
        // inbjudan i ContainerInvitationController — delar samma tak (issue
        // 27 § Beslut 5).
        $entitlements->assertCanShareContainer($container);

        $access = new ContainerAccess($request->safe()->only(['grantee_type', 'level', 'kind', 'expires_at']));
        $access->grantee_id = $granteeModel->id;
        $access->container_id = $container->id;
        $access->granted_by_user_id = $request->user()->id;
        $access->save();

        $access->setAttribute('grantee_ulid', $granteeModel->ulid);
        $access->setAttribute('granted_by_ulid', $request->user()->ulid);

        return (new ContainerAccessResource($access))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * DELETE /api/containers/{container}/accesses/{access} — 204, ingen
     * kropp. `revokeAccess()` auktoriserar — regel 1 bara, se issue 9b §
     * Beslut 2 och § Att se upp med.
     *
     * Sätter `revoked_at`, raderar aldrig raden (issue 9b § Beslut 9).
     * Redan återkallad: 204 utan att röra `revoked_at` — den ursprungliga
     * tidsstämpeln är historiken (9a § Beslut 4) och en andra `DELETE` får
     * inte skriva om den. `ContainerAccess` använder inte `SoftDeletes`.
     *
     * Sedan issue 40 sker själva återkallningen och `access.revoked`-raden i
     * audit_log i EN transaktion (Beslut 10) — en logg som skrevs utanför
     * transaktionen kunde överleva ett rollback och beskriva en återkallelse
     * som inte hände. En redan återkallad rad rörs inte och loggas inte en
     * andra gång.
     */
    public function destroy(Request $request, Container $container, ContainerAccess $access): Response
    {
        Gate::authorize('revokeAccess', $container);

        // ULID:en till `meta.grantee` löses upp i förväg — `ContainerAccess`
        // har medvetet ingen `grantee()`-relation (se modellens docblock), så
        // uppslagningen görs som en platt fråga, samma teknik som
        // hydrateGranteeUlids() nedan.
        $granteeUlid = $access->grantee_type === 'user'
            ? User::query()->whereKey($access->grantee_id)->value('ulid')
            : Account::query()->whereKey($access->grantee_id)->value('ulid');

        DB::transaction(function () use ($request, $container, $access, $granteeUlid): void {
            if ($access->revoked_at === null) {
                $access->revoked_at = now();
                $access->save();

                /** @var User $revoker */
                $revoker = $request->user();

                (new RecordAuditEvent)->handle(
                    action: AuditLog::ACTION_ACCESS_REVOKED,
                    account: $container->account,
                    user: $revoker,
                    container: $container,
                    subjectType: 'container_access',
                    subjectUlid: $access->ulid,
                    meta: [
                        'grantee_type' => $access->grantee_type,
                        'grantee' => $granteeUlid,
                        'level' => $access->level,
                        'kind' => $access->kind,
                    ],
                );
            }
        });

        return response()->noContent();
    }

    /**
     * Löser upp mottagarens och beviljarens ULID i EN fråga vardera,
     * oavsett antal rader (issue 9b § Beslut 11) — `ContainerAccess` har
     * medvetet ingen `grantee()`-relation (se modellens docblock), så
     * uppslagningen görs här i stället för i resursen. Sätts på varje
     * modellinstans med `setAttribute()` innan `ContainerAccessResource`
     * läser dem.
     *
     * @param  Collection<int, ContainerAccess>  $accesses
     */
    private function hydrateGranteeUlids(Collection $accesses): void
    {
        $userIds = $accesses->pluck('granted_by_user_id')
            ->merge($accesses->where('grantee_type', 'user')->pluck('grantee_id'))
            ->unique()
            ->values()
            ->all();

        $accountIds = $accesses->where('grantee_type', 'account')
            ->pluck('grantee_id')
            ->unique()
            ->values()
            ->all();

        $userUlids = User::query()->whereIn('id', $userIds)->pluck('ulid', 'id');
        $accountUlids = Account::query()->whereIn('id', $accountIds)->pluck('ulid', 'id');

        foreach ($accesses as $access) {
            $access->setAttribute('granted_by_ulid', $userUlids->get($access->granted_by_user_id));
            $access->setAttribute(
                'grantee_ulid',
                $access->grantee_type === 'user'
                    ? $userUlids->get($access->grantee_id)
                    : $accountUlids->get($access->grantee_id),
            );
        }
    }
}
