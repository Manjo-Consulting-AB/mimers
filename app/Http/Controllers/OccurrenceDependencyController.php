<?php

namespace App\Http\Controllers;

use App\Actions\Schedule\DependOccurrence;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Schedule\StoreOccurrenceDependencyRequest;
use App\Models\Container;
use App\Models\Item;
use App\Models\OccurrenceDependency;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Webbens två skrivningar mot ett FÖREKOMST-beroende — undantaget som bara
 * gäller den här gången — se issue 63c § Beslut 1, 3, 6, 7, 8 och 9.
 *
 * Systern till App\Http\Controllers\ScheduleDependencyController, en nivå ner,
 * och med samma uppdelning: schemaberoendet är REGELN som ärvs av varje ny
 * förekomst, förekomstberoendet är undantaget ([[ADR-0005 Schema och
 * förekomst]]). Båda syns på schemats sida, under var sin rubrik.
 *
 * **Ingenting av `/api` görs om.** `StoreOccurrenceDependencyRequest` delas
 * rakt av — dess `Rule::exists` binder motparten till samma container och är halva
 * skyddet — och reglerna (samma container, öppen väntande sida, inte sig
 * själv, ingen cykel) ligger orörda i App\Actions\Schedule\DependOccurrence
 * sedan issue 23b § Beslut 7.
 *
 * **`{other}` binds INTE av `scopeBindings()`** (issue 23b § Beslut 3).
 * Motparten slås upp för hand INOM containern, och en ULID från en annan container
 * blir därför 404. Itemet är `$occurrence->schedule->item`, aldrig containern.
 *
 * **Två grindar, `update` i båda ändar** (Beslut 8). Ordningen är den egna
 * änden FÖRST, motpartens item efter uppslaget. En motpart inom containern men
 * utanför omfånget ger 403 utan att svaret röjer dess titel eller itemnamn.
 *
 * **De sex domänfelen blir fältfel, aldrig JSON-kroppar** (Beslut 6), alla på
 * `depends_on`. `occurrence.not_open` går rakt genom
 * App\Support\Frontend\ApiErrorTranslator — nyckeln finns sedan 63b § Beslut 6
 * och säger samma sak här: förekomsten är redan avslutad.
 */
class OccurrenceDependencyController extends Controller
{
    /**
     * POST /containers/{container}/items/{item}/schedules/{schedule}
     * /occurrences/{occurrence}/dependencies — 302 tillbaka.
     *
     * Den väntande sidan måste vara ÖPPEN (`occurrence.not_open` annars), men
     * att bero på en redan STÄNGD motpart är tillåtet och direkt uppfyllt —
     * det är en historisk anteckning, inte ett hinder (issue 23b § Beslut 7).
     */
    public function store(
        StoreOccurrenceDependencyRequest $request,
        Container $container,
        Item $item,
        Schedule $schedule,
        ScheduleOccurrence $occurrence,
        DependOccurrence $dependOccurrence,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('update', $occurrence->schedule->item);

        // `container_id` står i kolumnlistan för att ItemPolicy läser itemets
        // container; utan den är relationen tom och grinden kastar — samma
        // rad som i Api\OccurrenceDependencyController::store().
        $other = ScheduleOccurrence::query()
            ->where('ulid', $request->validated('depends_on'))
            ->whereHas('schedule.item', fn ($query) => $query->where('container_id', $container->id))
            ->with(['schedule:id,title,item_id', 'schedule.item:id,ulid,name,container_id'])
            ->firstOrFail();

        Gate::authorize('update', $other->schedule->item);

        try {
            $dependOccurrence->handle($occurrence, $other);
        } catch (ApiException $e) {
            throw ValidationException::withMessages([
                'depends_on' => $this->errorMessage($e, $translator, $occurrence, $other),
            ]);
        }

        return back()->with('status', 'occurrence-dependency-created');
    }

    /**
     * DELETE /containers/{container}/items/{item}/schedules/{schedule}
     * /occurrences/{occurrence}/dependencies/{other} — 302 tillbaka.
     *
     * Raderingen är HÅRD (Beslut 7): både schemana och förekomsterna finns
     * kvar, det som går förlorat är undantaget. Finns ingen beroenderad mellan
     * paret är svaret 404 — samma som på `/api`.
     */
    public function destroy(
        Container $container,
        Item $item,
        Schedule $schedule,
        ScheduleOccurrence $occurrence,
        string $other,
    ): RedirectResponse {
        Gate::authorize('update', $occurrence->schedule->item);

        $otherOccurrence = ScheduleOccurrence::query()
            ->where('ulid', $other)
            ->whereHas('schedule.item', fn ($query) => $query->where('container_id', $container->id))
            ->with('schedule.item')
            ->firstOrFail();

        Gate::authorize('update', $otherOccurrence->schedule->item);

        $dependency = OccurrenceDependency::query()
            ->where('occurrence_id', $occurrence->id)
            ->where('depends_on_occurrence_id', $otherOccurrence->id)
            ->first();

        if ($dependency === null) {
            abort(404);
        }

        $dependency->delete();

        return back()->with('status', 'occurrence-dependency-removed');
    }

    /**
     * Meningen för felet (Beslut 6), systerimplementationen till
     * ScheduleDependencyController::errorMessage().
     *
     * `occurrence.dependency_cycle` bär `data.occurrence` och
     * `data.depends_on` — ULID:erna för den kant som försöktes. Meningen
     * namnger dem med sina SCHEMATITLAR och inte med ULID:er: "vilken kedja"
     * är obegripligt som två strängar ur en databas. De fem andra koderna går
     * rakt genom App\Support\Frontend\ApiErrorTranslator.
     */
    private function errorMessage(
        ApiException $exception,
        ApiErrorTranslator $translator,
        ScheduleOccurrence $occurrence,
        ScheduleOccurrence $other,
    ): string {
        if ($exception->errorCode() !== 'occurrence.dependency_cycle') {
            return $translator->message($exception);
        }

        $titleByUlid = [
            $occurrence->ulid => $occurrence->schedule->title,
            $other->ulid => $other->schedule->title,
        ];

        $data = $exception->data();

        return (string) trans('ui.error.occurrence.dependency_cycle', [
            'schedule' => $titleByUlid[$data['occurrence']] ?? $occurrence->schedule->title,
            'depends_on' => $titleByUlid[$data['depends_on']] ?? $other->schedule->title,
        ]);
    }
}
