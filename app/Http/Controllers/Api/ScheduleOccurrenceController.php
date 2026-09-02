<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScheduleOccurrenceResource;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Förekomsterna av ett schema, se issue 22. Bara `index()` — ingen show(),
 * ingen POST, ingen DELETE: en förekomst skapas ALDRIG av en klient, den är
 * systemets bokföring av ett schema och den enda vägen in är
 * App\Actions\Schedule\OpenNextOccurrence (issue 22 § Beslut 1).
 *
 * routes/api.php nästlar `{item}` under `{container}` och `{schedule}` under
 * `{item}` med gruppens `->scopeBindings()`, precis som schemarutterna i
 * issue 21 — `{schedule}` löses genom App\Models\Item::schedules(). Ett
 * schema på ett annat item ger 404, hela skyddet mot en främmande ULID
 * (issue 22 § Beslut 1). Grinden är den befintliga `view` på
 * App\Policies\ContainerPolicy, ingen ny policymetod.
 */
class ScheduleOccurrenceController extends Controller
{
    /**
     * GET /api/containers/{container}/items/{item}/schedules/{schedule}
     * /occurrences — 200. Listan bär BÅDE den öppna förekomsten och
     * historiken — historiken ÄR loggen över utförda jobb, ingen separat
     * historiktabell finns ([[Scheman och uppgifter]] § schedule_occurrence).
     * Sorterad `due_at` fallande med `id` fallande som andrasortering, så två
     * förekomster med samma datum ändå får en stabil ordning: det som är
     * aktuellt eller senast gjort står först (Beslut 1). Ingen paginering
     * (issue 15a § Beslut 8).
     *
     * `completedByAccount` laddas eager eftersom ScheduleOccurrenceResource
     * läser kontots ULID och namn därifrån — listningen gör ett konstant
     * antal frågor oavsett antalet förekomster, aldrig en fråga per rad.
     */
    public function index(Container $container, Item $item, Schedule $schedule): JsonResponse
    {
        Gate::authorize('view', $container);

        $occurrences = $schedule->occurrences()
            ->with('completedByAccount')
            ->orderByDesc('due_at')
            ->orderByDesc('id')
            ->get();

        return ScheduleOccurrenceResource::collection($occurrences)->response();
    }
}
