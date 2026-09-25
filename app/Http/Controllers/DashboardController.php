<?php

namespace App\Http\Controllers;

use App\Actions\Access\ResolveItemScope;
use App\Actions\Audit\ListAuditEvents;
use App\Actions\Audit\PresentAuditEvents;
use App\Actions\Container\ListContainerSummaries;
use App\Actions\Schedule\ListTodo;
use App\Models\Container;
use App\Models\User;
use App\Support\Cost\CostReport;
use App\Support\Tips;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboarden — startsidan efter inloggning, `GET /dashboard`, issue 122.
 *
 * **Sidan är ny, men rutten och namnet är gamla.** `/dashboard` var todo-vyn
 * (App\Http\Controllers\TodoController, issue 64) fram till issue 122, och
 * todo-vyn flyttade till `/tasks`. Adressen står kvar därför att ramverket
 * skickar en nyinloggad användare hit (Beslut 1 i 64): byter startsidan namn
 * måste varje omdirigering i inloggningen, registreringen och magic link följa
 * med, och en inloggad användare som bokmärkt adressen hade mötts av en 404.
 * Sidkomponenten heter fortfarande `Dashboard`, av samma skäl.
 *
 * **Panelen är sin egen komponent och sin egen propp.** M19 lägger brickorna
 * (124), kostnaderna (125), händelserna (126), klockan (127) och
 * informationsytan (128) på den här sidan, och de byggs parallellt: varje panel
 * får en egen komponent och en egen propp, så att två issues krockar om en rad
 * i den här filen och en rad i `Dashboard.vue` — inte om varandras paneler.
 *
 * **Uppgiftspanelen formulerar ingen egen fråga.** Den visar de fem första
 * raderna ur samma urval som `/tasks`, i samma ordning, och urvalet kommer ur
 * App\Actions\Schedule\ListTodo — samma anrop som todo-vyn gör. Klippningen
 * sker i PHP och inte i SQL: frågan är den samma, och den konstanta
 * frågekostnaden ärvdes med den (issue 64 § Beslut 2 och 8, issue 70
 * § Beslut 2). Ett eget `limit` i frågan hade varit en andra fråga om samma
 * rader — och en andra sanning om ordningen.
 *
 * **De två tomma lägena följer med** (issue 64 § Beslut 6). `hasContainers`
 * kommer ur samma anrop som listan, så panelen kan skilja "ingen container
 * alls" från "inget att göra" utan en egen räkning.
 *
 * **Brickorna och containerkorten kom med issue 124.** De är egna komponenter
 * med egna proppar, som panelen: `stats` bär de tre talen (containrar, öppna
 * uppgifter, försenade) och `containerGroups` bär korten grupperade på art.
 * Båda kommer ur App\Actions\Container\ListContainerSummaries, som får
 * `$todo` — todo-svaret som redan är hämtat — i stället för att ställa samma
 * fråga en gång till: uppgiftsbrickan ska visa antalet rader på `/tasks`, och
 * det finns bara ett sätt att vara säker på att den gör det.
 *
 * **Kostnaderna kom med issue 125**, som sin egen propp: `costs` bär
 * månadens totalsumma per valuta och nedbrytningen per container, och båda
 * kommer ur App\Support\Cost\CostReport::monthForContainers(). Månaden är
 * INNEVARANDE KALENDARMÅNAD och servern bestämmer den — sidan tar ingen
 * parameter, och en period i querysträngen är därför ett värde ingen läser.
 * En månad som går att välja vore en fråga, och en fråga är Pro
 * ([[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut); den parametriserade
 * rapporten ligger orörd i CostReportController.
 *
 * **Händelserna kom med issue 126**, som sin egen propp: `events` bär de
 * senaste raderna ur händelseloggen över ALLA användarens konton
 * ([[ADR-0043 Tre loggar]] § Konsekvenser). Läsregeln bor i
 * App\Actions\Audit\ListAuditEvents och anropas med panelens gräns —
 * kontrollern filtrerar ingenting själv, precis som historikflikarna låter
 * bli (issue 108 och 116). Formen kommer ur
 * App\Actions\Audit\PresentAuditEvents, som också slår upp containernamnen:
 * en rad på den här sidan står utanför sin container och måste säga vilken
 * den gäller.
 *
 * **Informationsytan kom med issue 128**, som sin egen propp: `tips` bär
 * nycklarna på de tips användaren inte kryssat bort, i App\Support\Tips
 * ordning — det första är det ytan visar, och resten är det hon kan bläddra
 * till. Kontrollern formulerar ingen egen lista och ingen egen fråga, precis
 * som den inte formulerar någon annan panels urval. Texten bor i
 * `lang/en/ui.php` och slås upp i klienten: servern skickar nycklar och
 * aldrig färdiga meningar ([[ADR-0021 Frontendteknik]] § Beslut).
 */
class DashboardController extends Controller
{
    /**
     * Antalet rader panelen visar.
     *
     * Fem enligt mockupen (`docs/Design/main.jpeg`) — och ett tak, inte en
     * förhoppning: en dashboard som växte med antalet uppgifter vore en andra
     * todo-vy, och den finns på `/tasks`.
     */
    public const TASK_LIMIT = 5;

    /**
     * Antalet rader händelsepanelen visar.
     *
     * Fem, samma tak som uppgiftspanelen och av samma skäl: panelen är en
     * glimt av loggen och inte loggen själv. Gränsen går in i anropet till
     * App\Actions\Audit\ListAuditEvents — läsregeln har inget eget femtal, och
     * en yta som vill se färre rader säger det i sin egen ände (issue 126).
     */
    public const ACTIVITY_LIMIT = 5;

    /**
     * GET /dashboard — 200. Panelerna. Uppgiftspanelen visar de fem första
     * raderna ur todo-urvalet, brickorna och korten räknar samma urval,
     * kostnaderna är innevarande kalendermånad, och händelsepanelen visar de
     * fem senaste läsbara loggraderna över användarens konton.
     */
    public function index(
        Request $request,
        CostReport $report,
        ListAuditEvents $listAuditEvents,
        PresentAuditEvents $presentAuditEvents,
    ): Response {
        $user = $request->user();

        $todo = app(ListTodo::class)->handle($user, $request);
        $summaries = app(ListContainerSummaries::class)->handle($user, $todo);

        return Inertia::render('Dashboard', [
            'tasks' => array_slice($todo['rows'], 0, self::TASK_LIMIT),
            'hasContainers' => $todo['hasContainers'],
            'stats' => $summaries['stats'],
            'containerGroups' => $summaries['groups'],
            'costs' => $this->monthCosts($user, $report),
            // Läsregeln, med panelens gräns: de fem senaste av det användaren
            // får läsa. ListAuditEvents svarar på vilka rader det är och
            // PresentAuditEvents på hur de ser ut — kontrollern gör inget av
            // det själv.
            'events' => $presentAuditEvents->handle(
                $listAuditEvents->forUser($user, self::ACTIVITY_LIMIT),
            ),
            // Tipsen användaren inte dolt, i Tips ordning — samma propp och
            // samma lista som containerns översikt bär (issue 128).
            'tips' => app(Tips::class)->visibleFor($user),
        ]);
    }

    /**
     * Månadens kostnader över användarens containrar, som `{totals,
     * breakdown}` — underlaget för den tredje brickan och donuten.
     *
     * **Containrarna är samma urval som containerlistan**, `Container::
     * scopeAccessibleBy()`, formulerat på ett ställe och använt här — en
     * mottagare av en itemgrant ska se sin containers månad och ingenting
     * annat. Månaden räknas ur användarens tidszon (se `timezoneFor()`), och
     * servern är den enda som vet vad "innevarande" betyder: klientens klocka
     * får aldrig flytta en månadsgräns.
     *
     * **Omfånget löses upp på en FÄRSK instans, i ETT anrop.** Samma grepp
     * och samma skäl som i CostSummaryController: memon på den
     * `scoped`-bundna instansen finns för ItemPolicy, som frågar en gång per
     * rad i en listning, och för en summering skulle den bara göra
     * frågekostnaden beroende av vad samma PHP-process råkade ha löst upp
     * tidigare. `forContainers()` och inte en upplösning per container: en
     * fråga per container vore den N+1 som issue 70 § Beslut 2 stänger.
     *
     * Ingen plangrind: en fast summering är fri (ADR-0038).
     *
     * @return array{totals: list<array{currency: string, amount: int, count: int}>, breakdown: list<array{key: mixed, totals: list<array{currency: string, amount: int, count: int}>}>}
     */
    private function monthCosts(User $user, CostReport $report): array
    {
        $accountIds = $user->accounts->pluck('id')->all();

        $containerIds = Container::query()
            ->accessibleBy($user, $accountIds)
            ->pluck('id')
            ->all();

        $scopes = app()->build(ResolveItemScope::class)
            ->forContainers($user, $containerIds);

        return $report->monthForContainers($scopes, $this->currentMonth($user));
    }

    /**
     * Innevarande kalendermånad i användarens tidszon, som 'YYYY-MM'.
     *
     * `Carbon::now($timezone)` och inte `today()`: det är ögonblicket i
     * användarens tid som avgör vilken månad hon är i, och en användare i
     * Europe/Stockholm är i oktober redan när servern i UTC ännu är i
     * september. Se `timezoneFor()`.
     */
    private function currentMonth(User $user): string
    {
        return Carbon::now($this->timezoneFor($user))->format('Y-m');
    }

    /**
     * Användarens tidszon, med kontots som reserv och appens som sista
     * utväg — samma fallande ordning som `User::preferredLocale()` har för
     * språk och App\Support\Notification\QuietHours::timezoneFor() har för
     * den tysta timmen. `user.timezone` är nullable och `account.timezone`
     * är det inte.
     */
    private function timezoneFor(User $user): string
    {
        $timezone = $user->timezone;

        if ($timezone === null && $user->accounts->isNotEmpty()) {
            // first() är godtyckligt när användaren har flera konton — accepterat
            // här, en gissning är bättre än UTC (samma resonemang som
            // User::preferredLocale() och QuietHours::timezoneFor()).
            $timezone = $user->accounts->first()->timezone;
        }

        return $timezone ?? config('app.timezone');
    }
}
