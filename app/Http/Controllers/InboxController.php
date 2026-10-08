<?php

namespace App\Http\Controllers;

use App\Actions\Attachment\StoreAttachment;
use App\Actions\Attachment\TrashAttachment;
use App\Actions\Inbox\ProcessInboxAttachments;
use App\Actions\Inbox\ProcessInboxTask;
use App\Actions\Inbox\ResolveInbox;
use App\Actions\Schedule\CreateSchedule;
use App\Actions\Schedule\DeleteSchedule;
use App\Actions\Schedule\ListTodo;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Inbox\DeleteInboxAttachmentsRequest;
use App\Http\Requests\Inbox\ProcessInboxAttachmentsRequest;
use App\Http\Requests\Inbox\ProcessInboxTaskRequest;
use App\Http\Requests\Inbox\StoreInboxAttachmentsRequest;
use App\Http\Requests\Inbox\StoreInboxTaskRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\Item;
use App\Models\Schedule;
use App\Support\Files\FileOrigin;
use App\Support\Frontend\ApiErrorTranslator;
use App\Support\Plan\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Inboxen — sidan `/inbox` och dess fyra handlingar. M27 · issue 244 och 245,
 * se [[ADR-0054 Inboxen]] § 4, 6, 7 och 8.
 *
 * **Sidan skapar ingen inbox.** `index` läser användarens inbox-item genom
 * App\Actions\Inbox\ResolveInbox::existing() och visar tomma listor när den
 * saknas — att läsa en vy ska inte skapa en container (ADR-0054 § 1; issue
 * 244:s `ListTodo::inboxItemId()` ställer samma fråga för `/tasks`). Den
 * skapas först när något FÅNGAS: `storeAttachments` och `storeTask` kallar
 * `ResolveInbox::handle()`, som skapar den i en transaktion.
 *
 * **Uppgifterna kommer ur ListTodo**, samma urval och samma radform som
 * `/tasks` (issue 64, issue 237, M27 · issue 244): `list: inbox` är de aktiva
 * förekomsterna på användarens inbox-item, och raden är `TodoEntryResource`
 * med `can`, `account` och `cover` bredvid. Vyn ritar dem och räknar
 * ingenting.
 *
 * **Bilagorna är inbox-itemets bilagor**, nyast först — samma sortering och
 * samma `AttachmentResource` som itemets detaljvy (issue 60 § Beslut 2) — med
 * `variants` och `inlineEnabled` BREDVID resursen, precis som i
 * App\Http\Controllers\ContainerDocumentController: miniatyren ritas bara när
 * servern säger att varianten finns (issue 61b § Beslut 1).
 *
 * **Grindarna är desamma som för en flytt** ([[ADR-0053 Flytt och kopiering]]
 * § 2) i varje skrivning: `delete` på KÄLLAN och `create` på MÅLET. Källan är
 * för uppgiften schemats item — läst null-säkert, så ett mjukraderat item ger
 * 404 och inte 500 — och för bilagorna användarens inbox-item.
 *
 * **Ett domänfel blir ett formulärfel, aldrig en JSON-kropp** — samma mönster
 * som AttachmentController::move(): `schedule.*` ritas på fältet `schedule`,
 * `attachment.*` och kvotfelet på fältet `attachments` (eller `files` vid
 * uppladdningen), och felet ritas över listan i vyn.
 */
class InboxController extends Controller
{
    /**
     * GET /inbox — 200. Användarens obearbetade uppgifter och bilagor.
     *
     * **Tomma listor utan en inbox** (ADR-0054 § 1): sidan skapar den inte,
     * och en användare som ännu inte fångat något möts av de tomma lägena.
     */
    public function index(Request $request, ListTodo $listTodo, ResolveInbox $resolveInbox): Response
    {
        $user = $request->user();
        $inbox = $resolveInbox->existing($user);

        $attachments = $inbox === null
            ? new Collection
            : $inbox->attachments()
                ->with(['storedFile.derivatives', 'billedAccount'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();

        return Inertia::render('Inbox/Index', [
            // `list: inbox` är samma fråga som fliken *Inbox* på `/tasks`
            // (issue 244): aktiva förekomster på användarens inbox-item.
            'tasks' => $listTodo->handle($user, $request, list: ListTodo::LIST_INBOX)['rows'],
            'attachments' => AttachmentResource::collection($attachments)->resolve($request),
            // Bilagans ULID → de varianter som finns, och flaggan för inline
            // leverans — samma två proppar som dokumentfliken, så miniatyren
            // ritas ur samma regel (issue 61b § Beslut 1 och 2).
            'variants' => $this->variants($attachments),
            'inlineEnabled' => FileOrigin::host() !== null,
            // Att fånga är alltid tillåtet för den inloggade: rutten ligger
            // bakom `auth`, och inboxen är hennes egen (ADR-0054 § 2). Utan en
            // inbox skapas den av fångsten.
            'can' => ['capture' => true],
        ]);
    }

    /**
     * POST /inbox/attachments — 302 tillbaka till `/inbox`.
     *
     * En eller flera filer, ett `StoreAttachment::handle()` per fil: hashen,
     * sniffningen, dedupen och referensräkningen bor i actionen och skrivs
     * inte om här. `ResolveInbox` körs först — fångsten skapar inboxen.
     *
     * **Kvoten och storleksgränsen är personkontots** (ADR-0054 § 3), och
     * kontot bestäms av servern: det är inboxens ägarkonto. Klienten skickar
     * inget konto.
     *
     * Ordningen är itemets uppladdnings, men i TVÅ steg: först den billiga
     * styckesspärren och SUMMAN, sedan skrivningarna — som prövar kvoten en
     * gång till var, auktoritativt, i sin egen transaktion.
     *
     * **Kvotspärren gäller hela satsen och står FÖRE den första skrivningen.**
     * Fångsten är inte "allt eller inget" — en fil i taget skrivs av
     * `StoreAttachment` — men utan en gemensam prövning hade en sats vars
     * summa spräcker kvoten lämnat fil 1–2 i inboxen och svarat med ett fel på
     * `files`: användaren ser ett fel och en halvfylld inbox. En enda
     * `assertStorageWithinLimit($account, summan)` gör svaret begripligt och
     * speglar App\Actions\Inbox\ProcessInboxAttachments, som prövar sin summa
     * på samma sätt (ADR-0054 § 6).
     */
    public function storeAttachments(
        StoreInboxAttachmentsRequest $request,
        ResolveInbox $resolveInbox,
        StoreAttachment $storeAttachment,
        Entitlements $entitlements,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        $user = $request->user();
        $inbox = $resolveInbox->handle($user);
        $account = $inbox->container->account;

        /** @var list<UploadedFile> $files */
        $files = $request->file('files');

        try {
            // Först: storlekarna, den billiga styckespärren och summan.
            $totalBytes = 0;

            foreach ($files as $file) {
                $byteSize = $file->getSize();

                if ($byteSize === false) {
                    throw new RuntimeException('Den mottagna filen kunde inte läsas.');
                }

                $entitlements->assertFileWithinLimit($account, $byteSize);

                $totalBytes += $byteSize;
            }

            // Kvoten prövas EN gång för hela satsen, innan den första
            // skrivningen. Kastet nekar allt och ingenting har skrivits.
            $entitlements->assertStorageWithinLimit($account, $totalBytes);

            foreach ($files as $file) {
                $storeAttachment->handle(
                    item: $inbox,
                    file: $file,
                    user: $user,
                    account: $account,
                );
            }
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['files' => $translator->message($e)]);
        }

        return back()->with('status', 'inbox-files-captured');
    }

    /**
     * POST /inbox/tasks — 302 tillbaka till `/inbox`, eller till `return`.
     *
     * **Hela formulärets kropp** (issue 246 § Beslut 1): `/tasks/create`
     * postar hit när platsen är *Inbox*, och fälten är schemats egna — titel,
     * anteckningar, återkommande och datum. En uppgift i inboxen har ändå
     * ingen lista (ADR-0054 § 5): `StoreInboxTaskRequest` tar inte emot
     * `gtd_list`, och `CreateSchedule` nollar både förekomstens lista och
     * schemats förval för ett inbox-item.
     *
     * **`recurrence_type` saknas i den gamla fångsten.** Formuläret på
     * `/inbox` postar bara en titel (ADR-0054 § 8), och den blir `none` utan
     * datum — samma svar som förut.
     *
     * **Svaret är `back()` när ingen `return` kom med, och annars den
     * adressen.** `return` valideras av
     * App\Http\Controllers\TaskCreateController::safeReturn(): en relativ
     * adress släpps igenom, allt annat — en extern värd inräknad — blir
     * `/tasks` (Beslut 3). Fångsten på `/inbox` skickar ingen `return` och
     * landar därför på samma sida som förut.
     */
    public function storeTask(
        StoreInboxTaskRequest $request,
        ResolveInbox $resolveInbox,
        CreateSchedule $createSchedule,
    ): RedirectResponse {
        $user = $request->user();
        $inbox = $resolveInbox->handle($user);

        $data = $request->validated();
        $data['recurrence_type'] ??= 'none';
        $data['is_active'] = true;

        $createSchedule->handle(
            $inbox,
            $user,
            new Schedule($data),
            null,
        );

        if ($request->has('return')) {
            return redirect(TaskCreateController::safeReturn($request->input('return')) ?? '/tasks')
                ->with('status', 'inbox-task-captured');
        }

        return back()->with('status', 'inbox-task-captured');
    }

    /**
     * POST /inbox/attachments/process — 302 tillbaka till `/inbox`.
     *
     * Flera bilagor till SAMMA item. Grindarna är flyttens: `delete` på
     * källan — användarens inbox-item — och `create` på målet. Finns ingen
     * inbox finns ingenting att bearbeta, och svaret är samma 422 som en
     * bilaga utanför inboxen (ADR-0054 § 6).
     *
     * Kvotsumman, låset och "allt eller inget" bor i
     * App\Actions\Inbox\ProcessInboxAttachments.
     */
    public function processAttachments(
        ProcessInboxAttachmentsRequest $request,
        ResolveInbox $resolveInbox,
        ProcessInboxAttachments $processInboxAttachments,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        $user = $request->user();
        $inbox = $resolveInbox->existing($user);

        if ($inbox === null) {
            throw ValidationException::withMessages([
                'attachments' => $translator->message(ApiException::make('attachment.not_in_inbox', [], 422)),
            ]);
        }

        Gate::authorize('delete', $inbox);

        $target = $request->targetItem();

        Gate::authorize('create', $target);

        try {
            $processInboxAttachments->handle($user, $target, $request->validated('attachments'));
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['attachments' => $translator->message($e)]);
        }

        return back()->with('status', 'inbox-attachments-processed');
    }

    /**
     * POST /inbox/tasks/{schedule}/process — 302 tillbaka.
     *
     * Bearbetningen av en uppgift: välj item, lista och datum. Grindarna är
     * flyttens — `delete` på KÄLLAN och `create` på MÅLET. Källan är schemats
     * item, och `{schedule}` binds globalt på ULID (ingen container i
     * adressen): ett schema i någon annans inbox finns, men `delete` på dess
     * item svarar 403, och ett schema utanför användarens egen inbox nekas av
     * actionen med 422 `schedule.not_in_inbox`.
     *
     * **Källitemet läses null-säkert.** Ett schema vars item är mjukraderat
     * har ingen `$schedule->item` att pröva grinden på; en rå
     * `Gate::authorize` hade gett 500. `withTrashed()` ser raden och 404:ar,
     * samma svar som en ULID som aldrig funnits.
     */
    public function process(
        ProcessInboxTaskRequest $request,
        Schedule $schedule,
        ProcessInboxTask $processInboxTask,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        $source = Item::withTrashed()->find($schedule->item_id);

        abort_if($source === null || $source->trashed(), 404);

        Gate::authorize('delete', $source);

        $target = $request->targetItem();

        Gate::authorize('create', $target);

        try {
            $processInboxTask->handle(
                $schedule,
                $target,
                $request->user(),
                $request->validated('gtd_list'),
                $request->validated('due_at'),
            );
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['schedule' => $translator->message($e)]);
        }

        return back()->with('status', 'schedule-processed');
    }

    /**
     * DELETE /inbox/tasks/{schedule} — 302 tillbaka till `/inbox`.
     *
     * **Inga nya raderingsregler** (M28 · issue 775 · Beslut 1): uppgiften
     * raderas med App\Actions\Schedule\DeleteSchedule, exakt som *Delete* på
     * uppgiftens sida. Mjukraderingen, händelseloggen och transaktionen bor i
     * actionen; kontrollern prövar bara grinden och var uppgiften ligger.
     *
     * **Grinden är `delete` på källitemet**, samma pinne som bearbetningen
     * intill (issue 63a § Beslut 7): en `write`-mottagare ändrar ett schema men
     * tar inte bort det. Källitemet läses null-säkert med `withTrashed()` — ett
     * schema vars item är mjukraderat har inget att pröva grinden på, och en rå
     * `Gate::authorize` hade gett 500 i stället för 404 (samma form som
     * `process()`).
     *
     * **Bara användarens EGEN inbox.** Ligger uppgiften på ett riktigt item —
     * eller i någon annans inbox, vilket grinden redan har svarat 403 på —
     * nekas anropet med 422 `schedule.not_in_inbox`, samma svar som
     * bearbetningen ger. Grinden står först: den svarar på VEM som frågar, och
     * 422:an på VAR uppgiften ligger.
     */
    public function destroyTask(
        Request $request,
        Schedule $schedule,
        ResolveInbox $resolveInbox,
        DeleteSchedule $deleteSchedule,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        $user = $request->user();

        $source = Item::withTrashed()->find($schedule->item_id);

        abort_if($source === null || $source->trashed(), 404);

        Gate::authorize('delete', $source);

        $inbox = $resolveInbox->existing($user);

        if ($inbox === null || (int) $schedule->item_id !== (int) $inbox->id) {
            throw ValidationException::withMessages([
                'schedule' => $translator->message(ApiException::make('schedule.not_in_inbox', [], 422)),
            ]);
        }

        $deleteSchedule->handle($schedule, $user);

        return back()->with('status', 'schedule-deleted');
    }

    /**
     * DELETE /inbox/attachments/{attachment} — 302 tillbaka till `/inbox`.
     *
     * **Inga nya raderingsregler** (M28 · issue 775 · Beslut 1): bilagan raderas
     * med App\Actions\Attachment\TrashAttachment — papperskorg, referensräkning
     * och fördröjd radering som överallt annars. Actionen öppnar sin egen
     * transaktion och läser om raden under lås.
     *
     * **Grinden är `delete` på användarens eget inbox-item** (ADR-0054 § 2) —
     * samma grind som `processAttachments()` prövar, och den enda som behövs:
     * raden kan bara ligga i den egna inboxen, för någon annans inbox nekas
     * redan av medlemskapsprövningen. En ULID som inte finns, en bilaga på ett
     * riktigt item och en bilaga i någon annans inbox får samma svar: 422
     * `attachment.not_in_inbox`, och raden är orörd (ADR-0054 § 6).
     */
    public function destroyAttachment(
        Request $request,
        Attachment $attachment,
        ResolveInbox $resolveInbox,
        TrashAttachment $trashAttachment,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        $user = $request->user();
        $inbox = $resolveInbox->existing($user);

        if ($inbox === null || (int) $attachment->item_id !== (int) $inbox->id) {
            throw ValidationException::withMessages([
                'attachment' => $translator->message(ApiException::make('attachment.not_in_inbox', [], 422)),
            ]);
        }

        Gate::authorize('delete', $inbox);

        $trashAttachment->handle($attachment, $user);

        return back()->with('status', 'inbox-attachment-deleted');
    }

    /**
     * POST /inbox/attachments/delete — 302 tillbaka till `/inbox`.
     *
     * **Flera bilagor på en gång, allt eller inget.** Kroppen är densamma som
     * `inbox.attachments.process` tar emot (`attachments[]`), och hela satsen
     * ligger i EN transaktion: `TrashAttachment` öppnar sin egen — den blir en
     * savepoint i den här — så ett kast på bilaga tre rullar tillbaka både dess
     * egen skrivning och de två första raderingarnas (ADR-0054 § 6).
     *
     * **Medlemskapsprövningen står FÖRE den första raderingen**, och raderna
     * läses om under lås: en bilaga som inte ligger i användarens EGEN inbox
     * nekar HELA satsen med 422 `attachment.not_in_inbox`, och ingen rad har
     * rörts. Utan spärren hade rutten varit en radering bakvägen — kroppen bär
     * ULID:er, och vilken bilaga som helst hade gått att träffa.
     */
    public function destroyAttachments(
        DeleteInboxAttachmentsRequest $request,
        ResolveInbox $resolveInbox,
        TrashAttachment $trashAttachment,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        $user = $request->user();
        $inbox = $resolveInbox->existing($user);

        if ($inbox === null) {
            throw ValidationException::withMessages([
                'attachments' => $translator->message(ApiException::make('attachment.not_in_inbox', [], 422)),
            ]);
        }

        Gate::authorize('delete', $inbox);

        /** @var list<string> $ulids */
        $ulids = $request->validated('attachments');

        try {
            DB::transaction(function () use ($ulids, $inbox, $user, $trashAttachment): void {
                $attachments = Attachment::query()
                    ->whereIn('ulid', $ulids)
                    ->lockForUpdate()
                    ->get();

                // Färre rader än ULID:er betyder att någon av dem inte finns —
                // då ligger den inte i inboxen heller.
                if ($attachments->count() !== count($ulids)) {
                    throw ApiException::make('attachment.not_in_inbox', [], 422);
                }

                foreach ($attachments as $attachment) {
                    if ((int) $attachment->item_id !== (int) $inbox->id) {
                        throw ApiException::make('attachment.not_in_inbox', [], 422);
                    }
                }

                foreach ($attachments as $attachment) {
                    $trashAttachment->handle($attachment, $user);
                }
            });
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['attachments' => $translator->message($e)]);
        }

        return back()->with('status', 'inbox-attachments-deleted');
    }

    /**
     * Bilagans ULID → de varianter som faktiskt finns, byggd ur de
     * eager-laddade relationerna — samma hjälpare som
     * App\Http\Controllers\ItemController::variants() och
     * ContainerDocumentController::variants(), och av samma skäl: en bilaga
     * utan derivat får en TOM lista och inte en utelämnad nyckel, så vyns
     * uppslag är detsamma för alla rader.
     *
     * @param  Collection<int, Attachment>  $attachments
     * @return array<string, list<string>>
     */
    private function variants(Collection $attachments): array
    {
        $variants = [];

        foreach ($attachments as $attachment) {
            $variants[$attachment->ulid] = $attachment->storedFile->derivatives
                ->pluck('variant')
                ->sort()
                ->values()
                ->all();
        }

        return $variants;
    }
}
