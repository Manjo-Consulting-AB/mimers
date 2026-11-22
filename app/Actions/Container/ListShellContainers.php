<?php

namespace App\Actions\Container;

use App\Http\Resources\ContainerResource;
use App\Models\Container;
use App\Models\User;

/**
 * Skalets containerlista, se issue 169 · [[ADR-0050 Desktopdesignen]] § 1 och
 * [[ADR-0036 Containerns art]].
 *
 * **Listan är navigering och inte en översikt.** Den bär containerns namn,
 * ULID och ikonval — adressen byggs av klienten ur ULID:n, samma form som
 * `ContainerCard` — och ingenting mer. Talen, bilden och undertiteln hör till
 * dashboardens kort (App\Actions\Container\ListContainerSummaries) och
 * räknas inte här: en rad i sidopanelen är ett mål, inte en sammanfattning,
 * och den som behöver talen står redan på översikten.
 *
 * **Urvalet är `Container::scopeAccessibleBy()`**, exakt samma villkor som
 * App\Http\Controllers\ContainerController::index() och
 * ListContainerSummaries ställer: den egna kontofamiljens containrar, plus de
 * containrar någon annan delat — som helhet eller genom ett enskilt item —
 * med användaren. Formulera det aldrig en andra gång här.
 *
 * **Grupperingen är delad med dashboardens kort**, se
 * App\Actions\Container\ContainerKindGroups: en art med minst två containrar
 * får en egen grupp, resten ligger i högen. Regeln bor i den klassen och inte
 * i den här filen, så att de två ytorna inte kan glida isär.
 *
 * **Ingen gräns och ingen paginering.** Taket vore ett svar på en fråga ingen
 * ställt: listan är användarens egen navigering, och en gruppering kan bara
 * avgöras mot ALLA containrar — en art med två containrar är en egen grupp
 * även när den andra står utanför en godtycklig gräns.
 *
 * **Raden bär containerns ikon sedan issue 316** ([[ADR-0064 Containerns
 * ikon]] § 5): `icon` och `icon_color` läses i SAMMA fråga som `ulid`, `name`
 * och `kind` och skickas med varje rad. Det kostar inga nya frågor — fälten
 * ligger på samma containerrad — och skalet ritar dem med `ItemIcon`, med
 * `container` som fallback när de är null.
 *
 * **Frågekostnaden är två frågor**: kontona och containrarna. Ingen
 * `ResolveItemScope` och ingen itemräkning, för raden bär inga tal.
 */
final class ListShellContainers
{
    /**
     * @return list<array{kind: string|null, containers: list<array{ulid: string, name: string, icon?: string|null, icon_color?: string|null, locked: bool}>}>
     */
    public function handle(User $user): array
    {
        $accountIds = $user->accounts->pluck('id')->values()->all();

        $containers = Container::query()
            ->accessibleBy($user, $accountIds, includeLocked: true)
            ->listable()
            ->orderBy('name')
            ->with('account:id,require_two_factor')
            ->get(['ulid', 'name', 'kind', 'icon', 'icon_color', 'account_id', 'require_two_factor']);

        /** @var list<array{kind: string|null, containers: list<array{ulid: string, name: string, icon?: string|null, icon_color?: string|null, locked: bool}>}> $grupper */
        $grupper = ContainerKindGroups::byKind(
            $containers,
            function (Container $container) use ($user, $accountIds): array {
                // En låst container bär ulid, namn och `locked` — ingen ikon
                // (ADR-0092, issue 449).
                if (ContainerResource::isLocked($container, $user, $accountIds)) {
                    return ['ulid' => $container->ulid, 'name' => $container->name, 'locked' => true];
                }

                return [
                    'ulid' => $container->ulid,
                    'name' => $container->name,
                    'icon' => $container->icon,
                    'icon_color' => $container->icon_color,
                    'locked' => false,
                ];
            },
        );

        return $grupper;
    }
}
