<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * En rad i preferenslistan, se issue 31b § Beslut 2: den EFFEKTIVA
 * inställningen per typ och kanal — värdet från en notification_preference-rad
 * om en finns, annars förvalet i kod — så att klienten kan rita en fullständig
 * lista utan att känna till förvalen (issue 25 § Beslut 7, samma resonemang).
 *
 * `is_default` säger om värdet kommer ur koden (true) eller ur en rad (false)
 * — den enda upplysningen klienten inte kan härleda själv, och den som gör en
 * "återställ"-knapp möjlig. Kanalen `webhook` listas inte: preferenser styr
 * inte webhooks (31a § Beslut 4).
 *
 * Resursen slår INTE in en NotificationPreference-modell utan en färdig,
 * sammansatt post (array) från kontrollern — en saknad rad är förvalet i kod
 * och har ingen modell att slå in. Kontrollern läser därför upp värdena
 * (raden, eller förvalet genom App\Support\Notification\NotificationPreferences)
 * och resursen bara väljer nycklarna, samma form som TodoEntryResource väljer
 * sina.
 */
class NotificationPreferenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->resource['type'],
            'channel' => $this->resource['channel'],
            'enabled' => $this->resource['enabled'],
            'digest' => $this->resource['digest'],
            'is_default' => $this->resource['is_default'],
        ];
    }
}
