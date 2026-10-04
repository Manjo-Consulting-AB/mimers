<?php

namespace App\Http\Controllers\Api;

use App\Actions\Schedule\ListTodo;
use App\Http\Controllers\Controller;
use App\Http\Resources\TodoEntryResource;
use App\Models\ScheduleOccurrence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Todo-listan, issue 24 — "vad ska jag göra?", produktens andra huvudfråga
 * vid sidan av "var la jag den där?". Precis som fritextsökningen (issue 15b)
 * är den en TOPPNIVÅrutt, `GET /api/todo`: frågan är global per definition —
 * alla öppna förekomster över ALLA containers användaren har åtkomst till —
 * inte inom en container hon redan valt (Beslut 1).
 *
 * Issuens tyngdpunkt: listan läser förekomster över containergränser och bär
 * sitt eget åtkomstfilter i stället för rutt-nästlingens grind. En glömd
 * `where` ger inget fel, inget larm och ett svar som ser rätt ut, bara med
 * rader ur andras containers. Filtret är därför utbrutet till
 * App\Models\ScheduleOccurrence::scopeTodoFor() (Beslut 2 och 3) — som
 * formulerar åtkomstvillkoret på Container-modellen genom relationskedjan
 * förekomst → schema → item → container, aldrig som en
 * `whereIn('container_id', ...)`-lista mot löpnummer (issue 15b § Att se upp
 * med).
 */
class TodoController extends Controller
{
    /**
     * GET /api/todo — 200. Svarar med SAMMA förekomster som `/tasks` (M24 ·
     * issue 701): de öppna förekomster användaren når, från att de skapas —
     * `visible_from` filtrerar inte längre (Beslut 1) — och bara försenat plus
     * i dag när växeln `show_upcoming_tasks` är av (Beslut 2). Blockerade
     * förekomster och förekomster i containrar $user inte når står utanför,
     * genom `scopeTodoFor()`. Sorterade `due_at` stigande med `ulid` stigande
     * som andra nyckel — en deterministisk ordning som inte byter mellan två
     * laddningar (Beslut 6). Ingen paginering (Beslut 7). Bara GET: listan är
     * en vy; allt som ändrar en uppgift går genom 22b:s rutter.
     *
     * **Växeln läses på ETT ställe** (Beslut 2): samma `ListTodo::onlyCurrent()`
     * som `/tasks` och panelen frågar, och samma modellvillkor
     * `scopeDueTodayOrEarlier()`. Kontrollern formulerar inget eget `where` och
     * läser inte kolumnen själv — två läsningar av samma växel hade kunnat
     * glida isär, och den ena hade glömt villkoret.
     *
     * **Grupperna är radernas flaggor** (Beslut 3): svaret är den platta
     * listan, och `overdue` och `upcoming` räknas per rad mot `User::today()` i
     * `TodoEntryResource`. Ingen `groups`-nyckel och inga räknare (Beslut 4).
     *
     * Förekomsterna hämtas med ETT konstant antal frågor oavsett hur många
     * rader listan bär: schemat, itemet och containern laddas i förväg med
     * `with()`, aldrig per rad — en N+1 här är fyra frågor per uppgift i den
     * vy som öppnas oftast i hela produkten (Beslut 8).
     */
    public function index(Request $request, ListTodo $listTodo): JsonResponse
    {
        $user = $request->user();
        $accountIds = $user->accounts->pluck('id')->values()->all();

        $occurrences = ScheduleOccurrence::query()
            ->todoFor($user, $accountIds)
            ->when($listTodo->onlyCurrent($user), fn (Builder $query) => $query->dueTodayOrEarlier($user))
            ->with(['schedule.item.container'])
            ->orderBy('due_at')
            ->orderBy('ulid')
            ->get();

        return TodoEntryResource::collection($occurrences)->response();
    }
}
