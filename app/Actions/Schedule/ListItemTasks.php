<?php

namespace App\Actions\Schedule;

use App\Actions\Access\ResolveItemScope;
use App\Actions\Item\ResolveItemDescendants;
use App\Models\Container;
use App\Models\Item;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Itemets uppgiftsflik — förekomsterna på itemet och, när reglaget är på,
 * ättlingarna (M24 · issue 227, Tonys beslut 2026-10-04).
 *
 * **Frågan är `ListTodo`s, avgränsad till en itemmängd.** Grupperna och raden
 * kommer ur App\Actions\Schedule\ListTodo — `group()` och `row()` är publika
 * sedan den här issuen — så en förekomst hamnar i samma grupp och bär samma
 * rad på itemets flik som på `/tasks` och containerns flik. Ingen egen rad och
 * ingen egen gruppering här: två formuleringar av samma rad hade glidit isär,
 * och den ena hade tappat `can`-flaggan ([[ADR-0024 Tunna controllers och
 * actions]]).
 *
 * **Itemmängden är id:n — itemet självt, eller hela underträdet.** Med
 * reglaget av är mängden `[$item->id]`; med det på är den
 * `ResolveItemDescendants::handle($item)`, som ger itemet FÖRST och därefter
 * varje ättling. Mängden är just en MÄNGD: ett item som nås längs två
 * förälderkanter (`parent` från A OCH från B) räknas en gång, så samma
 * förekomst kan aldrig ritas två gånger ([[ADR-0040 Underträdets summor]]
 * § Rättelse 2026-09-18).
 *
 * **Inget `scopeTodoFor()`.** Todo-scopet kräver ett aktivt schema och inga
 * öppna beroenden — villkor som hör till listan över "vad ska jag göra nu" och
 * inte till en beskrivning av itemets tillstånd. Pausade scheman (`is_active =
 * false`) och blockerade förekomster följer därför med, precis som i testarnas
 * fynd (#696 Beslut 4): de står kvar med ett märke i stället för att
 * försvinna. Mjukraderade scheman faller bort genom SoftDeletes, och
 * mjukraderade items genom `ResolveItemDescendants`.
 *
 * **Behörigheten prövas PER RAD** (#696 Beslut 3). `ResolveItemDescendants`
 * läser också omvända `child`-rader, vilket `ResolveItemScope` inte gör — de
 * två mängderna kan skilja sig, och en rad anroparen inte får `view` på ritas
 * inte alls. Det gäller både de öppna och de avbockade raderna: en gäst med en
 * itemgrant ska inte få läsa vad hon inte får se för att raden hunnit bli klar
 * (issue 74 § Beslut 7).
 *
 * **Raden är `ListTodo::row()`, plus två nycklar** (Beslut 1): `paused`
 * (`! schedule.is_active`) och `blocked` (förekomsten har ett öppet beroende,
 * samma fråga som `scopeTodoFor` ställer). Inga andra fält. Vyn ritar ett märke
 * för var och en (Beslut 4).
 *
 * **Frågekostnaden är konstant.** Förekomsterna hämtas i EN fråga med samma
 * ivriga laddningar som `ListTodo` — plus `dependsOn.schedule.item`, som svarar
 * på `blocked` utan en fråga per rad. Omfånget värms i ETT anrop
 * (`ResolveItemScope::forContainers` memoiserar per `{user, container}`), så
 * `view`- och `update`-grindarna per rad kostar inga egna frågor.
 */
class ListItemTasks
{
    /**
     * *Klart* är en glimt av det senaste, inte en historik — samma tak och
     * samma skäl som i `ListTodo::completedForContainer()` (Beslut 1).
     */
    public const COMPLETED_LIMIT = 20;

    public function __construct(
        private readonly ResolveItemDescendants $resolveItemDescendants,
        private readonly ResolveItemScope $resolveItemScope,
        private readonly ListTodo $listTodo,
    ) {}

    /**
     * Itemets uppgifter, grupperade och avbockade.
     *
     * @return array{
     *     groups: array<string, list<array<string, mixed>>>,
     *     completed: list<array<string, mixed>>
     * }
     */
    public function handle(
        User $user,
        Request $request,
        Container $container,
        Item $item,
        bool $includeDescendants,
    ): array {
        $itemIds = $includeDescendants
            ? $this->resolveItemDescendants->handle($item)
            : [$item->id];

        // Värm omfånget i ETT anrop: grindarna per rad läser den memoiserade
        // instansen, så `view` och `update` kostar inga frågor per rad (issue
        // 70 § Beslut 2).
        $this->resolveItemScope->forContainers($user, [$container->id]);

        return [
            'groups' => $this->groups($user, $request, $container, $itemIds),
            'completed' => $this->completed($user, $request, $container, $itemIds),
        ];
    }

    /**
     * De fem öppna grupperna, i ritningsordning — *No date* sist (ADR-0052 § 3).
     *
     * **Daterade före odaterade** (ADR-0052 § Konsekvenser):
     * `orderByRaw('due_at IS NULL')` är första nyckeln, för både MySQL och
     * sqlite sätter annars null först. Sedan `due_at` och `ulid`, samma
     * deterministiska ordning som `ListTodo` och `/tasks` ger.
     *
     * @param  list<int>  $itemIds
     * @return array<string, list<array<string, mixed>>>
     */
    private function groups(User $user, Request $request, Container $container, array $itemIds): array
    {
        $occurrences = $this->occurrences($itemIds)
            ->active()
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderBy('ulid')
            ->get();

        $groups = [
            ListTodo::GROUP_OVERDUE => [],
            ListTodo::GROUP_TODAY => [],
            ListTodo::GROUP_THIS_WEEK => [],
            ListTodo::GROUP_UPCOMING => [],
            ListTodo::GROUP_NO_DATE => [],
        ];

        $today = $user->today();
        $accountUlids = $user->accounts->pluck('ulid')->all();
        $covers = $this->listTodo->covers($occurrences);

        foreach ($occurrences as $occurrence) {
            if (! $this->visible($user, $container, $occurrence)) {
                continue;
            }

            $groups[$this->listTodo->group($occurrence->due_at, $today)][] = [
                ...$this->listTodo->row($user, $request, $occurrence, $accountUlids, $covers),
                'paused' => ! $occurrence->schedule->is_active,
                'blocked' => $this->blocked($occurrence),
            ];
        }

        return $groups;
    }

    /**
     * De avbockade förekomsterna, nyast först och högst
     * `COMPLETED_LIMIT` (Beslut 1).
     *
     * `skipped` står utanför, som i `ListTodo::completedForContainer()`: en
     * överhoppad förekomst påstår ett byte som inte gjordes och hör i
     * historiken, inte under *Klart*. `schedule.is_active` prövas INTE — det
     * som redan är gjort är gjort, också på ett schema som pausats sedan dess.
     *
     * @param  list<int>  $itemIds
     * @return list<array<string, mixed>>
     */
    private function completed(User $user, Request $request, Container $container, array $itemIds): array
    {
        $occurrences = $this->occurrences($itemIds)
            ->where('status', ScheduleOccurrence::STATUS_COMPLETED)
            ->orderByDesc('completed_at')
            ->orderByDesc('ulid')
            ->limit(self::COMPLETED_LIMIT)
            ->get();

        $accountUlids = $user->accounts->pluck('ulid')->all();
        $covers = $this->listTodo->covers($occurrences);

        $rows = [];

        foreach ($occurrences as $occurrence) {
            if (! $this->visible($user, $container, $occurrence)) {
                continue;
            }

            $rows[] = [
                ...$this->listTodo->row($user, $request, $occurrence, $accountUlids, $covers),
                'completed_at' => $occurrence->completed_at->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * Förekomstfrågan: itemmängden, de ivriga laddningarna och ingenting mer.
     *
     * Statusvillkoret läggs av anroparen — de öppna och de avbockade raderna
     * frågar samma mängd men olika status, och en gemensam `where` här hade
     * gömt skillnaden.
     *
     * `dependsOn.schedule.item` svarar på `blocked` utan en fråga per rad:
     * samma villkor som `scopeTodoFor()` ställer — ett öppet beroende vars
     * motpart har ett levande schema och item.
     *
     * @param  list<int>  $itemIds
     * @return Builder<ScheduleOccurrence>
     */
    private function occurrences(array $itemIds): Builder
    {
        return ScheduleOccurrence::query()
            ->whereHas('schedule', fn (Builder $query) => $query->whereIn('item_id', $itemIds))
            ->with(['schedule.item.container.account', 'dependsOn.schedule.item']);
    }

    /**
     * Prövar `view` på radens item, med containern hängd på modellen så att
     * grinden inte ställer en fråga per rad.
     */
    private function visible(User $user, Container $container, ScheduleOccurrence $occurrence): bool
    {
        $item = $occurrence->schedule->item;

        // Alla items i mängden ligger i samma container — `ResolveItemDescendants`
        // hämtar bara kanter med båda ändarna i containern — så containern
        // hängs på för hand i stället för att laddas per rad. Samma handgrepp
        // som `ItemController::show()` gör för sina rader.
        $item->setRelation('container', $container);

        return Gate::forUser($user)->allows('view', $item);
    }

    /**
     * Har förekomsten ett öppet beroende? Samma fråga som `scopeTodoFor()`
     * ställer med `whereDoesntHave('dependsOn')` — ett öppet beroende vars
     * motpart har ett levande schema och item blockerar.
     */
    private function blocked(ScheduleOccurrence $occurrence): bool
    {
        foreach ($occurrence->dependsOn as $dependency) {
            if ($dependency->isActive()
                && $dependency->schedule !== null
                && $dependency->schedule->item !== null) {
                return true;
            }
        }

        return false;
    }
}
