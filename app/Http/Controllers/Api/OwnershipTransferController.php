<?php

namespace App\Http\Controllers\Api;

use App\Actions\Notification\CreateNotification;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\OwnershipTransfer\StoreOwnershipTransferRequest;
use App\Http\Resources\OwnershipTransferResource;
use App\Models\Account;
use App\Models\Container;
use App\Models\Notification as NotificationModel;
use App\Models\OwnershipTransfer;
use App\Models\User;
use App\Notifications\OwnershipTransferNotification;
use App\Support\Plan\Entitlements;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Ägarbyte, avsändar- och mottagarytan — issue 39a. Ingen accept här: den
 * transaktion som flyttar containern, förbrukningen, åtkomsterna och planen
 * är 39b och rör ingen fil i den här issuen.
 *
 * INGEN behörighetslogik bor här: avsändarsidan (store/destroy) anropar bara
 * `Gate::authorize('transfer', $container)` mot den nya policymetoden i
 * App\Policies\ContainerPolicy (Beslut 7), och index anropar
 * `viewAccesses()` — samma avsändarvy som inbjudningarna (10a). Mottagarsidan
 * (incoming/reject) anropar INGEN policy: den som radens mottagarväg pekar ut
 * ÄR behörig, och en rad som inte pekar på användaren ska vara osynlig, inte
 * ge ett behörighetsfel som läcker att raden finns (Beslut 15).
 *
 * Avsändarytan nästlas under {container} i routes/api.php med
 * `scopeBindings()` — en transfer-ULID från en annan container löser aldrig
 * upp här (Beslut 14). Mottagarens rutter är toppnivå (`/transfers`), för hon
 * känner per definition inte till containern än.
 *
 * Plangrinden (ownership_transfer är Pro) sitter på initieringen, efter
 * Gate::authorize() (Beslut 8): en användare som inte får göra saken alls ska
 * få auth.forbidden, inte en reklamskylt för Pro.
 */
class OwnershipTransferController extends Controller
{
    /**
     * GET /api/containers/{container}/transfers — 200. Avsändarens lista:
     * ALLA rader för containern, även tillbakadragna och utgångna, sorterat
     * `created_at` fallande. Resursen redovisar en utgången `pending`-rad
     * som `expired` utan att kolumnen ändras (Beslut 10).
     *
     * `viewAccesses()` är bara regel 1 (medlemskap) — ett `read_only`
     * ägarkonto får fortfarande se sin lista, se App\Policies\ContainerPolicy.
     */
    public function index(Container $container): JsonResponse
    {
        Gate::authorize('viewAccesses', $container);

        $transfers = $container->transfers()
            ->with(['container', 'toAccount'])
            ->orderByDesc('created_at')
            ->get();

        return OwnershipTransferResource::collection($transfers)->response();
    }

    /**
     * POST /api/containers/{container}/transfers — 201.
     * StoreOwnershipTransferRequest har redan bevisat att kroppen är
     * sammanhängande: exakt en mottagarväg (to_account ELLER to_email, Beslut
     * 4) och att varje `excluded_items`-ULID pekar på ett levande item i den
     * här containern (Beslut 5).
     *
     * Ordningen i metoden följer Beslut 8 och 9:
     * 1. Gate::authorize('transfer') — regel 1 + regel 4.
     * 2. Plangrinden `assertFeature(..., 'ownership_transfer')` — efter
     *    gaten, så ett behörighetsfel aldrig blir en reklamskylt.
     * 3. Dubblettspärren: högst en `pending`-rad per container som inte gått
     *    ut. En utgången, avvisad eller tillbakadragen rad blockerar inget —
     *    att begära igen efter ett nej ska gå. Utgången läses ur
     *    `created_at` + TTL och inte ur `status`, för kolumnen flippas aldrig
     *    (Beslut 10).
     *
     * `to_account` löses upp till id; `to_email` normaliseras till gemener
     * innan den lagras, exakt som inbjudningarna (issue 10a § Beslut 6).
     * Alla kolumner sätts explicit, aldrig via massildelning.
     */
    public function store(StoreOwnershipTransferRequest $request, Container $container, Entitlements $entitlements): JsonResponse
    {
        Gate::authorize('transfer', $container);

        $entitlements->assertFeature($container->account, 'ownership_transfer');

        $existing = $container->transfers()
            ->where('status', 'pending')
            ->where('created_at', '>', now()->subDays(OwnershipTransfer::TTL_DAYS))
            ->first();

        if ($existing instanceof OwnershipTransfer) {
            throw ApiException::make('transfer.already_pending', ['transfer' => $existing->ulid], 422);
        }

        $transfer = new OwnershipTransfer;
        $transfer->container_id = $container->id;
        $transfer->from_account_id = $container->account_id;
        $transfer->status = 'pending';
        $transfer->excluded_item_ids = $request->validated('excluded_items') ?? [];
        $transfer->retain_access_level = $request->validated('retain_access_level');
        $transfer->initiated_by_user_id = $request->user()->id;

        $toAccount = null;
        $toEmail = null;

        if ($request->validated('to_account') !== null) {
            $toAccount = Account::query()->where('ulid', $request->validated('to_account'))->firstOrFail();
            $transfer->to_account_id = $toAccount->id;
            $transfer->to_email = null;
        } else {
            $toEmail = mb_strtolower((string) $request->validated('to_email'));
            $transfer->to_account_id = null;
            $transfer->to_email = $toEmail;
        }

        $transfer->save();

        $transfer->setRelation('container', $container);
        $transfer->load('toAccount');

        // Beslut 12: mottagarvägen avgör notifieringen.
        if ($toAccount instanceof Account) {
            $this->notifyAccountMembers($transfer, $toAccount, $container);
        } else {
            $this->sendOnDemandMail($toEmail, $container);
        }

        return (new OwnershipTransferResource($transfer))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * DELETE /api/containers/{container}/transfers/{transfer} — 204, ingen
     * kropp. Sätter `status = 'revoked'`; raden raderas aldrig (Beslut 16 och
     * [[Konton och åtkomst]] § ownership_transfer).
     *
     * Bara en `pending`-rad kan dras tillbaka. Är den redan `accepted`,
     * `rejected` eller `revoked` svarar rutten 422 `transfer.not_pending`. En
     * utgången `pending`-rad går däremot att dra tillbaka — kolumnen är
     * fortfarande `pending` (Beslut 10) och att städa bort en glömd begäran
     * ur listan är precis vad avsändaren vill kunna göra, samma princip som
     * inbjudningarna.
     */
    public function destroy(Container $container, OwnershipTransfer $transfer): Response
    {
        Gate::authorize('transfer', $container);

        if ($transfer->status !== 'pending') {
            throw ApiException::make('transfer.not_pending', ['transfer' => $transfer->ulid], 422);
        }

        $transfer->status = 'revoked';
        $transfer->save();

        return response()->noContent();
    }

    /**
     * GET /api/transfers — 200. Mottagarens inkorg: rader som är `pending`,
     * inte utgångna, och som antingen har `to_account_id` bland användarens
     * konton ELLER har `to_email` lika med användarens verifierade adress.
     * Ingen annan rad, aldrig — se inboxQuery(). Toppnivårutt (Beslut 14):
     * mottagaren känner per definition inte till containern än.
     */
    public function incoming(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $transfers = $this->inboxQuery($user)
            ->with(['container', 'toAccount'])
            ->orderByDesc('created_at')
            ->get();

        return OwnershipTransferResource::collection($transfers)->response();
    }

    /**
     * POST /api/transfers/{transfer}/reject — 204, ingen kropp.
     *
     * Prövar exakt samma urval som incoming (Beslut 15): en rad som inte
     * pekar på användaren, eller som inte längre är en öppen inkommande
     * begäran, ger 404 — samma tystnad som en okänd ULID. Själva flippen är
     * en villkorad `UPDATE ... WHERE status = 'pending'`, aldrig
     * läs-följt-av-skriv — samma spärr som AcceptInvitation.
     */
    public function reject(Request $request, OwnershipTransfer $transfer): Response
    {
        /** @var User $user */
        $user = $request->user();

        $incoming = $this->inboxQuery($user)
            ->whereKey($transfer->getKey())
            ->first();

        if (! $incoming instanceof OwnershipTransfer) {
            throw ApiException::make('resource.not_found', [], 404);
        }

        $rejected = OwnershipTransfer::query()
            ->whereKey($transfer->getKey())
            ->where('status', 'pending')
            ->update(['status' => 'rejected']);

        if ($rejected !== 1) {
            throw ApiException::make('transfer.not_pending', [], 422);
        }

        return response()->noContent();
    }

    /**
     * En `transfer.requested`-notis per medlem i det mottagande kontot, genom
     * App\Actions\Notification\CreateNotification — den enda vägen in i
     * `notification`, som respekterar preferenser och tysta timmar och skapar
     * leveransraderna för kön (Beslut 12). Inget on-demand-mejl här; `to_email`
     * är null.
     *
     * `dedupe_key` bär transfer och mottagare: skulle store() anropas två
     * gånger (efter att den första raden hunnit skapas men innan svaret)
     * skapar CreateNotification ingen andra notisrad.
     */
    private function notifyAccountMembers(OwnershipTransfer $transfer, Account $toAccount, Container $container): void
    {
        foreach ($toAccount->users as $recipient) {
            app(CreateNotification::class)->handle(
                type: NotificationModel::TYPE_TRANSFER_REQUESTED,
                account: $toAccount,
                user: $recipient,
                container: $container,
                payload: ['container' => $container->name],
                dedupeKey: "transfer.requested:{$transfer->ulid}:{$recipient->ulid}",
            );
        }
    }

    /**
     * On-demand-mejlet till en adress utan konto (Beslut 12). Inget
     * `notification`-rad skapas och mejlet bär ingen token eller länk med
     * hemlighet (Beslut 11) — det ber mottagaren skapa ett konto med just den
     * adressen och verifiera den, och pekar på `{app.url}/transfers`, sökvägen
     * issue 67 (M10) ska implementera.
     */
    private function sendOnDemandMail(string $email, Container $container): void
    {
        $url = rtrim((string) config('app.url'), '/').'/transfers';

        NotificationFacade::route('mail', $email)
            ->notify(new OwnershipTransferNotification($url, $container));
    }

    /**
     * Urvalet för mottagarens inkorg — den ENDA formuleringen av "vad som är
     * inkommande för den här användaren", delad av incoming och reject
     * (Beslut 15). Två formuleringar skulle kunna glida isär, och en rad som
     * listas men inte går att avvisa — eller tvärtom — vore en bugg.
     *
     * Villkoren: `status = 'pending'`, inte utgången (`created_at` +
     * TTL_DAYS i framtiden), containern lever (SoftDeletes — en mjukraderad
     * pärm ska inte erbjudas till övertag, och resursen läser dess namn),
     * och antingen `to_account_id` bland användarens konton eller `to_email`
     * lika med användarens VERIFIERADE adress (jämförelse i gemener; en
     * overifierad adress ska inte se begäran — [[ADR-0003 Åtkomstmodell]]).
     *
     * @return Builder<OwnershipTransfer>
     */
    private function inboxQuery(User $user): Builder
    {
        $accountIds = $user->accounts->pluck('id');

        return OwnershipTransfer::query()
            ->where('status', 'pending')
            ->where('created_at', '>', now()->subDays(OwnershipTransfer::TTL_DAYS))
            ->whereHas('container')
            ->where(function (Builder $query) use ($user, $accountIds): void {
                $query->whereIn('to_account_id', $accountIds);

                if ($user->hasVerifiedEmail()) {
                    $query->orWhere('to_email', mb_strtolower($user->email));
                }
            });
    }
}
