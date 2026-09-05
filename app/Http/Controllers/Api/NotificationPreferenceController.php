<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Http\Resources\NotificationPreferenceResource;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\Notification\NotificationPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Preferensytan, issue 31b — läsa och ändra den inloggade användarens
 * notispreferenser, se [[Notiser]] § notification_preference. Två toppnivå-
 * rutter under `/me` (Beslut 1): det finns ingen legitim anledning att läsa
 * eller ändra någon annans inställningar, och en rutt utan identifierare är
 * en rutt som inte går att peka fel — samma val som /api/todo gjorde i issue
 * 24 § Beslut 1. Allt utgår från `$request->user()`; ingen policy och ingen
 * Gate (Beslut 3) — en NotificationPreferencePolicy skulle svara på "får jag
 * ändra min egen rad?", vars svar alltid är ja.
 *
 * Kontrollern LÄSER App\Support\Notification\NotificationPreferences och
 * formulerar inte om förvalen: för typer utan rad hämtas `enabled`/`digest`
 * därifrån (med `$user = null`, så rader aldrig slås upp — en saknad rad
 * betyder förvalet). Listan över typer kommer från
 * UpdateNotificationPreferencesRequest::TYPES, se dess docblock.
 */
class NotificationPreferenceController extends Controller
{
    /**
     * GET /api/me/notification-preferences — 200. Alla typer × kanalen
     * `email`, i konstanternas ordning, med det EFFEKTIVA värdet: raden om
     * en finns, annars förvalet i kod (Beslut 2). `is_default` skiljer de två
     * åt. Kanalen `webhook` listas inte (31a § Beslut 4).
     */
    public function index(Request $request): JsonResponse
    {
        return $this->preferenceResponse($request->user());
    }

    /**
     * PUT /api/me/notification-preferences — 200 med samma kropp som GET.
     * En UPSERT av en delmängd i en transaktion på (user_id, type, channel)
     * (Beslut 4): typer som inte nämns rörs inte, och samma kropp två gånger
     * ger samma rader och samma svar. Ett värde som råkar vara lika med
     * förvalet skapar ändå en rad — användaren har uttryckt en åsikt, och ett
     * framtida ändrat förval ska inte köra över den.
     */
    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user, $request): void {
            foreach ($request->validated('preferences') as $preference) {
                NotificationPreference::updateOrCreate(
                    [
                        'user_id' => $user->getKey(),
                        'type' => $preference['type'],
                        'channel' => $preference['channel'],
                    ],
                    [
                        'enabled' => $preference['enabled'],
                        'digest' => $preference['digest'],
                    ],
                );
            }
        });

        return $this->preferenceResponse($user);
    }

    /**
     * Den effektiva listan för en användare, som GET:s svar och PUT:s svar
     * delar. Ett konstant antal frågor: en uppslagning av användarens rader,
     * och inga fler — förvalen hämtas med `$user = null`, vilket aldrig
     * frågar databasen.
     */
    private function preferenceResponse(User $user): JsonResponse
    {
        $preferences = app(NotificationPreferences::class);

        $rows = NotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->where('channel', NotificationDelivery::CHANNEL_EMAIL)
            ->get()
            ->keyBy('type');

        $items = [];

        foreach (UpdateNotificationPreferencesRequest::TYPES as $type) {
            $row = $rows->get($type);

            $items[] = $row === null
                ? [
                    'type' => $type,
                    'channel' => NotificationDelivery::CHANNEL_EMAIL,
                    'enabled' => $preferences->isEnabled(null, $type, NotificationDelivery::CHANNEL_EMAIL),
                    'digest' => $preferences->digest(null, $type, NotificationDelivery::CHANNEL_EMAIL),
                    'is_default' => true,
                ]
                : [
                    'type' => $type,
                    'channel' => NotificationDelivery::CHANNEL_EMAIL,
                    'enabled' => $row->enabled,
                    'digest' => $row->digest,
                    'is_default' => false,
                ];
        }

        return NotificationPreferenceResource::collection($items)->response();
    }
}
