<?php

namespace App\Http\Controllers;

use App\Actions\Schedule\CloseOccurrence;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Schedule\CompleteOccurrenceRequest;
use App\Http\Resources\ContainerResource;
use App\Http\Resources\ScheduleOccurrenceResource;
use App\Http\Resources\ScheduleResource;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webbens förekomstytor — den enskilda gången, se issue 63b § Beslut 1–9.
 *
 * **Schemat är regeln och förekomsten den enskilda gången** ([[ADR-0005
 * Schema och förekomst]]). 63a byggde regeln (App\Http\Controllers\
 * ScheduleController). Den här kontrollern svarar på det som händer en gång:
 * den öppna förekomsten, avbockningen, överhoppningen och historiken.
 *
 * **`CloseOccurrence` rörs inte** (Beslut 1 och issuens omfång). Den äger
 * transaktionen: spärren mot öppna beroenden, stängningen, beräkningen av
 * nästa `due_at` och avbrottet av oskickade notiser. Den här kontrollern
 * anropar den — den skriver inte om en rad av flödet, och den räknar aldrig
 * ett förfallodatum själv. Att `interval` räknar från `completed_at` och
 * `fixed` från kalendern är Actionens regel (issue 22b § Beslut 4) och
 * prövas på `/api`; webben ärver den genom att göra exakt samma anrop.
 *
 * **Ingen ny FormRequest och ingen återöppning.** `CompleteOccurrenceRequest`
 * delas rakt av — `account` krävs, `completion_note` är valfri. Det finns
 * ingen rutt som sätter en förekomst tillbaka till `open`, med flit
 * (issue 22b § Beslut 1): en `{"status":"open"}`-kropp vore just den vägen.
 *
 * **Grinden är ITEMETS `update`** (Beslut 1, issue 71 § Beslut 5): att bocka
 * av ändrar en förekomst som redan finns. En `read`-mottagare ser historiken
 * och den öppna förekomsten men ingen knapp, och får 403 om hon postar ändå;
 * en `create`-mottagare nekas av samma grind.
 *
 * **Efter en avbockning laddas sidan om från servern** (Beslut 8). Den nya
 * förekomsten skapas i samma transaktion som den gamla stängs, och dess
 * `due_at` beräknas av Actionen — vyn kan inte gissa det talet och försöker
 * inte: svaret är `back()` med en flash-kod, och listan ritas ur serverns
 * svar.
 */
class ScheduleOccurrenceController extends Controller
{
    /**
     * GET /containers/{container}/items/{item}/schedules/{schedule} — schemats
     * sida: regeln, den öppna förekomsten och historiken (Beslut 1).
     *
     * **Historiken ÄR loggen** ([[Scheman och uppgifter]] §
     * schedule_occurrence): svaret på "när bytte jag impellern senast" är de
     * avklarade förekomsterna själva, inte en historiktabell. Sidan visar
     * därför samma lista som `Api\ScheduleOccurrenceController::index()` —
     * `due_at` fallande med `id` fallande, ingen paginering och ingen egen
     * sortering (Beslut 7).
     *
     * `completedByAccount` laddas eager precis som i `/api` (Beslut 9):
     * listan gör ett konstant antal frågor oavsett antalet förekomster,
     * aldrig en fråga per rad. Det är samma resonemang som gör att
     * 63a § Beslut 9 laddade `openOccurrence`.
     *
     * **`overdue` kommer från servern** (Beslut 3). Vyn läser det härledda
     * fältet ur App\Http\Resources\ScheduleOccurrenceResource och jämför
     * aldrig `due_at` mot klientens klocka — [[ADR-0005 Schema och förekomst]]
     * :s regel, att ett tillstånd klockan ändrar aldrig lagras, gäller lika
     * mycket för en `computed` i en komponent som för en kolumn.
     */
    public function show(Request $request, Container $container, Item $item, Schedule $schedule): Response
    {
        Gate::authorize('view', $schedule->item);

        $container->loadMissing('account');

        $occurrences = $schedule->occurrences()
            ->with('completedByAccount')
            ->orderByDesc('due_at')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Containers/Items/Schedules/Show', [
            'container' => ContainerResource::make($container)->resolve($request),
            'item' => ['ulid' => $item->ulid, 'name' => $item->name],
            'schedule' => (new ScheduleResource($schedule))->resolve($request),
            'occurrences' => ScheduleOccurrenceResource::collection($occurrences)->resolve($request),
            'can' => [
                'update' => Gate::forUser($request->user())->allows('update', $schedule->item),
            ],
        ]);
    }

    /**
     * POST /containers/{container}/items/{item}/schedules/{schedule}
     * /occurrences/{occurrence}/complete — 302 tillbaka.
     *
     * `{occurrence}` binds av rutternas `scopeBindings()` genom
     * App\Models\Schedule::occurrences(): en förekomst i ett annat schema ger
     * 404, och därmed också en ULID ur en annan pärm. Det är samma skydd som
     * `/api` har (issue 22 § Beslut 1) och det enda som behövs — rutten bär
     * hela kedjan container → item → schema → förekomst.
     */
    public function complete(
        CompleteOccurrenceRequest $request,
        Container $container,
        Item $item,
        Schedule $schedule,
        ScheduleOccurrence $occurrence,
        CloseOccurrence $closeOccurrence,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        return $this->close($request, $schedule, $occurrence, $closeOccurrence, $translator, ScheduleOccurrence::STATUS_COMPLETED);
    }

    /**
     * POST /containers/{container}/items/{item}/schedules/{schedule}
     * /occurrences/{occurrence}/skip — 302 tillbaka.
     *
     * Samma avslutsflöde som `complete()`, men `skip` stänger förekomsten
     * UTAN att påstå att jobbet gjordes (Beslut 5). `CloseOccurrence` prövar
     * samma spärr — en uppgift som inte får göras än får inte heller hoppas
     * över — och räknar nästa `interval`-förfall ur den överhoppade
     * förekomstens `due_at` i stället för ur `completed_at` (issue 22b
     * § Beslut 4).
     *
     * Skillnaden mot `complete` syns i historiken, och den syns i flashkoden:
     * en avklarad rad och en överhoppad rad är två olika saker, för
     * historiken är loggen.
     */
    public function skip(
        CompleteOccurrenceRequest $request,
        Container $container,
        Item $item,
        Schedule $schedule,
        ScheduleOccurrence $occurrence,
        CloseOccurrence $closeOccurrence,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        return $this->close($request, $schedule, $occurrence, $closeOccurrence, $translator, ScheduleOccurrence::STATUS_SKIPPED);
    }

    /**
     * Det `complete()` och `skip()` delar. Ordningen är `/api`:s
     * (App\Http\Controllers\Api\ScheduleOccurrenceController::close()):
     * FormRequesten har validerat, grinden avgör behörighet,
     * `CloseOccurrence` utför flödet.
     *
     * **`account` måste vara ett konto användaren är medlem i** — samma
     * prövning som `/api` gör (issue 22b § Beslut 2), och därför samma svar:
     * 403. Det är ingen containerregel utan ett "får användaren skriva i det
     * angivna kontots namn", och den ligger därför här och inte i policyn.
     * Attributionen är varvet, inte den anställde
     * ([[Scheman och uppgifter]] § schedule_occurrence).
     *
     * **Ett domänfel blir ett formulärfel, aldrig en JSON-kropp.** Att
     * `CloseOccurrence` kastar App\Exceptions\Api\ApiException märks inte på
     * `/api` — den svarar `{"error":{"code":…}}` var den än kastas — men mitt
     * i en webbsida hade användaren fått rå JSON. App\Support\Frontend\
     * ApiErrorTranslator formulerar meningen ur `lang/`, samma mönster som
     * issue 54 § Beslut 4, ItemLinkController och AttachmentController.
     *
     * **`occurrence.blocked` går INTE genom översättaren** (Beslut 6). Den
     * bär `data.blocked_by` som en LISTA av `{ulid, title, due_at}`, och
     * `ApiErrorTranslator::message()` skickar `data` rakt in i `trans()` som
     * ersättningar — en array som ersättning är i bästa fall en varning och i
     * sämsta ett undantag mitt i felhanteringen. Se `occurrenceMessage()`.
     */
    private function close(
        CompleteOccurrenceRequest $request,
        Schedule $schedule,
        ScheduleOccurrence $occurrence,
        CloseOccurrence $closeOccurrence,
        ApiErrorTranslator $translator,
        string $status,
    ): RedirectResponse {
        Gate::authorize('update', $schedule->item);

        $account = Account::where('ulid', $request->validated('account'))->firstOrFail();

        if (! $account->users()->whereKey($request->user()->id)->exists()) {
            abort(403);
        }

        try {
            $closeOccurrence->handle(
                $schedule,
                $occurrence,
                $request->user(),
                $account,
                $status,
                $request->validated('completion_note'),
            );
        } catch (ApiException $e) {
            throw ValidationException::withMessages([
                'occurrence' => $this->occurrenceMessage($e, $translator),
            ]);
        }

        return back()->with('status', $status === ScheduleOccurrence::STATUS_COMPLETED
            ? 'occurrence-completed'
            : 'occurrence-skipped');
    }

    /**
     * Meningen för ett domänfel ur avslutsflödet (Beslut 6).
     *
     * De tre andra koderna — `occurrence.not_open` och `schedule.inactive` —
     * går rakt genom App\Support\Frontend\ApiErrorTranslator. `not_open` bär
     * `data.status` för en API-klient som vill skilja `completed` från
     * `skipped`; meningen säger ändå bara att förekomsten redan är avslutad,
     * för det är samma svar till användaren hur den stängdes.
     *
     * **`occurrence.blocked` formuleras här i stället.** `data.blocked_by` är
     * en lista, och översättarens `trans($key, $data)` hade fått en array som
     * ersättning. Meningen blir därför en ledande rad ur `lang/` plus en rad
     * per blockerare, med titel och datum — och listan är HELA listan, inte
     * den första (issue 23b § Beslut 4): annars bockar användaren av en, får
     * samma fel igen och lär sig att systemet ljuger om vad som återstår.
     *
     * Raderna fogas ihop till EN sträng med radbrytningar, för felpåsen bär
     * en sträng per nyckel och `flash` delar bara `status`
     * (App\Http\Middleware\HandleInertiaRequests). Vyn renderar den med
     * `whitespace-pre-line`, så varje blockerare står på sin egen rad.
     *
     * Datumet formateras på servern och inte i vyn — raden är redan en färdig
     * mening när den når klienten. `->locale(App::getLocale())` sätts
     * uttryckligen: Carbon ärver inte Laravels locale av sig själv, och en
     * svensk sida med engelska månadsnamn är precis den sortens glidning
     * språkregeln finns för att undvika.
     */
    private function occurrenceMessage(ApiException $exception, ApiErrorTranslator $translator): string
    {
        if ($exception->errorCode() !== 'occurrence.blocked') {
            return $translator->message($exception);
        }

        $lines = [(string) trans('ui.error.occurrence.blocked')];

        foreach ($exception->data()['blocked_by'] as $blocker) {
            $lines[] = (string) trans('ui.error.occurrence.blocked_row', [
                'title' => $blocker['title'],
                'date' => Carbon::parse($blocker['due_at'])->locale(App::getLocale())->translatedFormat('j F Y'),
            ]);
        }

        return implode("\n", $lines);
    }
}
