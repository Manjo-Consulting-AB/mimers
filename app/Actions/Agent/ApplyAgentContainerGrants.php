<?php

namespace App\Actions\Agent;

use App\Models\Agent;
use App\Models\AgentContainerGrant;
use App\Models\Container;
use App\Support\Access\AccessLevel;
use App\Support\Agent\AgentGroup;
use App\Support\Agent\AgentLevels;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Skriver agentens rader i `agent_container_grant` för en container: ersätter
 * alla agentens rader där med kontots nivåer (`$levels` är null — allt upp
 * till kontots nivå, en ögonblicksbild) eller med valda nivåer, där varje
 * nivå kapas till kontots ([[ADR-0087 MCP-server för agenter]] Riktning 6).
 *
 * En grupp utan kontonivå eller vald nivå får ingen rad. Ögonblicksbilden
 * följer inte kontot uppåt senare — bara en sänkning på kontot slår igenom
 * (`UpdateAgentAccountGrants`).
 *
 * Internt, utan spärr mot agentanrop: `CreateContainerForAgent` anropar den
 * i en agents namn. De publika ingångarna för en människa är
 * `EnableAgentInContainer`.
 */
class ApplyAgentContainerGrants
{
    /**
     * @param  array<string, string|null>|null  $levels  grupp => nivå; null = allt upp till kontots nivå
     * @return array<string, string> det som sparades, grupp => nivå
     */
    public function handle(Agent $agent, Container $container, ?array $levels = null): array
    {
        $this->validate($levels);

        $account = $agent->accountGrants()->pluck('level', 'group')->all();

        $rows = [];

        foreach (AgentGroup::cases() as $group) {
            $accountLevel = $account[$group->value] ?? null;
            $wanted = $levels === null ? $accountLevel : ($levels[$group->value] ?? null);
            $level = $wanted === null ? null : AgentLevels::cap($wanted, $accountLevel);

            if ($level !== null) {
                $rows[$group->value] = $level;
            }
        }

        DB::transaction(function () use ($agent, $container, $rows): void {
            AgentContainerGrant::query()
                ->where('agent_id', $agent->id)
                ->where('container_id', $container->id)
                ->delete();

            foreach ($rows as $group => $level) {
                $grant = new AgentContainerGrant;
                $grant->agent_id = $agent->id;
                $grant->container_id = $container->id;
                $grant->group = $group;
                $grant->level = $level;
                $grant->save();
            }
        });

        return $rows;
    }

    /**
     * @param  array<string, string|null>|null  $levels
     */
    private function validate(?array $levels): void
    {
        foreach ($levels ?? [] as $group => $level) {
            if (AgentGroup::tryFrom((string) $group) === null) {
                throw new InvalidArgumentException("Okänd agentgrupp: {$group}");
            }

            if ($level !== null) {
                AccessLevel::rank($level);
            }
        }
    }
}
