<?php

namespace App\Http\Controllers\Api;

use App\Actions\Schedule\DependOccurrence;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\StoreOccurrenceDependencyRequest;
use App\Http\Resources\OccurrenceDependencyResource;
use App\Models\Container;
use App\Models\Item;
use App\Models\OccurrenceDependency;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * API-ytan för beroenden mellan förekomster, se issue 23b. De tre rutterna är
 * nästlade under `{occurrence}` i routes/api.php — `{occurrence}` är alltid
 * den BEROENDE sidan, den som väntar (issue 23b § Beslut 3). `{container}`,
 * `{item}`, `{schedule}` och `{occurrence}` binds av gruppens scopeBindings()
 * (containern genom App\Models\Container::items(), itemet genom
 * App\Models\Item::schedules(), schemat genom App\Models\Schedule::occurrences());
 * `{other}` binds INTE — motparten slås upp inom containern i destroy(),
 * precis som ScheduleDependencyController och ItemLinkController gör (issue 23a
 * § Beslut 3, issue 14 § Beslut 7).
 *
 * INGEN behörighetslogik bor här — varje metod anropar bara
 * `Gate::authorize()` mot ITEMETS egna grindar på App\Policies\ItemPolicy
 * sedan issue 71 (andra halvan): `view` (GET) och `update` (POST, DELETE),
 * aldrig `delete` — ett beroende tar inte bort något av förekomsterna
 * (§ Beslut 3, issue 71 § Beslut 1 och 5). Ingen ny policymetod.
 *
 * BÅDA ändarna auktoriseras, `update` i var och en, också vid skapande —
 * exakt som App\Http\Controllers\Api\ItemLinkController gör sedan PR #283 och
 * av samma skäl: [[ADR-0028 Åtkomst på itemnivå]] § Beslut kräver `write` i
 * båda ändar för att ändra en kant. Itemet är `$occurrence->schedule->item`,
 * aldrig containern. Ordningen är den egna änden FÖRST, motpartens item efter
 * uppslaget, så att en mottagare som inte når den egna förekomsten får 403
 * innan hon får veta något om motparten. Motparten bevisas mot containern
 * redan i StoreOccurrenceDependencyRequest (422 på en okänd ULID) respektive
 * uppslaget i destroy() (404), så en känd men onåbar motpart ger 403 — aldrig
 * motpartens titel eller itemnamn i svaret.
 *
 * Fram till dess var grinden containerns `view`/`update`: en
 * omfångsbegränsad mottagare kunde läsa vilken förekomst som helst och knyta
 * beroenden till förekomster utanför sitt omfång.
 *
 * Domänreglerna (samma container, öppen väntande sida, inte sig själv, ingen
 * cykel) ligger i App\Actions\Schedule\DependOccurrence, aldrig här —
 * [[ADR-0024 Tunna controllers och actions]]. Raderingen bär ingen regel och
 * skrivs rakt i kontrollern.
 */
class OccurrenceDependencyController extends Controller
{
    /**
     * GET /api/containers/{container}/items/{item}/schedules/{schedule}
     * /occurrences/{occurrence}/dependencies — 200. Den här förekomstens
     * beroenden: vad den väntar på. Inte vad som väntar på den; den frågan
     * har ingen användare ställt (samma val som 23a § Beslut 3).
     *
     * Uppslaget är ett konstant antal frågor oavsett antal rader:
     * beroenderaderna, motparternas förekomster, motparternas scheman och
     * motparternas items — aldrig en fråga per rad (§ Beslut 8). En motpart
     * under ett mjukraderat schema eller item filtreras bort av SoftDeletes
     * globala scope i förekomst-/schemas-/item-frågorna, så dess beroenden
     * döljs medan raden ligger kvar i tabellen (samma resonemang som 23a §
     * Beslut 7).
     *
     * `satisfied` härleds ur motpartens status vid läsningen (§ Beslut 8) och
     * sorteringen är ouppfyllda först, därefter `due_at` stigande — den
     * ordning frågan "vad väntar jag på" ställs i (§ Beslut 8). Ingen
     * paginering.
     */
    public function index(Container $container, Item $item, Schedule $schedule, ScheduleOccurrence $occurrence): JsonResponse
    {
        Gate::authorize('view', $occurrence->schedule->item);

        $rows = OccurrenceDependency::query()
            ->where('occurrence_id', $occurrence->id)
            ->get();

        $counterpartsById = ScheduleOccurrence::query()
            ->whereIn('id', $rows->pluck('depends_on_occurrence_id')->unique()->values()->all())
            ->with(['schedule:id,title,item_id', 'schedule.item:id,ulid,name'])
            ->get()
            ->keyBy('id');

        $visible = $rows->filter(function (OccurrenceDependency $row) use ($counterpartsById): bool {
            $counterpart = $counterpartsById->get($row->depends_on_occurrence_id);

            return $counterpart !== null
                && $counterpart->schedule !== null
                && $counterpart->schedule->item !== null;
        });

        $visible->each(function (OccurrenceDependency $row) use ($counterpartsById): void {
            $counterpart = $counterpartsById->get($row->depends_on_occurrence_id);
            $schedule = $counterpart->schedule;
            $item = $schedule->item;

            $row->setAttribute('counterpart_ulid', $counterpart->ulid);
            $row->setAttribute('counterpart_title', $schedule->title);
            $row->setAttribute('counterpart_due_at', $counterpart->due_at->toDateString());
            $row->setAttribute('counterpart_status', $counterpart->status);
            $row->setAttribute('counterpart_item_ulid', $item->ulid);
            $row->setAttribute('counterpart_item_name', $item->name);
            $row->setAttribute('satisfied', $counterpart->status !== ScheduleOccurrence::STATUS_OPEN);
        });

        $ordered = $visible->sortBy(fn (OccurrenceDependency $row): array => [
            $row->getAttribute('satisfied') ? 1 : 0,
            $row->getAttribute('counterpart_due_at'),
        ])->values();

        return OccurrenceDependencyResource::collection($ordered)->response();
    }

    /**
     * POST /api/containers/{container}/items/{item}/schedules/{schedule}
     * /occurrences/{occurrence}/dependencies — 201. Motparten har redan
     * bevisats finnas i containern och inte ligga under ett mjukraderat schema
     * av StoreOccurrenceDependencyRequest (§ Beslut 7) — här slås den bara
     * upp. Alla regler (samma container, öppen väntande sida, inte sig själv,
     * ingen cykel) ligger i App\Actions\Schedule\DependOccurrence, inte här.
     *
     * 201-svaret är samma form som listningen (§ Beslut 8).
     */
    public function store(StoreOccurrenceDependencyRequest $request, Container $container, Item $item, Schedule $schedule, ScheduleOccurrence $occurrence, DependOccurrence $dependOccurrence): JsonResponse
    {
        Gate::authorize('update', $occurrence->schedule->item);

        // `validated('depends_on')` är fortfarande klientens ULID —
        // StoreOccurrenceDependencyRequest lämnar fältets värde orört och låter
        // Rule::exists mot schedule_occurrence.ulid göra existens- och
        // containerkontrollen. `container_id` står i kolumnlistan för att
        // ItemPolicy läser itemets container; utan den är relationen tom och
        // grinden kastar.
        $other = ScheduleOccurrence::query()
            ->where('ulid', $request->validated('depends_on'))
            ->whereHas('schedule.item', fn ($query) => $query->where('container_id', $container->id))
            ->with(['schedule:id,title,item_id', 'schedule.item:id,ulid,name,container_id'])
            ->firstOrFail();

        // Andra änden, efter uppslaget (issue 71 § Beslut 1 och 3).
        Gate::authorize('update', $other->schedule->item);

        $dependency = $dependOccurrence->handle($occurrence, $other);

        $this->attachCounterpart($dependency, $other);

        return (new OccurrenceDependencyResource($dependency))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * DELETE /api/containers/{container}/items/{item}/schedules/{schedule}
     * /occurrences/{occurrence}/dependencies/{other} — 204, ingen kropp. Hård
     * radering: förekomsterna finns kvar, det som går förlorat är regeln.
     * Raden som raderas är historik (§ Beslut 5); bara en framtida öppning
     * av samma par skapar en ny. `{other}` binds inte av scopeBindings() utan
     * slås upp här, inom containern (§ Beslut 3).
     *
     * Finns motparten inte i containern, eller finns ingen beroenderad mellan
     * paret: 404 `resource.not_found` — i destroy() är det uppslagets fel och
     * svaret är 404, till skillnad från store() där valideringen svarar 422.
     * Ordningen 403 före 404 är oförändrad sedan grindbytet: en ULID ur en
     * annan container ger fortfarande 404 (issue 71 § Beslut 4).
     *
     * Båda ändarna kräver `update`, precis som i store() (issue 71 § Beslut 1
     * och 3). En motpart inom containern men utanför omfånget ger 403 utan att
     * svaret röjer dess titel eller itemnamn.
     */
    public function destroy(Container $container, Item $item, Schedule $schedule, ScheduleOccurrence $occurrence, string $other): Response
    {
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
            throw ApiException::make('resource.not_found', [], 404);
        }

        $dependency->delete();

        return response()->noContent();
    }

    /**
     * Sätter de `counterpart_*`-attribut OccurrenceDependencyResource läser —
     * motpartens ULID/titel/status/förfallodatum, motpartens item och den
     * härledda `satisfied`. Ska matcha listningens attribut exakt så
     * 201-svaret har samma form som GET (§ Beslut 8).
     */
    private function attachCounterpart(OccurrenceDependency $dependency, ScheduleOccurrence $other): void
    {
        $dependency->setAttribute('counterpart_ulid', $other->ulid);
        $dependency->setAttribute('counterpart_title', $other->schedule->title);
        $dependency->setAttribute('counterpart_due_at', $other->due_at->toDateString());
        $dependency->setAttribute('counterpart_status', $other->status);
        $dependency->setAttribute('counterpart_item_ulid', $other->schedule->item->ulid);
        $dependency->setAttribute('counterpart_item_name', $other->schedule->item->name);
        $dependency->setAttribute('satisfied', $other->status !== ScheduleOccurrence::STATUS_OPEN);
    }
}
