<?php

namespace App\Console;

use App\Models\NotificationDelivery;
use App\Support\Notification\AddressSuppressedException;
use App\Support\Notification\EmailChannel;
use App\Support\Notification\UnknownNotificationTypeException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Leveransloopen — minutjobbet som tömmer outboxen, issue 34a. Se [[Notiser]]
 * § Kön och § notification_delivery, [[ADR-0010 Notisarkitektur]] och
 * config/notiser.php § delivery.
 *
 * Loopen plockar `pending`-leveranser vars notis har passerat
 * `available_at`, kör dem genom e-postkanalen
 * (App\Support\Notification\EmailChannel) och bokför utfallet. Den är den
 * ENDA platsen i systemet som sätter `notification_delivery.status`,
 * `attempts`, `last_error` och `sent_at`; generatorerna som fyller outboxen
 * är 34b.
 *
 * Leveransen sker in-process och synkront (Beslut 2): servern har ingen
 * `queue:work`-process, bara den minutcron som startar schemaläggaren. En
 * Mailable som markerades `ShouldQueue` skulle läggas i `jobs`-tabellen och
 * aldrig plockas — det är det fel som ser mest ut som en förbättring och som
 * gör att inga mejl går ut.
 *
 * Urvalet (Beslut 3) join:ar notification för att nå `available_at` — den
 * kolumnen ligger på notisen, inte på leveransen, och en fråga bara mot
 * leveranstabellen skulle skicka tysta-timmar-notiser klockan tre på natten.
 * Bara `channel = 'email'` levereras här; webhook-kanalen läggs till i 37b.
 * Bara `digest = false` levereras här — `digest = true`-rader samlas i
 * veckosammanfattningen (35 § Beslut 3) och ska inte nås av minutloopen.
 * Sorteringen på id gör ordningen deterministisk och gör att en rad som
 * fastnat inte hoppas över för alltid.
 *
 * En rad i taget, i en egen transaktion (Beslut 4). Statuskontrollen INUTI
 * transaktionen — raden läses om under `lockForUpdate()` och lämnas om den
 * inte längre är `pending` — är det som gör dubbelutskick omöjligt även om
 * `Schedule::withoutOverlapping()` skulle svika (cachelåset nollställs eller
 * går ut mitt i en lång körning). `lockForUpdate()` är verkningslöst i sqlite
 * och därmed i testsviten; det som bär är statuskontrollen, inte låset. Ett
 * fel på en rad stoppar inte de andra: ett oväntat fel utanför de tre kända
 * utfallsvägarna i deliver() fångas i handle() och loggas som
 * `notification.delivery_failed`.
 *
 * Utfallen står i Beslut 5-tabellen i issuen: skickat ger `sent` med
 * `sent_at`, en undertryckt adress (33a) ger `suppressed` utan att räkna upp
 * `attempts` (inget leveransförsök gjordes), en okänd notistyp (32a) ger
 * `failed` direkt — att försöka fem gånger med en mall som inte finns ger fem
 * identiska loggrader och fördröjer ingenting — och ett övrigt fel går
 * tillbaka till `pending` tills `max_attempts` är nått, sedan `failed`. Ingen
 * backoff här: nästa försök är om en minut. `last_error` trunkeras till 1 000
 * tecken; en stacktrace från en HTTP-klient kan vara tiotusentals.
 *
 * Schemaläggs i routes/console.php med `Schedule::call`, aldrig
 * `Schedule::command` och aldrig `->runInBackground()` — se AGENTS.md §
 * Driftmiljön saknar proc_open.
 */
class DeliversNotifications
{
    public function __construct(
        private readonly EmailChannel $channel,
    ) {}

    /**
     * Levererar en omgång väntande e-postleveranser och loggar en
     * sammanfattning — men bara när något gjordes (Beslut 7): en tom minut ska
     * inte skriva en rad, 1 440 tomma loggrader per dygn gör loggen oläsbar
     * precis den natt någon behöver den.
     */
    public function handle(): void
    {
        $batchSize = (int) config('notiser.delivery.batch_size', 200);

        $deliveryIds = DB::table('notification_delivery')
            ->join('notification', 'notification.id', '=', 'notification_delivery.notification_id')
            ->where('notification_delivery.status', NotificationDelivery::STATUS_PENDING)
            ->where('notification_delivery.channel', NotificationDelivery::CHANNEL_EMAIL)
            ->where('notification_delivery.digest', false)
            ->where('notification.available_at', '<=', now())
            ->orderBy('notification_delivery.id')
            ->limit($batchSize)
            ->pluck('notification_delivery.id');

        $utfall = ['sent' => 0, 'failed' => 0, 'suppressed' => 0, 'pending' => 0];

        foreach ($deliveryIds as $id) {
            $deliveryId = (int) $id;

            try {
                $resultat = $this->deliver($deliveryId);
            } catch (Throwable $e) {
                // Ett oväntat fel utanför de tre kända utfallsvägarna i
                // deliver() — i urvalsläsningen, bokföringen eller själva
                // DB::transaction — får inte tysta resten av batchen (Beslut
                // 4), precis som AdvancesAccountLifecycle gör. Raden ligger
                // kvar som `pending` och plockas nästa minut; felet loggas så
                // att en körning som slutar skicka inte försvinner spårlöst.
                Log::error('notification.delivery_failed', [
                    'delivery_id' => $deliveryId,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            if ($resultat !== null) {
                $utfall[$resultat]++;
            }
        }

        if (array_sum($utfall) > 0) {
            Log::info('notification.delivery_run', $utfall);
        }
    }

    /**
     * Levererar en enda rad och bokför utfallet. En rad som inte längre är
     * `pending` när turen kommer — en annan körning hann före — rörs inte och
     * räknas inte in i sammanfattningen (returnerar null).
     *
     * @return 'sent'|'failed'|'suppressed'|'pending'|null
     */
    private function deliver(int $deliveryId): ?string
    {
        return DB::transaction(function () use ($deliveryId): ?string {
            $delivery = NotificationDelivery::query()
                ->whereKey($deliveryId)
                ->lockForUpdate()
                ->first();

            if ($delivery === null || $delivery->status !== NotificationDelivery::STATUS_PENDING) {
                return null;
            }

            $maxAttempts = (int) config('notiser.delivery.max_attempts', 5);

            try {
                $this->channel->send($delivery);
            } catch (AddressSuppressedException $e) {
                $delivery->status = NotificationDelivery::STATUS_SUPPRESSED;
                $delivery->last_error = mb_substr($e->getMessage(), 0, 1000);
                $delivery->save();

                return 'suppressed';
            } catch (UnknownNotificationTypeException $e) {
                $delivery->status = NotificationDelivery::STATUS_FAILED;
                $delivery->attempts = $delivery->attempts + 1;
                $delivery->last_error = mb_substr($e->getMessage(), 0, 1000);
                $delivery->save();

                return 'failed';
            } catch (Throwable $e) {
                $delivery->attempts = $delivery->attempts + 1;
                $delivery->last_error = mb_substr($e->getMessage(), 0, 1000);
                $delivery->status = $delivery->attempts >= $maxAttempts
                    ? NotificationDelivery::STATUS_FAILED
                    : NotificationDelivery::STATUS_PENDING;
                $delivery->save();

                return $delivery->status === NotificationDelivery::STATUS_FAILED ? 'failed' : 'pending';
            }

            $delivery->status = NotificationDelivery::STATUS_SENT;
            $delivery->attempts = $delivery->attempts + 1;
            $delivery->sent_at = now();
            $delivery->last_error = null;
            $delivery->save();

            return 'sent';
        });
    }
}
