<?php

namespace App\Http\Controllers\Api;

use App\Actions\Attachment\StoreAttachment;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attachment\StoreAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

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
 * grinden är den befintliga `update` på App\Policies\ContainerPolicy
 * (§ Beslut 1 — inte `delete`, ingen ny AttachmentPolicy), och
 * medlemskapskontrollen nedan avgör om DEN HÄR användaren får handla i det
 * angivna kontots namn (§ Beslut 2). Båda felen blir 403 `auth.forbidden`.
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
     */
    public function store(StoreAttachmentRequest $request, Container $container, Item $item, StoreAttachment $storeAttachment): JsonResponse
    {
        Gate::authorize('update', $container);

        $account = Account::where('ulid', $request->validated('account'))->firstOrFail();

        if (! $account->users()->whereKey($request->user()->id)->exists()) {
            throw ApiException::make('auth.forbidden', [], 403);
        }

        $file = $request->file('file');
        assert($file instanceof UploadedFile); // krävd och storleksvaliderad i requesten ovan

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
        Gate::authorize('view', $container);

        $attachments = $item->attachments()
            ->with(['storedFile', 'billedAccount'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return AttachmentResource::collection($attachments)->response();
    }

    /**
     * DELETE /api/containers/{container}/items/{item}/attachments/{attachment}
     * — 204, ingen kropp. Mjukradering och ingenting annat (issue 16b §
     * Beslut 4): `delete()` sätter bara `deleted_at`. `stored_file`-
     * `reference_count` minskas INTE och inga bytes rörs — minskningen sker
     * först när bilagan lämnar papperskorgen, issue 17a
     * ([[ADR-0008 Soft delete och papperskorg]]).
     *
     * `{attachment}` binds av gruppens scopeBindings() genom
     * App\Models\Item::attachments() (§ Beslut 1), så en bilaga på ett annat
     * item ger 404, och en redan mjukraderad bilaga syns inte av bindningen —
     * 404 `resource.not_found`.
     */
    public function destroy(Container $container, Item $item, Attachment $attachment): Response
    {
        Gate::authorize('update', $container);

        $attachment->delete();

        return response()->noContent();
    }
}
