<?php

namespace App\Support\Mcp;

use App\Models\Agent;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Rate limiting per agent och per konto, se [[ADR-0087 MCP-server för
 * agenter]] Riktning 13. Värdena står i `config('mcp.rate_limits')`.
 *
 * **Alla gränser prövas innan någon räknas.** Annars skulle ett anrop som
 * stoppas av kontots gräns ändå ha förbrukat agentens, och en agent kunde
 * tömma sin egen budget på anrop som aldrig kördes.
 *
 * Uppladdning räknas också i den befintliga `uploads`-gränsen (60 per minut
 * och användare, config/files.php), med samma nyckel som
 * ThrottleRequests bygger för den.
 */
final class McpRateLimiter
{
    /**
     * Räkna ett anrop. Returnerar sekunderna till nästa tillfälle om någon
     * gräns är nådd, annars `null` — och då är anropet räknat.
     */
    public function attempt(Agent $agent, ToolKind $kind): ?int
    {
        $buckets = $this->buckets($agent, $kind);

        $wait = 0;

        foreach ($buckets as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $wait = max($wait, RateLimiter::availableIn($key));
            }
        }

        if ($wait > 0) {
            return $wait;
        }

        foreach ($buckets as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }

        return null;
    }

    /**
     * @return list<array{0: string, 1: int, 2: int}> nyckel, tak, sekunder
     */
    private function buckets(Agent $agent, ToolKind $kind): array
    {
        $buckets = [];
        $limits = (array) config('mcp.rate_limits.'.$kind->value, []);

        foreach ((array) ($limits['agent'] ?? []) as [$max, $decay]) {
            $buckets[] = ['mcp:agent:'.$agent->id.':'.$kind->value.':'.$decay, (int) $max, (int) $decay];
        }

        foreach ((array) ($limits['account'] ?? []) as [$max, $decay]) {
            $buckets[] = ['mcp:account:'.$agent->account_id.':'.$kind->value.':'.$decay, (int) $max, (int) $decay];
        }

        if ($kind === ToolKind::Upload) {
            $buckets[] = [
                md5('uploads'.$agent->user_id),
                (int) config('files.upload_rate_limit_per_minute', 60),
                60,
            ];
        }

        return $buckets;
    }
}
