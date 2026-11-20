<?php

namespace App\Actions\Agent;

use App\Actions\Security\RecordSecurityEvent;
use App\Models\Agent;
use App\Models\AgentAccountGrant;
use App\Models\AgentContainerGrant;
use App\Models\SecurityLog;
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
 *
 * **Rättigheterna hör till kontot, inte till en container**, så ändringen
 * skrivs i säkerhetsloggen och inte i händelseloggen ([[ADR-0043 Tre
 * loggar]] § Säkerhetsloggen). `agent.grants_changed` skrivs i samma
 * transaktion, **högst en rad per anrop och bara när något ändrades** — ett
 * anrop som sätter samma nivå igen lämnar inget spår. `meta` bär agentens
 * ULID, gruppernas gamla och nya nivå, `create_containers` och antalet
 * containerrader som sänktes, och aldrig ett containernamn eller en
 * e-postadress (ADR-0087 Riktning 12, uppföljning 2026-11-18).
 */
class UpdateAgentAccountGrants
{
    use RefusesAgentCaller;

    public function __construct(
        private readonly RecordSecurityEvent $recordSecurityEvent,
    ) {}

    /**
     * @param  array<string, string|null>  $levels  grupp => nivå eller null
     */
    public function handle(
        Agent $agent,
        User $actor,
        array $levels,
        ?bool $createContainers = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        $this->assertOwnAgent($agent, $actor);

        foreach ($levels as $group => $level) {
            if (AgentGroup::tryFrom((string) $group) === null) {
                throw new InvalidArgumentException("Okänd agentgrupp: {$group}");
            }

            if ($level !== null) {
                AccessLevel::rank($level);
            }
        }

        DB::transaction(function () use ($agent, $actor, $levels, $createContainers, $ip, $userAgent): void {
            Agent::query()->whereKey($agent->id)->lockForUpdate()->first();

            // Gamla nivåer läses före skrivningen: `changed` beskriver
            // övergången, och en grupp som sätts till samma nivå igen hör
            // inte hemma i raden.
            $changed = [];
            $containersLowered = 0;

            foreach ($levels as $group => $level) {
                $group = (string) $group;
                $previous = AgentAccountGrant::query()
                    ->where('agent_id', $agent->id)
                    ->where('group', $group)
                    ->value('level');

                $containersLowered += $this->setGroup($agent, $group, $level);

                if ($previous !== $level) {
                    $changed[$group] = [$previous, $level];
                }
            }

            $previousCreateContainers = (bool) $agent->create_containers;
            $createContainersChanged = $createContainers !== null
                && $agent->create_containers !== $createContainers;

            if ($createContainersChanged) {
                $agent->create_containers = $createContainers;
                $agent->save();
            }

            if ($changed === [] && ! $createContainersChanged && $containersLowered === 0) {
                return;
            }

            $meta = ['agent_ulid' => $agent->ulid];

            if ($changed !== []) {
                $meta['changed'] = $changed;
            }

            if ($createContainersChanged) {
                $meta['create_containers'] = [$previousCreateContainers, $createContainers];
            }

            if ($containersLowered > 0) {
                $meta['containers_lowered'] = $containersLowered;
            }

            $this->recordSecurityEvent->handle(
                action: SecurityLog::ACTION_AGENT_GRANTS_CHANGED,
                account: $agent->account,
                user: $actor,
                ip: $ip,
                userAgent: $userAgent,
                meta: $meta,
            );
        });
    }

    /**
     * Sätter gruppens nivå och returnerar antalet containerrader som
     * sänktes eller togs bort.
     */
    private function setGroup(Agent $agent, string $group, ?string $level): int
    {
        if ($level === null) {
            AgentAccountGrant::query()->where('agent_id', $agent->id)->where('group', $group)->delete();

            return AgentContainerGrant::query()->where('agent_id', $agent->id)->where('group', $group)->delete();
        }

        $grant = AgentAccountGrant::query()->where('agent_id', $agent->id)->where('group', $group)->first()
            ?? new AgentAccountGrant;
        $grant->agent_id = $agent->id;
        $grant->group = $group;
        $grant->level = $level;
        $grant->save();

        $lowered = 0;
        $rows = AgentContainerGrant::query()->where('agent_id', $agent->id)->where('group', $group)->get();

        foreach ($rows as $row) {
            if (AgentLevels::isAbove($row->level, $level)) {
                $row->level = $level;
                $row->save();
                $lowered++;
            }
        }

        return $lowered;
    }
}
