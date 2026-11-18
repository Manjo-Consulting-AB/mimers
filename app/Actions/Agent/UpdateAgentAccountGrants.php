<?php

namespace App\Actions\Agent;

use App\Models\Agent;
use App\Models\AgentAccountGrant;
use App\Models\AgentContainerGrant;
use App\Models\User;
use App\Support\Access\AccessLevel;
use App\Support\Agent\AgentGroup;
use App\Support\Agent\AgentLevels;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sätter agentens rättigheter på kontonivå ([[ADR-0087 MCP-server för
 * agenter]] Riktning 6): en nivå per grupp, och rätten att skapa containrar.
 *
 * **Höjning slår inte igenom, sänkning gör det.** Att höja en grupp ändrar
 * inga rader i `agent_container_grant`. Att sänka den — eller ta bort den —
 * sänker i samma transaktion varje containerrad för samma agent och grupp som
 * ligger över den nya nivån, eftersom en container aldrig får överstiga
 * kontot. Tas gruppen bort försvinner raderna.
 *
 * Bara grupperna i `$levels` ändras; `null` tar bort gruppens rad (ingen
 * åtkomst). `$createContainers` null lämnar rätten orörd.
 */
class UpdateAgentAccountGrants
{
    use RefusesAgentCaller;

    /**
     * @param  array<string, string|null>  $levels  grupp => nivå eller null
     */
    public function handle(Agent $agent, User $actor, array $levels, ?bool $createContainers = null): void
    {
        $this->assertOwnAgent($agent, $actor);

        foreach ($levels as $group => $level) {
            if (AgentGroup::tryFrom((string) $group) === null) {
                throw new InvalidArgumentException("Okänd agentgrupp: {$group}");
            }

            if ($level !== null) {
                AccessLevel::rank($level);
            }
        }

        DB::transaction(function () use ($agent, $levels, $createContainers): void {
            Agent::query()->whereKey($agent->id)->lockForUpdate()->first();

            foreach ($levels as $group => $level) {
                $this->setGroup($agent, (string) $group, $level);
            }

            if ($createContainers !== null && $agent->create_containers !== $createContainers) {
                $agent->create_containers = $createContainers;
                $agent->save();
            }
        });
    }

    private function setGroup(Agent $agent, string $group, ?string $level): void
    {
        if ($level === null) {
            AgentAccountGrant::query()->where('agent_id', $agent->id)->where('group', $group)->delete();
            AgentContainerGrant::query()->where('agent_id', $agent->id)->where('group', $group)->delete();

            return;
        }

        $grant = AgentAccountGrant::query()->where('agent_id', $agent->id)->where('group', $group)->first()
            ?? new AgentAccountGrant;
        $grant->agent_id = $agent->id;
        $grant->group = $group;
        $grant->level = $level;
        $grant->save();

        $rows = AgentContainerGrant::query()->where('agent_id', $agent->id)->where('group', $group)->get();

        foreach ($rows as $row) {
            if (AgentLevels::isAbove($row->level, $level)) {
                $row->level = $level;
                $row->save();
            }
        }
    }
}
