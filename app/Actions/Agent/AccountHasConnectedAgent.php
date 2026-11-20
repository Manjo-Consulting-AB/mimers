<?php

namespace App\Actions\Agent;

use App\Models\Account;
use App\Models\Agent;
use Illuminate\Support\Facades\DB;

/**
 * Har kontot minst en agent med en giltig anslutning? Frågan ställs av
 * nedgraderingsvarningen (issue 1457 · [[Planer och kvoter]] § Nedgradering
 * och [[ADR-0087 MCP-server för agenter]] Riktning 11): meningen om att MCP
 * stängs ska bara stå med när det faktiskt finns en anslutning att stänga.
 *
 * **Giltig betyder en token som fortfarande fungerar.** En rad i `agent` är
 * anslutningen, men raden står kvar när tokens återkallas — ett lösenordsbyte
 * gör precis det (App\Actions\Agent\RevokeAgentTokens::forUser) — och en
 * agent utan token når ingenting redan i dag. Att räkna rader hade alltså
 * lovat att något stängs som redan är stängt. Kravet är därför en
 * access-token för parets `(user_id, client_id)` som varken är återkallad
 * eller utgången.
 *
 * **Pausen räknas inte.** En pausad agent har kvar sin token och återupptas
 * med ett klick (App\Actions\Agent\SetAgentPaused); anslutningen är giltig,
 * och varningen säger att den inte fungerar när planen tar slut.
 *
 * Frågan bor här och inte i varningsactionen därför att villkoret är agentens:
 * nästa yta som behöver samma svar ska inte formulera det en andra gång.
 */
class AccountHasConnectedAgent
{
    public function handle(Account $account): bool
    {
        return Agent::query()
            ->where('agent.account_id', $account->getKey())
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('oauth_access_tokens')
                    ->whereColumn('oauth_access_tokens.user_id', 'agent.user_id')
                    ->whereColumn('oauth_access_tokens.client_id', 'agent.oauth_client_id')
                    ->where('oauth_access_tokens.revoked', false)
                    ->where(function ($token): void {
                        $token->whereNull('oauth_access_tokens.expires_at')
                            ->orWhere('oauth_access_tokens.expires_at', '>', now());
                    });
            })
            ->exists();
    }
}
