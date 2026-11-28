<?php

namespace App\Actions\Dashboard;

use App\Actions\Access\ResolveItemScope;
use App\Actions\Container\ListContainerSummaries;
use App\Actions\Schedule\ListTodo;
use App\Models\Container;
use App\Models\User;
use App\Support\Transaction\CostReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Talen dashboardens hjälte ritar — `{stats, costs}` — se issue 437 ·
 * [[ADR-0090 Dashboardens hjälte]] § Tillägg 2026-11-22.
 *
 * **Beräkningen bor här och inte i kontrollern.** Hjälten ritas på alla
 * toppnivåsidor, och en rad i varje kontroller hade varit elva ställen att
 * glömma. Dashboarden använder actionen och behåller sina proppar `stats` och
 * `costs`; de andra sidorna får svaret som den valfria proppen `heroFigures`
 * ur App\Http\Middleware\HandleInertiaRequests, så att talen bara räknas när
 * en sida faktiskt visar dem.
 *
 * **Talen är desamma som i dag, utan en andra väg.** `ListTodo` anropas med
 * `onlyCurrent: true` — försenat plus i dag, oavsett växeln (issue 697) — och
 * `ListContainerSummaries` räknar samma urval. Ingen ny fråga formuleras här;
 * actionen flyttar bara det DashboardController gjorde före issue 437.
 *
 * **Månaden är innevarande kalendermånad i användarens tidszon.** Servern
 * bestämmer den ([[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut): en
 * period i querysträngen är ett värde ingen läser, och klientens klocka får
 * aldrig flytta en månadsgräns. Containrarna är samma urval som
 * containerlistan, `Container::scopeAccessibleBy()`, och omfånget löses upp i
 * ETT anrop — en fråga per container vore den N+1 som issue 70 § Beslut 2
 * stänger.
 */
final class HeroFigures
{
    public function __construct(
        private readonly CostReport $report,
        private readonly ListContainerSummaries $listContainerSummaries,
        private readonly ListTodo $listTodo,
    ) {}

    /**
     * Talen, ur en sammanfattning anroparen redan hämtat — eller ur en egen
     * fråga när den inte har någon.
     *
     * **Dashboarden skickar in sin sammanfattning**: korten på sidan behöver
     * `groups` ur samma svar, och en andra fråga här hade varit samma
     * containerlista två gånger. De andra sidorna har ingen sammanfattning och
     * låter actionen hämta den.
     *
     * @param  array{stats: array{containers: int, tasks: int, overdue: int}, groups: list<array<string, mixed>>}|null  $summaries
     * @return array{
     *     stats: array{containers: int, tasks: int, overdue: int},
     *     costs: array{totals: list<array{currency: string, amount: int, count: int}>}
     * }
     */
    public function handle(User $user, Request $request, ?array $summaries = null): array
    {
        $summaries ??= $this->listContainerSummaries->handle(
            $user,
            $this->listTodo->handle($user, $request, onlyCurrent: true),
        );

        return [
            'stats' => $summaries['stats'],
            'costs' => ['totals' => $this->monthCosts($user)],
        ];
    }

    /**
     * Månadens kostnader för EN container, som containerns hjälte ritar
     * (issue 475).
     *
     * **Samma väg som `monthCosts()` ovan, med ett annat urval.** Månaden
     * kommer ur `currentMonth()` — användarens lokala kalendermånad — och
     * raderna ur `CostReport::monthForContainers()`, aldrig ur en egen `SUM`
     * eller en egen månadsgräns. Omfånget löses upp för containern med samma
     * `ResolveItemScope::forContainers()` som dashboarden använder, så
     * mottagarens omfång gäller: en rad på ett item hon inte når räknas inte.
     *
     * **Ingen egen grind.** Anroparen har redan prövat `view` på containern —
     * se HandleInertiaRequests::containerMonthCosts(); metoden räknar bara.
     * Den är publik eftersom den delade proppen byggs i middlewaren och inte
     * i den här klassen.
     *
     * @return list<array{currency: string, amount: int, count: int}>
     */
    public function containerMonthCosts(User $user, Container $container): array
    {
        $scopes = app()->build(ResolveItemScope::class)
            ->forContainers($user, [$container->getKey()]);

        return $this->report->monthForContainers($scopes, $this->currentMonth($user));
    }

    /**
     * Månadens kostnader över användarens containrar, som `totals`.
     *
     * Sedan issue 474 bär svaret bara totalerna: hjälten ritar en rad per
     * valuta och ingen nedbrytning, och nedbrytningen ska därför inte räknas
     * för en yta som inte visar den ([[ADR-0090 Dashboardens hjälte]]
     * § Tillägg 2026-11-24).
     *
     * @return list<array{currency: string, amount: int, count: int}>
     */
    private function monthCosts(User $user): array
    {
        $accountIds = $user->accounts->pluck('id')->all();

        $containerIds = Container::query()
            ->accessibleBy($user, $accountIds)
            ->listable()
            ->pluck('id')
            ->all();

        $scopes = app()->build(ResolveItemScope::class)
            ->forContainers($user, $containerIds);

        return $this->report->monthForContainers($scopes, $this->currentMonth($user));
    }

    /**
     * Innevarande kalendermånad i användarens tidszon, som 'YYYY-MM'.
     *
     * `Carbon::now($timezone)` och inte `today()`: det är ögonblicket i
     * användarens tid som avgör vilken månad hon är i. Tidszonen kommer ur
     * `User::preferredTimezone()` — regeln *användarens tidszon, annars
     * kontots, annars appens* bor på ett ställe (issue 135).
     */
    private function currentMonth(User $user): string
    {
        return Carbon::now($user->preferredTimezone())->format('Y-m');
    }
}
