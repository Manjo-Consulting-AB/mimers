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
 * Beslut 7). E-posten skrivs som en `pending`-rad och plockas av kön (34a);
 * `in_app` skrivs som `sent` i samma transaktion (issue 383), eftersom
 * listan läses direkt ur raden och ingen kö behövs.
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
     * Listan i appen, se [[ADR-0077 Notislistan i appen]] § 1. Raden skrivs
     * direkt med status `sent` av CreateNotification — ingen kö, och tysta
     * timmar gäller inte kanalen (§ 6). Läst-läget är `read_at` på raden.
     */
    public const CHANNEL_IN_APP = 'in_app';

    /**
     * De giltiga kanalvärdena, se migrationens CHECK-villkor.
     *
     * @var list<string>
     */
    public const CHANNELS = [self::CHANNEL_EMAIL, self::CHANNEL_WEBHOOK, self::CHANNEL_IN_APP];

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
     * Hur länge en `in_app`-rad visas i listan — 90 dagar från att notisen
     * skapades, läst eller inte ([[ADR-0077 Notislistan i appen]] § 5).
     * Samma tal används av listan, räknaren och gallringen; att det bor här
     * gör att de tre inte kan glida isär.
     */
    public const IN_APP_RETENTION_DAYS = 90;

    /**
     * Get the attributes that should be cast.
     *
     * `attempts` är SMALLINT UNSIGNED, `sent_at` en tidsstämpel och `digest`
     * en boolean — i sqlite (testsviten) ligger boolean som ett heltal, och
     * utan castet vore `'0'` sant (issue 35 § Att se upp med). `claimed_at`
     * är tidpunkten en leveransloop tog raden i anspråk (issue 1056) och är
     * `null` när ingen håller den. `read_at` är `in_app`-kanalens läst-läge
     * (issue 383): `null` = oläst, och kolumnen rörs aldrig av e-postraden.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'claimed_at' => 'datetime',
            'read_at' => 'datetime',
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
