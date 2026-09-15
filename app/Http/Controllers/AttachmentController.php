<?php

namespace App\Http\Controllers;

use App\Actions\Attachment\StoreAttachment;
use App\Actions\Attachment\TrashAttachment;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Attachment\StoreAttachmentRequest;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Support\Frontend\ApiErrorTranslator;
use App\Support\Plan\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Webbens bilageyta — uppladdningen och den mjuka raderingen, se issue 60
 * § Beslut 1–9.
 *
 * **Ingenting av `/api` görs om.** `StoreAttachmentRequest` delas rakt av
 * (Beslut 3), och hela arbetet — hashen, sniffningen, dedupen,
 * referensräkningen, förbrukningen — ligger kvar i
 * App\Actions\Attachment\StoreAttachment (Beslut 2) respektive
 * App\Actions\Attachment\TrashAttachment (Beslut 7). Den här kontrollern
 * anropar dem, den skriver inte om dem.
 *
 * **Listan har ingen rutt.** Bilagorna kommer med detaljvyns props ur
 * App\Http\Controllers\ItemController::show() (Beslut 2): itemets bilagor är
 * itemets innehåll, och en andra väg till samma lista hade varit en andra
 * sanning om sorteringen och om vad resursen bär.
 *
 * **Tre grindar, alla på ITEMET** (Beslut 3), exakt som
 * App\Http\Controllers\Api\AttachmentController sedan issue 71: `create` för
 * att lägga till, `delete` för att ta bort. Ingen AttachmentPolicy skrivs —
 * bilagan följer itemet ([[ADR-0028 Åtkomst på itemnivå]] § Beslut). En
 * `write`-mottagare ser ingen raderingsknapp i vyn OCH får 403 här; en
 * `read`-mottagare ser varken uppladdnings- eller raderingsyta och nekas på
 * båda.
 *
 * **Medlemsprövningen är inte en policyfråga** (Beslut 4). Att användaren inte
 * är medlem i det anropade kontot är 403 — samma prövning och samma svar som
 * `/api` ger, och samma mönster som
 * App\Http\Controllers\ItemController::store(). Den formuleras INTE som en
 * `Gate::authorize('create', [Account::class, …])`, som handlar om att skapa
 * konton.
 *
 * **Kvot- och storleksfel blir formulärfel, aldrig en JSON-kropp** (Beslut 5).
 * App\Support\Plan\Entitlements kastar App\Exceptions\Api\ApiException, som
 * svarar `{"error":{"code":…}}` var den än kastas — också mitt i en
 * Inertia-sida. App\Support\Frontend\ApiErrorTranslator gör koden till en
 * mening med gränsen och värdet i läsbar form, och fångsten lägger den på
 * fältet `file`. Det gäller BÅDA kontrollerna: den billiga avvisningen här
 * och den auktoritativa inne i StoreAttachments transaktion, som kastar
 * samma kod — utan den andra fångsten hade en samtidig uppladdning kunnat
 * svara med rå JSON.
 */
class AttachmentController extends Controller
{
    /**
     * POST /containers/{container}/items/{item}/attachments — 302 tillbaka
     * till itemets detaljvy.
     *
     * Ordningen är `/api`:s, och den är inte godtycklig (Beslut 4 och 5):
     * grinden först, sedan medlemskapet, sedan plangränserna, sist
     * StoreAttachment. En användare som inte får skriva ska inte få veta
     * något om kontots kvot, och en fil som ändå nekas ska varken skrivas
     * till disken eller få en rad.
     *
     * `throttle:uploads` ligger på rutten (Beslut 1) — samma begränsare som
     * `/api` använder, nycklad på användaren och inte på rutten, så webben
     * och API:et delar tak.
     */
    public function store(
        StoreAttachmentRequest $request,
        Container $container,
        Item $item,
        StoreAttachment $storeAttachment,
        Entitlements $entitlements,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('create', $item);

        $account = Account::where('ulid', $request->validated('account'))->firstOrFail();

        if (! $account->users()->whereKey($request->user()->id)->exists()) {
            abort(403);
        }

        $file = $request->file('file');
        assert($file instanceof UploadedFile); // krävd och storleksvaliderad i requesten ovan

        $byteSize = $file->getSize();
        if ($byteSize === false) {
            throw new RuntimeException('Den mottagna filen kunde inte läsas.');
        }

        try {
            // Styckstorleken prövas bara här; totalkvoten prövas här som en
            // billig avvisning och en gång till, auktoritativt, inne i
            // StoreAttachments transaktion (issue 27b § Beslut 4).
            $entitlements->assertFileWithinLimit($account, $byteSize);
            $entitlements->assertStorageWithinLimit($account, $byteSize);

            $storeAttachment->handle(
                item: $item,
                file: $file,
                user: $request->user(),
                account: $account,
            );
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['file' => $translator->message($e)]);
        }

        return back()->with('status', 'attachment-uploaded');
    }

    /**
     * DELETE /containers/{container}/items/{item}/attachments/{attachment} —
     * 302 tillbaka till itemets detaljvy.
     *
     * Grinden är ITEMETS `delete` (Beslut 3): att mjukradera en bilaga tar
     * bort, och `write` räcker inte — samma pinne som `/api` prövar sedan
     * issue 71, och samma pinne som ritar raderingsknappen i vyn.
     *
     * Raderingen är MJUK (Beslut 7). `TrashAttachment` sätter `deleted_at` och
     * drar av bytena från kontots förbrukning i samma transaktion, men
     * `stored_file.reference_count` rörs inte förrän bilagan lämnar
     * papperskorgen ([[ADR-0008 Soft delete och papperskorg]]) — och därför
     * säger bekräftelsen i vyn papperskorgen och de 30 dagarna, aldrig
     * "raderas permanent", vilket vore osant.
     *
     * `{attachment}` binds av rutternas `scopeBindings()` genom
     * App\Models\Item::attachments(), precis som på `/api`: en bilaga på ett
     * annat item ger 404, och en redan mjukraderad bilaga syns inte av
     * bindningen.
     */
    public function destroy(
        Container $container,
        Item $item,
        Attachment $attachment,
        TrashAttachment $trashAttachment,
    ): RedirectResponse {
        Gate::authorize('delete', $item);

        $trashAttachment->handle($attachment);

        return back()->with('status', 'attachment-deleted');
    }
}
