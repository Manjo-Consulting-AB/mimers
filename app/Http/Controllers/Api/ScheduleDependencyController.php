<?php

namespace App\Http\Controllers\Api;

use App\Actions\Schedule\DependSchedule;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\StoreScheduleDependencyRequest;
use App\Http\Resources\ScheduleDependencyResource;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleDependency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * API-ytan för beroenden mellan scheman, se issue 23. De tre rutterna är
 * nästlade under `{schedule}` i routes/api.php — `{schedule}` är alltid den
 * BEROENDE sidan, den som väntar (issue 23 § Beslut 3). `{container}`,
 * `{item}` och `{schedule}` binds av gruppens scopeBindings() (containern
 * genom App\Models\Container::items(), itemet och schemat genom
 * App\Models\Item::schedules()); `{other}` binds INTE — motparten slås upp
 * inom containern i destroy(), precis som ItemLinkController gör (issue 14 §
 * Beslut 7, se issue 23 § Beslut 3).
 *
 * INGEN behörighetslogik bor här — varje metod anropar bara
 * `Gate::authorize()` mot de BEFINTLIGA grindarna `view` (GET) och `update`
 * (POST, DELETE) på App\Policies\ContainerPolicy (§ Beslut 3). Ingen ny
 * policymetod.
 *
 * Domänreglerna (samma container, inte sig själv, ingen cykel) ligger i
 * App\Actions\Schedule\DependSchedule, aldrig här — [[ADR-0024 Tunna
 * controllers och actions]]. Raderingen bär ingen regel och skrivs rakt i
 * kontrollern (§ Beslut 7 och 11).
 */
class ScheduleDependencyController extends Controller
{
    /**
     * GET /api/containers/{container}/items/{item}/schedules/{schedule}/dependencies
     * — 200. Det här schemats beroenden: vad det väntar på. Inte vad som
     * väntar på det; den frågan har ingen användare ställt (issue 23 § Beslut
     * 3). Sorterat på motpartens `title` stigande, ingen paginering (§ Beslut
     * 8).
     *
     * Uppslaget är tre frågor oavsett antal rader: beroenderaderna, motparternas
     * scheman och motparternas items — aldrig en fråga per rad (§ Beslut 8).
     * Ett mjukraderat schema filtreras bort av SoftDeletes globala scope i
     * schema-frågan, så dess beroenden döljs medan raden ligger kvar i
     * tabellen (§ Beslut 7).
     */
    public function index(Container $container, Item $item, Schedule $schedule): JsonResponse
    {
        Gate::authorize('view', $container);

        $rows = ScheduleDependency::query()
            ->where('schedule_id', $schedule->id)
            ->get();

        $schedulesById = Schedule::query()
            ->whereIn('id', $rows->pluck('depends_on_schedule_id')->unique()->values()->all())
            ->get(['id', 'ulid', 'title', 'item_id'])
            ->keyBy('id');

        $itemsById = Item::query()
            ->whereIn('id', $schedulesById->pluck('item_id')->unique()->values()->all())
            ->get(['id', 'ulid', 'name'])
            ->keyBy('id');

        $visible = $rows->filter(function (ScheduleDependency $row) use ($schedulesById, $itemsById): bool {
            $counterpart = $schedulesById->get($row->depends_on_schedule_id);

            return $counterpart !== null && $itemsById->has($counterpart->item_id);
        });

        $visible->each(function (ScheduleDependency $row) use ($schedulesById, $itemsById): void {
            $counterpart = $schedulesById->get($row->depends_on_schedule_id);
            $counterpartItem = $itemsById->get($counterpart->item_id);

            $row->setAttribute('counterpart_ulid', $counterpart->ulid);
            $row->setAttribute('counterpart_title', $counterpart->title);
            $row->setAttribute('counterpart_item_ulid', $counterpartItem->ulid);
            $row->setAttribute('counterpart_item_name', $counterpartItem->name);
        });

        return ScheduleDependencyResource::collection($visible->sortBy('counterpart_title')->values())->response();
    }

    /**
     * POST /api/containers/{container}/items/{item}/schedules/{schedule}/dependencies
     * — 201. Motparten har redan bevisats finnas i containern och inte vara
     * mjukraderad av StoreScheduleDependencyRequest (§ Beslut 4) — här slås
     * den bara upp. Alla regler (samma container, inte sig själv, ingen cykel)
     * ligger i App\Actions\Schedule\DependSchedule, inte här (§ Beslut 5).
     *
     * 201-svaret är samma form som listningen: motparten med sitt item (§
     * Beslut 8).
     */
    public function store(StoreScheduleDependencyRequest $request, Container $container, Item $item, Schedule $schedule, DependSchedule $dependSchedule): JsonResponse
    {
        Gate::authorize('update', $container);

        // `validated('depends_on')` är fortfarande klientens ULID —
        // StoreScheduleDependencyRequest lämnar fältets värde orört och låter
        // Rule::exists mot schedule.ulid göra existens- och containerkontrollen.
        $other = Schedule::query()
            ->whereHas('item', fn ($query) => $query->where('container_id', $container->id))
            ->where('ulid', $request->validated('depends_on'))
            ->with('item:id,ulid,name')
            ->firstOrFail();

        $dependency = $dependSchedule->handle($schedule, $other);

        $this->attachCounterpart($dependency, $other);

        return (new ScheduleDependencyResource($dependency))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * DELETE /api/containers/{container}/items/{item}/schedules/{schedule}/dependencies/{other}
     * — 204, ingen kropp. Hård radering (§ Beslut 7): scheman finns kvar, det
     * som går förlorat är regeln. `{other}` binds inte av scopeBindings() utan
     * slås upp här, inom containern (§ Beslut 3).
     *
     * Finns motparten inte i containern, eller finns ingen beroenderad mellan
     * paret: 404 `resource.not_found` — i destroy() är det uppslagets fel och
     * svaret är 404, till skillnad från store() där valideringen svarar 422
     * (§ Att se upp med).
     */
    public function destroy(Container $container, Item $item, Schedule $schedule, string $other): Response
    {
        Gate::authorize('update', $container);

        // `{other}` är motpartens ULID och binds INTE av scopeBindings() —
        // uppslaget inom containern här är hela skyddet mot ett schema i en
        // annan container (§ Beslut 3). Missar det: 404 `resource.not_found`
        // via ModelNotFoundException, samma mappning som rutt-bindningen.
        $otherSchedule = Schedule::query()
            ->whereHas('item', fn ($query) => $query->where('container_id', $container->id))
            ->where('ulid', $other)
            ->firstOrFail();

        $dependency = ScheduleDependency::query()
            ->where('schedule_id', $schedule->id)
            ->where('depends_on_schedule_id', $otherSchedule->id)
            ->first();

        if ($dependency === null) {
            throw ApiException::make('resource.not_found', [], 404);
        }

        $dependency->delete();

        return response()->noContent();
    }

    /**
     * Sätter de `counterpart_*`-attribut ScheduleDependencyResource läser —
     * motpartens ULID/titel och motpartens item. Ska matcha listningens
     * attribut exakt så 201-svaret har samma form som GET (§ Beslut 8).
     */
    private function attachCounterpart(ScheduleDependency $dependency, Schedule $other): void
    {
        $dependency->setAttribute('counterpart_ulid', $other->ulid);
        $dependency->setAttribute('counterpart_title', $other->title);
        $dependency->setAttribute('counterpart_item_ulid', $other->item->ulid);
        $dependency->setAttribute('counterpart_item_name', $other->item->name);
    }
}
