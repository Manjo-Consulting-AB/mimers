<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invitation\StoreInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Models\Container;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Support\Plan\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * API-ytan för att bjuda in, lista och dra tillbaka inbjudningar till en
 * container — avsändarsidan, issue 10a. INGEN behörighetslogik bor här:
 * varje metod anropar bara `Gate::authorize()` och litar på svaret från
 * App\Policies\ContainerPolicy::viewAccesses()/manageAccess(), oförändrade
 * sedan 9b (issue 10a § Beslut 10) — se ADR-0024.
 *
 * Ingen Action, se issue 10a § Beslut 16 och
 * [[ADR-0024 Tunna controllers och actions]] § Konsekvenser: skapandet är
 * en `create()` med en duplikatspärr framför, och regeln värd ett eget
 * test kommer först i 10b vid accept.
 *
 * `store()` skickar sedan 10b App\Notifications\InvitationNotification med
 * det klartexttoken som genereras där — den enda ändring 10b gör i den här
 * filen, se issue 10b § Beslut 2. Mottagarsidan (acceptera, avvisa) bor i
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
     * Längden på den slump som ska skickas i mejlets länk (tecken, inte
     * bytes), samma som App\Support\Auth\MagicLinkBroker::TOKEN_LENGTH.
     * `Str::random()` hämtar sin entropi från `random_bytes()`.
     */
    private const TOKEN_LENGTH = 64;

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
     * Adressen normaliseras med `mb_strtolower()` INNAN duplikatspärren
     * frågar (§ Beslut 6 och § Att se upp med), exakt som
     * App\Support\Auth\MagicLinkBroker::normalise() — annars slinker
     * `Alice@x.se` förbi bredvid `alice@x.se` och 10b:s adressjämförelse
     * hittar två rader.
     *
     * § Beslut 12: bara EN pending inbjudan per adress och container. En
     * utgången, avvisad, accepterad eller tillbakadragen rad blockerar
     * inget — att bjuda in igen efter ett nej ska gå. Utgång läses ur
     * `expires_at` och inte ur `status`, för kolumnen flippas aldrig
     * (§ Beslut 7). Hittas en spärrande rad: `ApiException`
     * (`invitation.already_pending`, 422) med den befintliga radens ULID i
     * `data.invitation` — ett tillståndsfel i domänen, inte ett fältfel,
     * se issue 7 § Beslut 2 och samma mönster i
     * ContainerAccessController::store().
     *
     * § Beslut 5: token genereras, hashas och kastas. Klartexten lagras
     * aldrig, returneras aldrig och loggas aldrig.
     *
     * `container_id`, `token_hash`, `status` och `invited_by_user_id`
     * sätts explicit på modellinstansen, aldrig via massildelning — se
     * App\Models\Invitation och § Beslut 15.
     */
    public function store(StoreInvitationRequest $request, Container $container, Entitlements $entitlements): JsonResponse
    {
        Gate::authorize('manageAccess', $container);

        $email = mb_strtolower($request->validated('email'));

        $existing = $container->invitations()
            ->where('email', $email)
            ->outstanding()
            ->first();

        if ($existing instanceof Invitation) {
            throw ApiException::make('invitation.already_pending', ['invitation' => $existing->ulid], 422);
        }

        // Kvotkontrollen kommer efter Gate (Beslut 3) och efter
        // duplikatspärren: att bjuda in någon som redan har en pending
        // inbjudan är inte en ny delning, så den ska svara already_pending,
        // inte avslöja taket. Delningstaket följer ägarkontots plan och
        // räknar även den här inbjudan när den ligger pending (issue 27 §
        // Beslut 5).
        $entitlements->assertCanShareContainer($container);

        // Kontotaket kommer sist (issue 48 § Beslut 8): delningstaket är den
        // gräns användaren kan göra något åt, och först när den är passerad
        // är frågan om utskicksvolymen. Taket räknas på ägarkontot, som
        // delningstaket ovan. Undantaget kastas före Str::random(), före
        // save() och före Notification::route() — ett nekande lämnar inga
        // spår, varken rad, token eller mejl (issue 48 § Beslut 9).
        $entitlements->assertPendingInvitationsWithinLimit($container->account);

        // `item` speglar container_access.item_id, se [[Konton och åtkomst]]
        // § invitation och issue 72 § Beslut 2 och 7. ULID:en är redan
        // bevisad finnas i DEN HÄR containern och vara levande av
        // StoreInvitationRequest; `withTrashed()` behövs därför inte här.
        $item = $request->validated('item') === null
            ? null
            : Item::where('ulid', $request->validated('item'))->firstOrFail();

        // Klartexten är mejlets enda konsument — den skickas i länken
        // nedan och lagras aldrig, se klassens docblock.
        $rawToken = Str::random(self::TOKEN_LENGTH);

        $invitation = new Invitation([
            'email' => $email,
            'level' => $request->validated('level'),
        ]);
        $invitation->container_id = $container->id;
        // Kolumnen är medvetet inte #[Fillable] — den sätts explicit, som
        // container_id och invited_by_user_id.
        $invitation->item_id = $item?->id;
        $invitation->token_hash = hash('sha256', $rawToken);
        $invitation->status = 'pending';
        $invitation->expires_at = now()->addDays(Invitation::TTL_DAYS);
        $invitation->invited_by_user_id = $request->user()->id;
        $invitation->save();

        // Issue 10b § Beslut 2 och 3: mejlet skickas härifrån, direkt efter
        // att raden skapats, med den klartext-token som genererades ovan.
        // Mottagaren har inget konto och därmed ingen `User` att notifiera
        // — on-demand-notifikation. Länken pekar på frontendens
        // landningssida (issue 55, M10); URL:en byggs ur `config('app.url')`
        // och inte med `URL::route()`, för accept kräver en inloggad,
        // verifierad användare och en sida som kan be henne registrera sig
        // först. Sökvägen `/invitations/{token}` är kontraktet issue 55 ska
        // implementera.
        $url = rtrim((string) config('app.url'), '/').'/invitations/'.$rawToken;

        Notification::route('mail', $invitation->email)->notify(new InvitationNotification($url, $container));

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
     * Bara en `pending`-rad kan dras tillbaka. Är den redan `accepted`,
     * `rejected` eller `revoked` svarar rutten 422
     * `invitation.not_pending` — en accepterad inbjudan går inte att ångra
     * härifrån, det gör man genom att återkalla åtkomsten (9b).
     *
     * En utgången `pending`-rad går däremot att dra tillbaka: kolumnen är
     * fortfarande `pending` (§ Beslut 7) och att städa bort en glömd
     * inbjudan ur listan är precis vad avsändaren vill kunna göra.
     *
     * `manageAccess()` auktoriserar, inte `revokeAccess()`: 9b:s
     * återkallningsgrind är regel 4:s undantag för att KLIPPA en befintlig
     * relation, medan en pending inbjudan aldrig blivit en relation — den
     * hör till samma yta som att bjuda in, se issue 10a § Beslut 10.
     */
    public function destroy(Container $container, Invitation $invitation): Response
    {
        Gate::authorize('manageAccess', $container);

        if ($invitation->status !== 'pending') {
            throw ApiException::make('invitation.not_pending', ['invitation' => $invitation->ulid], 422);
        }

        $invitation->status = 'revoked';
        $invitation->save();

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
