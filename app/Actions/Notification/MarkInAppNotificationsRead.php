<?php

namespace App\Actions\Notification;

use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Markerar `in_app`-rader som lästa — enstaka eller alla
 * ([[ADR-0077 Notislistan i appen]] § 3). Issue 383 bygger actionen; 384:s
 * rutter (webb och API) anropar den.
 *
 * **Bara personens egna rader.** Frågan går via `notification.user_id`, så
 * en ULID som tillhör någon annan träffar ingenting — den ignoreras i
 * stället för att avslöja att den finns. Samma regel för en okänd ULID.
 *
 * **Bara olästa rader skrivs.** `read_at` är första gången personen såg
 * raden; att markera igen ska inte flytta tidsstämpeln. Det gör också
 * "markera alla" idempotent: andra anropet uppdaterar noll rader.
 *
 * Returvärdet är antalet rader som faktiskt ändrades — anroparen behöver
 * aldrig räkna om det, och ett tomt svar betyder att allt redan var läst.
 */
final class MarkInAppNotificationsRead
{
    /**
     * @param  list<string>|null  $ulids  notisernas ULID:n, eller null för alla
     * @return int antal rader som fick `read_at`
     */
    public function handle(User $user, ?array $ulids): int
    {
        if ($ulids === []) {
            return 0;
        }

        return NotificationDelivery::query()
            ->where('channel', NotificationDelivery::CHANNEL_IN_APP)
            ->whereNull('read_at')
            ->whereHas('notification', function (Builder $query) use ($user, $ulids): void {
                $query->where('user_id', $user->getKey());

                if ($ulids !== null) {
                    $query->whereIn('ulid', $ulids);
                }
            })
            ->update(['read_at' => now()]);
    }
}
