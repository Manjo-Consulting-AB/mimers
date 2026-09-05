<?php

namespace App\Http\Requests;

use App\Models\Notification;
use App\Models\NotificationDelivery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/me/notification-preferences, se issue 31b § Beslut 4. Kroppen är
 * `{"preferences": [{"type", "channel", "enabled", "digest"}, ...]}` — en
 * UPSERT av en delmängd, inte en ersättning av hela listan: typer som inte
 * nämns rörs inte.
 *
 * `type` prövas mot TYPES nedan. Listan är typerna i Notification-modellen,
 * i konstanternas deklarationsordning — samma lista GET läser upp (Beslut
 * 2). Issuen pekade på `Notification::TYPES` (från issue 30 § Beslut 4),
 * men modellen har ingen sådan lista: docblocken säger uttryckligen att
 * typnamnrymden är ÖPPEN och att det medvetet inte finns någon TYPES-lista,
 * bara konstanterna. Listan bor därför här, som enda ställe, se Frågor och
 * antaganden i PR:en.
 *
 * `channel` är en sluten mängd och bara `email` accepteras — webhooken är
 * en integration ett konto registrerat med egna `event_types` och frågar
 * aldrig den här tabellen (31a § Beslut 4).
 *
 * Inga domänbeslut i klassen: `authorize()` är alltid sant och kontrollern
 * utgår från `$request->user()` (Beslut 3) — ingen policy, ingen Gate.
 */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    /**
     * De typer en användare kan ställa in för kanalen `email`, i samma ordning
     * som konstanterna deklareras i App\Models\Notification.
     *
     * @var list<string>
     */
    public const TYPES = [
        Notification::TYPE_TASK_DUE,
        Notification::TYPE_TASK_OVERDUE,
        Notification::TYPE_LOAN_DUE,
        Notification::TYPE_QUOTA_WARNING,
        Notification::TYPE_INVITATION_RECEIVED,
        Notification::TYPE_TRANSFER_REQUESTED,
        Notification::TYPE_ACCOUNT_INACTIVE,
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array', 'min:1', 'max:50'],
            'preferences.*.type' => ['required', 'string', Rule::in(self::TYPES)],
            'preferences.*.channel' => ['required', 'string', Rule::in([NotificationDelivery::CHANNEL_EMAIL])],
            'preferences.*.enabled' => ['required', 'boolean'],
            'preferences.*.digest' => ['required', 'boolean'],
        ];
    }
}
