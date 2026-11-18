<?php

namespace App\Support\Agent;

use App\Models\Agent;
use App\Models\AgentReadAudience;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Vad agenten har läst, och vilka som når allt av det — kopieringsspärren i
 * [[ADR-0087 MCP-server för agenter]] Riktning 10, *Kopiering*, och issue
 * 404.
 *
 * **Snittet, inte unionen.** Varje noterad läsning bidrar med mängden
 * personer som når det lästa innehållet, och det som sparas är snittet av
 * alla mängder sedan fönstret började: de personer som når ALLT agenten har
 * läst. En union hade varit en svagare spärr — den hade släppt igenom en
 * skrivning som når någon som bara nådde en av sakerna.
 *
 * **Användaren finns alltid i snittet.** Hen når allt agenten når, och en
 * skrivning som bara användaren ser ska alltid gå igenom.
 *
 * **Fönstret.** Snittet gäller till `mcp.read_audience_hours` timmar efter
 * agentens SENASTE läsning. Varje noterad läsning flyttar slutet framåt.
 * Har fönstret passerat finns ingen spärr — och nästa läsning börjar om från
 * sin egen mängd i stället för att snitta mot en gammal.
 *
 * **Lagringen är databasen, aldrig cachen.** En tömd cache får inte släppa
 * spärren (Riktning 10). Raderna i `agent_read_audience` är mängden och
 * `agent.read_audience_until` är fönstrets slut.
 *
 * Läsverktygen noterar genom App\Mcp\Tools\McpTool::noteReads(); skrivverktygen
 * prövar genom App\Support\Agent\CopyGate, som är det enda som anropar
 * permits().
 */
final class ReadAudience
{
    /**
     * Notera en läsning: mängden krymps till snittet med det som redan
     * sparats, och fönstret flyttas fram.
     *
     * @param  list<int>  $userIds  personerna som når det lästa innehållet
     */
    public function note(Agent $agent, array $userIds): void
    {
        $userIds = $this->normalize($userIds, (int) $agent->user_id);
        $until = $this->windowEndsAt();

        DB::transaction(function () use ($agent, $userIds, $until): void {
            if ($this->isOpen($agent)) {
                // Snittet: en person som inte finns i den nya mängden når
                // inte allt agenten har läst och faller bort.
                AgentReadAudience::query()
                    ->where('agent_id', $agent->id)
                    ->whereNotIn('user_id', $userIds)
                    ->delete();
            } else {
                // Inget öppet fönster: den gamla mängden beskriver en tid
                // före fönstret och får inte snittas mot den nya.
                AgentReadAudience::query()->where('agent_id', $agent->id)->delete();
            }

            $known = AgentReadAudience::query()
                ->where('agent_id', $agent->id)
                ->pluck('user_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $missing = array_values(array_diff($userIds, $known));

            if ($missing !== []) {
                $now = now();

                AgentReadAudience::query()->insertOrIgnore(array_map(
                    fn (int $userId): array => [
                        'agent_id' => $agent->id,
                        'user_id' => $userId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $missing,
                ));
            }

            Agent::query()->whereKey($agent->id)->update(['read_audience_until' => $until]);
            $agent->read_audience_until = $until;
        });
    }

    /**
     * Får en skrivning nå `$userIds` utan att någon utanför snittet får
     * åtkomst? Utan ett öppet fönster är svaret alltid ja (Riktning 10:
     * "Utan läsning i fönstret finns ingen spärr").
     *
     * @param  list<int>  $userIds
     */
    public function permits(Agent $agent, array $userIds): bool
    {
        if (! $this->isOpen($agent)) {
            return true;
        }

        $known = AgentReadAudience::query()
            ->where('agent_id', $agent->id)
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_diff($this->normalize($userIds), $known) === [];
    }

    /**
     * Finns ett öppet fönster? Läses ur databasen varje gång, så att svaret
     * inte beror på en modellinstans som laddades före den senaste
     * läsningen.
     */
    private function isOpen(Agent $agent): bool
    {
        $until = Agent::query()->whereKey($agent->id)->value('read_audience_until');

        return $until !== null && now()->lessThan(Carbon::parse($until));
    }

    private function windowEndsAt(): Carbon
    {
        return now()->addHours((int) config('mcp.read_audience_hours'));
    }

    /**
     * @param  list<int>  $userIds
     * @return list<int>
     */
    private function normalize(array $userIds, ?int $always = null): array
    {
        $all = array_map(fn ($id): int => (int) $id, $userIds);

        if ($always !== null) {
            $all[] = $always;
        }

        $all = array_values(array_unique($all));

        sort($all);

        return $all;
    }
}
