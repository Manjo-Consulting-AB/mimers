<?php

namespace App\Http\Controllers;

use App\Actions\Attachment\CopyAttachment;
use App\Actions\Attachment\MoveAttachment;
use App\Actions\Attachment\StoreAttachment;
use App\Actions\Attachment\TrashAttachment;
use App\Actions\Inbox\SendToInbox;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Attachment\StoreAttachmentRequest;
use App\Http\Requests\TargetItemRequest;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Support\Frontend\ApiErrorTranslator;
use App\Support\Plan\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        Request $request,
        Container $container,
        Item $item,
        Attachment $attachment,
        TrashAttachment $trashAttachment,
    ): RedirectResponse {
        Gate::authorize('delete', $item);

        $trashAttachment->handle($attachment, $request->user());

        return back()->with('status', 'attachment-deleted');
    }

    /**
     * POST /containers/{container}/items/{item}/attachments/{attachment}/move
     * — 302 tillbaka till itemets detaljvy.
     *
     * **Två grindar, och pinnen skiljer sig från raderingens** ([[ADR-0053
     * Flytt och kopiering]] § 2): `delete` på KÄLLANS item — en flytt tar
     * bort något därifrån, och en `write`-mottagare ska inte kunna tömma ett
     * item genom att flytta allt — och `create` på MÅLETS. Båda prövas innan
     * actionen rör något, och `delete` först: en anropare som inte får flytta
     * från källan ska inte kunna sondera vilka mål som finns.
     *
     * Målet kommer ur kroppens `target` och slås upp av
     * App\Http\Requests\TargetItemRequest — en ULID som inte finns eller är
     * mjukraderad ger 404, inte ett valideringsfel (ADR-0053 § 8).
     *
     * Kvotfelet (`quota.storage_exceeded`, när den nya ägarens kvot inte
     * räcker) ritas som fältfel på `attachment`, inte som en JSON-kropp mitt
     * i sidan — samma väg som uppladdningens filfel (Beslut 5 i issue 60).
     */
    public function move(
        TargetItemRequest $request,
        Container $container,
        Item $item,
        Attachment $attachment,
        MoveAttachment $moveAttachment,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('delete', $item);

        $target = $request->targetItem();

        Gate::authorize('create', $target);

        try {
            $moveAttachment->handle($attachment, $target, $request->user());
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['attachment' => $translator->message($e)]);
        }

        return back()->with('status', 'attachment-moved');
    }

    /**
     * POST /containers/{container}/items/{item}/attachments/{attachment}/inbox
     * — *Back to Inbox*, M27 · issue 244, se App\Actions\Inbox\SendToInbox och
     * [[ADR-0054 Inboxen]] § 6.
     *
     * **Grinden är `delete` på källans item**, densamma som flytten intill.
     * Målet är användarens EGEN inbox — den står inte i kroppen, och väljaren
     * (issue 242) visar den aldrig.
     *
     * **Ägaren blir personkontot och dess kvot prövas** (ADR-0053 § 3 och 4):
     * inboxens container ägs av personkontot, och flytten väljer målcontainerns
     * ägarkonto när användaren är medlem i det. Nekas kvoten ligger bilagan
     * kvar på källan, oförändrad.
     *
     * Svaret är `back()` — bilagans rad står kvar på itemet tills sidan ritas
     * om ur serverns svar, och ett kvotfel blir ett fältfel på `attachment`
     * precis som vid en flytt.
     */
    public function toInbox(
        Request $request,
        Container $container,
        Item $item,
        Attachment $attachment,
        SendToInbox $sendToInbox,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('delete', $item);

        try {
            $sendToInbox->attachment($attachment, $request->user());
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['attachment' => $translator->message($e)]);
        }

        return back()->with('status', 'attachment-in-inbox');
    }

    /**
     * POST /containers/{container}/items/{item}/attachments/{attachment}/copy
     * — 302 tillbaka till itemets detaljvy.
     *
     * Grindarna är `view` på KÄLLAN och `create` på MÅLET ([[ADR-0053 Flytt
     * och kopiering]] § 2): kopian rör inte originalet, så `delete` krävs
     * inte — den som bara får läsa får kopiera vidare, och den som får skriva
     * på målet får lägga den där.
     *
     * Kvoten prövas alltid, även inom samma konto: kopian är en ny bilaga med
     * hela sin logiska storlek (ADR-0053 § 4). Felet ritas på `attachment`,
     * som vid uppladdning och flytt.
     */
    public function copy(
        TargetItemRequest $request,
        Container $container,
        Item $item,
        Attachment $attachment,
        CopyAttachment $copyAttachment,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('view', $item);

        $target = $request->targetItem();

        Gate::authorize('create', $target);

        try {
            $copyAttachment->handle($attachment, $target, $request->user());
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['attachment' => $translator->message($e)]);
        }

        return back()->with('status', 'attachment-copied');
    }
}
