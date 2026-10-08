<?php

namespace App\Http\Controllers;

use App\Actions\Schedule\ListTodo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Todo-vyn — "vad ska jag göra?" — `GET /tasks`, issue 64, issue 122 och
 * issue 123.
 *
 * **Sidan är produktens andra huvudfråga** vid sidan av "var la jag den där?",
 * och den öppnas oftare än någon annan i M10. Den låg på `/dashboard` fram
 * till issue 122: startsidan efter inloggning är nu dashboarden, och todo-vyn
 * har fått sin egen adress och sitt eget ruttnamn, `tasks`. Sidnamnet följde
 * med — `Tasks/Index.vue` — för sidnamnet är kontraktet mot
 * `import.meta.glob` över `pages/` (issue 51).
 *
 * **Allt som var genomtänkt i vyn flyttade med oförändrat** (issue 122):
 * urvalet, grupperingen på servern, `can.update` per rad, kontoförvalet och
 * den konstanta frågekostnaden. Det bor nu i App\Actions\Schedule\ListTodo,
 * för dashboardens uppgiftspanel läser samma urval och två sidor som
 * formulerade samma fråga var förr eller senare två sanningar om den.
 *
 * **Kontrollern väljer sida och översätter markörer till adresser** (issue
 * 123) — ingenting annat. Den formulerar inget `where`, räknar ingen grupp och
 * klipper ingen lista: den ber actionen om en sida, och länkarna byggs här
 * därför att adressen och parameternamnen hör till rutten och inte till vyn.
 * Sidan är därför högst
 * `ListTodo::PER_PAGE` rader, och vilka rader det är bestäms av `before` och
 * `after` i querysträngen — namnen kommer ur actionens konstanter, så den som
 * läser dem och den som skriver dem inte kan glida ifrån varandra.
 *
 * **Växelns läge följer med som en propp** (issue 134). Sidan ritar
 * resources/js/components/UpcomingTasksToggle.vue och behöver veta vad som
 * är sparat; urvalet självt formulerar kontrollern inte — det gör
 * App\Actions\Schedule\ListTodo ur användarens flagga. Proppen är läget och
 * ingenting annat: växeln skriver till `PUT /settings/tasks` och svarar
 * `back()`, så nästa sidladdning bär det nya värdet.
 *
 * **Filtren går samma väg som markören** (M28 · issue 782): kontrollern läser
 * dem inte, den lägger dem bara tillbaka i nästa sidas adress — normaliserade
 * av actionen, så att den som bygger en länk aldrig tolkar ett värde en gång
 * till. Se `queryString()`.
 *
 * Gruppkonstanterna står kvar här som alias mot actionens: de är nycklarna i
 * `lang/en/ui.php` och prövas mot `TodoController::GROUP_*` i
 * tests/Feature/Frontend/TodovyTest.php.
 */
class TodoController extends Controller
{
    /**
     * Raden är försenad — `due_at` ligger före serverns idag.
     */
    public const GROUP_OVERDUE = ListTodo::GROUP_OVERDUE;

    /**
     * Raden förfaller idag.
     */
    public const GROUP_TODAY = ListTodo::GROUP_TODAY;

    /**
     * Raden förfaller senare i veckan — efter i dag och senast på söndag.
     */
    public const GROUP_THIS_WEEK = ListTodo::GROUP_THIS_WEEK;

    /**
     * Raden förfaller framåt i tiden, bortom innevarande vecka.
     */
    public const GROUP_UPCOMING = ListTodo::GROUP_UPCOMING;

    /**
     * Raden har inget datum — sista gruppen bland de öppna (ADR-0052 § 3).
     */
    public const GROUP_NO_DATE = ListTodo::GROUP_NO_DATE;

    /**
     * GET /tasks — 200. En sida av de öppna förekomsterna användaren når, i
     * `due_at`-ordning, grupperade i försenat, idag, denna vecka och kommande.
     *
     * Länkarna till nästa och föregående sida byggs här och inte i vyn:
     * adressen och parameternamnen hör till rutten, och vyn ska bara rita den
     * href den fick. `null` betyder att sidan är den första eller den sista.
     */
    public function index(Request $request): Response
    {
        $listTodo = app(ListTodo::class);
        $user = $request->user();

        // Kontrollern LÄSER `list` och skickar den vidare (Beslut 2): den
        // tolkar inte värdet, räknar ingen grupp och formulerar inget
        // `where`. Ett okänt värde blir *Active* inne i actionen.
        $list = $request->query('list');

        $todo = $listTodo->page($user, $request, is_string($list) ? $list : null);

        // Markörens adresser bär listan OCH filtren (Beslut 2 och M28 · issue
        // 782, Beslut 1): båda står i adressen bredvid markören, så en
        // bläddring stannar i samma flik med samma filter. Det som inte
        // tillämpades — *Active*, ett okänt värde, filtret fliken redan
        // bestämmer — står som `null` i `filters` och lämnas utanför: en tom
        // parameter är brus, och en adress som säger något den inte gör är
        // värre än en kort.
        $query = $this->queryString($todo);

        // Panelens tal räknas EN gång (granskningsfynd på issue 783):
        // översikten bär samma *Done* som *Lists*-panelen, och `groupCounts()`
        // får den färdiga siffran i stället för att ställa samma fråga igen.
        $counts = $listTodo->gtdCounts($user);

        return Inertia::render('Tasks/Index', [
            'groups' => $todo['groups'],
            // *Done* är egen väg (Beslut 1): grupperna är tomma och raderna
            // ligger här. Vyn ritar det ena eller det andra ur `list`.
            'completed' => $todo['completed'],
            // Filterraden (M28 · issue 782, Beslut 5): filtren som de
            // tillämpades, och menyens containrar. Vyn tolkar inget värde —
            // den ritar de val den fick och låter servern äga urvalet.
            'filters' => $todo['filters'],
            'containers' => $todo['containers'],
            // Panelens tal (Beslut 3) — alla användarens containrar.
            'counts' => $counts,
            // Högerspalten (M28 · issue 783): översiktens tal per datumgrupp
            // (Beslut 1) och containerkortets tal per container (Beslut 2).
            // Ingen av dem känner filtren eller fliken (Beslut 3).
            'overview' => $listTodo->groupCounts($user, $counts[ListTodo::LIST_DONE]),
            // Containerkortets rader bär sin adress färdig, som panelens
            // övriga rader: `/tasks?container=<ulid>` (Beslut 2) byggs här och
            // inte i vyn — parameternamnet är actionens konstant, och adressen
            // hör till rutten. Vyn ritar bara den href den fick.
            'myContainers' => array_map(
                fn (array $rad): array => [
                    ...$rad,
                    'href' => route('tasks', [ListTodo::FILTER_CONTAINER => $rad['ulid']], false),
                ],
                $listTodo->containerCounts($user),
            ),
            // Fliken som är vald, ur `?list=` — vyn tänder sin flik ur den.
            'list' => $todo['list'],
            'hasContainers' => $todo['hasContainers'],
            // Växelns läge, så att komponenten kan rita sitt eget tillstånd
            // (issue 134). Servern är den enda som vet vad som sparats, och
            // `PUT /settings/tasks` svarar `back()` — sidan ritas om ur det
            // här värdet och aldrig ur ett klienttillstånd.
            'showUpcomingTasks' => $user->show_upcoming_tasks,
            'previousUrl' => $todo['previous'] === null
                ? null
                : route('tasks', [...$query, ListTodo::CURSOR_BEFORE => $todo['previous']], false),
            'nextUrl' => $todo['next'] === null
                ? null
                : route('tasks', [...$query, ListTodo::CURSOR_AFTER => $todo['next']], false),
        ]);
    }

    /**
     * Flikens och filternas parametrar, som de ska stå i en adress (M28 ·
     * issue 782, Beslut 1 och 5).
     *
     * **Bara det som gäller skrivs ut.** *Active* är frånvaron av `list`, ett
     * filter som inte tillämpades är `null` i `filters`, och `due_asc` är
     * sorteringens förval — ingen av dem bär något i adressen. Regeln är
     * `CostFilterBar`s och `Containers/Tasks.vue`s: en URL utan brus går att
     * läsa och att dela.
     *
     * Parameternamnen kommer ur actionens konstanter, så den som skriver
     * adressen och den som läser den inte kan glida ifrån varandra.
     *
     * @param  array{
     *     list: string|null,
     *     filters: array{container: string|null, gtd: string|null, status: string|null, sort: string}
     * }  $todo
     * @return array<string, string>
     */
    private function queryString(array $todo): array
    {
        $query = $todo['list'] === null ? [] : ['list' => $todo['list']];

        foreach ([ListTodo::FILTER_CONTAINER, ListTodo::FILTER_GTD, ListTodo::FILTER_STATUS] as $namn) {
            if ($todo['filters'][$namn] !== null) {
                $query[$namn] = $todo['filters'][$namn];
            }
        }

        if ($todo['filters'][ListTodo::FILTER_SORT] !== ListTodo::SORT_DUE_ASC) {
            $query[ListTodo::FILTER_SORT] = $todo['filters'][ListTodo::FILTER_SORT];
        }

        return $query;
    }
}
