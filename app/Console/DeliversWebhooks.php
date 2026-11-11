<?php

namespace App\Console;

use App\Models\Notification;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Notification\PinnedResolution;
use App\Support\Notification\UnsafeUrlException;
use App\Support\Notification\UrlSafetyValidator;
use App\Support\Notification\WebhookSignature;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Webhook-leveransloopen — minutjobbet som anropar kontons endpoints, issue
 * 37b. Se [[Notiser]] § Webhooks, [[ADR-0010 Notisarkitektur]] och
 * config/notiser.php § webhook.
 *
 * Rader skapas av App\Actions\Notification\CreateNotification (utfläkningen,
 * Beslut 3) och plockas här när `next_attempt_at` passerats. Loopen är den
 * ENDA platsen i systemet som sätter `webhook_delivery.status`, `attempts`,
 * `response_status`, `last_error`, `next_attempt_at` och `delivered_at`.
 *
 * En EGEN loop, inte 34a:s: omförsökssemantiken är en annan (backoff här,
 * nästa minut där), tabellen är en annan, och en långsam mottagare ska inte
 * kunna fördröja mejlen. Urvalet är bara leveransrader — `status = 'pending'`
 * och `next_attempt_at` passerad — utan join mot notification (Beslut 4):
 * webhooks bryr sig varken om tysta timmar eller om `digest`. Tysta timmar
 * finns för att ingen ska väckas klockan tre; en mottagande server sover
 * inte. `digest` gäller mejl. `available_at` läses inte och
 * `notification_preference` frågas inte.
 *
 * En rad i taget, i två korta transaktioner med anropet emellan. Först tas
 * raden: den läses under `lockForUpdate()`, lämnas om den inte längre är
 * `pending` eller har en `claimed_at` yngre än tio minuter, och får annars
 * `claimed_at = now()`. Sedan görs HTTP-anropet (upp till fem sekunder) utanför
 * varje transaktion, så att databasanslutningen och radlåset inte hålls under
 * nätverksanropet — hundra långsamma mottagare höll dem i över åtta minuter.
 * Till sist bokförs utfallet i en andra transaktion som läser raden om under
 * lås och inte skriver något om den under tiden slutat vara `pending`
 * (34a § Beslut 4), och som nollställer `claimed_at`. En arbetare som dog
 * mitt i anropet lämnar en claim som löper ut efter tio minuter; då tas raden
 * igen. Det är samma "minst en gång"-garanti som förut: mottagaren avduplicerar
 * på `X-Mimers-Delivery`, radens ULID, som inte ändras mellan försök.
 * `lockForUpdate()` är verkningslöst i sqlite och därmed i testsviten; det som
 * bär är statuskontrollen och `claimed_at`, inte låset. Ett fel på en rad
 * stoppar inte de andra: ett oväntat fel i deliver() fångas i handle() och
 * loggas som `webhook.delivery_failed`, precis som AdvancesAccountLifecycle
 * gör.
 *
 * Utfallen står i Beslut 6-tabellen i issuen. `attempts` räknas upp i alla
 * tre fallen. `consecutive_failures` på endpointen räknas BARA när en
 * leverans slutgiltigt gett upp (`failed`) — inte vid varje misslyckat
 * försök — annars inaktiveras en endpoint av en enda mottagare som var nere i
 * tio minuter (Beslut 6).
 *
 * Schemaläggs i routes/console.php med `Schedule::call`, aldrig
 * `Schedule::command` och aldrig `->runInBackground()` — se AGENTS.md §
 * Subprocesser i driftmiljön.
 */
class DeliversWebhooks
{
    /** Hur länge en claim håller innan raden är ledig igen. */
    private const CLAIM_TIMEOUT_MINUTES = 10;

    public function __construct(
        private readonly UrlSafetyValidator $urlSafety,
        private readonly WebhookSignature $signature,
    ) {}

    /**
     * Levererar en omgång väntande webhook-leveranser. Ingen sammanfattning
     * loggas — en tom minut ska inte skriva en rad (34a § Beslut 7), och
     * 1 440 tomma loggrader per dygn gör loggen oläsbar precis den natt någon
     * behöver den.
     */
    public function handle(): void
    {
        $batchSize = (int) config('notiser.webhook.batch_size', 100);

        $deliveryIds = DB::table('webhook_delivery')
            ->where('status', WebhookDelivery::STATUS_PENDING)
            ->where('next_attempt_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('claimed_at')
                    ->orWhere('claimed_at', '<=', now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES));
            })
            ->orderBy('id')
            ->limit($batchSize)
            ->pluck('id');

        foreach ($deliveryIds as $id) {
            $deliveryId = (int) $id;

            try {
                $this->deliver($deliveryId);
            } catch (Throwable $e) {
                // Ett oväntat fel utanför de kända utfallsvägarna i deliver()
                // — i urvalsläsningen, bokföringen eller själva
                // DB::transaction — får inte tysta resten av batchen (Beslut
                // 5). Raden ligger kvar som `pending` och plockas nästa minut;
                // felet loggas så att en körning som slutar anropa inte
                // försvinner spårlöst.
                Log::error('webhook.delivery_failed', [
                    'delivery_id' => $deliveryId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Levererar en enda rad i tre steg: ta raden, anropa utanför transaktion,
     * bokför utfallet. En rad som inte längre är `pending` när turen kommer, är
     * tagen av en annan arbetare eller ändrats under anropet rörs inte och
     * räknas inte in någonstans (returnerar null).
     *
     * @return 'sent'|'failed'|'pending'|null
     */
    private function deliver(int $deliveryId): ?string
    {
        // Steg 1: ta raden. Transaktionen slutar innan anropet görs.
        $delivery = DB::transaction(function () use ($deliveryId): ?WebhookDelivery {
            $delivery = WebhookDelivery::query()
                ->whereKey($deliveryId)
                ->lockForUpdate()
                ->first();

            if ($delivery === null || $delivery->status !== WebhookDelivery::STATUS_PENDING) {
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

        if ($delivery === null) {
            return null;
        }

        // Endpointen och notisen finns alltid: båda nycklarna är ON DELETE
        // RESTRICT, och städningen vid kontoradering tar leveransraderna
        // först (DeleteAccount, Beslut 10). En endpoint som INAKTIVERATS
        // (is_active = false) får inga nya rader, men de `pending`-rader
        // som redan ligger fortsätter försöka (Att se upp med).
        $endpoint = $delivery->endpoint;
        $notification = $delivery->notification;

        // Steg 2: anropet, utanför varje transaktion. SSRF-kontrollen körs
        // IGEN, före varje anrop (Beslut 7): [[ADR-0010 Notisarkitektur]] är
        // uttrycklig om varför — "eftersom DNS kan ändras däremellan". Faller
        // kontrollen: `failed` direkt, utan omförsök — en URL som blivit
        // farlig blir inte ofarlig av att man försöker igen.
        $unsafeReason = null;
        $error = null;
        $response = null;

        try {
            $ips = $this->urlSafety->resolveSafe($endpoint->url);
        } catch (UnsafeUrlException $e) {
            $ips = null;
            $unsafeReason = $e->reason;
        }

        if ($ips !== null) {
            $body = $this->buildBody($notification);

            try {
                $response = $this->send($endpoint, $delivery, $notification, $body, $ips);
            } catch (Throwable $e) {
                $error = mb_substr($e->getMessage(), 0, 1000);
            }
        }

        // Steg 3: bokför utfallet. Raden läses om under lås; har den under
        // tiden slutat vara `pending` skrivs inget.
        return DB::transaction(function () use ($deliveryId, $unsafeReason, $error, $response): ?string {
            $delivery = WebhookDelivery::query()
                ->whereKey($deliveryId)
                ->lockForUpdate()
                ->first();

            if ($delivery === null || $delivery->status !== WebhookDelivery::STATUS_PENDING) {
                return null;
            }

            $endpoint = $delivery->endpoint;
            $maxAttempts = (int) config('notiser.webhook.max_attempts', 6);

            $delivery->claimed_at = null;
            $delivery->attempts = $delivery->attempts + 1;

            if ($unsafeReason !== null) {
                $delivery->status = WebhookDelivery::STATUS_FAILED;
                $delivery->last_error = mb_substr('osäker url: '.$unsafeReason, 0, 1000);
                $delivery->save();
                $this->registerEndpointFailure($endpoint);

                return 'failed';
            }

            if ($response === null) {
                return $this->bookFailure($delivery, $endpoint, $maxAttempts, (string) $error);
            }

            $delivery->response_status = $response->status();

            if ($response->successful()) {
                $delivery->status = WebhookDelivery::STATUS_SENT;
                $delivery->delivered_at = now();
                $delivery->last_error = null;
                $delivery->save();

                // `consecutive_failures` nollställs vid varje lyckad leverans,
                // även om räknaren stod på 19 (Att se upp med) — annars
                // inaktiveras en fungerande endpoint av gamla fel.
                $endpoint->consecutive_failures = 0;
                $endpoint->save();

                return 'sent';
            }

            return $this->bookFailure($delivery, $endpoint, $maxAttempts, mb_substr('http_status_'.$response->status(), 0, 1000));
        });
    }

    /**
     * Bokför ett misslyckat försök — icke-2xx-svar eller undantag. `attempts`
     * räknas redan inte upp här: anroparen har gjort det. Är taket nått
     * markeras raden `failed` och endpointens `consecutive_failures` ökar;
     * annars ligger den kvar `pending` med nästa försök enligt backoffen i
     * Beslut 8.
     */
    private function bookFailure(
        WebhookDelivery $delivery,
        WebhookEndpoint $endpoint,
        int $maxAttempts,
        string $error,
    ): string {
        $delivery->last_error = $error;

        if ($delivery->attempts >= $maxAttempts) {
            $delivery->status = WebhookDelivery::STATUS_FAILED;
            $delivery->save();
            $this->registerEndpointFailure($endpoint);

            return 'failed';
        }

        $delivery->status = WebhookDelivery::STATUS_PENDING;
        $delivery->next_attempt_at = $this->backoff($delivery->attempts);
        $delivery->save();

        return 'pending';
    }

    /**
     * Nästa försök enligt exponentiell backoff: 2^(försök−1) minuter — 1, 2,
     * 4, 8 … — takad vid 60 minuter så att formeln inte kan springa iväg om
     * `max_attempts` höjs. `attempts` räknas här EFTER uppräkningen (Beslut
     * 8): det första misslyckade försöket ger alltså en minut.
     */
    private function backoff(int $attempts): Carbon
    {
        return now()->addMinutes(min(60, 2 ** ($attempts - 1)));
    }

    /**
     * Anropet: POST, JSON, snäv timeout, inga omdirigeringar (Beslut 6).
     *
     * `withoutRedirecting()` är en säkerhetsåtgärd, inte en optimering: en
     * mottagare som svarar 302 Location: http://169.254.169.254/ skulle annars
     * få klienten att följa med — och SSRF-kontrollen ovan har redan gjorts på
     * den ursprungliga URL:en. Kroppen skickas med `withBody` som EXAKT den
     * JSON-sträng som signerades (Beslut 9) — `Http::post($url, $array)`
     * serialiserar själv och skulle ge en annan sträng.
     *
     * @param  list<string>  $ips  adresserna SSRF-kontrollen godkände; klienten
     *                             ansluter till exakt dem (DNS rebinding)
     */
    private function send(
        WebhookEndpoint $endpoint,
        WebhookDelivery $delivery,
        Notification $notification,
        string $body,
        array $ips,
    ): Response {
        $timestamp = now()->getTimestamp();

        $headers = [
            'X-Mimers-Event' => $notification->type,
            'X-Mimers-Delivery' => $delivery->ulid,
            'X-Mimers-Signature' => $this->signature->header($endpoint->secret, $timestamp, $body),
        ];

        return Http::withHeaders($headers)
            ->withoutRedirecting()
            ->withOptions(PinnedResolution::options($endpoint->url, $ips))
            ->timeout((int) config('notiser.webhook.timeout_seconds', 5))
            ->withBody($body, 'application/json')
            ->post($endpoint->url);
    }

    /**
     * Leveranskroppen (Beslut 6): ULID utåt, aldrig löpnummer, ingen renderad
     * text och inget språk — mottagaren är ett program (issue 30 § Beslut 5,
     * [[ADR-0013 Språk och i18n]]). `data` är notisens payload, oförändrad.
     *
     * @throws \JsonException
     */
    private function buildBody(Notification $notification): string
    {
        $body = [
            'id' => $notification->ulid,
            'type' => $notification->type,
            'created_at' => $notification->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'account' => $notification->account?->ulid,
            'container' => $notification->container?->ulid,
            'data' => $notification->payload,
        ];

        return (string) json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Räknar upp endpointens `consecutive_failures` efter en slutgiltigt
     * misslyckad leverans och inaktiverar endpointen när taket nåtts (Beslut
     * 8): is_active sätts till false och en varning loggas. Kontot får ingen
     * notis om det — att skicka en notis om att notiser inte fungerar är en
     * väg in i en loop, och ytan som visar tillståndet är issue 65 (M10).
     * Återaktivering sker manuellt genom PATCH (37a § Beslut 3), som nollställer
     * räknaren.
     */
    private function registerEndpointFailure(WebhookEndpoint $endpoint): void
    {
        $endpoint->consecutive_failures = $endpoint->consecutive_failures + 1;

        $deactivateAfter = (int) config('notiser.webhook.deactivate_after_failures', 20);

        if ($endpoint->consecutive_failures >= $deactivateAfter && $endpoint->is_active) {
            $endpoint->is_active = false;

            Log::warning('webhook.deactivated', [
                'endpoint_ulid' => $endpoint->ulid,
                'account_ulid' => $endpoint->account?->ulid,
            ]);
        }

        $endpoint->save();
    }
}
