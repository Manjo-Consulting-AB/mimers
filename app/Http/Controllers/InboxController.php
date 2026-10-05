<?php

namespace App\Http\Controllers;

use App\Actions\Inbox\ProcessInboxTask;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Inbox\ProcessInboxTaskRequest;
use App\Models\Schedule;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Bearbetningen av en uppgift i inboxen — `POST /inbox/tasks/{schedule}/
 * process`, M27 · issue 244, se App\Actions\Inbox\ProcessInboxTask och
 * [[ADR-0054 Inboxen]] § 6.
 *
 * **En rutt och ingen sida.** Sidan `/inbox` kommer i issue 245; hit når
 * bara formuläret som redan står där, och svaret är `back()` — användaren
 * står kvar i kön och nästa uppgift ligger överst.
 *
 * **Grindarna är desamma som för en flytt** (ADR-0053 § 2): `delete` på
 * KÄLLAN — uppgiften lämnar sin plats — och `create` på MÅLET. Källan är
 * schemats item, och `{schedule}` binds globalt på ULID (ingen container i
 * adressen): ett schema i någon annans inbox finns, men `delete` på dess item
 * svarar 403, och ett schema utanför användarens egen inbox nekas av actionen
 * med 422 `schedule.not_in_inbox`.
 *
 * **Ett domänfel blir ett formulärfel, aldrig en JSON-kropp** — samma mönster
 * som ScheduleController::move(): `schedule.not_in_inbox`,
 * `schedule.has_dependencies` och `schedule.same_item` ritas på fältet
 * `schedule`.
 */
class InboxController extends Controller
{
    /**
     * POST /inbox/tasks/{schedule}/process — 302 tillbaka.
     */
    public function process(
        ProcessInboxTaskRequest $request,
        Schedule $schedule,
        ProcessInboxTask $processInboxTask,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('delete', $schedule->item);

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
}
