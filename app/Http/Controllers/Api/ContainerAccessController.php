<?php

namespace App\Http\Controllers\Api;

use App\Actions\Access\ResolveItemScope;
use App\Actions\Audit\RecordAuditEvent;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ContainerAccess\StoreContainerAccessRequest;
use App\Http\Requests\ContainerAccess\UpdateContainerAccessRequest;
use App\Http\Resources\ContainerAccessResource;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use App\Support\Plan\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * API-ytan för att bevilja, lista, ÄNDRA och återkalla delegerade åtkomster
 * till en container, se issue 9b. Sedan issue 72 kan en åtkomst vara
 * avgränsad till ett enskilt item: `POST` tar ett valfritt `item`, `PATCH`
 * ändrar nivå och utgång, och listningen redovisar omfånget och hur många
 * items det når, se [[ADR-0028 Åtkomst på itemnivå]] § Beslut.
 *
 * INGEN behörighetslogik bor här — varje metod
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
     *
     * Sedan issue 72 § Beslut 5 bär varje rad sitt omfång (`item`) och hur
     * långt det når (`reach`). Återkallade och utgångna rader får `reach`
     * räknat på DAGENS graf — ett närmevärde för historiska rader, och
     * avsiktligt: alternativet vore att spara ett tal som var sant en gång,
     * och det vore en andra sanning om omfånget.
     */
    public function index(Container $container, ResolveItemScope $scope): JsonResponse
    {
        Gate::authorize('viewAccesses', $container);

        $accesses = $container->accesses()
            ->orderByDesc('created_at')
            ->get();

        $this->hydrateGranteeUlids($accesses);
        $this->hydrateItemScope($accesses, $container, $scope);

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
     * `container_id`, `item_id` och `granted_by_user_id` sätts explicit på
     * modellinstansen, aldrig via massildelning — se
     * App\Models\ContainerAccess docblock och issue 9b § Beslut 8.
     *
     * Sedan issue 72 § Beslut 3 gäller dubblettspärren `(container, item,
     * mottagare)` och inte `(container, mottagare)`: `where('item_id',
     * $itemId)` blir `is null` för en container-bred grant, så samma rad
     * bär båda fallen. Det ger tre svar — två container-breda rader för
     * samma mottagare avvisas som i dag, två rader för samma mottagare OCH
     * samma item avvisas, medan en container-bred rad PLUS en itemrad
     * tillåts. Det sista är avsiktligt och hela poängen med ADR-0028 §
     * Beslut regel 4: mottagaren får `read` på pärmen och `write` på
     * motorn, och upplösningen tar max.
     */
    public function store(StoreContainerAccessRequest $request, Container $container, Entitlements $entitlements, ResolveItemScope $scope): JsonResponse
    {
        Gate::authorize('manageAccess', $container);

        $granteeType = $request->validated('grantee_type');

        $granteeModel = $granteeType === 'user'
            ? User::where('ulid', $request->validated('grantee'))->firstOrFail()
            : Account::where('ulid', $request->validated('grantee'))->firstOrFail();

        // `item` är redan bevisat finnas i DEN HÄR containern och vara
        // levande av StoreContainerAccessRequest, se dess docblock.
        $item = $request->validated('item') === null
            ? null
            : Item::where('ulid', $request->validated('item'))->firstOrFail();

        $existing = $container->accesses()
            ->where('grantee_type', $granteeType)
            ->where('grantee_id', $granteeModel->id)
            ->where('item_id', $item?->id)
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
        // 27 § Beslut 5). Taket räknar sedan issue 72 § Beslut 6 distinkta
        // mottagare, inte rader.
        $entitlements->assertCanShareContainer($container);

        $access = new ContainerAccess($request->safe()->only(['grantee_type', 'level', 'kind', 'expires_at']));
        $access->grantee_id = $granteeModel->id;
        $access->container_id = $container->id;
        $access->item_id = $item?->id;
        $access->granted_by_user_id = $request->user()->id;
        $access->save();

        $access->setAttribute('grantee_ulid', $granteeModel->ulid);
        $access->setAttribute('granted_by_ulid', $request->user()->ulid);

        $this->hydrateItemScope(collect([$access]), $container, $scope);

        return (new ContainerAccessResource($access))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH /api/containers/{container}/accesses/{access} — 200, samma
     * resurs som store(). Se issue 72 § Beslut 4.
     *
     * Kroppen får bära `level` och `expires_at`, ingenting annat —
     * UpdateContainerAccessRequest nekar allt övrigt med 422.
     * `item_id`, `grantee_type`, `grantee_id` och `kind` går aldrig att
     * ändra, och `fill()` rör dem följaktligen inte.
     *
     * `manageAccess()` auktoriserar: att ändra en åtkomst är att hantera
     * åtkomster, samma grind som att bevilja (regel 3, oförändrad sedan
     * 9b).
     *
     * En redan återkallad eller utgången rad nekas med 422
     * `container_access.revoked` — att höja nivån på en död rad är
     * antingen ett misstag eller en väg runt återkallandet.
     *
     * Dubblettspärren i store() prövas inte här: den gäller
     * `(container, item, mottagare)`, och PATCH rör inget av de tre.
     */
    public function update(UpdateContainerAccessRequest $request, Container $container, ContainerAccess $access, ResolveItemScope $scope): JsonResponse
    {
        Gate::authorize('manageAccess', $container);

        if ($access->revoked_at !== null || ($access->expires_at !== null && $access->expires_at->isPast())) {
            throw ApiException::make('container_access.revoked', ['access' => $access->ulid], 422);
        }

        $access->fill($request->safe()->only(['level', 'expires_at']));
        $access->save();

        $this->hydrateGranteeUlids(collect([$access]));
        $this->hydrateItemScope(collect([$access]), $container, $scope);

        return (new ContainerAccessResource($access))->response();
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
     * som inte hände. Raden läses om och låses INNE i transaktionen
     * (`lockForUpdate`): route-modellbindningens instans lästes innan
     * transaktionen öppnades, och två samtidiga DELETE-anrop mot samma access
     * skulle annars båda se `revoked_at === null` på sin egen instans och
     * skriva var sin loggrad för samma återkallelse. En redan återkallad rad
     * rörs inte och loggas inte en andra gång.
     *
     * Sedan issue 72 § Beslut 8 får `meta` ett fält till: `item`, grantens
     * item-ULID eller `null` för en container-bred rad. Ingen ny `action` —
     * `access.revoked` skrivs som förut, och att bevilja loggas fortfarande
     * inte.
     */
    public function destroy(Request $request, Container $container, ContainerAccess $access): Response
    {
        Gate::authorize('revokeAccess', $container);

        // ULID:erna till `meta.grantee` och `meta.item` löses upp i förväg —
        // `ContainerAccess` har medvetet ingen `grantee()`-relation (se
        // modellens docblock), så uppslagningen görs som platta frågor, samma
        // teknik som hydrateGranteeUlids() nedan. Varken `grantee_id` eller
        // `item_id` rörs av en återkallning, så uppslagningarna kan stå
        // utanför transaktionen. `withTrashed()`: en grant på ett sedan
        // länge mjukraderat item ska loggas med sitt item, inte som `null`.
        $granteeUlid = $access->grantee_type === 'user'
            ? User::query()->whereKey($access->grantee_id)->value('ulid')
            : Account::query()->whereKey($access->grantee_id)->value('ulid');

        $itemUlid = $access->item_id === null
            ? null
            : Item::withTrashed()->whereKey($access->item_id)->value('ulid');

        DB::transaction(function () use ($request, $container, $access, $granteeUlid, $itemUlid): void {
            $låstAccess = ContainerAccess::query()
                ->whereKey($access->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($låstAccess->revoked_at === null) {
                $låstAccess->revoked_at = now();
                $låstAccess->save();

                /** @var User $revoker */
                $revoker = $request->user();

                (new RecordAuditEvent)->handle(
                    action: AuditLog::ACTION_ACCESS_REVOKED,
                    account: $container->account,
                    user: $revoker,
                    container: $container,
                    subjectType: 'container_access',
                    subjectUlid: $låstAccess->ulid,
                    meta: [
                        'grantee_type' => $låstAccess->grantee_type,
                        'grantee' => $granteeUlid,
                        'item' => $itemUlid,
                        'level' => $låstAccess->level,
                        'kind' => $låstAccess->kind,
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

    /**
     * Sätter `item_ulid` och `reach` på varje rad — omfånget och hur långt
     * det når, se App\Http\Resources\ContainerAccessResource och issue 72
     * § Beslut 5.
     *
     * TVÅ frågor, oavsett antal rader: en `Item::withTrashed()` för alla
     * ULID:er och ett anrop till App\Actions\Access\ResolveItemScope::reach()
     * som laddar containerns `parent`-kanter i EN fråga. `withTrashed()` är
     * inte en detalj: en grant på ett sedan länge mjukraderat item ska
     * redovisas som sitt item, inte som `null` — `null` hade lästs som en
     * container-bred grant.
     *
     * Finns inga itemrader alls kostas ingenting: båda nycklarna blir
     * `null`, vilket är exakt vad en container-bred rad ska svara. Antalet
     * frågor växer alltså inte med antalet rader, vilket är kravet från
     * issue 9b § Beslut 11 som issue 72 § Beslut 5 upprepar.
     *
     * @param  Collection<int, ContainerAccess>  $accesses
     */
    private function hydrateItemScope(Collection $accesses, Container $container, ResolveItemScope $scope): void
    {
        $itemIds = $accesses->pluck('item_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($itemIds === []) {
            foreach ($accesses as $access) {
                $access->setAttribute('item_ulid', null);
                $access->setAttribute('reach', null);
            }

            return;
        }

        $itemUlids = Item::withTrashed()->whereIn('id', $itemIds)->pluck('ulid', 'id');
        $reach = $scope->reach($container->id, $itemIds);

        foreach ($accesses as $access) {
            $access->setAttribute(
                'item_ulid',
                $access->item_id === null ? null : $itemUlids->get($access->item_id),
            );
            $access->setAttribute(
                'reach',
                $access->item_id === null ? null : ($reach[$access->item_id] ?? null),
            );
        }
    }
}
