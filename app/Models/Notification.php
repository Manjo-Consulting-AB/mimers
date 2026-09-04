<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Utboksraden — "vad som hänt, till vem", se [[Notiser]] § notification och
 * [[ADR-0010 Notisarkitektur]] § Beslut. En notis skapas som en rad och
 * levereras sedan av kön, aldrig synkront i ett request; den här modellen är
 * systemets bokföring, inte ett meddelande som skickas.
 *
 * Den ENDA vägen in är App\Actions\Notification\CreateNotification (issue 30
 * § Beslut 7) — allt är därför UTESLUTET ur `#[Fillable]`, sätts explicit av
 * Actionen och aldrig via massilldelning, samma resonemang som
 * App\Models\ScheduleOccurrence.
 *
 * Ingen `deleted_at` (issue 30 § Beslut 2): en notis är systemgenererad
 * bokföring, inte användarskapat innehåll. Raderas den raderas den hårt av
 * kontots/containerpapperskorgens städning (Beslut 8).
 *
 * `payload` bär data, aldrig text (Beslut 5): den renderas per kanal och
 * språk vid leverans (32a). `type` är ett ÖPPET namnrum (Beslut 4) — listan i
 * [[Notiser]] räknar upp sju kända värden men fler kan komma, så det finns
 * ingen `TYPES`-lista här, bara konstanterna så att generatorerna (34b)
 * aldrig stavar en sträng.
 *
 * OBS: namnet kolliderar med `Illuminate\Notifications\Notification`.
 * Importera aldrig den blint i en fil som också använder den här klassen —
 * felet ser ut som ett stavfel och är det inte (issue 30 § Att se upp med).
 */
#[Fillable([])]
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory, HasUlid;

    /**
     * Tabellen heter `notification`, inte Eloquents standardplural
     * `notifications`.
     */
    protected $table = 'notification';

    public const TYPE_TASK_DUE = 'task.due';

    public const TYPE_TASK_OVERDUE = 'task.overdue';

    public const TYPE_LOAN_DUE = 'loan.due';

    public const TYPE_QUOTA_WARNING = 'quota.warning';

    public const TYPE_INVITATION_RECEIVED = 'invitation.received';

    public const TYPE_TRANSFER_REQUESTED = 'transfer.requested';

    public const TYPE_ACCOUNT_INACTIVE = 'account.inactive';

    /**
     * Get the attributes that should be cast.
     *
     * `payload` är JSON (i sqlite en TEXT-kolumn) och castas till array —
     * utan castet vore den en sträng och varje jämförelse ser rätt ut på fel
     * sätt (issue 30 § Att se upp med). `available_at` är en tidsstämpel.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
        ];
    }

    /**
     * Leveransraderna för notisen — en per kanal. Det är här idempotensen
     * bor: unik `(notification_id, channel)` ([[Notiser]] §
     * notification_delivery).
     *
     * @return HasMany<NotificationDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    /**
     * Mottagaren — NULL för rena webhook-händelser till ett konto
     * ([[Notiser]] § notification).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Kontot notisen hör till — alltid satt; även en webhook-händelse har ett
     * konto.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Containern händelsen gäller, när den gäller en — NULL för kontonivå-
     * händelser som `account.inactive`.
     *
     * @return BelongsTo<Container, $this>
     */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    /**
     * Vad notisen handlar om — oftast en `schedule_occurrence`
     * ([[Notiser]] § notification). Polymorf, så att samma tabell kan peka på
     * vilken modell som helst utan nya kolumner.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
