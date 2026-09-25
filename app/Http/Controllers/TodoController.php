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
     * Raden förfaller framåt i tiden.
     */
    public const GROUP_UPCOMING = ListTodo::GROUP_UPCOMING;

    /**
     * GET /tasks — 200. En sida av de öppna förekomsterna användaren når, i
     * `due_at`-ordning, grupperade i försenat, idag och kommande.
     *
     * Länkarna till nästa och föregående sida byggs här och inte i vyn:
     * adressen och parameternamnen hör till rutten, och vyn ska bara rita den
     * href den fick. `null` betyder att sidan är den första eller den sista.
     */
    public function index(Request $request): Response
    {
        $todo = app(ListTodo::class)->page($request->user(), $request);

        return Inertia::render('Tasks/Index', [
            'groups' => $todo['groups'],
            'hasContainers' => $todo['hasContainers'],
            // Växelns läge, så att komponenten kan rita sitt eget tillstånd
            // (issue 134). Servern är den enda som vet vad som sparats, och
            // `PUT /settings/tasks` svarar `back()` — sidan ritas om ur det
            // här värdet och aldrig ur ett klienttillstånd.
            'showUpcomingTasks' => $request->user()->show_upcoming_tasks,
            'previousUrl' => $todo['previous'] === null
                ? null
                : route('tasks', [ListTodo::CURSOR_BEFORE => $todo['previous']], false),
            'nextUrl' => $todo['next'] === null
                ? null
                : route('tasks', [ListTodo::CURSOR_AFTER => $todo['next']], false),
        ]);
    }
}
