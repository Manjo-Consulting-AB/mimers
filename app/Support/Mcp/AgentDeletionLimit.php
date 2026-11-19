<?php

namespace App\Support\Mcp;

use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use Illuminate\Support\Carbon;

/**
 * Raderingstaket — högst 20 raderingar per timme och agent — se
 * [[ADR-0087 MCP-server för agenter]] Riktning 9 och 13 och issue 406 § 5.
 *
 * **Taket räknar lyckade raderingar, inte anrop.** Räknaren är
 * händelseloggen: varje action skriver sin `*.deleted`-rad i samma transaktion
 * som raderingen, och ett avvisat försök skriver ingen rad. En agent som
 * famlar mot en saknad ULID bränner alltså inte sin kvot.
 *
 * **Taket släpps av notisen, inte av tiden.** Fönstret är en timme, men
 * räkningen börjar om vid det senaste `agent.deleted` som användaren LÄST i
 * appen. När notisen är läst går nästa radering igenom direkt, oavsett hur
 * många som hunnit ske inom timmen; utan läsningen står taket kvar.
 * Notisen skapas 15 minuter efter den sista raderingen, så den som blir
 * stoppad behöver aldrig vänta på en ny notis (Riktning 9: avvisade försök
 * förlänger inte tillfället).
 *
 * Gränsen och fönstret läses ur `config('mcp.rate_limits.delete.agent')`, samma
 * post som beskriver slaget i Riktning 13 — McpRateLimiter hoppar över just
 * den posten för radering, eftersom en tidsstyrd hink hade stängt ute
 * användarens läsning som väg tillbaka.
 */
final class AgentDeletionLimit
{
    public function assertCanDelete(Agent $agent): void
    {
        [$max, $windowSeconds] = $this->limits();

        // En oläst notis för ett tillfälle som nådde taket håller kvar
        // spärren också efter timmen. Utan den hade taket släppt av sig självt
        // när raderingarna föll utanför fönstret, och "inte efter en viss tid"
        // i issue 406 § 5 vore tomma ord.
        if ($this->unreadLimitNotification($agent, $max)) {
            throw new McpToolException('agent.delete_limit', [
                'limit' => $max,
                'window_seconds' => $windowSeconds,
            ]);
        }

        $cutoff = now()->subSeconds($windowSeconds);
        $readAt = $this->lastReadAt($agent);

        // Läsningen är den nya startpunkten när den ligger inom fönstret.
        $since = $readAt !== null && $readAt->gt($cutoff) ? $readAt : $cutoff;

        $count = AuditLog::query()
            ->where('agent_id', $agent->getKey())
            ->whereIn('action', AgentDeletion::DELETE_ACTIONS)
            ->where('created_at', '>', $since)
            ->count();

        if ($count >= $max) {
            throw new McpToolException('agent.delete_limit', [
                'limit' => $max,
                'window_seconds' => $windowSeconds,
            ]);
        }
    }

    /**
     * Den senaste `agent.deleted` för agentens användare är oläst och
     * tillfället nådde taket. Då väntar agenten på att användaren öppnar
     * notisen, oavsett hur länge sedan raderingarna hände.
     */
    private function unreadLimitNotification(Agent $agent, int $max): bool
    {
        $notification = Notification::query()
            ->with(['deliveries' => function ($query): void {
                $query->where('channel', NotificationDelivery::CHANNEL_IN_APP);
            }])
            ->where('user_id', $agent->user_id)
            ->where('type', Notification::TYPE_AGENT_DELETED)
            ->where('dedupe_key', 'like', 'agent.deleted:'.$agent->ulid.':%')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id', 'payload']);

        $delivery = $notification?->deliveries->first();

        if ($notification === null || $delivery === null || $delivery->read_at !== null) {
            return false;
        }

        return (int) ($notification->payload['total'] ?? 0) >= $max;
    }

    /**
     * Det senaste `agent.deleted` som agentens användare läst i appen.
     * E-postens leveransrad räknas aldrig: taket släpps bara av notisen i
     * appen, som inte går att stänga av (Riktning 14). Nyckeln binder
     * läsningen till DEN HÄR agenten — en annan agents notis släpper inte
     * taket, eftersom `dedupe_key` bär agentens ULID.
     */
    private function lastReadAt(Agent $agent): ?Carbon
    {
        $readAt = NotificationDelivery::query()
            ->where('channel', NotificationDelivery::CHANNEL_IN_APP)
            ->whereNotNull('read_at')
            ->whereHas('notification', function ($query) use ($agent): void {
                $query->where('user_id', $agent->user_id)
                    ->where('type', Notification::TYPE_AGENT_DELETED)
                    ->where('dedupe_key', 'like', 'agent.deleted:'.$agent->ulid.':%');
            })
            ->max('read_at');

        return $readAt === null ? null : Carbon::parse($readAt);
    }

    /**
     * @return array{0: int, 1: int} antal och sekunder
     */
    private function limits(): array
    {
        $limits = (array) config('mcp.rate_limits.delete.agent', [[20, 3600]]);
        $first = is_array($limits[0] ?? null) ? $limits[0] : [];

        return [
            (int) ($first[0] ?? 20),
            (int) ($first[1] ?? 3600),
        ];
    }
}
