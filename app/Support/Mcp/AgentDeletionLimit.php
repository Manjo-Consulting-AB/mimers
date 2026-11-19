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
 * räkningen börjar om när användaren LÄST notisen för det tillfälle som
 * utlöste taket — inte vilken `agent.deleted` som helst. Att läsa en äldre
 * notis medan det aktuella tillfället ännu inte fått sin får alltså inte
 * släppa spärren (granskningsfynd 6). När rätt notis är läst går nästa
 * radering igenom direkt, oavsett hur många som hunnit ske inom timmen; utan
 * läsningen står taket kvar. Notisen skapas 15 minuter efter den sista
 * raderingen, så den som blir stoppad behöver aldrig vänta på en ny notis
 * (Riktning 9: avvisade försök förlänger inte tillfället).
 *
 * **Tillfället som utlöste taket är den max:te raderingen sedan den senaste
 * frigöringen.** Räkningen sker i tidsordning, och den raderingen bär
 * batchen vars notis är nyckeln tillbaka. Det gör uppslaget oberoende av
 * vilken ordning notiserna skapades i.
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

        $since = now()->subSeconds($windowSeconds);

        // Räkningen börjar om vid den läsning som gäller — notisen för
        // tillfället som utlöste taket. Loopen är sällan mer än ett varv:
        // den går vidare bara när en tidigare frigöring redan ligger i
        // fönstret.
        while (true) {
            $rows = AuditLog::query()
                ->where('agent_id', $agent->getKey())
                ->whereIn('action', AgentDeletion::DELETE_ACTIONS)
                ->where('created_at', '>', $since)
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($max)
                ->get(['id', 'created_at', 'meta']);

            if ($rows->count() < $max) {
                return;
            }

            // Den max:te raderingen sedan startpunkten utlöste taket; dess
            // batch är tillfället användaren måste ha öppnat notisen för.
            $batch = $rows->last()->meta['agent_deletion_batch'] ?? null;

            $readAt = is_string($batch) && $batch !== ''
                ? $this->readAtForBatch($agent, $batch)
                : null;

            // Ingen läsning av RÄTT notis: spärren står kvar. Att en annan
            // `agent.deleted` är läst flyttar ingenting.
            if ($readAt === null || ! $readAt->gt($since)) {
                throw new McpToolException('agent.delete_limit', [
                    'limit' => $max,
                    'window_seconds' => $windowSeconds,
                ]);
            }

            // Läsningen flyttar startpunkten: det som räknats är avräknat,
            // och bara raderingar efter läsningen räknas.
            $since = $readAt;
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
     * När agentens användare läste notisen för EXAKT den batchen, eller null
     * om den inte är läst (eller inte finns ännu).
     *
     * E-postens leveransrad räknas aldrig: taket släpps bara av notisen i
     * appen, som inte går att stänga av (Riktning 14). Dedupe-nyckeln bär
     * agenten, batchen och mottagaren, så uppslaget kan inte träffa en annan
     * agents eller en annan batchs notis.
     */
    private function readAtForBatch(Agent $agent, string $batch): ?Carbon
    {
        $readAt = NotificationDelivery::query()
            ->where('channel', NotificationDelivery::CHANNEL_IN_APP)
            ->whereNotNull('read_at')
            ->whereHas('notification', function ($query) use ($agent, $batch): void {
                $query->where('user_id', $agent->user_id)
                    ->where('type', Notification::TYPE_AGENT_DELETED)
                    ->where('dedupe_key', 'like', 'agent.deleted:'.$agent->ulid.':'.$batch.':%');
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
