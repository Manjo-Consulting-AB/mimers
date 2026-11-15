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
 * **Webhookarna räknas också som leveranser.** `webhook_delivery` pekar på
 * `notification` med `ON DELETE RESTRICT`; att bara titta på
 * `notification_delivery` hade låtit en notis med enbart webhookleveranser
 * försöka raderas och fallera på främmande nyckeln. Frågan kontrollerar
 * därför båda tabellerna, och en notis som har någon av dem står kvar.
 *
 * Båda stegen går i EN transaktion: en notis ska aldrig hinna bli av med sin
 * `in_app`-rad och ändå stå kvar som en tom rad om körningen dör mitt emellan.
 *
 * Schemaläggs i routes/console.php med `Schedule::call(...)`, aldrig
 * `Schedule::command(...)` — se AGENTS.md § Subprocesser i driftmiljön.
 */
class PrunesInAppNotifications
{
    /**
     * Steg ett tar `in_app`-raderna, steg två de notiser som blev utan
     * leveranser. Båda talen returneras så att en körning går att följa i
     * loggen och i ett prov.
     *
     * @return array{deliveries: int, notifications: int}
     */
    public function handle(): array
    {
        $cutoff = now()->subDays(NotificationDelivery::IN_APP_RETENTION_DAYS);

        return DB::transaction(function () use ($cutoff): array {
            $deliveries = DB::table('notification_delivery')
                ->where('channel', NotificationDelivery::CHANNEL_IN_APP)
                ->whereIn('notification_id', function ($query) use ($cutoff): void {
                    $query->select('id')
                        ->from('notification')
                        ->where('created_at', '<', $cutoff);
                })
                ->delete();

            $notifications = Notification::query()
                ->where('created_at', '<', $cutoff)
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
