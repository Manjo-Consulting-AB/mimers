<?php

namespace App\Http\Controllers\Api;

use App\Actions\Attachment\CopyAttachment;
use App\Actions\Attachment\MoveAttachment;
use App\Actions\Attachment\StoreAttachment;
use App\Actions\Attachment\TrashAttachment;
use App\Actions\Inbox\SendToInbox;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attachment\StoreAttachmentRequest;
use App\Http\Requests\TargetItemRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Support\Plan\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * POST /api/containers/{container}/items/{item}/attachments, se issue 16a
 * § Beslut 1 och 2. `{item}` binds genom App\Models\Container::items() via
 * gruppens `scopeBindings()` — hela skyddet mot en item-ULID från en annan
 * container som löses upp här.
 *
 * store() är 16a:s POST-yta; index() och destroy() är 16b:s listning och
 * mjukradering. Nedladdning av bytena är 19a.
 *
 * Samma två kontroller som App\Http\Controllers\Api\ItemController::store():
 * grinden är ITEMETS egen — `create` i store(), `view` i index(), `delete` i
 * destroy() — på App\Policies\ItemPolicy sedan issue 71 § Beslut 1. Ingen
 * AttachmentPolicy skrivs: bilagan följer itemet, se [[ADR-0028 Åtkomst på
 * itemnivå]] § Beslut ("itemets beroenden följer itemet") och issue 70
 * § Beslut 7. Medlemskapskontrollen nedan avgör om DEN HÄR användaren får
 * handla i det angivna kontots namn (§ Beslut 2). Båda felen blir 403
 * `auth.forbidden`.
 *
 * Före issue 71 var grinden `update` på containern i alla tre — vilket lät
 * en `write`-mottagare radera en bilaga och krävde en container-bred grant
 * för att ladda upp en. `{item}` binds genom App\Models\Container::items()
 * via gruppens `scopeBindings()`, och `Container $container` står kvar i
 * signaturerna just för det: ImplicitRouteBinding löser barnbindningen mot
 * den redan lösta föräldern.
 */
class AttachmentController extends Controller
{
    /**
     * POST /api/containers/{container}/items/{item}/attachments — 201.
     * StoreAttachmentRequest har redan bevisat att `account` finns och att
     * `file` finns och ligger under det tekniska taket (Beslut 9).
     * Medlemskapskontrollen nedan — `$account->users()->whereKey(...)`
     * ->exists() — avgör om användaren får skriva i det kontots namn; en
     * icke-medlem får 403 `auth.forbidden`, samma mönster som ItemController.
     *
     * Grinden är `create` på itemet (issue 71 § Beslut 1): att lägga en
     * bilaga PÅ ett item är att lägga till, inte att ändra. Uppladdningen
     * räknas mot det uppladdande kontot, oförändrat (issue 26a, [[ADR-0028
     * Åtkomst på itemnivå]] § Konsekvenser).
     */
    public function store(StoreAttachmentRequest $request, Container $container, Item $item, StoreAttachment $storeAttachment, Entitlements $entitlements): JsonResponse
    {
        Gate::authorize('create', $item);

        $account = Account::where('ulid', $request->validated('account'))->firstOrFail();

        if (! $account->users()->whereKey($request->user()->id)->exists()) {
            throw ApiException::make('auth.forbidden', [], 403);
        }

        $file = $request->file('file');
        assert($file instanceof UploadedFile); // krävd och storleksvaliderad i requesten ovan

        $byteSize = $file->getSize();
        if ($byteSize === false) {
            throw new RuntimeException('Den mottagna filen kunde inte läsas.');
        }

        // Plangränserna prövas här, efter medlemskapskontrollen och före
        // StoreAttachment (issue 27b § Beslut 4). Styckstorleken kontrolleras
        // bara här: en fil som ändå nekas ska varken skrivas till disken
        // eller få en rad. Totalkvoten kontrolleras här som en billig
        // avvisning OCH en gång till, auktoritativt, inne i StoreAttachments
        // transaktion — den här tidiga kontrollen är aldrig den enda, för den
        // håller inte mot två samtidiga uppladdningar (issue 27b § Beslut 4).
        $entitlements->assertFileWithinLimit($account, $byteSize);
        $entitlements->assertStorageWithinLimit($account, $byteSize);

        $attachment = $storeAttachment->handle(
            item: $item,
            file: $file,
            user: $request->user(),
            account: $account,
        );

        return (new AttachmentResource($attachment))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/containers/{container}/items/{item}/attachments — 200.
     * Itemets bilagor, nyast först: `created_at` fallande med `id` fallande
     * som andrasortering, så två bilagor uppladdade samma sekund ändå får en
     * stabil ordning (issue 16b § Beslut 3). Mjukraderade bilagor kommer
     * aldrig med — SoftDeletes globala scope sköter det. Ingen paginering
     * (Beslut 3).
     *
     * `storedFile` och `billedAccount` laddas eager eftersom
     * AttachmentResource läser `mime_type`/`byte_size` respektive
     * `billed_account` därifrån — listningen gör ett konstant antal frågor
     * oavsett antalet bilagor (Beslut 5), aldrig en fråga per bilaga.
     */
    public function index(Container $container, Item $item): JsonResponse
    {
        Gate::authorize('view', $item);

        $attachments = $item->attachments()
            ->with(['storedFile', 'billedAccount'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return AttachmentResource::collection($attachments)->response();
    }

    /**
     * DELETE /api/containers/{container}/items/{item}/attachments/{attachment}
     * — 204, ingen kropp. Mjukradering (issue 16b § Beslut 4): `delete()`
     * sätter bara `deleted_at`. `stored_file`-`reference_count` minskas INTE
     * — det sker först när bilagan lämnar papperskorgen, issue 17a
     * ([[ADR-0008 Soft delete och papperskorg]]).
     *
     * Hela arbetet — mjukraderingen OCH minskningen av kontots förbrukning
     * (issue 26a), i samma transaktion — bor sedan issue 28 i
     * App\Actions\Attachment\TrashAttachment (issue 28 § Beslut 5): den här
     * rutten och storage-ytans bulkrensning gör exakt samma sak, och paret
     * får inte ligga i två filer. Beteendet är oförändrat: samma 204, samma
     * räkning. Sedan issue 71 är grinden `delete` på ITEMET — att mjukradera
     * en bilaga tar bort, och `write` räcker inte (§ Beslut 1 och 6).
     *
     * `{attachment}` binds av gruppens scopeBindings() genom
     * App\Models\Item::attachments() (§ Beslut 1), så en bilaga på ett annat
     * item ger 404, och en redan mjukraderad bilaga syns inte av bindningen —
     * 404 `resource.not_found`.
     */
    public function destroy(Request $request, Container $container, Item $item, Attachment $attachment, TrashAttachment $trashAttachment): Response
    {
        Gate::authorize('delete', $item);

        $trashAttachment->handle($attachment, $request->user());

        return response()->noContent();
    }

    /**
     * POST /api/containers/{container}/items/{item}/attachments/{attachment}
     * /move — 200, AttachmentResource. M27 · issue 247: SAMMA action som
     * webbens AttachmentController::move() ([[ADR-0053 Flytt och kopiering]]
     * § 2 och § 8), och samma två grindar — `delete` på KÄLLANS item och
     * `create` på MÅLETS, `delete` först så en anropare utan rätt att flytta
     * från källan inte kan sondera vilka mål som finns.
     *
     * Målet kommer ur kroppens `target` och slås upp av
     * App\Http\Requests\TargetItemRequest — en ULID som inte finns eller är
     * mjukraderad ger 404, inte ett valideringsfel. Domänfelet
     * (`quota.storage_exceeded` vid ett ägarbyte) kastas av actionen som
     * App\Exceptions\Api\ApiException och renderas i felhöljet
     * ([[AGENTS.md]] § Felformat).
     */
    public function move(
        TargetItemRequest $request,
        Container $container,
        Item $item,
        Attachment $attachment,
        MoveAttachment $moveAttachment,
    ): AttachmentResource {
        Gate::authorize('delete', $item);

        $target = $request->targetItem();

        Gate::authorize('create', $target);

        $moved = $moveAttachment->handle($attachment, $target, $request->user());

        // Flytten returnerar den LÅSTA raden utan relationer; resursen läser
        // `storedFile` och `billedAccount`, så de laddas här i stället för ett
        // oplanerat lazy-load per svar.
        $moved->loadMissing(['storedFile', 'billedAccount']);

        return new AttachmentResource($moved);
    }

    /**
     * POST /api/containers/{container}/items/{item}/attachments/{attachment}
     * /copy — 201, AttachmentResource. M27 · issue 247: SAMMA action som
     * webbens AttachmentController::copy() ([[ADR-0053 Flytt och kopiering]]
     * § 2 och § 4), och samma grindar — `view` på KÄLLAN (kopian rör inte
     * originalet) och `create` på MÅLET.
     *
     * Kvoten prövas alltid, även inom samma konto; felet blir 403
     * `quota.storage_exceeded` i felhöljet.
     */
    public function copy(
        TargetItemRequest $request,
        Container $container,
        Item $item,
        Attachment $attachment,
        CopyAttachment $copyAttachment,
    ): JsonResponse {
        Gate::authorize('view', $item);

        $target = $request->targetItem();

        Gate::authorize('create', $target);

        $copy = $copyAttachment->handle($attachment, $target, $request->user());

        return (new AttachmentResource($copy))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * POST /api/containers/{container}/items/{item}/attachments/{attachment}
     * /inbox — *Back to Inbox*, 200 med AttachmentResource. M27 · issue 247
     * och issue 244: SAMMA action som webbens AttachmentController::toInbox(),
     * App\Actions\Inbox\SendToInbox::attachment ([[ADR-0054 Inboxen]] § 6).
     *
     * **Grinden är `delete` på källans item**, som för en flytt. Målet är
     * användarens EGEN inbox och står därför inte i kroppen — den skapas om
     * den saknas. Ägaren blir personkontot, och flytten prövar dess kvot; ett
     * kvotfel blir 403 `quota.storage_exceeded`.
     */
    public function toInbox(
        Request $request,
        Container $container,
        Item $item,
        Attachment $attachment,
        SendToInbox $sendToInbox,
    ): AttachmentResource {
        Gate::authorize('delete', $item);

        $moved = $sendToInbox->attachment($attachment, $request->user());

        $moved->loadMissing(['storedFile', 'billedAccount']);

        return new AttachmentResource($moved);
    }
}
