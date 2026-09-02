<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\StoreScheduleRequest;
use App\Http\Requests\Schedule\UpdateScheduleRequest;
use App\Http\Resources\ScheduleResource;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * CRUD-ytan för schema, se issue 21. INGEN behörighetslogik bor här — varje
 * metod anropar bara `Gate::authorize()` mot de BEFINTLIGA grindarna `view`
 * (listning) och `update` (skapa/ändra/radera) på App\Policies\ContainerPolicy,
 * se issue 21 § Beslut 2. Ingen ny policymetod, ingen `SchedulePolicy`.
 *
 * routes/api.php nästlar `{item}` under `{container}` och `{schedule}` under
 * `{item}` med gruppens `->scopeBindings()` — `{item}` löses genom
 * App\Models\Container::items() och `{schedule}` genom
 * App\Models\Item::schedules(). Det är HELA skyddet mot ett schema på ett
 * annat item, eller ett item i en annan container: båda ger 404 (issue 21 §
 * Beslut 1).
 *
 * Ingen show(): listan hämtar hela uppsättningen, som är kort per definition
 * (§ Beslut 1). Ingen Action: [[ADR-0024 Tunna controllers och actions]].
 *
 * `item_id` sätts explicit från rutten, aldrig via massildelning —
 * `item_id` är UTESLUTEN ur Schedule#[Fillable] (§ Att se upp med).
 */
class ScheduleController extends Controller
{
    /**
     * GET /api/containers/{container}/items/{item}/schedules — 200. Sorterad
     * `title` stigande, ingen paginering (issue 21 § Beslut 8). Ett konstant
     * antal frågor oavsett antal scheman — ScheduleResource läser bara
     * kolumner på raden själv, inga relationer att ladda i förväg.
     */
    public function index(Container $container, Item $item): JsonResponse
    {
        Gate::authorize('view', $container);

        $schedules = $item->schedules()
            ->orderBy('title')
            ->get();

        return ScheduleResource::collection($schedules)->response();
    }

    /**
     * POST /api/containers/{container}/items/{item}/schedules — 201.
     * StoreScheduleRequest har redan bevisat att kroppen är sammanhängande:
     * alla tre återkommandetyperna kräver `anchor_date`, `fixed`/`interval`
     * kräver intervallkolumnerna och `none` avvisar dem (issue 21 § Beslut
     * 5). Ett schema som skapas här har ingen öppen förekomst — tabellen
     * `schedule_occurrence` finns inte förrän 22a.
     */
    public function store(StoreScheduleRequest $request, Container $container, Item $item): JsonResponse
    {
        Gate::authorize('update', $container);

        $schedule = new Schedule($request->validated());
        $schedule->item_id = $item->id;
        $schedule->save();

        return (new ScheduleResource($schedule))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH /api/containers/{container}/items/{item}/schedules/{schedule} —
     * 200. Bara dokumenterade fält, alla valfria. Reglerna i
     * UpdateScheduleRequest gäller det sammanslagna tillståndet efter
     * ändringen; `validated()` bär redan de nollade intervallkolumnerna när
     * schemat byter till `none`, så raden städas i samma skrivning (§ Att se
     * upp med).
     */
    public function update(UpdateScheduleRequest $request, Container $container, Item $item, Schedule $schedule): ScheduleResource
    {
        Gate::authorize('update', $container);

        $schedule->fill($request->validated());

        if ($schedule->recurrence_type === 'none') {
            $schedule->interval_unit = null;
            $schedule->interval_count = null;
        }

        $schedule->save();

        return new ScheduleResource($schedule);
    }

    /**
     * DELETE /api/containers/{container}/items/{item}/schedules/{schedule} —
     * 204, ingen kropp. Mjuk radering (SoftDeletes): `deleted_at` sätts och
     * raden ligger kvar. Schemat hamnar INTE i papperskorgen — den listar
     * fyra typer och behåller fyra, se issue 21 § Beslut 9. Ett raderat
     * schema går alltså inte att ta tillbaka via API:et i MVP; ett medvetet
     * glapp, inte ett förbiseende.
     */
    public function destroy(Container $container, Item $item, Schedule $schedule): Response
    {
        Gate::authorize('update', $container);

        $schedule->delete();

        return response()->noContent();
    }
}
