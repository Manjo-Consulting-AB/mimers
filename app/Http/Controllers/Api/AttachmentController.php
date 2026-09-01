<?php

namespace App\Http\Controllers\Api;

use App\Actions\Attachment\StoreAttachment;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attachment\StoreAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/containers/{container}/items/{item}/attachments, se issue 16a
 * § Beslut 1 och 2. `{item}` binds genom App\Models\Container::items() via
 * gruppens `scopeBindings()` — hela skyddet mot en item-ULID från en annan
 * container som löses upp här.
 *
 * Bara store() i den här issuen. Listning och radering av bilagor är 16b,
 * nedladdning 19a.
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
}
