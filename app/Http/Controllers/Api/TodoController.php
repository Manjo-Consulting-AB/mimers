<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TodoEntryResource;
use App\Models\ScheduleOccurrence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Todo-listan, issue 24 — "vad ska jag göra?", produktens andra huvudfråga
 * vid sidan av "var la jag den där?". Precis som fritextsökningen (issue 15b)
 * är den en TOPPNIVÅrutt, `GET /api/todo`: frågan är global per definition —
 * alla öppna förekomster över ALLA containers användaren har åtkomst till —
 * inte inom en pärm hon redan valt (Beslut 1).
 *
 * Issuens tyngdpunkt: listan läser förekomster över containergränser och bär
 * sitt eget åtkomstfilter i stället för rutt-nästlingens grind. En glömd
 * `where` ger inget fel, inget larm och ett svar som ser rätt ut, bara med
 * rader ur andras pärmar. Filtret är därför utbrutet till
 * App\Models\ScheduleOccurrence::scopeTodoFor() (Beslut 2 och 3) — som
 * formulerar åtkomstvillkoret på Container-modellen genom relationskedjan
 * förekomst → schema → item → container, aldrig som en
 * `whereIn('container_id', ...)`-lista mot löpnummer (issue 15b § Att se upp
 * med).
 */
class TodoController extends Controller
{
    /**
     * GET /api/todo — 200. Svarar med de öppna förekomster som syns idag,
     * inte är blockerade och ligger i en container $user når, sorterade
     * `due_at` stigande med `ulid` stigande som andra nyckel — en
     * deterministisk ordning som inte byter mellan två laddningar (Beslut 6).
     * Ingen paginering (Beslut 7). Bara GET: listan är en vy; allt som ändrar
     * en uppgift går genom 22b:s rutter.
     *
     * Förekomsterna hämtas med ETT konstant antal frågor oavsett hur många
     * rader listan bär: schemat, itemet och containern laddas i förväg med
     * `with()`, aldrig per rad — en N+1 här är fyra frågor per uppgift i den
     * vy som öppnas oftast i hela produkten (Beslut 8).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $accountIds = $user->accounts->pluck('id')->values()->all();

        $occurrences = ScheduleOccurrence::query()
            ->todoFor($user, $accountIds)
            ->with(['schedule.item.container'])
            ->orderBy('due_at')
            ->orderBy('ulid')
            ->get();

        return TodoEntryResource::collection($occurrences)->response();
    }
}
