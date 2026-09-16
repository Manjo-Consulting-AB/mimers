<?php

namespace App\Http\Controllers;

use App\Actions\Schedule\DependSchedule;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Schedule\StoreScheduleDependencyRequest;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleDependency;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Webbens två skrivningar mot ett SCHEMA-beroende — regeln som ärvs av varje
 * ny förekomst — se issue 63c § Beslut 1, 3, 6, 7, 8 och 9.
 *
 * **Ingenting av `/api` görs om.** `StoreScheduleDependencyRequest` delas rakt
 * av — dess `Rule::exists` binder motparten till samma pärm och är halva
 * skyddet — och reglerna (samma container, inte sig själv, ingen cykel) ligger
 * orörda i App\Actions\Schedule\DependSchedule sedan issue 23 § Beslut 5.
 * Ingen cykelkontroll skrivs i vyn och ingen i den här kontrollern: den som
 * formulerar samma regel två gånger får två sanningar som glider isär.
 *
 * **`{other}` binds INTE av `scopeBindings()`** (issue 23 § Beslut 3).
 * Motparten slås upp för hand INOM containern, exakt som
 * App\Http\Controllers\ItemLinkController gör, och en ULID från en annan pärm
 * blir därför 404.
 *
 * **Två grindar, `update` i båda ändar** (Beslut 8, issue 71 § Beslut 1 och
 * 3). Ordningen är `$schedule->item` FÖRST, motpartens item efter uppslaget —
 * så att en mottagare som inte når det egna schemat får 403 innan hon får
 * veta något om motparten. En motpart inom pärmen men utanför omfånget ger
 * också 403, utan att svaret röjer dess titel eller itemnamn. Aldrig `delete`:
 * ett beroende tar inte bort något av schemana (Beslut 7).
 *
 * **De sex domänfelen blir fältfel, aldrig JSON-kroppar** (Beslut 6).
 * `DependSchedule` kastar App\Exceptions\Api\ApiException, som svarar
 * `{"error":{"code":…}}` var den än kastas — också från en Inertia-kontroller.
 * Utan fångsten får användaren en rå JSON-kropp mitt i en sida. Alla koder
 * hamnar på `depends_on`: varje regel handlar om vilket schema som valdes.
 */
class ScheduleDependencyController extends Controller
{
    /**
     * POST /containers/{container}/items/{item}/schedules/{schedule}/dependencies
     * — 302 tillbaka.
     *
     * `StoreScheduleDependencyRequest` har redan bevisat att motpartens ULID
     * finns i DEN HÄR containern och inte är mjukraderad — en ULID från en
     * annan pärm är ett fältfel på `depends_on`, aldrig en 404 och aldrig en
     * behörighetsfråga. Här slås motparten bara upp, och `update` krävs i båda
     * ändarna.
     */
    public function store(
        StoreScheduleDependencyRequest $request,
        Container $container,
        Item $item,
        Schedule $schedule,
        DependSchedule $dependSchedule,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('update', $schedule->item);

        $other = Schedule::query()
            ->whereHas('item', fn ($query) => $query->where('container_id', $container->id))
            ->where('ulid', $request->validated('depends_on'))
            ->firstOrFail();

        Gate::authorize('update', $other->item);

        try {
            $dependSchedule->handle($schedule, $other);
        } catch (ApiException $e) {
            throw ValidationException::withMessages([
                'depends_on' => $this->errorMessage($e, $translator, $schedule, $other),
            ]);
        }

        return back()->with('status', 'schedule-dependency-created');
    }

    /**
     * DELETE /containers/{container}/items/{item}/schedules/{schedule}
     * /dependencies/{other} — 302 tillbaka.
     *
     * Raderingen är HÅRD och bär ingen regel (Beslut 7): båda schemana finns
     * kvar, det som går förlorat är regeln. Finns ingen beroenderad mellan
     * paret är svaret 404 — samma som på `/api`.
     *
     * Båda ändarna auktoriseras med `update`, samma grind som POST: att bryta
     * en koppling är samma skrivning som att knyta den.
     */
    public function destroy(Container $container, Item $item, Schedule $schedule, string $other): RedirectResponse
    {
        Gate::authorize('update', $schedule->item);

        $otherSchedule = Schedule::query()
            ->whereHas('item', fn ($query) => $query->where('container_id', $container->id))
            ->where('ulid', $other)
            ->firstOrFail();

        Gate::authorize('update', $otherSchedule->item);

        $dependency = ScheduleDependency::query()
            ->where('schedule_id', $schedule->id)
            ->where('depends_on_schedule_id', $otherSchedule->id)
            ->first();

        if ($dependency === null) {
            abort(404);
        }

        $dependency->delete();

        return back()->with('status', 'schedule-dependency-removed');
    }

    /**
     * Meningen för felet (Beslut 6). `data` slängs aldrig bort: ett meddelande
     * utan det är sämre än felkoden det ersatte, samma regel som issue 58
     * § Beslut 6.
     *
     * `schedule.dependency_cycle` bär `data.schedule` och `data.depends_on` —
     * ULID:erna för den kant som försöktes, alltså exakt de två schemaobjekt
     * kontrollern redan håller. Meningen namnger dem med sina TITLAR och inte
     * med ULID:er: "vilken kedja" är obegripligt som två strängar ur en
     * databas, och det är samma översättning av `data` som issue 58 gör för
     * `item_link.pair_exists`. De tre andra koderna går rakt genom
     * App\Support\Frontend\ApiErrorTranslator.
     */
    private function errorMessage(
        ApiException $exception,
        ApiErrorTranslator $translator,
        Schedule $schedule,
        Schedule $other,
    ): string {
        if ($exception->errorCode() !== 'schedule.dependency_cycle') {
            return $translator->message($exception);
        }

        $titleByUlid = [
            $schedule->ulid => $schedule->title,
            $other->ulid => $other->title,
        ];

        $data = $exception->data();

        return (string) trans('ui.error.schedule.dependency_cycle', [
            'schedule' => $titleByUlid[$data['schedule']] ?? $schedule->title,
            'depends_on' => $titleByUlid[$data['depends_on']] ?? $other->title,
        ]);
    }
}
