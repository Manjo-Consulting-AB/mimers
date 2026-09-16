<?php

namespace App\Http\Controllers\Api;

use App\Actions\Schedule\CloseOccurrence;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\CompleteOccurrenceRequest;
use App\Http\Resources\ScheduleOccurrenceResource;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Förekomsterna av ett schema, se issue 22. Tre ytor: `index()` listar den
 * öppna förekomsten och historiken (22a), `complete()`/`skip()` stänger en
 * öppen förekomst och öppnar nästa i samma transaktion (22b). Ingen show(),
 * ingen allmän POST/DELETE: en förekomst skapas ALDRIG av en klient, den är
 * systemets bokföring av ett schema och den enda vägen in är
 * App\Actions\Schedule\OpenNextOccurrence (issue 22 § Beslut 1) — avslutet
 * delegerar till den genom App\Actions\Schedule\CloseOccurrence, som bär
 * hela flödet ([[ADR-0024 Tunna controllers och actions]]).
 *
 * routes/api.php nästlar `{item}` under `{container}` och `{schedule}` under
 * `{item}` med gruppens `->scopeBindings()`, precis som schemarutterna i
 * issue 21 — `{schedule}` löses genom App\Models\Item::schedules() och
 * `{occurrence}` genom App\Models\Schedule::occurrences(). Ett schema på ett
 * annat item, eller en förekomst i ett annat schema, ger 404 — hela skyddet
 * mot en främmande ULID (issue 22 § Beslut 1). Grinden är ITEMETS egen på
 * App\Policies\ItemPolicy sedan issue 71 (andra halvan): `view` (index)
 * respektive `update` (complete/skip), ingen ny policymetod (issue 22b
 * § Beslut 1). Schemat hör till `{item}` genom bindningen, så `$schedule->item`
 * är samma item — men grinden ställs mot det item schemat faktiskt hör till,
 * aldrig mot containern, se issue 71 § Beslut 1.
 *
 * `complete` och `skip` kräver `update`, inte `create`: att bocka av ändrar
 * en förekomst som redan finns (issue 71 § Beslut 5). Fram till dess krävdes
 * en container-bred grant, vilket stängde ute varje omfångsbegränsad
 * mottagare från att bocka av sin egen uppgift — och en `write`-mottagare
 * kunde det för att grinden delades med containerns `update`.
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
        Gate::authorize('view', $schedule->item);

        $occurrences = $schedule->occurrences()
            ->with('completedByAccount')
            ->orderByDesc('due_at')
            ->orderByDesc('id')
            ->get();

        return ScheduleOccurrenceResource::collection($occurrences)->response();
    }

    /**
     * POST /api/containers/{container}/items/{item}/schedules/{schedule}
     * /occurrences/{occurrence}/complete — 200. Den ÖPPNA förekomsten stängs
     * som `completed` och nästa öppnas i samma transaktion, se
     * App\Actions\Schedule\CloseOccurrence. Två rutter i stället för ett
     * statusfält (issue 22b § Beslut 1): en kropp med `{"status": "open"}`
     * vore en väg att återöppna, och det vill vi uttryckligen inte ha.
     */
    public function complete(CompleteOccurrenceRequest $request, Container $container, Item $item, Schedule $schedule, ScheduleOccurrence $occurrence, CloseOccurrence $closeOccurrence): JsonResponse
    {
        return $this->close($request, $container, $item, $schedule, $occurrence, $closeOccurrence, ScheduleOccurrence::STATUS_COMPLETED);
    }

    /**
     * POST /api/containers/{container}/items/{item}/schedules/{schedule}
     * /occurrences/{occurrence}/skip — 200. Samma avslutsflöde som complete(),
     * men med `status = 'skipped'` — och nästa `interval`-förfall räknas från
     * den överhoppade förekomstens `due_at`, inte från `completed_at`
     * (issue 22b § Beslut 4). `completion_note` är tillåten även här.
     */
    public function skip(CompleteOccurrenceRequest $request, Container $container, Item $item, Schedule $schedule, ScheduleOccurrence $occurrence, CloseOccurrence $closeOccurrence): JsonResponse
    {
        return $this->close($request, $container, $item, $schedule, $occurrence, $closeOccurrence, ScheduleOccurrence::STATUS_SKIPPED);
    }

    /**
     * Det complete() och skip() delar. Fyra steg, i den ordningen
     * ([[ADR-0024 Tunna controllers och actions]]): FormRequesten har
     * validerat, `Gate::authorize('update')` avgör behörighet, CloseOccurrence
     * utför hela flödet, och ScheduleOccurrenceResource formar svaret.
     *
     * `account` i kroppen är obligatorisk och måste vara ett konto användaren
     * är medlem i — annars 403 `auth.forbidden`, samma kontroll som
     * App\Http\Controllers\Api\ItemController::store() gör (issue 22b §
     * Beslut 2). Det är ingen containerregel utan ett "får användaren skriva
     * i det angivna kontots namn", och därför ligger den här och inte i
     * policyn.
     *
     * Svaret är 200 med den stängda och den nya förekomsten i varsin
     * ScheduleOccurrenceResource-form (issue 22b § Beslut 8). `next` är null
     * när `recurrence_type` är `none`, men nyckeln finns alltid.
     *
     * `cost_prompt` är kostnadskroken (issue 47): när förekomsten stängdes som
     * `completed` bär svaret ett erbjudande att registrera en kostnad på
     * itemet, med `incurred_on` förifyllt till `completed_at`:ets datum. Vid
     * `skip` är värdet null — nyckeln finns alltid, samma regel som `next`
     * (issue 47 § Beslut 2). Erbjudandet är bara en extra nyckel i svaret:
     * ingenting skrivs, ingen relation lagras mellan kostnaden och
     * förekomsten, och avslutsflödet i CloseOccurrence är orört. Itemet som
     * erbjudandet pekar på är rutten `{item}` — complete()/skip() har det
     * redan bundet, inget nytt uppslag (Beslut 4).
     */
    private function close(CompleteOccurrenceRequest $request, Container $container, Item $item, Schedule $schedule, ScheduleOccurrence $occurrence, CloseOccurrence $closeOccurrence, string $status): JsonResponse
    {
        Gate::authorize('update', $schedule->item);

        $account = Account::where('ulid', $request->validated('account'))->firstOrFail();

        if (! $account->users()->whereKey($request->user()->id)->exists()) {
            throw ApiException::make('auth.forbidden', [], 403);
        }

        $result = $closeOccurrence->handle(
            $schedule,
            $occurrence,
            $request->user(),
            $account,
            $status,
            $request->validated('completion_note'),
        );

        $closed = $result['closed'];
        $next = $result['next'];

        // Kontot är redan i handen och den nya förekomsten har inget konto —
        // sätt relationerna direkt så resursen aldrig gör ett oplanerat
        // lazy-load per rad (jfr index() ovan).
        $closed->setRelation('completedByAccount', $account);

        if ($next !== null) {
            $next->setRelation('completedByAccount', null);
        }

        // Kostnadskroken (issue 47 § Beslut 1): en KEY till i data, bredvid
        // closed och next. completed_at är en tidsstämpel och incurred_on en
        // DATE, så uttaget är $closed->completed_at->toDateString() — en dag,
        // aldrig en tidsstämpel (Beslut 3, jfr LoanResource).
        $costPrompt = $status === ScheduleOccurrence::STATUS_COMPLETED
            ? ['item' => $item->ulid, 'incurred_on' => $closed->completed_at->toDateString()]
            : null;

        return response()->json([
            'data' => [
                'closed' => new ScheduleOccurrenceResource($closed),
                'next' => $next === null ? null : new ScheduleOccurrenceResource($next),
                'cost_prompt' => $costPrompt,
            ],
        ]);
    }
}
