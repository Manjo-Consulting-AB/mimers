<?php

namespace App\Http\Controllers;

use App\Actions\Inbox\ProcessInboxTask;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Inbox\ProcessInboxTaskRequest;
use App\Models\Item;
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
 * **Källitemet läses null-säkert.** Ett schema vars item är mjukraderat har
 * ingen `$schedule->item` att pröva grinden på; en rå `Gate::authorize` hade
 * gett 500. `withTrashed()` ser raden och 404:ar, samma svar som en ULID som
 * aldrig funnits.
 *
 * **Ett domänfel blir ett formulärfel, aldrig en JSON-kropp** — samma mönster
 * som ScheduleController::move(): `schedule.not_in_inbox`,
 * `schedule.has_dependencies`, `schedule.same_item` och
 * `schedule.not_a_valid_target` ritas på fältet `schedule`.
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
}
