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
 * En rad i taget, i två korta transaktioner med utskicket emellan (Beslut 4,
 * ändrat i issue 1056). Att hålla en transaktion — och därmed anslutningen
 * och radlåset — öppen under hela nätverksanropet till Mailgun var för dyrt.
 * Nu tar den första transaktionen raden i anspråk (`claimed_at = now()`
 * under `lockForUpdate()`), mejlet skickas utanför varje transaktion och den
 * andra transaktionen bokför utfallet och nollställer `claimed_at`. En rad
 * vars körning dog mitt i utskicket blir ledig igen efter tio minuter, så
 * "minst en gång"-garantin är densamma som förut.
 *
 * Statuskontrollerna INUTI transaktionerna — raden läses om under
 * `lockForUpdate()` och lämnas om den inte längre är `pending` (eller, vid
 * tagandet, redan är tagen) — är det som gör dubbelutskick osannolikt även om
 * `Schedule::withoutOverlapping()` skulle svika (cachelåset nollställs eller
 * går ut mitt i en lång körning). `lockForUpdate()` är verkningslöst i sqlite
 * och därmed i testsviten; det som bär är kontrollerna, inte låset. Ett
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
 * Subprocesser i driftmiljön.
 */
class DeliversNotifications
{
    /**
     * Hur länge en tagen rad hålls av den som tog den. Därefter anses
     * körningen ha dött och raden är ledig igen.
     */
    private const CLAIM_TIMEOUT_MINUTES = 10;

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
            ->where(function ($query): void {
                $query->whereNull('notification_delivery.claimed_at')
                    ->orWhere('notification_delivery.claimed_at', '<=', now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES));
            })
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
     * Levererar en enda rad i tre steg: ta raden, skicka utanför transaktion,
     * bokför utfallet. En rad som inte längre är `pending`, eller som någon
     * annan håller, rörs inte och räknas inte in i sammanfattningen
     * (returnerar null).
     *
     * @return 'sent'|'failed'|'suppressed'|'pending'|null
     */
    private function deliver(int $deliveryId): ?string
    {
        $delivery = $this->claim($deliveryId);

        if ($delivery === null) {
            return null;
        }

        // Utskicket sker utanför varje transaktion; utfallet hålls i
        // variabler tills bokföringen.
        $suppressed = false;
        $unknownType = false;
        $error = null;

        try {
            $this->channel->send($delivery);
        } catch (AddressSuppressedException $e) {
            $suppressed = true;
            $error = $e;
        } catch (UnknownNotificationTypeException $e) {
            $unknownType = true;
            $error = $e;
        } catch (Throwable $e) {
            $error = $e;
        }

        return $this->settle($deliveryId, $suppressed, $unknownType, $error);
    }

    /**
     * Steg 1 — tar raden i anspråk i en kort transaktion. Null när raden är
     * borta, inte `pending` eller redan tagen av en yngre körning.
     */
    private function claim(int $deliveryId): ?NotificationDelivery
    {
        return DB::transaction(function () use ($deliveryId): ?NotificationDelivery {
            $delivery = NotificationDelivery::query()
                ->whereKey($deliveryId)
                ->lockForUpdate()
                ->first();

            if ($delivery === null || $delivery->status !== NotificationDelivery::STATUS_PENDING) {
                return null;
            }

            if ($delivery->claimed_at !== null
                && $delivery->claimed_at->gt(now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES))) {
                return null;
            }

            $delivery->claimed_at = now();
            $delivery->save();

            return $delivery;
        });
    }

    /**
     * Steg 3 — bokför utfallet i en andra kort transaktion och släpper
     * anspråket. Raden läses om: är den inte längre `pending` (någon annan
     * hann ändra den medan mejlet gick) skrivs ingenting.
     *
     * @return 'sent'|'failed'|'suppressed'|'pending'|null
     */
    private function settle(int $deliveryId, bool $suppressed, bool $unknownType, ?Throwable $error): ?string
    {
        return DB::transaction(function () use ($deliveryId, $suppressed, $unknownType, $error): ?string {
            $delivery = NotificationDelivery::query()
                ->whereKey($deliveryId)
                ->lockForUpdate()
                ->first();

            if ($delivery === null || $delivery->status !== NotificationDelivery::STATUS_PENDING) {
                return null;
            }

            $maxAttempts = (int) config('notiser.delivery.max_attempts', 5);
            $delivery->claimed_at = null;

            if ($error === null) {
                $delivery->status = NotificationDelivery::STATUS_SENT;
                $delivery->attempts = $delivery->attempts + 1;
                $delivery->sent_at = now();
                $delivery->last_error = null;
                $delivery->save();

                return 'sent';
            }

            $delivery->last_error = mb_substr($error->getMessage(), 0, 1000);

            if ($suppressed) {
                $delivery->status = NotificationDelivery::STATUS_SUPPRESSED;
                $delivery->save();

                return 'suppressed';
            }

            $delivery->attempts = $delivery->attempts + 1;

            if ($unknownType) {
                $delivery->status = NotificationDelivery::STATUS_FAILED;
                $delivery->save();

                return 'failed';
            }

            $gaveUp = $delivery->attempts >= $maxAttempts;
            $delivery->status = $gaveUp
                ? NotificationDelivery::STATUS_FAILED
                : NotificationDelivery::STATUS_PENDING;
            $delivery->save();

            return $gaveUp ? 'failed' : 'pending';
        });
    }
}
