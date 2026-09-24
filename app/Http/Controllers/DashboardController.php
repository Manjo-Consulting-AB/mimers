<?php

namespace App\Http\Controllers;

use App\Actions\Container\ListContainerSummaries;
use App\Actions\Schedule\ListTodo;
use Illuminate\Http\Request;
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
     * GET /dashboard — 200. Panelerna. Uppgiftspanelen visar de fem första
     * raderna ur todo-urvalet, brickorna och korten räknar samma urval.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $todo = app(ListTodo::class)->handle($user, $request);
        $summaries = app(ListContainerSummaries::class)->handle($user, $todo);

        return Inertia::render('Dashboard', [
            'tasks' => array_slice($todo['rows'], 0, self::TASK_LIMIT),
            'hasContainers' => $todo['hasContainers'],
            'stats' => $summaries['stats'],
            'containerGroups' => $summaries['groups'],
        ]);
    }
}
