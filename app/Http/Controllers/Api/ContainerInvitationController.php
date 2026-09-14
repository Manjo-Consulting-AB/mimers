<?php

namespace App\Http\Controllers\Api;

use App\Actions\Invitation\CreateInvitation;
use App\Actions\Invitation\RevokeInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invitation\StoreInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Models\Container;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * API-ytan för att bjuda in, lista och dra tillbaka inbjudningar till en
 * container — avsändarsidan, issue 10a. INGEN behörighetslogik bor här:
 * varje metod anropar bara `Gate::authorize()` och litar på svaret från
 * App\Policies\ContainerPolicy::viewAccesses()/manageAccess(), oförändrade
 * sedan 9b (issue 10a § Beslut 10) — se ADR-0024.
 *
 * **Skapandet och tillbakadragandet bor i Actions sedan issue 55b**
 * (§ Beslut 7): App\Actions\Invitation\CreateInvitation och
 * App\Actions\Invitation\RevokeInvitation, med kropparna oförändrade.
 * Webben fick sin egen väg in i 55b, och två kopior av duplikatspärren,
 * kvotordningen och tokenhanteringen hade varit två formuleringar av samma
 * svar — samma tröskel och samma skäl som 55a § Beslut 8. Kvar här är skalet
 * [[ADR-0024 Tunna controllers och actions]] beskriver: behörigheten, och
 * svarsformatet. Beteendet är oförändrat, och
 * tests/Feature/Container/InbjudanTest.php är grönt utan en ändrad
 * förväntan.
 *
 * Mottagarsidan (acceptera, avvisa) bor i
 * App\Http\Controllers\Api\InvitationResponseController.
 *
 * `routes/api.php` nästlar {invitation} under {container} med
 * `->scopeBindings()` — en ULID från en annan container löser aldrig upp
 * här, av exakt samma skäl som 9b § Beslut 1 (utan det går en inbjudan i
 * container B att dra tillbaka via container A:s rutt).
 */
class ContainerInvitationController extends Controller
{
    /**
     * GET /api/containers/{container}/invitations — 200. Visar ALLA rader,
     * även tillbakadragna och utgångna (issue 10a § Beslut 14), sorterat
     * `created_at` fallande. Resursen redovisar en utgången `pending`-rad
     * som `expired` utan att kolumnen ändras, se
     * App\Http\Resources\InvitationResource.
     *
     * `viewAccesses()` är bara regel 1 (medlemskap) — ett `read_only`
     * ägarkonto får alltså fortfarande se sina inbjudningar, se
     * App\Policies\ContainerPolicy.
     */
    public function index(Container $container): JsonResponse
    {
        Gate::authorize('viewAccesses', $container);

        $invitations = $container->invitations()
            ->orderByDesc('created_at')
            ->get();

        $this->hydrateInviterUlids($invitations);
        $this->hydrateItemUlids($invitations);

        return InvitationResource::collection($invitations)->response();
    }

    /**
     * POST /api/containers/{container}/invitations — 201.
     * `StoreInvitationRequest` har redan bevisat att `email` är en adress,
     * att `level` är ett steg i laddern och att ett skickat `item` finns i
     * DEN HÄR containern och är levande.
     *
     * `manageAccess()` avgör behörighet (regel 1 + regel 4). Att bjuda in
     * ÄR att hantera åtkomster: regel 3 säger att `write` aldrig får det,
     * och en inbjudan är en åtkomst med fördröjning — issue 10a § Beslut
     * 10. Ingen ny policymetod har lagts till.
     *
     * Skrivningen — normaliseringen, duplikatspärren, kvotordningen,
     * token och mejlet — bor i App\Actions\Invitation\CreateInvitation sedan
     * issue 55b § Beslut 7, med kroppen oförändrad. Kvar här är behörigheten,
     * uppslaget av `item` och svarsformatet.
     *
     * `item` speglar `container_access.item_id`, se [[Konton och åtkomst]]
     * § invitation och issue 72 § Beslut 2 och 7.
     */
    public function store(StoreInvitationRequest $request, Container $container, CreateInvitation $createInvitation): JsonResponse
    {
        Gate::authorize('manageAccess', $container);

        // ULID:en är redan bevisad finnas i DEN HÄR containern och vara
        // levande av StoreInvitationRequest; `withTrashed()` behövs därför
        // inte här.
        $item = $request->validated('item') === null
            ? null
            : Item::where('ulid', $request->validated('item'))->firstOrFail();

        $invitation = $createInvitation->handle(
            $request->user(),
            $container,
            $request->validated('email'),
            $request->validated('level'),
            $item,
        );

        $invitation->setAttribute('invited_by_ulid', $request->user()->ulid);
        $invitation->setAttribute('item_ulid', $item?->ulid);

        return (new InvitationResource($invitation))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * DELETE /api/containers/{container}/invitations/{invitation} — 204,
     * ingen kropp. Sätter `status = 'revoked'`; raden raderas aldrig
     * (issue 10a § Beslut 13 och [[Konton och åtkomst]] § invitation).
     *
     * Reglerna för vad som går att dra tillbaka bor i
     * App\Actions\Invitation\RevokeInvitation sedan issue 55b § Beslut 7 —
     * bara en `pending`-rad, också en utgången sådan, och allt annat är
     * `invitation.not_pending` (422).
     *
     * `manageAccess()` auktoriserar, inte `revokeAccess()`: 9b:s
     * återkallningsgrind är regel 4:s undantag för att KLIPPA en befintlig
     * relation, medan en pending inbjudan aldrig blivit en relation — den
     * hör till samma yta som att bjuda in, se issue 10a § Beslut 10.
     */
    public function destroy(Container $container, Invitation $invitation, RevokeInvitation $revokeInvitation): Response
    {
        Gate::authorize('manageAccess', $container);

        $revokeInvitation->handle($container, $invitation);

        return response()->noContent();
    }

    /**
     * Löser upp inbjudarnas ULID i EN fråga, oavsett antal rader (issue
     * 10a § Beslut 14) — ingen `belongsTo`-lazy-load per rad. Sätts på
     * varje modellinstans med `setAttribute()` innan
     * App\Http\Resources\InvitationResource läser dem, samma mönster som
     * ContainerAccessController::hydrateGranteeUlids().
     *
     * @param  Collection<int, Invitation>  $invitations
     */
    private function hydrateInviterUlids(Collection $invitations): void
    {
        $userIds = $invitations->pluck('invited_by_user_id')
            ->unique()
            ->values()
            ->all();

        $userUlids = User::query()->whereIn('id', $userIds)->pluck('ulid', 'id');

        foreach ($invitations as $invitation) {
            $invitation->setAttribute('invited_by_ulid', $userUlids->get($invitation->invited_by_user_id));
        }
    }

    /**
     * Löser upp inbjudningarnas item-ULID:er i EN fråga, oavsett antal rader
     * — `Invitation` har en `item()`-relation, men en lazy load per rad vore
     * precis den N+1 som issue 10a § Beslut 14 undvek för inbjudarna.
     * Samma mönster som hydrateInviterUlids() ovan.
     *
     * `withTrashed()`: en inbjudan till ett sedan länge mjukraderat item ska
     * redovisas med sitt item, inte som `null` — `null` hade lästs som en
     * container-bred inbjudan.
     *
     * @param  Collection<int, Invitation>  $invitations
     */
    private function hydrateItemUlids(Collection $invitations): void
    {
        $itemIds = $invitations->pluck('item_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $itemUlids = $itemIds === []
            ? collect()
            : Item::withTrashed()->whereIn('id', $itemIds)->pluck('ulid', 'id');

        foreach ($invitations as $invitation) {
            $invitation->setAttribute(
                'item_ulid',
                $invitation->item_id === null ? null : $itemUlids->get($invitation->item_id),
            );
        }
    }
}
