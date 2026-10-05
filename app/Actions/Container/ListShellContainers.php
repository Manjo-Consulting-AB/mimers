<?php

namespace App\Actions\Container;

use App\Models\Container;
use App\Models\User;

/**
 * Skalets containerlista, se issue 169 · [[ADR-0050 Desktopdesignen]] § 1 och
 * [[ADR-0036 Containerns art]].
 *
 * **Listan är navigering och inte en översikt.** Den bär containerns namn och
 * ULID — adressen byggs av klienten ur ULID:n, samma form som
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
 * **Frågekostnaden är två frågor**: kontona och containrarna. Ingen
 * `ResolveItemScope` och ingen itemräkning, för raden bär inga tal.
 */
final class ListShellContainers
{
    /**
     * @return list<array{kind: string|null, containers: list<array{ulid: string, name: string}>}>
     */
    public function handle(User $user): array
    {
        $accountIds = $user->accounts->pluck('id')->values()->all();

        $containers = Container::query()
            ->accessibleBy($user, $accountIds)
            ->listable()
            ->orderBy('name')
            ->get(['ulid', 'name', 'kind']);

        /** @var list<array{kind: string|null, containers: list<array{ulid: string, name: string}>}> $grupper */
        $grupper = ContainerKindGroups::byKind(
            $containers,
            fn (Container $container): array => [
                'ulid' => $container->ulid,
                'name' => $container->name,
            ],
        );

        return $grupper;
    }
}
