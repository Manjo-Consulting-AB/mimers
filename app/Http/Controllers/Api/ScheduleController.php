<?php

namespace App\Http\Controllers\Api;

use App\Actions\Schedule\CreateSchedule;
use App\Actions\Schedule\DeleteSchedule;
use App\Actions\Schedule\UpdateSchedule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\StoreScheduleRequest;
use App\Http\Requests\Schedule\UpdateScheduleRequest;
use App\Http\Resources\ScheduleResource;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * CRUD-ytan för schema, se issue 21. INGEN behörighetslogik bor här — varje
 * metod anropar bara `Gate::authorize()` mot ITEMETS egna grindar på
 * App\Policies\ItemPolicy sedan issue 71 (andra halvan): `view` (listning),
 * `create` (POST), `update` (PATCH) och `delete` (DELETE). Laddern avgör, se
 * [[ADR-0028 Åtkomst på itemnivå]] § Beslut och issue 71 § Beslut 1 och 5.
 * Ingen ny policymetod, ingen `SchedulePolicy` — schemat följer itemet.
 *
 * Fram till dess var grinden containerns `view`/`update`: en
 * omfångsbegränsad mottagare kunde läsa vilket schema som helst i containern men
 * inte skapa ett på sitt eget item, och en `write`-mottagare kunde radera ett
 * schema. `Container $container` står kvar i signaturerna för att
 * ImplicitRouteBinding löser barnbindningen mot den redan lösta föräldern,
 * samma skäl som Api\AttachmentControllers docblock skriver ut.
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
     *
     * Grinden är itemets `view` (issue 71 § Beslut 1).
     */
    public function index(Container $container, Item $item): JsonResponse
    {
        Gate::authorize('view', $item);

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
     *
     * Grinden är itemets `create` (issue 71 § Beslut 1 och 5): att lägga
     * till ett schema är att lägga till, och en `create`-mottagare får göra
     * det på sitt item utan att för den skull få ändra det som redan står
     * där.
     */
    public function store(StoreScheduleRequest $request, Container $container, Item $item, CreateSchedule $createSchedule): JsonResponse
    {
        Gate::authorize('create', $item);

        $schedule = $createSchedule->handle($item, $request->user(), new Schedule($request->validated()));

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
     *
     * Grinden är itemets `update` (issue 71 § Beslut 1 och 5).
     */
    public function update(UpdateScheduleRequest $request, Container $container, Item $item, Schedule $schedule, UpdateSchedule $updateSchedule): ScheduleResource
    {
        Gate::authorize('update', $item);

        $schedule->fill($request->validated());

        if ($schedule->recurrence_type === 'none') {
            $schedule->interval_unit = null;
            $schedule->interval_count = null;
        }

        $updateSchedule->handle($schedule, $request->user());

        return new ScheduleResource($schedule);
    }

    /**
     * DELETE /api/containers/{container}/items/{item}/schedules/{schedule} —
     * 204, ingen kropp. Mjuk radering (SoftDeletes): `deleted_at` sätts och
     * raden ligger kvar. Schemat hamnar INTE i papperskorgen — den listar
     * fyra typer och behåller fyra, se issue 21 § Beslut 9. Ett raderat
     * schema går alltså inte att ta tillbaka via API:et i MVP; ett medvetet
     * glapp, inte ett förbiseende.
     *
     * Grinden är itemets `delete` (issue 71 § Beslut 1 och 5): `write` ändrar
     * ett schema men tar inte bort det.
     */
    public function destroy(Request $request, Container $container, Item $item, Schedule $schedule, DeleteSchedule $deleteSchedule): Response
    {
        Gate::authorize('delete', $item);

        $deleteSchedule->handle($schedule, $request->user());

        return response()->noContent();
    }
}
