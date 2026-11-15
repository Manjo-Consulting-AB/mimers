<?php

namespace App\Console;

use App\Models\NotificationDelivery;
use App\Models\WebhookDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Sentry\Severity;
use Sentry\State\Scope;

use function Sentry\captureMessage;
use function Sentry\withScope;

/**
 * Vakten för leveranskörna — issue 389, [[ADR-0079 Övervakning]] § 2.
 *
 * Schemaläggaren kan köra och ändå inte leverera: ett fel i kanalen, en
 * mottagare som aldrig svarar eller en arbetare som dött lämnar rader som
 * `pending`. Jobbens egna hjärtslag ser inte det. Vakten läser kön utifrån och
 * rapporterar till Sentry när en leverans väntat för länge.
 *
 * Två köer, och "väntande" betyder som i [[Notiser]] § Kön:
 *
 * - `email`: `notification_delivery` med `channel = email`, `status = pending`
 *   och `digest = false` (sammanfattningarna levereras av SendsWeeklyDigest,
 *   inte av DeliversNotifications), vars notis har `available_at` mer än
 *   {@see self::LIMIT_MINUTES} minuter bakåt. En leverans som skjutits upp av
 *   tysta timmar har `available_at` i framtiden och väntar därför inte.
 * - `webhook`: `webhook_delivery` med `status = pending`, mätt på samma sätt
 *   mot notisens `available_at`.
 *
 * Meddelandet bär köns namn, antalet väntande och den äldstas ålder i minuter
 * — inga id:n och inga adresser. Fingeravtrycket är per kö, så att Sentry
 * grupperar alla larm för en kö i ett ärende. Utan DSN är anropet verkningslöst.
 *
 * Schemaläggs i routes/console.php med `Schedule::call`, aldrig
 * `Schedule::command` — se AGENTS.md § Subprocesser i driftmiljön.
 */
class WatchesDeliveryQueues
{
    /** Hur länge en leverans får ha väntat innan vakten larmar. */
    public const LIMIT_MINUTES = 60;

    /**
     * @return list<string> Namnen på de köer som larmades.
     */
    public function handle(): array
    {
        $cutoff = now()->subMinutes(self::LIMIT_MINUTES);

        $queues = [
            'email' => DB::table('notification_delivery')
                ->join('notification', 'notification.id', '=', 'notification_delivery.notification_id')
                ->where('notification_delivery.status', NotificationDelivery::STATUS_PENDING)
                ->where('notification_delivery.channel', NotificationDelivery::CHANNEL_EMAIL)
                ->where('notification_delivery.digest', false)
                ->where('notification.available_at', '<', $cutoff),
            'webhook' => DB::table('webhook_delivery')
                ->join('notification', 'notification.id', '=', 'webhook_delivery.notification_id')
                ->where('webhook_delivery.status', WebhookDelivery::STATUS_PENDING)
                ->where('notification.available_at', '<', $cutoff),
        ];

        $alarmed = [];

        foreach ($queues as $name => $query) {
            $row = $query
                ->selectRaw('count(*) as waiting, min(notification.available_at) as oldest')
                ->first();

            if ($row === null || (int) $row->waiting === 0) {
                continue;
            }

            $age = (int) Carbon::parse($row->oldest, 'UTC')->diffInMinutes(now(), true);

            withScope(function (Scope $scope) use ($name, $row, $age): void {
                $scope->setFingerprint(['delivery-queue', $name]);

                captureMessage(sprintf(
                    'Leveranskön %s står still: %d väntande, den äldsta har väntat %d minuter.',
                    $name,
                    (int) $row->waiting,
                    $age,
                ), Severity::warning());
            });

            $alarmed[] = $name;
        }

        return $alarmed;
    }
}
