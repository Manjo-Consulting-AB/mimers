<?php

namespace App\Mcp\Middleware;

use App\Models\Agent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hittar agentraden bakom tokenen och sätter `agent.last_used_at`, se
 * [[ADR-0087 MCP-server för agenter]] Riktning 3 och issue 402.
 *
 * Tokens ligger i `oauth_access_tokens` och agenten på
 * `(user_id, oauth_client_id)`. Saknas raden — den hård-raderades med en
 * kaskad, se App\Models\Agent § hörnet — är tokenen värdelös och svaret
 * 401, så en token utan agent aldrig når ett verktyg.
 *
 * Pausad agent och plan prövas inte här utan i verktygen, så att de blir
 * verktygsfel med `hint` (App\Mcp\Tools\McpTool). `last_used_at` sätts för
 * varje anrop men högst en gång per minut: en skrivning per anrop hade gjort
 * varje läsning till en UPDATE.
 */
class ResolveAgent
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('mcp');
        // Passports AccessToken bär klienten som attribut; `data_get` läser den
        // utan att kräva Passports typ — användaren har Sanctums HasApiTokens.
        $clientId = data_get($user?->currentAccessToken(), 'oauth_client_id');

        $agent = $clientId === null ? null : Agent::query()
            ->where('user_id', $user->getKey())
            ->where('oauth_client_id', (string) $clientId)
            ->first();

        if ($agent === null) {
            return response()->json(['error' => ['code' => 'auth.unauthenticated', 'data' => new \stdClass]], 401)
                ->header('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.route('mcp.oauth.protected-resource.nested', ['path' => $request->path()]).'"');
        }

        $interval = (int) config('mcp.last_used_interval_seconds', 60);

        if ($agent->last_used_at === null || $agent->last_used_at->lte(now()->subSeconds($interval))) {
            // Utan `updated_at`: användningen är ingen ändring av anslutningen.
            $agent->timestamps = false;
            $agent->last_used_at = now();
            $agent->save();
            $agent->timestamps = true;
        }

        $request->attributes->set('mcp.agent', $agent);

        return $next($request);
    }
}
