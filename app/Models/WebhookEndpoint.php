<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En registrerad webhook-mottagare — den URL ett konto vill att notiserna
 * skickas till, se [[Notiser]] § webhook_endpoint och issue 37a. Hör till ett
 * konto (aldrig en container): kontot äger URL:en, betalar för funktionen och
 * är det vars plan grinden läser.
 *
 * `secret` är en 64-tecken slump (Str::random(64)) som krypteras i kolumnen
 * med `encrypted`-casten, precis som `user.totp_secret` — servern måste kunna
 * läsa den igen för att signera varje leverans med HMAC-SHA256 (37b), så den
 * hashas aldrig (issue 37a § Beslut 2). Den visas i klartext EN gång, i
 * POST-svaret, och är dold för serialisering överallt annars (#[Hidden]).
 *
 * `url`, `event_types` och `is_active` är massilldelningsbara — de tre fälten
 * PATCH ändrar (Beslut 3). `account_id`, `secret` och `consecutive_failures`
 * sätts aldrig via massilldelning: kontot och hemligheten av kontrollern vid
 * skapandet, och räknaren ägs av 37b (undantaget: en återaktivering nollställer
 * den, se WebhookEndpointController::update).
 *
 * Ingen SoftDeletes (Beslut 1): en endpoint som ska bort tas bort på riktigt.
 *
 * `event_types` är en JSON-lista av notistyper endpointen prenumererar på.
 * `EVENT_TYPES` här är de KÄNDA typerna just nu, i valideringssyfte. Notisens
 * typnamnrymd är öppen (App\Models\Notification), så listan fylls på när en ny
 * generator lägger till en typ — den är en ögonblicksbild, inte en spärr.
 */
#[Fillable(['url', 'event_types', 'is_active'])]
#[Hidden(['secret'])]
#[RouteKey('ulid')]
class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory, HasUlid;

    /**
     * Tabellen heter `webhook_endpoint`, i singular liksom `invitation`,
     * `container` och `user` — se AGENTS.md § Databaskonventioner.
     */
    protected $table = 'webhook_endpoint';

    /**
     * De kända notistyperna en endpoint kan prenumerera på — de sju
     * typkonstanterna i App\Models\Notification. Listan finns här (och inte på
     * Notification) därför att Notification medvetet saknar en TYPES-lista:
     * typnamnrymden är öppen (issue 30 § Beslut 4). Se Frågor och antaganden i
     * PR:en.
     *
     * @var list<string>
     */
    public const EVENT_TYPES = [
        Notification::TYPE_TASK_DUE,
        Notification::TYPE_TASK_OVERDUE,
        Notification::TYPE_LOAN_DUE,
        Notification::TYPE_QUOTA_WARNING,
        Notification::TYPE_INVITATION_RECEIVED,
        Notification::TYPE_TRANSFER_REQUESTED,
        Notification::TYPE_ACCOUNT_INACTIVE,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * `secret` är `encrypted` — krypterad i kolumnen, läsbar av servern för
     * HMAC-signeringen i 37b. `event_types` är JSON. `consecutive_failures`
     * skrivs av 37b och nollställs av en återaktivering (Beslut 3).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'event_types' => 'array',
            'is_active' => 'boolean',
            'consecutive_failures' => 'integer',
        ];
    }

    /**
     * Kontot endpointen hör till. Exakt ett — en webhook är alltid knuten till
     * det konto som registrerat den och som betalar för funktionen.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
