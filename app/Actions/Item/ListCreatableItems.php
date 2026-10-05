<?php

namespace App\Actions\Item;

use App\Actions\Access\ResolveItemScope;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Items användaren får skapa i, grupperade per container — mållistan bakom
 * målväljaren, se issue 242 och [[M27 Flytten och inboxen]] § 242.
 *
 * **Frågan är `ItemPolicy::create` per item, och den ställs på samma policy
 * som `store()` prövar.** Grinden bor i App\Policies\ItemPolicy och
 * formularas inte på nytt här: en egen nivåjämförelse hade blivit en andra
 * regel om behörighet, och den hade glidit isär från den första. Att policyn
 * frågas per rad är ingen N+1 — omfånget är värmt i förväg (nedan), och
 * `container.account` är läst, så varje fråga är ett minnesuppslag.
 *
 * **Omfånget värms i ETT anrop.** `ResolveItemScope::forContainers()` löser
 * upp alla containrar samtidigt, precis som `GET /items` och `/api/todo`
 * gör (issue 70 § Beslut 2), så antalet frågor är konstant oavsett hur många
 * containrar användaren når. Utan värmningen hade `ItemPolicy::create` ställt
 * sina tre frågor per container — den N+1 issue 9a § Att se upp med varnar
 * för.
 *
 * **Inboxen är aldrig ett mål.** Containrarna hämtas med
 * App\Models\Container::scopeListable() ovanpå `accessibleBy()`, samma urval
 * som varje annan containerlista (issue 243 · [[ADR-0054 Inboxen]] § 2): den
 * egna inboxen ÄR åtkomlig men listas inte, och en annan användares inbox
 * nås inte alls. Inboxen är en egen destination och ritas som ett eget val
 * där den behövs (issue 246), aldrig som ett vanligt item.
 *
 * **Containrar utan valbara items utelämnas**, och mjukraderade items faller
 * på SoftDeletes' globala scope. Ordningen är containernas namn och sedan
 * itemens, och svaret bär bara det klienten ritar:
 *
 *     [{container: {ulid, name}, items: [{ulid, name}]}]
 *
 * Ingen `Gate::authorize()` och ingen `ItemResource` (samma linje som
 * App\Actions\Item\ListItems): anroparen är den enda yta som finns, och raden
 * är namn och ULID och ingenting mer.
 */
final class ListCreatableItems
{
    public function __construct(private readonly ResolveItemScope $resolveItemScope) {}

    /**
     * @return list<array{container: array{ulid: string, name: string}, items: list<array{ulid: string, name: string}>}>
     */
    public function handle(User $user): array
    {
        $accountIds = $user->accounts->pluck('id')->values()->all();

        // `account` läses i förväg: ItemPolicy::allows() frågar efter
        // ägarkontots status (regel 4), och utan raden hade varje item kostat
        // ett uppslag i stället för noll.
        $containers = Container::query()
            ->accessibleBy($user, $accountIds)
            ->listable()
            ->with('account')
            ->orderBy('name')
            ->get(['id', 'ulid', 'name', 'account_id']);

        if ($containers->isEmpty()) {
            return [];
        }

        $this->resolveItemScope->forContainers(
            $user,
            $containers->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        );

        // Alla items i EN fråga, namnordnade — Mjukraderade faller på
        // SoftDeletes' globala scope, och containerns items läses ur samma
        // mängd som containrarna ovan gav.
        $items = Item::query()
            ->whereIn('container_id', $containers->pluck('id'))
            ->orderBy('name')
            ->get(['id', 'ulid', 'name', 'container_id']);

        /** @var array<int, list<Item>> $perContainer */
        $perContainer = [];

        foreach ($items as $item) {
            $perContainer[$item->container_id][] = $item;
        }

        $grupper = [];

        foreach ($containers as $container) {
            $valbara = [];

            foreach ($perContainer[$container->id] ?? [] as $item) {
                // Containern är redan läst, kontot med den: relationen sätts
                // så policyns regel 4 inte gör ett uppslag per rad.
                $item->setRelation('container', $container);

                if (! Gate::forUser($user)->allows('create', $item)) {
                    continue;
                }

                $valbara[] = ['ulid' => $item->ulid, 'name' => $item->name];
            }

            if ($valbara === []) {
                continue;
            }

            $grupper[] = [
                'container' => ['ulid' => $container->ulid, 'name' => $container->name],
                'items' => $valbara,
            ];
        }

        return $grupper;
    }
}
