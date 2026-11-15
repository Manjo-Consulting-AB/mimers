<?php

namespace App\Http\Requests;

use App\Models\NotificationDelivery;
use App\Support\Notification\NotificationPreferences;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/me/notification-preferences, se issue 31b § Beslut 4. Kroppen är
 * `{"preferences": [{"type", "channel", "enabled", "digest"}, ...]}` — en
 * UPSERT av en delmängd, inte en ersättning av hela listan: typer som inte
 * nämns rörs inte.
 *
 * `type` prövas mot NotificationPreferences::types(). Listan är nycklarna i
 * 31a-klassens EMAIL_DEFAULTS, i den ordning listan ska ritas — samma lista
 * GET läser upp (Beslut 2). Issuen pekade på `Notification::TYPES` (från
 * issue 30 § Beslut 4), men modellen har ingen sådan lista: docblocken säger
 * uttryckligen att typnamnrymden är ÖPPEN och att det medvetet inte finns
 * någon TYPES-lista, bara konstanterna. Listan bor därför i
 * NotificationPreferences::types(), se Frågor och antaganden i PR:en.
 *
 * `channel` är en sluten mängd: `email` och `in_app` accepteras (issue 386,
 * [[ADR-0077 Notislistan i appen]] § 4). `digest` godtas bara för `email`;
 * för `in_app` måste den vara falsk, en rad i listan är ingen sammanfattning.
 * Webhooken är
 * en integration ett konto registrerat med egna `event_types` och frågar
 * aldrig den här tabellen (31a § Beslut 4).
 *
 * Inga domänbeslut i klassen: `authorize()` är alltid sant och kontrollern
 * utgår från `$request->user()` (Beslut 3) — ingen policy, ingen Gate.
 */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(NotificationPreferences $preferences): array
    {
        return [
            'preferences' => ['required', 'array', 'min:1', 'max:50'],
            'preferences.*.type' => ['required', 'string', Rule::in($preferences->types())],
            'preferences.*.channel' => ['required', 'string', Rule::in([NotificationDelivery::CHANNEL_EMAIL, NotificationDelivery::CHANNEL_IN_APP])],
            'preferences.*.enabled' => ['required', 'boolean'],
            'preferences.*.digest' => ['required', 'boolean', 'declined_if:preferences.*.channel,'.NotificationDelivery::CHANNEL_IN_APP],
        ];
    }
}
