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
 * [[Notiser]] räknar upp sex kända värden men fler kan komma, så det finns
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

    public const TYPE_TRANSFER_REQUESTED = 'transfer.requested';

    public const TYPE_ACCOUNT_INACTIVE = 'account.inactive';

    public const TYPE_CONTAINER_DELETED = 'container.deleted';

    /**
     * Ägaren av en container varnas innan ett nedgraderat konto lämnar sitt
     * innehåll där (issue 1304 · ADR-0081 § 6a). Ingen webhooktyp.
     */
    public const TYPE_CONTENT_MOVING = 'container.content_moving';

    /**
     * En ny agent anslöts, se [[ADR-0087 MCP-server för agenter]] Riktning 14
     * och issue 400 § 5. Går bara som mejl, kan inte stängas av i
     * App\Support\Notification\NotificationPreferences och samlas aldrig i
     * veckosammanfattningen.
     *
     * **Ingen `in_app`-kanal.** Klockan i appen visar inte anslutningar; ett
     * nytt godkännande är något användaren själv just gjorde, och den enda
     * ytan som behöver säga det är mejlet — som också bär den signerade
     * bortkopplingslänken.
     */
    public const TYPE_AGENT_CONNECTED = 'agent.connected';

    /**
     * En agent raderade innehåll, se [[ADR-0087 MCP-server för agenter]]
     * Riktning 8, 9 och 14 och issue 406.
     *
     * En notis per tillfälle och mottagare, skapad 15 minuter efter den sista
     * raderingen. Den går till agentens användare, och till containerns ägare
     * när det är någon annan. **Kanalen `in_app` kan inte stängas av** —
     * läsningen av notisen är det som släpper raderingstaket (Riktning 13) —
     * men mejlet kan stängas av, se
     * App\Support\Notification\NotificationPreferences.
     */
    public const TYPE_AGENT_DELETED = 'agent.deleted';

    /**
     * Ägaren av en container gick till Gratis och användarens agenter tappade
     * åtkomsten dit, se [[ADR-0087 MCP-server för agenter]] Riktning 11 och 14
     * och issue 409. En notis per användare och ägare, inte per container.
     * Går i appen och som mejl, och ingen av kanalerna kan stängas av — se
     * App\Support\Notification\NotificationPreferences.
     */
    public const TYPE_AGENT_ACCESS_LOST = 'agent.access_lost';

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
