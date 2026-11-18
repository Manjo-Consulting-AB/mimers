<?php

namespace App\Models;

use App\Actions\Agent\RevokeAgentTokens;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

/**
 * En ansluten AI-agent — en MCP-klient som användaren godkänt via OAuth, se
 * [[ADR-0087 MCP-server för agenter]] Riktning 3, 6 och 12 och
 * [[Konton och åtkomst]] § agent.
 *
 * **En rad per användare och OAuth-klient.** Ett nytt godkännande från samma
 * klient återanvänder raden med namn, rättigheter och paus; en ny klient kan
 * ersätta en befintlig genom att `oauth_client_id` byts (issue 400). Taket på
 * tio räknar rader, inte godkännanden.
 *
 * **Agenten börjar utan åtkomst.** Rättigheterna bor i
 * `agent_account_grant` och `agent_container_grant` (issue 401), och en
 * saknad rad betyder ingen åtkomst. Den här modellen bär bara anslutningen.
 *
 * **Tokens hör till raden, inte till modellen.** Passports tokens ligger i
 * `oauth_access_tokens` och nyckas på `(user_id, client_id)`; relationen
 * `accessTokens()` är läsvägen och `App\Actions\Agent\RevokeAgentTokens` är
 * den enda vägen att ta bort dem. Raderingen av en agent återkallar dem — det
 * är därför `booted()` nedan finns, och därför en hård radering inte lämnar
 * en fungerande token efter sig.
 *
 * **Hörnet: en kaskad i databasen avfyrar inga händelser.** `booted()` fångar
 * Eloquents radering (`$agent->delete()`), inte `ON DELETE CASCADE`. Raderas
 * personkontot eller personen försvinner agentraden därför utan att
 * `deleting` körs, och tokens ligger kvar i `oauth_access_tokens`. Blir de
 * obrukbara beror det på att personen själv är borta — en raderad
 * personkonto-rad lämnar alltså giltiga tokens utan agentrad. Issue 402
 * stänger hörnet genom att kräva att agentraden finns vid varje anrop.
 *
 * Den ENDA vägen in är actions under `App\Actions\Agent`, så allt är uteslutet
 * ur `#[Fillable]` och sätts explicit — samma resonemang som
 * App\Models\Notification.
 */
#[Fillable([])]
#[RouteKey('ulid')]
class Agent extends Model
{
    use HasUlid;

    /**
     * Tabellen heter `agent`, inte Eloquents standardplural.
     */
    protected $table = 'agent';

    /**
     * Rättigheterna och tokens följer raden. En Eloquent-radering — från
     * `/settings/agents` (issue 407) eller från mejlets signerade länk
     * (`App\Actions\Agent\DisconnectAgent`) — ska lämna varken en användbar
     * access token eller refresh token efter sig. En kaskad i databasen
     * avfyrar däremot ingen `deleting`-händelse; se klassens docblock.
     */
    protected static function booted(): void
    {
        static::deleting(function (Agent $agent): void {
            app(RevokeAgentTokens::class)->handle($agent);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paused_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Personkontot agentens anslutning och plan hör till — inte det konto en
     * container råkar ägas av ([[ADR-0087 MCP-server för agenter]] Riktning 4).
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Agentens tokens — Passports access tokens för parets skull, alltså
     * `(user_id, client_id)`. Ingen främmande nyckel binder dem till raden:
     * ett nytt godkännande återanvänder agenten, men tokens skapas och
     * återkallas av Passport.
     *
     * @return HasMany<Token, $this>
     */
    public function accessTokens(): HasMany
    {
        return $this->hasMany(Passport::tokenModel(), 'user_id', 'user_id')
            ->where('client_id', $this->oauth_client_id);
    }

    /**
     * Är agenten pausad? Under pausen avvisas varje anrop (issue 402), men
     * rättigheter, påslag och tokens står kvar (Riktning 12).
     */
    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }
}
