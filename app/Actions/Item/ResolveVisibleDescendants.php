<?php

namespace App\Actions\Item;

use App\Actions\Access\ResolveItemScope;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Ättlingarna som en läsare NÅR — mängden *Include child items* bygger på,
 * se [[ADR-0068 Itemets sida och skapandet]] § Uppföljning 2026-11-14 punkt 2.
 *
 * **Två steg, två ägare.** Vilka items som hänger under itemet är
 * App\Actions\Item\ResolveItemDescendants svar, och vem som får se dem är
 * ItemPolicy::view. Actionen här lägger dem efter varandra och hittar aldrig
 * på en egen vandring: en andra formulering av underträdet hade glidit isär
 * från den Tasks redan använder ([[ADR-0024 Tunna controllers och actions]]).
 *
 * **Itemet självt är ingen ättling.** `ResolveItemDescendants` svarar med
 * itemet FÖRST — formen `ListItemTasks` behöver — och det id:t faller bort
 * här. Anroparen har redan itemet, och en rad för det hade blivit samma rad
 * två gånger.
 *
 * **Behörigheten prövas PER ITEM, som i `ListItemTasks`** (#696 Beslut 3).
 * `ResolveItemDescendants` läser också omvända `child`-rader, vilket
 * `ResolveItemScope` inte gör — de två mängderna kan skilja sig, och en
 * ättling anroparen inte får `view` på faller bort. Att filtrera här i
 * stället för i varje flik är hela poängen med actionen: *Documents*,
 * *Costs* och *Loans* delar svaret, och tre kopior av filtret hade kunnat
 * svara olika på samma fråga.
 *
 * **Frågekostnaden är konstant.** Kantfrågan bor i `ResolveItemDescendants`
 * (EN fråga), ättlingarnas modeller hämtas i EN fråga, och omfånget värms i
 * ETT anrop — `ResolveItemScope::forContainers` memoiserar per
 * `{user, container}`, så `view`-grinden per rad kostar inga egna frågor.
 * Containern hängs på modellerna för hand, samma handgrepp som
 * `ListItemTasks::visible()`, så grinden inte slår upp den per rad.
 *
 * **Ordningen är `ResolveItemDescendants` egen** — itemet först (borttaget),
 * därefter ättlingarna i den ordning vandringen hittade dem. Anroparen
 * sorterar sina egna rader; mängden här är en mängd.
 */
class ResolveVisibleDescendants
{
    public function __construct(
        private readonly ResolveItemDescendants $resolveItemDescendants,
        private readonly ResolveItemScope $resolveItemScope,
    ) {}

    /**
     * Itemets ättlingar som $user får se, som id:n.
     *
     * @return Collection<int, int> itemets id:n, utan itemet självt
     */
    public function handle(User $user, Item $item): Collection
    {
        $descendantIds = array_values(array_filter(
            $this->resolveItemDescendants->handle($item),
            fn (int $id): bool => $id !== $item->id,
        ));

        if ($descendantIds === []) {
            return collect();
        }

        $container = $item->container;

        // Värm omfånget i ETT anrop: grinden per rad läser den memoiserade
        // instansen, så `view` kostar inga frågor per ättling (issue 70
        // § Beslut 2).
        $this->resolveItemScope->forContainers($user, [$container->id]);

        $models = Item::query()
            ->whereIn('id', $descendantIds)
            ->get(['id'])
            ->keyBy('id');

        $visible = [];

        foreach ($descendantIds as $id) {
            $descendant = $models->get($id);

            if ($descendant === null) {
                continue;
            }

            // Alla items i mängden ligger i samma container —
            // `ResolveItemDescendants` hämtar bara kanter med båda ändarna i
            // containern — så den hängs på för hand i stället för att laddas
            // per rad. Samma handgrepp som `ListItemTasks::visible()`.
            $descendant->setRelation('container', $container);

            if (Gate::forUser($user)->allows('view', $descendant)) {
                $visible[] = $id;
            }
        }

        return collect($visible);
    }
}
