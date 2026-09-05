<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En leveransrad per endpoint och notis — "utfläkningen" av en notis till ett
 * kontos webhook-endpoints, se [[Notiser]] § Webhooks och issue 37b.
 *
 * Raden skapas när notisen skapas (Beslut 3), av
 * App\Actions\Notification\CreateNotification, som en `pending`-rad per aktiv
 * endpoint som prenumererar på notisens typ. Själva anropet görs av
 * App\Console\DeliversWebhooks, som också är den ENDA platsen som sätter
 * `status`, `attempts`, `response_status`, `last_error`, `next_attempt_at`
 * och `delivered_at`.
 *
 * `ulid` finns för att en leverans ska gå att peka ut i en supportfråga utan
 * att löpnumret läcker (Beslut 1), även om ingen rutt exponerar den i MVP.
 * Inget `deleted_at` — raderna är systemgenererad bokföring, och städningen
 * vid kontoradering ligger i DeleteAccount.
 *
 * `status` är en sluten mängd — värdena ligger i migrationens CHECK-villkor
 * och som konstanter här, så loopen och utfläkningen aldrig stavar strängarna.
 * Ingen `suppressed`: undertryckning gäller e-postadresser, inte webhooks.
 */
#[Fillable([])]
class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory, HasUlid;

    /**
     * Tabellen heter `webhook_delivery`, inte Eloquents standardplural.
     */
    protected $table = 'webhook_delivery';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /**
     * De giltiga statusvärdena, se migrationens CHECK-villkor.
     *
     * @var list<string>
     */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_SENT, self::STATUS_FAILED];

    /**
     * Get the attributes that should be cast.
     *
     * `attempts` och `response_status` är SMALLINT UNSIGNED, `next_attempt_at`
     * och `delivered_at` tidsstämplar — i sqlite (testsviten) ligger heltal
     * och boolean som text respektive heltal, och utan casten vore
     * jämförelserna fel (issue 30 § Att se upp med).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'response_status' => 'integer',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * Endpointen leveransen går till — exakt en. Nyckeln är ON DELETE
     * RESTRICT, så endpointen finns alltid kvar så länge raden finns; en
     * endpoint som inaktiverats (is_active = false) får inga NYA rader, men
     * rader som redan ligger `pending` fortsätter försöka (issue 37b § Att se
     * upp med).
     *
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /**
     * Notisen leveransen förmedlar — exakt en.
     *
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
