<?php

namespace App\Actions\Notification;

use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Antalet olästa `in_app`-rader för en person — klockans märke
 * ([[ADR-0077 Notislistan i appen]] § 7). Issue 383 bygger räkningen och
 * delar den som `notifications.unread`; 385 ritar den.
 *
 * **Samma fönster som listan.** Bara rader vars notis är yngre än 90 dagar
 * räknas: märket ska stämma med det personen kan öppna, och en rad som
 * väntar på nattens gallring ska inte synas som ett oläst tal utan rad.
 *
 * Frågan börjar på `notification (user_id, created_at)` och filtrerar
 * leveranssidan genom det unika `(notification_id, channel)` plus
 * `read_at`-indexet — samma väg som App\Actions\Notification\
 * ListInAppNotifications, så tal och lista alltid är samma mängd.
 */
final class CountUnreadInAppNotifications
{
    public function handle(User $user): int
    {
        return Notification::query()
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', now()->subDays(NotificationDelivery::IN_APP_RETENTION_DAYS))
            ->whereHas('deliveries', function (Builder $query): void {
                $query->where('channel', NotificationDelivery::CHANNEL_IN_APP)
                    ->whereNull('read_at');
            })
            ->count();
    }
}
