<?php

namespace App\Http\Controllers;

use App\Actions\Schedule\ListTodo;
use App\Http\Resources\ContainerResource;
use App\Models\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Containerns uppgiftsflik — `GET /containers/{container}/tasks`, se issue 174 ·
 * [[ADR-0050 Desktopdesignen]] § 4 och 16.
 *
 * **Fliken är en lista med de grupper som redan finns.** *Försenade*, *Idag*,
 * *Denna vecka* och *Kommande* är `ListTodo`-grupperna från `/tasks` (Beslut 2)
 * och *Klart* är den nya frågan (Beslut 3). Bildens GTD-listor — Inbox, Next,
 * Waiting, Calendar, Someday — har ingen datakälla: de förutsätter status,
 * prioritet, kontext och tilldelning som `schedule_occurrence` inte har, och de
 * står i [[Efter MVP]] (ADR-0050 § 16).
 *
 * **Fliken släpper växeln `show_upcoming_tasks`** (M24 · issue 719, Beslut 2):
 * den skickar `onlyCurrent: false` till `forContainer()`, för **en container
 * beskriver containerns tillstånd, inte användarens filter**. Översiktens panel
 * och dashboarden skickar inget och följer växeln som förut.
 *
 * **Kontrollern väljer sida och ingenting annat** — samma regel som
 * App\Http\Controllers\TodoController och ContainerHistoryController följer.
 * Den formulerar inget `where`, räknar ingen grupp och klipper ingen lista:
 * urvalet är App\Actions\Schedule\ListTodo, och de fyra kolumnerna kommer
 * färdiga. Att filtrera i kontrollern hade varit den andra sanningen om vad
 * frågan är, och den hade glidit isär från `/tasks` ([[ADR-0024 Tunna
 * controllers och actions]]).
 *
 * **Grinden är `view` på containern** (Beslut 1). Fliken är en läsyta som
 * varje annan containerns flik, och en främling får 403 och inte en tom tavla
 * — en tom lista hade sagt "inget att göra" om en container hon inte når.
 * Grinden svarar på om hon når containern; VILKA rader hon sedan ser svarar
 * `ListTodo` på, genom samma `scopeTodoFor()` och samma item-omfång som
 * `/tasks` (issue 74 § Beslut 7): en gäst med en itemgrant ser sina items
 * uppgifter och inga andras.
 *
 * **Underhållsfiltret står i querysträngen** (Beslut 4): `?maintenance=1`
 * avgränsar alla grupperna till scheman som återkommer. Det är ett
 * filter på servern och inte i klienten — en klient som sållade hade visat
 * fel tavla för den som laddar om sidan, och ett filtrerat läge ska vara en
 * adress man kan spara och dela (issue 59a § Beslut 1). Flaggan läses som
 * `boolean()` och skickas vidare oförändrad; kontrollern tolkar den inte.
 *
 * **Snabblänkarna ritas ur samma policy som deras sidor prövar** (Beslut 5).
 * `CalendarFeedController::index()` och `ExportController::index()` grindas
 * båda på `ContainerPolicy::view`, och flaggorna nedan ställer samma fråga.
 * I dag sammanfaller de tre svaren — den som ser tavlan får öppna båda
 * ytorna — men de är TVÅ svar på TVÅ frågor och hålls åtskilda av samma skäl
 * som `ContainerPolicy::viewAccesses()` och `revokeAccess()` är två metoder
 * med samma kropp: den dag en av grindarna skärps ska länken följa med, och
 * en hårdkodad `<Link>` hade blivit en död länk utan ett larm.
 *
 * **`can.update` räknas med en policyfråga per sida och läggs BREDVID
 * `ContainerResource`**, precis som i ContainerController::show() och
 * ContainerHistoryController (issue 54 § Beslut 9, issue 170): flaggan ritar
 * hjältens *Redigera container*, och rutten `PATCH /containers/{container}`
 * prövar `update` på nytt.
 *
 * Rutten ligger bakom `auth` (routes/web.php) — en utloggad besökare skickas
 * till inloggningen och når aldrig den här metoden.
 */
class ContainerTaskController extends Controller
{
    /**
     * GET /containers/{container}/tasks — 200.
     *
     * Propparna kommer ur `ListTodo::forContainer()` (Beslut 2): `groups` är
     * de fyra öppna grupperna UTAN gräns, och `completed` är *Done* — tom på
     * *Active* och de lagrade listorna, för fliken har ersatt den gamla
     * gruppen (Beslut 4), och en sida i taget under `?list=done` (Beslut 1).
     * `counts` är panelens tal ur `ListTodo::gtdCounts()`, `list` är fliken,
     * `maintenance` filtrets läge så att vyn kan rita sin egen kontroll, och
     * `can` bär hjältens och snabblänkarnas flaggor.
     *
     * **De öppna grupperna pagineras inte.** Listan visar hela containerns
     * uppgifter: en container är en avgränsad mängd, och `/tasks` finns kvar
     * för den som vill se allt över alla containrar (Beslut 2). *Done* är
     * däremot en egen lista och pagineras över `(completed_at, ulid)`
     * (Beslut 1): den gamla glimten på tjugo är borta, och den som vill se
     * hela historiken går vidare till historikfliken (issue 179).
     */
    public function __invoke(Request $request, Container $container, ListTodo $listTodo): Response
    {
        Gate::authorize('view', $container);

        // Ägarkontot och bilden: skalets topprad ritar miniatyren ur `cover`,
        // som resursen läser genom `coverAttachment` (issue 159), och hjälten
        // detsamma över `md:`. Utan den här raden hade båda blivit oplanerade
        // lazy-loads per sidladdning — samma rad som de andra containertabbarna
        // gör (issue 170).
        $container->loadMissing(['account', 'coverAttachment.storedFile.derivatives']);

        $user = $request->user();

        $maintenance = $request->boolean('maintenance');

        // Kontrollern LÄSER `list` och skickar den vidare (Beslut 2): den
        // tolkar inte värdet och formulerar inget `where`. Ett okänt värde
        // blir *Active* inne i actionen.
        $list = $request->query('list');

        $todo = $listTodo->forContainer(
            $user,
            $request,
            $container,
            maintenanceOnly: $maintenance,
            onlyCurrent: false,
            list: is_string($list) ? $list : null,
        );

        // Samma policyfråga som CalendarFeedController och ExportController
        // ställer i sina index() — se klassens docblock för varför de två
        // flaggorna finns trots att de svarar likadant i dag.
        $canView = Gate::forUser($user)->allows('view', $container);

        // Markörens adresser bär listan och filtret (Beslut 2): en bläddring
        // i *Done* stannar i fliken och behåller underhållsfiltret. Är listan
        // *Active* och filtret av lämnas båda parametrarna utanför.
        $query = [];

        if ($todo['list'] !== null) {
            $query['list'] = $todo['list'];
        }

        if ($maintenance) {
            $query['maintenance'] = 1;
        }

        return Inertia::render('Containers/Tasks', [
            'container' => ContainerResource::make($container)->resolve($request),
            'can' => [
                'update' => Gate::forUser($user)->allows('update', $container),
                'calendar' => $canView,
                'export' => $canView,
            ],
            // De fyra öppna grupperna, i ritningsordning: försenat, idag,
            // denna vecka, kommande. Vyn itererar objektets nycklar som de
            // kommer och räknar aldrig en grupp själv (issue 64 § Beslut 3).
            // På *Done* är de tomma, och raderna ligger i `completed`.
            'groups' => $todo['groups'],
            // *Done* — avbockade förekomster i containern, nyast först, en
            // sida i taget (Beslut 1 och 4). På de övriga flikarna är listan
            // tom: den gamla *Klart*-gruppen sist på fliken är nu en egen
            // flik.
            'completed' => $todo['completed'],
            // Panelens tal (Beslut 3) — containerns.
            'counts' => $listTodo->gtdCounts($user, $container),
            // Fliken som är vald, ur `?list=` — vyn tänder sin flik ur den.
            'list' => $todo['list'],
            // Filtrets läge, så att kryssrutan ritar sitt eget tillstånd ur
            // serverns svar i stället för ur ett klienttillstånd som kan gå
            // isär från adressen efter en bakåtknapp.
            'maintenance' => $maintenance,
            'previousUrl' => $todo['previous'] === null
                ? null
                : route('containers.tasks', [$container, ...$query, ListTodo::CURSOR_BEFORE => $todo['previous']], false),
            'nextUrl' => $todo['next'] === null
                ? null
                : route('containers.tasks', [$container, ...$query, ListTodo::CURSOR_AFTER => $todo['next']], false),
        ]);
    }
}
