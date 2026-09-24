<?php

namespace App\Actions\Container;

use App\Actions\Access\ResolveItemScope;
use App\Actions\Schedule\ListTodo;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Dashboardens containerkort och dess två brickor, se issue 124 ·
 * [[ADR-0039 Containerns översikt]] och [[ADR-0036 Containerns art]].
 *
 * **Varje tal räknar det användaren SJÄLV når.** Kortets itembricka är
 * omfånget i den containern — en mottagare av en itemgrant ser antalet items
 * inom sitt omfång och aldrig containerns alla ([[ADR-0028 Åtkomst på
 * itemnivå]] § Konsekvenser, issue 73 § Beslut 6). Ingen totalsumma, ingen
 * *av N*, ingen rad om att något dolts: ett sådant tal är precis vad omfånget
 * stänger ute. Samma regel som App\Http\Controllers\ContainerController::show()
 * räknar containerns översikt efter.
 *
 * **Containerurvalet är `Container::scopeAccessibleBy()`** — exakt samma
 * villkor som App\Http\Controllers\ContainerController::index() ställer.
 * Formulera det aldrig en andra gång här.
 *
 * **Uppgiftstalen kommer ur `$todo`, och det är hela poängen.** Brickan ska
 * visa *samma tal som antalet rader på `/tasks`*, och kortets uppgiftstal är
 * antalet rader i den containern — alltså ställs ingen fråga om uppgifter här
 * alls. App\Actions\Schedule\ListTodo har redan svarat för panelen på samma
 * sida, och att ställa samma fråga en gång till hade varit en andra sanning om
 * urvalet: den ena hade glömt omfånget eller `visible_from`, och ingen rad
 * hade gett ett fel. Raden bär containerns ULID och `overdue` beräknat mot
 * serverns datum (se TodoEntryResource), så både kortets tal och brickans
 * underrad räknas ur samma svar som todo-vyn ritar.
 *
 * **Frågekostnaden är konstant oberoende av antalet containrar** (issue 70
 * § Beslut 2). Containrarna är EN fråga, omfånget värms i ETT anrop genom
 * `ResolveItemScope::forContainers()` — som memoiserar per `{user, container}`
 * och därför aldrig kostar en upplösning per kort — och itemtalen är EN
 * grupperad fråga. Uppgiftstalen kostar noll, för de kommer ur ett svar som
 * redan är hämtat. Ingen egen vandring över itemgrafer byggs: omfånget löses
 * upp av App\Actions\Access\ResolveItemScope och ingen annanstans.
 *
 * **Grupperingen är ADR-0036:s regel.** En art med minst två containrar får en
 * egen rubrik med artens namn — skrivet ORDAGRANT, för fältet är fritt och har
 * ingen översättningsnyckel (samma linje som
 * resources/js/pages/Containers/Overview.vue) — och resten ligger under en
 * rubrik som vyn formulerar. `kind` i svaret är därför `null` för den högen
 * och artens sträng för de andra, så att vyn kan se skillnaden: en sträng som
 * kommer ur användarens tangentbord ska aldrig slås upp i `lang/`.
 *
 * **Foto, undertitel och framdriftsstapel finns inte i svaret.** Ingen av dem
 * har en datakälla (issue 124 § Klart när, [[ADR-0042 Designsystemet]]), och
 * ett fält utan källa är ett påstående om att något finns.
 */
class ListContainerSummaries
{
    public function __construct(private readonly ResolveItemScope $resolveItemScope) {}

    /**
     * @param  array{
     *     rows: list<array<string, mixed>>,
     *     groups: array<string, list<array<string, mixed>>>,
     *     hasContainers: bool
     * }  $todo  App\Actions\Schedule\ListTodo::handle()s svar, orört
     * @return array{
     *     stats: array{containers: int, tasks: int, overdue: int},
     *     groups: list<array{
     *         kind: string|null,
     *         containers: list<array{ulid: string, name: string, items: int, todos: int}>
     *     }>
     * }
     */
    public function handle(User $user, array $todo): array
    {
        $accountIds = $user->accounts->pluck('id')->values()->all();

        $containers = Container::query()
            ->accessibleBy($user, $accountIds)
            ->orderBy('name')
            ->get(['id', 'ulid', 'name', 'kind']);

        $itemCounts = $this->itemCounts(
            $user,
            $containers->pluck('id')->all(),
        );

        $todoCounts = $this->todoCounts($todo['rows']);

        return [
            'stats' => [
                // Antalet containrar användaren når — samma urval som listan.
                'containers' => $containers->count(),
                // Antalet rader i `scopeTodoFor()`, alltså exakt de rader som
                // står bakom länken till `/tasks`. Inget annat tal.
                'tasks' => count($todo['rows']),
                // Underraden. Grupperingen sker på serverns datum, i ListTodo
                // — den här filen jämför inget datum själv.
                'overdue' => count($todo['groups'][ListTodo::GROUP_OVERDUE]),
            ],
            'groups' => $this->group($containers, $itemCounts, $todoCounts),
        ];
    }

    /**
     * Antalet items användaren når i var och en av containrarna, i EN fråga.
     *
     * Unionen är samma form som `ScheduleOccurrence::scopeTodoFor()` ställer
     * över items: de obegränsade containers som HELHET, och de begränsades
     * item-id:n var för sig. Att bygga den i PHP är nödvändigt och inte ett
     * val — omfånget skiljer sig mellan containrarna, så `Item::scopeInScope()`
     * (som tar ETT omfång) kan inte bära frågan. Den här filen är därför den
     * enda platsen utanför modellen som formulerar unionen, och formen är
     * lånad ordagrant.
     *
     * En container som varken är obegränsad eller bär ett item-id räknas som
     * noll, och när ingen container bär något item alls ställs ingen fråga.
     *
     * @param  list<int>  $containerIds
     * @return array<int, int> container_id → antal items inom omfånget
     */
    private function itemCounts(User $user, array $containerIds): array
    {
        if ($containerIds === []) {
            return [];
        }

        $unrestricted = [];
        $scopedItemIds = [];

        foreach ($this->resolveItemScope->forContainers($user, $containerIds) as $containerId => $scope) {
            if ($scope->isUnrestricted()) {
                $unrestricted[] = $containerId;

                continue;
            }

            foreach ($scope->itemIds() ?? [] as $itemId) {
                $scopedItemIds[] = $itemId;
            }
        }

        if ($unrestricted === [] && $scopedItemIds === []) {
            return [];
        }

        return Item::query()
            ->where(function (Builder $query) use ($unrestricted, $scopedItemIds): void {
                if ($unrestricted !== []) {
                    $query->whereIn('item.container_id', $unrestricted);
                }

                if ($scopedItemIds !== []) {
                    $unrestricted === []
                        ? $query->whereIn('item.id', $scopedItemIds)
                        : $query->orWhereIn('item.id', $scopedItemIds);
                }
            })
            ->selectRaw('item.container_id as container_id, COUNT(*) as item_count')
            ->groupBy('item.container_id')
            ->pluck('item_count', 'container_id')
            ->map(fn ($antal): int => (int) $antal)
            ->all();
    }

    /**
     * Öppna uppgifter per container, räknade ur todo-svaret och inte ur en
     * fråga — se klassens docblock.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int> container-ULID → antal öppna uppgifter
     */
    private function todoCounts(array $rows): array
    {
        $antal = [];

        foreach ($rows as $row) {
            $ulid = $row['container']['ulid'];

            $antal[$ulid] = ($antal[$ulid] ?? 0) + 1;
        }

        return $antal;
    }

    /**
     * Korten, grupperade enligt [[ADR-0036 Containerns art]]: en art med minst
     * två containrar får en egen rubrik, resten ligger i en hög.
     *
     * Högen ritas FÖRST, som i mockupen (`docs/Design/main.jpeg`), och
     * arterubrikerna därefter i bokstavsordning. Korten behåller frågans
     * ordning — namn stigande — inom varje grupp, så en container står på
     * samma plats i gruppen som i containerlistan.
     *
     * @param  Collection<int, Container>  $containers
     * @param  array<int, int>  $itemCounts
     * @param  array<string, int>  $todoCounts
     * @return list<array{
     *     kind: string|null,
     *     containers: list<array{ulid: string, name: string, items: int, todos: int}>
     * }>
     */
    private function group(Collection $containers, array $itemCounts, array $todoCounts): array
    {
        $antalPerArt = [];

        foreach ($containers as $container) {
            // En NÄRVAROKONTROLL och ingen jämförelse: fältet är fritt, och
            // regeln om att ingen grenar på VILKEN art det är står i
            // App\Models\Container:s klasskommentar (issue 84). En tom sträng
            // från äldre data är inget värde att gruppera på — samma linje som
            // ContainerController::kindsUsedBy().
            if (! $container->kind) {
                continue;
            }

            $antalPerArt[$container->kind] = ($antalPerArt[$container->kind] ?? 0) + 1;
        }

        /** @var list<string> $egnaArter */
        $egnaArter = array_keys(array_filter($antalPerArt, fn (int $antal): bool => $antal >= 2));
        sort($egnaArter);

        $hog = [];
        $perArt = [];

        foreach ($containers as $container) {
            $kort = [
                'ulid' => $container->ulid,
                'name' => $container->name,
                'items' => $itemCounts[$container->id] ?? 0,
                'todos' => $todoCounts[$container->ulid] ?? 0,
            ];

            if (in_array($container->kind, $egnaArter, true)) {
                $perArt[$container->kind][] = $kort;

                continue;
            }

            $hog[] = $kort;
        }

        $grupper = [];

        if ($hog !== []) {
            $grupper[] = ['kind' => null, 'containers' => $hog];
        }

        foreach ($egnaArter as $art) {
            $grupper[] = ['kind' => $art, 'containers' => $perArt[$art]];
        }

        return $grupper;
    }
}
