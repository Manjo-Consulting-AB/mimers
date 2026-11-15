<?php

namespace App\Console;

use App\Models\Notification;
use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\DB;

/**
 * Gallrar `in_app`-rader som är äldre än 90 dagar — se
 * [[ADR-0077 Notislistan i appen]] § 5 och [[Notiser]] § notification_delivery.
 * Listan är ett flöde, inte ett arkiv; det som ska sparas längre står redan i
 * händelseloggen ([[ADR-0043 Tre loggar]]).
 *
 * **Bara `in_app`-rader tas bort.** En notis som också har en e-postleverans
 * behåller den raden och sin notisrad — gallringen rör aldrig en annan kanal.
 * En notis vars enda leverans var `in_app` blir däremot föräldralös och tas
 * bort i samma körning. Det finns ingenting kvar att visa eller skicka, och
 * `notification` är outboxen som redan levererat allt den skulle.
 *
 * **Notisen raderas bara om den just förlorade sin `in_app`-rad.** Mängden
 * notiser som får raderas är den som hade en `in_app`-rad äldre än gränsen —
 * inte varje gammal notis som saknar leveranser. En notis utan någon
 * leverans alls är normalfallet när e-posten är avslagen, och notisraden är
 * fortfarande outboxen som veckosammanfattningen och webhookarna läser.
 * Frågan "saknar leveranser?" ensam hade alltså tyst raderat notiser som
 * aldrig hade något med `in_app` att göra.
 *
 * **Webhookarna räknas också som leveranser.** `webhook_delivery` pekar på
 * `notification` med `ON DELETE RESTRICT`; att bara titta på
 * `notification_delivery` hade låtit en notis med enbart webhookleveranser
 * försöka raderas och fallera på främmande nyckeln. Frågan kontrollerar
 * därför båda tabellerna, och en notis som har någon av dem står kvar.
 *
 * Alla tre stegen går i EN transaktion: en notis ska aldrig hinna bli av med
 * sin `in_app`-rad och ändå stå kvar som en tom rad om körningen dör mitt
 * emellan.
 *
 * Schemaläggs i routes/console.php med `Schedule::call(...)`, aldrig
 * `Schedule::command(...)` — se AGENTS.md § Subprocesser i driftmiljön.
 */
class PrunesInAppNotifications
{
    /**
     * Steg ett samlar notiserna som har en gammal `in_app`-rad, steg två tar
     * raderna och steg tre de notiser ur samma mängd som blev utan leveranser.
     * Talen returneras så att en körning går att följa i loggen och i ett
     * prov.
     *
     * @return array{deliveries: int, notifications: int}
     */
    public function handle(): array
    {
        $cutoff = now()->subDays(NotificationDelivery::IN_APP_RETENTION_DAYS);

        return DB::transaction(function () use ($cutoff): array {
            // Mängden som steg tre får radera ur. Den hämtas före raderingen,
            // eftersom `notification_delivery`-raderna då är borta och inte
            // längre kan peka ut vilka notiser som hade en `in_app`-rad.
            $notificationIds = DB::table('notification_delivery')
                ->where('channel', NotificationDelivery::CHANNEL_IN_APP)
                ->whereIn('notification_id', function ($query) use ($cutoff): void {
                    $query->select('id')
                        ->from('notification')
                        ->where('created_at', '<', $cutoff);
                })
                ->distinct()
                ->pluck('notification_id');

            $deliveries = $notificationIds->isEmpty() ? 0 : DB::table('notification_delivery')
                ->where('channel', NotificationDelivery::CHANNEL_IN_APP)
                ->whereIn('notification_id', $notificationIds)
                ->delete();

            $notifications = $notificationIds->isEmpty() ? 0 : Notification::query()
                ->whereIn('id', $notificationIds)
                ->whereDoesntHave('deliveries')
                ->whereNotExists(function ($query): void {
                    $query->select(DB::raw('1'))
                        ->from('webhook_delivery')
                        ->whereColumn('webhook_delivery.notification_id', 'notification.id');
                })
                ->delete();

            return ['deliveries' => $deliveries, 'notifications' => $notifications];
        });
    }
}
