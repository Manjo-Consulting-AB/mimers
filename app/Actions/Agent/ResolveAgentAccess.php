<?php

namespace App\Actions\Agent;

use App\Actions\Access\ResolveItemScope;
use App\Models\Agent;
use App\Models\AgentAccountGrant;
use App\Models\AgentContainerGrant;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Support\Access\AccessLevel;
use App\Support\Agent\AgentGroup;
use App\Support\Agent\AgentLevels;

/**
 * Vilken nivå har agenten i en grupp för en container, eller för ett item i
 * den? Det enda stället som svarar, se [[ADR-0087 MCP-server för agenter]]
 * Riktning 6 och [[ADR-0028 Åtkomst på itemnivå]].
 *
 * Nivån är den lägsta av
 *
 * 1. kontots nivå för gruppen (`agent_account_grant`),
 * 2. containerns nivå för gruppen (`agent_container_grant`),
 * 3. ägarens tak (`container_access.agent_level`) när containern inte är
 *    användarens egen,
 * 4. användarens egen nivå, ur `ResolveItemScope`.
 *
 * En saknad rad i 1 eller 2 är ingen åtkomst, och en `null` i något lager gör
 * hela svaret `null`. Dessutom är svaret `null` när agenten är pausad, när
 * agentens användare inte har Pro eller Pro+, och när containerns ägarkonto
 * inte har det (Riktning 4). Planerna prövas vid varje anrop, så en
 * nedgradering tar åtkomsten utan att någon rad ändras.
 *
 * **Ägarens tak läser alla giltiga rader.** En medlem kan ha en
 * container-bred rad och rader per item, och `agent_level` sitter på varje.
 * Taket är den högsta `agent_level` bland användarens giltiga rader i
 * containern; användarens egen nivå (lager 4) kapar sedan per item, så ett
 * item där hen bara har `read` ger aldrig agenten mer. Taket gäller medlemmens
 * agenter gemensamt, aldrig en agent för sig (Riktning 6, *Takets regler*).
 *
 * Inkorgen är användarens egen när hen äger den; någon annans inkorg ger
 * `ResolveItemScope` ingen åtkomst alls.
 */
class ResolveAgentAccess
{
    public function __construct(private readonly ResolveItemScope $resolveItemScope) {}

    public function handle(Agent $agent, AgentGroup $group, Container $container, ?Item $item = null): ?string
    {
        if ($agent->isPaused()) {
            return null;
        }

        $user = $agent->user;

        if (! AgentLevels::planIncludesMcp($agent->account)
            || ! AgentLevels::planIncludesMcp($container->account)) {
            return null;
        }

        $accountLevel = AgentAccountGrant::query()
            ->where('agent_id', $agent->id)
            ->where('group', $group->value)
            ->value('level');

        if ($accountLevel === null) {
            return null;
        }

        $containerLevel = AgentContainerGrant::query()
            ->where('agent_id', $agent->id)
            ->where('container_id', $container->id)
            ->where('group', $group->value)
            ->value('level');

        if ($containerLevel === null) {
            return null;
        }

        $scope = $this->resolveItemScope->handle($user, $container);
        $userLevel = $item === null ? $scope->unrestrictedLevel() : $scope->levelFor($item->id);

        $ceiling = $this->isOwnContainer($agent, $container)
            ? AccessLevel::DELETE
            : $this->ownerCeiling($agent, $container);

        return AgentLevels::min($accountLevel, $containerLevel, $ceiling, $userLevel);
    }

    private function isOwnContainer(Agent $agent, Container $container): bool
    {
        if ($container->inbox_user_id !== null) {
            return (int) $container->inbox_user_id === (int) $agent->user_id;
        }

        return $agent->user->accounts()->whereKey($container->account_id)->exists();
    }

    private function ownerCeiling(Agent $agent, Container $container): ?string
    {
        $user = $agent->user;
        $accountIds = $user->accounts->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $ceiling = null;

        $levels = ContainerAccess::query()
            ->where('container_id', $container->id)
            ->whereNotNull('agent_level')
            ->validFor($user, $accountIds)
            ->pluck('agent_level');

        foreach ($levels as $level) {
            $ceiling = $ceiling === null ? $level : AccessLevel::max($ceiling, $level);
        }

        return $ceiling;
    }
}
