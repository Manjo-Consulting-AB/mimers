<?php

namespace App\Http\Controllers\Api;

use App\Actions\Schedule\OpenNextOccurrence;
use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\StoreScheduleRequest;
use App\Http\Requests\Schedule\UpdateScheduleRequest;
use App\Http\Resources\ScheduleResource;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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
 * (§ Beslut 1). Domänregeln "ett aktivt schema öppnar sin första förekomst"
 * bor i App\Actions\Schedule\OpenNextOccurrence, aldrig här — [[ADR-0024
 * Tunna controllers och actions]] (issue 22 § Beslut 3). Skapandet av
 * schemat och dess förekomst delar en transaktion (issue 22 § Beslut 9).
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
     * 5).
     *
     * Ett schema som skapas AKTIVT öppnar sin första förekomst i samma
     * transaktion som schemat (issue 22 § Beslut 3 och 9) — ett schema som
     * sparats utan sin öppna förekomst är ett tillstånd användaren varken kan
     * se eller laga. Kastar OpenNextOccurrence rullas schemat tillbaka. Ett
     * schema som skapas PAUSAT (`is_active: false`) får ingen förekomst —
     * den öppnas först när det aktiveras.
     */
    public function store(StoreScheduleRequest $request, Container $container, Item $item, OpenNextOccurrence $openNextOccurrence): JsonResponse
    {
        Gate::authorize('update', $container);

        $schedule = new Schedule($request->validated());
        $schedule->item_id = $item->id;

        DB::transaction(function () use ($schedule, $openNextOccurrence): void {
            $schedule->save();

            if ($schedule->is_active) {
                $openNextOccurrence->handle($schedule);
            }
        });

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
     *
     * Ett PAUSAT schema som aktiveras (`is_active` falskt → sant) och saknar
     * en öppen förekomst öppnar en — i samma transaktion som aktiveringen
     * (issue 22 § Beslut 3). Att pausa rör ALDRIG den öppna förekomsten:
     * raden ligger kvar, och en återaktivering skriver inte om historien. Ett
     * `recurrence_type: none` vars enda förekomst redan är stängd får ingen
     * ny vid återaktivering — engångsuppgiften är slut.
     */
    public function update(UpdateScheduleRequest $request, Container $container, Item $item, Schedule $schedule, OpenNextOccurrence $openNextOccurrence): ScheduleResource
    {
        Gate::authorize('update', $container);

        $wasActive = $schedule->is_active;

        $schedule->fill($request->validated());

        if ($schedule->recurrence_type === 'none') {
            $schedule->interval_unit = null;
            $schedule->interval_count = null;
        }

        $reactivated = $schedule->is_active && ! $wasActive;

        DB::transaction(function () use ($schedule, $openNextOccurrence, $reactivated): void {
            $schedule->save();

            if ($reactivated && ! $schedule->openOccurrence()->exists()) {
                $openNextOccurrence->handle($schedule);
            }
        });

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
