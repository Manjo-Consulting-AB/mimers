<?php

namespace App\Models;

use Database\Factories\NotificationDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En leveransrad per notis och kanal — "det är här idempotensen bor", se
 * [[Notiser]] § notification_delivery och [[ADR-0010 Notisarkitektur]].
 *
 * Skapas bara av App\Actions\Notification\CreateNotification (issue 30 §
 * Beslut 7), som en `pending`-rad för e-post; kanalen väljs utifrån
 * användarens preferenser först i 31a, och ingenting plockar `pending`-rader
 * förrän 34a. Ingenting skickas av den här issuen.
 *
 * Ingen `ulid` och ingen `#[RouteKey]` — raden är en leveranskvittens som
 * aldrig syns i API:et, den läses genom sin notis. Ingen SoftDeletes — en
 * notis är systemgenererad bokföring, inte användarskapat innehåll
 * (Beslut 2).
 *
 * `channel` och `status` är slutna mängder (Beslut 4) — värdena ligger i
 * migrationens CHECK-villkor och som konstanter här, så leveransloopen (34a)
 * och kanalerna (32a, 37b) aldrig stavar strängarna.
 */
#[Fillable([])]
class NotificationDelivery extends Model
{
    /** @use HasFactory<NotificationDeliveryFactory> */
    use HasFactory;

    /**
     * Tabellen heter `notification_delivery`, inte Eloquents standardplural.
     */
    protected $table = 'notification_delivery';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_WEBHOOK = 'webhook';

    /**
     * De giltiga kanalvärdena, se migrationens CHECK-villkor.
     *
     * @var list<string>
     */
    public const CHANNELS = [self::CHANNEL_EMAIL, self::CHANNEL_WEBHOOK];

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SUPPRESSED = 'suppressed';

    /**
     * De giltiga statusvärdena, se migrationens CHECK-villkor.
     *
     * @var list<string>
     */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_SUPPRESSED];

    /**
     * Get the attributes that should be cast.
     *
     * `attempts` är SMALLINT UNSIGNED, `sent_at` en tidsstämpel och `digest`
     * en boolean — i sqlite (testsviten) ligger boolean som ett heltal, och
     * utan castet vore `'0'` sant (issue 35 § Att se upp med).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'digest' => 'boolean',
        ];
    }

    /**
     * Notisen leveransraden hör till — exakt en.
     *
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
