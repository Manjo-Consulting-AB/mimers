<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * En rad i notislistan i appen, se [[ADR-0077 Notislistan i appen]] § 2 och
 * M60 · issue 384.
 *
 * **Raden är färdigbyggd när resursen ser den.** App\Actions\Notification\
 * ListInAppNotifications gör uppslaget, fönstret på 90 dagar, sorteringen och
 * adressen per typ — resursen lindar den arrayen, precis som
 * TrashEntryResource lindar en färdig papperskorgspost. Webben och API:et
 * delar både actionen och den här formen (§ 8).
 *
 * **Ingen färdig text.** `payload` bär data och `type` namnet; klienten
 * renderar meningen per typ ur `lang/` ([[Notiser]] § notification, payloaden
 * bär data aldrig text). Att skicka en färdig mening här hade bundit listan
 * till serverns språk i stället för personens.
 *
 * `target` är adressen raden pekar på, eller `null` när notisen inte har
 * någon sida — samma uppslag som klockan använder
 * (App\Support\Notification\NotificationTarget). `read_at` är `null` för en
 * oläst rad och annars första gången personen såg den.
 *
 * @property array{
 *     ulid: string,
 *     type: string,
 *     payload: array<string, mixed>,
 *     created_at: string,
 *     read_at: string|null,
 *     target: string|null,
 * } $resource
 */
final class InAppNotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->resource['ulid'],
            'type' => $this->resource['type'],
            'payload' => $this->resource['payload'],
            'target' => $this->resource['target'],
            'created_at' => $this->resource['created_at'],
            'read_at' => $this->resource['read_at'],
        ];
    }
}
