<?php

namespace App\Http\Controllers;

use App\Actions\Access\ResolveItemScope;
use App\Actions\Cost\ListContainerCosts;
use App\Http\Resources\ContainerResource;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use App\Support\Access\ItemScope;
use App\Support\Cost\CostReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Containerns kostnadsflik — `GET /containers/{container}/costs`, se issue 175 ·
 * [[ADR-0050 Desktopdesignen]] § 9.
 *
 * **Fliken är den fria delen av kostnaderna, och gränsen är [[ADR-0038
 * Gränsen för Pro i kostnaderna]]s.** Fri: raderna i en tabell, brickorna
 * *Totalt* och *I år*, och donuten per item. Pro, och därmed issue 176:
 * periodväljaren, filtren, grafen över tid, nedbrytningen per kategori och
 * jämförelsen mot en annan period. Sidan tar därför INGEN parameter: en
 * period i querysträngen är ett värde ingen läser, precis som på dashboarden
 * (issue 125).
 *
 * **Grinden är `view` på containern** (Beslut 1), som varje annan av
 * containerns flikar (ContainerHistoryController, ContainerTaskController):
 * en främling får 403 och inte en tom tabell — en tom lista hade sagt "inga
 * kostnader" om en container hon inte når. Grinden svarar på om hon når
 * containern; VILKA rader hon sedan ser svarar App\Actions\Cost\
 * ListContainerCosts på, och vilka tal brickorna och donuten räknar svarar
 * App\Support\Cost\CostReport på — båda genom samma `ResolveItemScope` som
 * `Api\CostEntryController::suppliers()` och CostReportController använder.
 *
 * **Kontrollern räknar ingenting själv** ([[ADR-0024 Tunna controllers och
 * actions]]). Den löser upp omfånget EN gång, på en FÄRSK instans — samma
 * grepp och samma skäl som ContainerController::show() och
 * CostSummaryController: memon på den `scoped`-bundna instansen finns för
 * ItemPolicy, som frågar en gång per rad; för en summering skulle den bara
 * göra frågekostnaden beroende av vad processen råkade lösa upp tidigare.
 *
 * **`canReport` är Pro-grinden, och den läses på CONTAINERNS konto**
 * (Beslut 4) — `$container->account->planLimit('cost_reports')`, exakt samma
 * läsning som `Entitlements::assertFeature()` och CostReportController gör:
 * rättigheten hör till containern och inte till den som tittar, och en
 * inloggad gäst ska inte kunna läsa rapportvyn genom att hennes eget konto
 * råkar ha Pro. Flaggan är presentation — den ritar uppgraderingsytan där
 * Pro-delen kommer att stå. Ändpunkten `GET /containers/{container}/costs`
 * tar inga parametrar, och därför finns här ingen grind att kringgå: en fast
 * summering är fri, och den parametriserade rapporten ligger oförändrad i
 * CostReportController.
 *
 * **Året räknas ur användarens tidszon och är ändå ingen fråga.** Brickan
 * *I år* avgränsar till innevarande kalenderår, räknat med
 * `User::preferredTimezone()` (issue 135) — en användare i Europe/Stockholm
 * är i det nya året redan när servern i UTC ännu är i det gamla. Det är
 * samma regel och samma metod som DashboardController::currentMonth()
 * använder för månaden, och av samma skäl: den fasta perioden är användarens,
 * men den går inte att BYTA, och därför ingen fråga ([[ADR-0038 Gränsen för
 * Pro i kostnaderna]] § Beslut).
 *
 * **`canUpgrade` är svaret på om planvyn går att öppna för den här
 * användaren.** Uppgraderingsytan länkar dit, och en länk ingen får följa är
 * en död länk — samma regel som snabblänkarna på uppgiftsfliken (issue 174
 * § Beslut 5). Flaggan ställer därför SAMMA fråga som
 * App\Http\Controllers\Settings\PlanController::index() ställer: `viewStorage`
 * på containerns konto, alltså MEDLEMSKAP i containerns konto
 * ([[Konton och åtkomst]]). `AccountPolicy::viewStorage()` frågar sin privata
 * `isMember()` och skiljer inte på ägare, admin och vanlig medlem — en vanlig
 * medlem når plansidan lika väl som ägaren, och flaggan är därför ingen
 * ägarkontroll. En gäst med en itemgrant är inte medlem och får
 * ingen länk; hennes egen plansida hade visat hennes egna konton och inte det
 * hon tittar i.
 *
 * **`items` är de items användaren får SKAPA på**, och det är hela
 * itemväljaren bakom *Lägg till kostnad* (Beslut 1): knappen leder inte till
 * ett formulär här utan till itemets kostnadsflik `?tab=costs` (issue 168),
 * och en kostnad hör till ett item. Grinden är `ItemPolicy::create()` per
 * item — samma grind som `POST …/items/{item}/costs` prövar (issue 71
 * § Beslut 1: en kostnadsrad är ny information som läggs till itemet) — och
 * listan är tom när ingen item får användas. En menyrad man inte får följa
 * ritas inte alls (App\Support\Frontend\CreateTarget § docblock), och vyn
 * ritar därför ingen knapp för en tom lista.
 *
 * `container.account` eager-loadas på listan: `ItemPolicy::allows()` läser
 * `$item->container->account->status` för regel 4, och utan den raden hade
 * varje item kostat ett uppslag — policyns eget docblock säger det.
 *
 * **`can.update` räknas med en policyfråga per sida och läggs BREDVID
 * `ContainerResource`**, precis som i ContainerController::show() och de
 * andra flikarna (issue 54 § Beslut 9, issue 170): flaggan ritar hjältens
 * *Redigera container* — hjälten är `compact` här (issue 170) — och rutten
 * `PATCH /containers/{container}` prövar `update` på nytt.
 */
class ContainerCostController extends Controller
{
    /**
     * GET /containers/{container}/costs — 200.
     */
    public function __invoke(
        Request $request,
        Container $container,
        CostReport $report,
        ListContainerCosts $listContainerCosts,
    ): Response {
        Gate::authorize('view', $container);

        // Ägarkontot och bilden: skalets topprad ritar miniatyren ur `cover`,
        // som resursen läser genom `coverAttachment` (issue 159), och hjälten
        // detsamma över `md:`. Utan den här raden hade båda blivit oplanerade
        // lazy-loads per sidladdning — samma rad som de andra flikarna gör.
        $container->loadMissing(['account', 'coverAttachment.storedFile.derivatives']);

        $user = $request->user();

        $scope = app()->build(ResolveItemScope::class)->handle($user, $container);

        // Året räknas EN gång och används två: som spannet i frågan och som
        // ordet i brickans etikett. Vyn får det som propp och ritar aldrig sin
        // egen klocka — klientens klocka är inte användarens tidszon.
        $year = $this->currentYear($user);

        return Inertia::render('Containers/Costs', [
            'container' => ContainerResource::make($container)->resolve($request),
            'can' => [
                'update' => Gate::forUser($user)->allows('update', $container),
            ],
            // Den fasta summeringen: HELA containern, utan period. Donuten
            // ritas ur samma svar — bitarna är de items som bär raderna
            // ([[ADR-0040 Underträdets summor]]).
            'costs' => $report->summary($container, $scope),
            // *I år*: samma radmängd, avgränsad till användarens kalenderår.
            'yearCosts' => $report->yearForContainer($container, $scope, $year)['totals'],
            // Årtalet bakom *I år*, så att brickan kan säga vilket år den
            // räknar — och så att vyn inte räknar fram det ur sin egen klocka.
            'year' => (int) $year,
            // Rader i en sida om tjugofem, nyast först. Paginatorn går rakt
            // igenom: sidnumret kommer ur `?page=` och räknas av ramverket,
            // och vyn ritar bara de länkar den får.
            'rows' => $listContainerCosts->handle($user, $container),
            'items' => $this->creatableItems($user, $container, $scope),
            'canReport' => $container->account->planLimit('cost_reports') !== false,
            'canUpgrade' => Gate::forUser($user)->allows('viewStorage', $container->account),
        ]);
    }

    /**
     * Innevarande kalenderår i användarens tidszon, som 'YYYY'.
     *
     * `Carbon::now($timezone)` och inte `today()`: det är ögonblicket i
     * användarens tid som avgör vilket år hon är i, och en användare i
     * America/New_York är kvar i det gamla året när servern i UTC redan
     * skrivit januari. Samma metod och samma skäl som månaden i
     * DashboardController::currentMonth() — tidszonen kommer ur
     * `User::preferredTimezone()` och inte ur en privat kopia här (issue 135).
     */
    private function currentYear(User $user): string
    {
        return Carbon::now($user->preferredTimezone())->format('Y');
    }

    /**
     * De items användaren får skapa en kostnadsrad på, sorterade på namn.
     *
     * Omfånget är `Item::scopeInScope()` — samma formulering som
     * App\Actions\Item\ListItems använder, och inte en egen variant av "vilka
     * items når hon". Sedan prövas `ItemPolicy::create()` per item, som är
     * samma grind `POST …/items/{item}/costs` prövar: en `read`-mottagare ser
     * containerns kostnader men får ingen itemväljare.
     *
     * @return list<array{ulid: string, name: string}>
     */
    private function creatableItems(User $user, Container $container, ItemScope $scope): array
    {
        $items = $container->items()
            ->inScope($scope)
            // Regel 4 i ItemPolicy läser ägarkontots status per item; utan
            // den här raden blir varje rad ett uppslag.
            ->with('container.account')
            ->orderBy('name')
            ->get();

        $gate = Gate::forUser($user);

        return $items
            ->filter(fn (Item $item): bool => $gate->allows('create', $item))
            ->map(fn (Item $item): array => ['ulid' => $item->ulid, 'name' => $item->name])
            ->values()
            ->all();
    }
}
