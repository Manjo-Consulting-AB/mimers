<?php

namespace App\Actions\Agent;

use App\Models\Agent;
use App\Models\User;
use Laravel\Passport\Passport;

/**
 * Återkallar Passports tokens för en agent, eller för alla en användares
 * agenter, se [[ADR-0087 MCP-server för agenter]] Riktning 12 och issue 400.
 *
 * **Båda tabellerna rörs.** En access token som bara får `revoked = true` går
 * inte längre att använda, och en refresh token vars access token är
 * återkallad avvisas av RefreshTokenGrant — men den ligger kvar som en
 * användbar rad om ingenting rör den. Båda raderna sätts därför, i samma
 * anrop: "återkalla klientens tokens" betyder båda slagen.
 *
 * **Ingen egen transaktion**, samma regel som App\Actions\Security\
 * RecordSecurityEvent: anroparen äger den. `Agent::deleting` anropar den inuti
 * raderingens transaktion, och `ConfirmPasswordChange` inuti lösenordsbytets.
 */
class RevokeAgentTokens
{
    /**
     * Alla tokens som hör till agentens `(user_id, oauth_client_id)`.
     */
    public function handle(Agent $agent): void
    {
        $this->forClient((int) $agent->user_id, (string) $agent->oauth_client_id);
    }

    /**
     * En klientadresserad återkallning, för ersättningen: raden har redan
     * bytt `oauth_client_id` när den gamla klientens tokens ska bort.
     */
    public function forClient(int $userId, string $clientId): void
    {
        $this->revoke($userId, $clientId);
    }

    /**
     * Alla Passport-tokens en användare har, oavsett klient. Används av
     * lösenordsbytet, som återkallar agenternas tokens på samma sätt som
     * Sanctums — raderna i `agent` står kvar (issue 400 § 7).
     */
    public function forUser(User $user): void
    {
        $this->revoke($user->getKey(), null);
    }

    private function revoke(int $userId, ?string $clientId): void
    {
        $tokens = Passport::tokenModel()::query()
            ->where('user_id', $userId)
            ->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId))
            ->get();

        if ($tokens->isEmpty()) {
            return;
        }

        foreach ($tokens as $token) {
            $token->revoke();
        }

        Passport::refreshTokenModel()::query()
            ->whereIn('access_token_id', $tokens->pluck('id')->all())
            ->update(['revoked' => true]);
    }
}
