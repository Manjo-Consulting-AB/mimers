<?php

namespace App\Http\Controllers\Api;

use App\Actions\Attachment\StoreAttachment;
use App\Actions\Inbox\ProcessInboxAttachments;
use App\Actions\Inbox\ProcessInboxTask;
use App\Actions\Inbox\ResolveInbox;
use App\Actions\Schedule\CreateSchedule;
use App\Actions\Schedule\ListTodo;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inbox\ProcessInboxAttachmentsRequest;
use App\Http\Requests\Inbox\ProcessInboxTaskRequest;
use App\Http\Requests\Inbox\StoreInboxAttachmentsRequest;
use App\Http\Requests\Inbox\StoreInboxTaskRequest;
use App\Http\Resources\AttachmentResource;
use App\Http\Resources\ScheduleResource;
use App\Models\Item;
use App\Models\Schedule;
use App\Support\Plan\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Inboxen i `/api` — M27 · issue 247, se [[ADR-0054 Inboxen]] § 4, 6, 7 och 8.
 *
 * **Varje rutt anropar SAMMA action som webben.** index() läser genom
 * App\Actions\Schedule\ListTodo och App\Actions\Inbox\ResolveInbox, och
 * skrivningarna går genom StoreAttachment, CreateSchedule, ProcessInboxTask
 * och ProcessInboxAttachments — precis som App\Http\Controllers\
 * InboxController. Ingen regel bor här: kontrollern validerar (FormRequest),
 * prövar grinden och formar svaret ([[ADR-0024 Tunna controllers och
 * actions]]).
 *
 * **GET /api/inbox skapar ingen inbox.** index() läser användarens inbox-item
 * genom ResolveInbox::existing() — att läsa en vy ska inte skapa en container
 * — och svarar med tomma listor när den saknas (ADR-0054 § 1). Den skapas
 * först när något FÅNGAS: storeAttachments och storeTask kallar
 * ResolveInbox::handle().
 *
 * **Grindarna är flyttens** ([[ADR-0053 Flytt och kopiering]] § 2) där en
 * källa och ett mål finns: `delete` på KÄLLAN och `create` på MÅLET. Att
 * fånga är alltid tillåtet för den inloggade — rutten ligger bakom `auth`, och
 * inboxen är hennes egen (ADR-0054 § 2) — så fångst och bearbetning av den
 * egna inboxen har ingen egen policy att pröva.
 *
 * **Felen följer [[AGENTS.md]] § Felformat.** Domänfelen kastas av actionen
 * som App\Exceptions\Api\ApiException och renderas i felhöljet med sin kod
 * orörd: `quota.storage_exceeded`, `schedule.has_dependencies`,
 * `schedule.not_in_inbox`, `attachment.not_in_inbox`,
 * `schedule.already_in_inbox` och `attachment.already_in_inbox`.
 */
class InboxController extends Controller
{
    /**
     * GET /api/inbox — 200, `{data: {tasks, attachments}}`. Användarens
     * obearbetade uppgifter och bilagor.
     *
     * **Ingen inbox skapas** (ADR-0054 § 1): uppslaget är
     * ResolveInbox::existing(), och listorna är tomma när den saknas.
     *
     * Uppgifterna kommer ur ListTodo med `list: inbox` — samma urval och samma
     * radform som webbens `/inbox` och fliken *Inbox* på `/tasks` (issue 244).
     * Bilagorna är inbox-itemets bilagor, nyast först, i AttachmentResource —
     * samma sortering och samma resurs som itemets detaljvy (issue 60
     * § Beslut 2).
     */
    public function index(Request $request, ListTodo $listTodo, ResolveInbox $resolveInbox): JsonResponse
    {
        $user = $request->user();
        $inbox = $resolveInbox->existing($user);

        $attachments = $inbox === null
            ? new Collection
            : $inbox->attachments()
                ->with(['storedFile', 'billedAccount'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();

        return response()->json([
            'data' => [
                'tasks' => $listTodo->handle($user, $request, list: ListTodo::LIST_INBOX)['rows'],
                'attachments' => AttachmentResource::collection($attachments)->resolve($request),
            ],
        ]);
    }

    /**
     * POST /api/inbox/attachments — 201, AttachmentResource-samlingen.
     * Fångar en eller flera filer på användarens inbox-item.
     *
     * **Kontot är personkontot** (ADR-0054 § 3), och klienten skickar inget
     * konto: inboxens ägarkonto bestäms av servern. Ett `StoreAttachment` per
     * fil — hashen, sniffningen, dedupen och referensräkningen bor i actionen
     * och skrivs inte om här.
     *
     * **Kvotspärren gäller hela satsen och står FÖRE den första skrivningen**
     * (samma mönster som webbens InboxController::storeAttachments): först
     * styckesspärren, sedan summan, så en sats vars summa spräcker kvoten
     * nekas i ett svar i stället för att lämna fil 1–2 i inboxen.
     */
    public function storeAttachments(
        StoreInboxAttachmentsRequest $request,
        ResolveInbox $resolveInbox,
        StoreAttachment $storeAttachment,
        Entitlements $entitlements,
    ): JsonResponse {
        $user = $request->user();
        $inbox = $resolveInbox->handle($user);
        $account = $inbox->container->account;

        /** @var list<UploadedFile> $files */
        $files = $request->file('files');

        $totalBytes = 0;

        foreach ($files as $file) {
            $byteSize = $file->getSize();

            if ($byteSize === false) {
                throw new RuntimeException('Den mottagna filen kunde inte läsas.');
            }

            $entitlements->assertFileWithinLimit($account, $byteSize);

            $totalBytes += $byteSize;
        }

        // EN prövning för hela satsen, innan den första skrivningen. Kastet
        // nekar allt och ingenting har skrivits.
        $entitlements->assertStorageWithinLimit($account, $totalBytes);

        $created = [];

        foreach ($files as $file) {
            $created[] = $storeAttachment->handle(
                item: $inbox,
                file: $file,
                user: $user,
                account: $account,
            );
        }

        return AttachmentResource::collection($created)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * POST /api/inbox/tasks — 201, ScheduleResource. Fångar en uppgift på
     * användarens inbox-item.
     *
     * **Hela formulärets kropp** (issue 246 § Beslut 1): fälten är schemats
     * egna, `StoreInboxTaskRequest` tar inte emot `gtd_list`, och
     * CreateSchedule nollar både förekomstens lista och schemats förval för
     * ett inbox-item (ADR-0054 § 5). `recurrence_type` saknas i den gamla
     * fångsten och blir `none` utan datum.
     */
    public function storeTask(
        StoreInboxTaskRequest $request,
        ResolveInbox $resolveInbox,
        CreateSchedule $createSchedule,
    ): JsonResponse {
        $user = $request->user();
        $inbox = $resolveInbox->handle($user);

        $data = $request->validated();
        $data['recurrence_type'] ??= 'none';
        $data['is_active'] = true;

        $schedule = $createSchedule->handle($inbox, $user, new Schedule($data), null);

        return (new ScheduleResource($schedule))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * POST /api/inbox/tasks/{schedule}/process — 200, ScheduleResource.
     * Bearbetar en uppgift: välj item, lista och datum, genom
     * App\Actions\Inbox\ProcessInboxTask (ADR-0054 § 6).
     *
     * **Grindarna är flyttens** — `delete` på KÄLLAN och `create` på MÅLET.
     * Källan är schemats item och `{schedule}` binds globalt på ULID (ingen
     * container i adressen, som i webben): ett schema i någon annans inbox
     * finns, men `delete` på dess item svarar 403, och ett schema utanför
     * användarens egen inbox nekas av actionen med 422 `schedule.not_in_inbox`.
     *
     * **Källitemet läses null-säkert.** Ett schema vars item är mjukraderat
     * har ingen `$schedule->item` att pröva grinden på; `withTrashed()` ser
     * raden och 404:ar, samma svar som en ULID som aldrig funnits.
     */
    public function process(
        ProcessInboxTaskRequest $request,
        Schedule $schedule,
        ProcessInboxTask $processInboxTask,
    ): ScheduleResource {
        $source = Item::withTrashed()->find($schedule->item_id);

        abort_if($source === null || $source->trashed(), 404);

        Gate::authorize('delete', $source);

        $target = $request->targetItem();

        Gate::authorize('create', $target);

        return new ScheduleResource($processInboxTask->handle(
            $schedule,
            $target,
            $request->user(),
            $request->validated('gtd_list'),
            $request->validated('due_at'),
        ));
    }

    /**
     * POST /api/inbox/attachments/process — 200. Bearbetar flera bilagor till
     * SAMMA item, genom App\Actions\Inbox\ProcessInboxAttachments (ADR-0054
     * § 6).
     *
     * **Grindarna är flyttens** — `delete` på KÄLLAN (användarens inbox-item)
     * och `create` på MÅLET. Finns ingen inbox finns ingenting att bearbeta,
     * och svaret är 422 `attachment.not_in_inbox` — samma svar som en bilaga
     * utanför inboxen. Kvotsumman, låset och "allt eller inget" bor i actionen.
     *
     * Åtgärden returnerar inget: det finns ingen skapad rad att svara med —
     * bilagorna byter bara `item_id` — så kroppen är en tom `data`.
     */
    public function processAttachments(
        ProcessInboxAttachmentsRequest $request,
        ResolveInbox $resolveInbox,
        ProcessInboxAttachments $processInboxAttachments,
    ): JsonResponse {
        $user = $request->user();
        $inbox = $resolveInbox->existing($user);

        if ($inbox === null) {
            throw ApiException::make('attachment.not_in_inbox', [], 422);
        }

        Gate::authorize('delete', $inbox);

        $target = $request->targetItem();

        Gate::authorize('create', $target);

        $processInboxAttachments->handle($user, $target, $request->validated('attachments'));

        return response()->json(['data' => []]);
    }
}
