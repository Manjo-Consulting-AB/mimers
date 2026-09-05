<?php

namespace App\Models;

use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vad en användare vill ha för en typ och kanal, se [[Notiser]] §
 * notification_preference.
 *
 * Raderna skapas aldrig vid registrering — en SAKNAD rad betyder förvalt
 * värde i kod, och förvalen bor i App\Support\Notification\NotificationPreferences
 * (issue 31a § Beslut 2). Den här tabellen innehåller bara avvikelser, så ett
 * ändrat förval slår igenom för alla som aldrig rört inställningen.
 *
 * Ingen `ulid` och ingen `#[RouteKey]` (Beslut 1) — raden identifieras av sin
 * trippel `(user_id, type, channel)` som klienten redan känner till, samma
 * resonemang som `plan.code`. Ingen SoftDeletes (Beslut 1) — ett bortvalt val
 * är en rad med `enabled = false`, inte en mjukraderad rad.
 *
 * `enabled` och `digest` är boolean-kolumner; i sqlite är de heltal, så båda
 * castas — annars blir `'0'` sant (issue 31a § Att se upp med).
 */
#[Fillable(['user_id', 'type', 'channel', 'enabled', 'digest'])]
class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory;

    /**
     * Tabellen heter `notification_preference`, inte Eloquents
     * standardplural `notification_preferences`.
     */
    protected $table = 'notification_preference';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'digest' => 'boolean',
        ];
    }

    /**
     * Användaren valet gäller — exakt en.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
