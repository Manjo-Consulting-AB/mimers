<?php

use App\Actions\Account\DeleteAccount;
use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Notification\CreateNotification;
use App\Actions\Trash\PurgeContainer;
use App\Actions\Trash\PurgeContent;
use App\Console\DeliversWebhooks;
use App\Models\Account;
use App\Models\Container;
use App\Models\Notification;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Notification\UrlSafetyValidator;
use App\Support\Notification\WebhookSignature;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

use function Pest\Laravel\artisan;
use function Pest\Laravel\deleteJson;

/*
 * Issue 37b · Leveransen — utfläkningen från en notis till kontots endpoints,
 * HMAC-SHA256-signaturen, omförsöken med backoff, den automatiska
 * inaktiveringen och SSRF-kontrollen vid varje anrop. Se
 * App\Console\DeliversWebhooks, App\Support\Notification\WebhookSignature,
 * [[Notiser]] § Webhooks och config/notiser.php § webhook.
 *
 * Klassen anropas direkt, precis som LeveransloopTest anropar
 * DeliversNotifications. Utfallen testas med Http::fake() — olika svar per
 * URL — och Http::assertSent() inspekterar headers och kropp.
 *
 * UrlSafetyValidator injiceras med en resolver som aldrig slår upp riktiga
 * värdnamn (issue 37a § Att se upp med): värdnamns-URL:er i de här testerna
 * ska vara SÄKRA och passera kontrollen, så resolvern svarar med en publik IP
 * för allt. Undantaget är testet för en osäker URL, som använder en
 * IP-literal (169.254.169.254) och därför aldrig når resolvern.
 *
 * `Carbon::setTestNow()` styr `now()` i loopen och backoffen. Varje
 * "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 */

beforeEach(function () {
    app()->instance(UrlSafetyValidator::class, new UrlSafetyValidator(
        fn (string $host): array => ['93.184.216.34'],
    ));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en medlem — utgångsläget för en endpoint. Webhooks hör till
 * kontot, inte till en användare eller container (37a § Beslut 1).
 *
 * @return array{0: Account, 1: User}
 */
function webhookKonto(): array
{
    return kontoMedMedlem();
}

/**
 * En `pending`-leverans mot en endpoint, redo för loopen. Fabriken går förbi
 * CreateNotification med flit (fabriken skapar ingen utfläkning att testa —
 * det gör Actionen); den här hjälparen sätter bara ihop den rad loopen ska
 * plocka.
 *
 * @param  array<string, mixed>  $notisAttribut
 * @param  array<string, mixed>  $leveransAttribut
 */
function webhookLeverans(WebhookEndpoint $endpoint, array $notisAttribut = [], array $leveransAttribut = []): WebhookDelivery
{
    $notification = Notification::factory()->create(array_merge([
        'account_id' => $endpoint->account_id,
        'type' => Notification::TYPE_TASK_DUE,
        'payload' => ['due_at' => '2027-05-05', 'title' => 'Byt impeller'],
        'available_at' => now()->subMinute(),
    ], $notisAttribut));

    return WebhookDelivery::factory()->create(array_merge([
        'webhook_endpoint_id' => $endpoint->id,
        'notification_id' => $notification->id,
    ], $leveransAttribut));
}

/**
 * Kör en omgång av webhook-leveransloopen.
 */
function webhookKor(): void
{
    app(DeliversWebhooks::class)->handle();
}

/**
 * En aktiv endpoint på kontot som prenumererar på task.due, med den URL
 * testernas Http::fake svarar på.
 */
function webhookEndpoint(Account $account, array $attribut = []): WebhookEndpoint
{
    return WebhookEndpoint::factory()->for($account, 'account')->create(array_merge([
        'url' => 'https://example.com/notiser',
        'event_types' => [Notification::TYPE_TASK_DUE],
    ], $attribut));
}

it('en notis fläks ut till kontots aktiva endpoints', function () {
    [$account] = webhookKonto();
    $första = webhookEndpoint($account);
    $andra = webhookEndpoint($account);

    $notis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        payload: ['due_at' => '2027-05-05'],
    );

    // Två aktiva endpoints ger två webhook_delivery-rader (Beslut 3), båda
    // `pending`, utan försök, mogna direkt.
    $rader = WebhookDelivery::query()->where('notification_id', $notis->id)->get();
    expect($rader)->toHaveCount(2);
    expect($rader->pluck('webhook_endpoint_id'))->toContain($första->id, $andra->id);

    foreach ($rader as $rad) {
        expect($rad->status)->toBe(WebhookDelivery::STATUS_PENDING);
        expect($rad->attempts)->toBe(0);
        expect($rad->next_attempt_at)->not->toBeNull();
    }
});

it('en inaktiv endpoint får ingen leveransrad', function () {
    [$account] = webhookKonto();
    webhookEndpoint($account, ['is_active' => false]);

    $notis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        payload: [],
    );

    expect(WebhookDelivery::query()->where('notification_id', $notis->id)->count())->toBe(0);
});

it('en endpoint som inte prenumererar på typen får ingen rad', function () {
    [$account] = webhookKonto();
    $påTypen = webhookEndpoint($account, ['event_types' => [Notification::TYPE_TASK_DUE]]);
    $påAnnat = webhookEndpoint($account, ['event_types' => [Notification::TYPE_QUOTA_WARNING]]);

    $notis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        payload: [],
    );

    // Bara endpointen vars event_types innehåller notisens typ får en rad
    // (Beslut 3) — filtreringen sker i PHP, inte i SQL.
    $rader = WebhookDelivery::query()->where('notification_id', $notis->id)->get();
    expect($rader)->toHaveCount(1);
    expect($rader->first()->webhook_endpoint_id)->toBe($påTypen->id);
    expect(WebhookDelivery::query()->where('webhook_endpoint_id', $påAnnat->id)->count())->toBe(0);
});

it('en lyckad leverans markeras sent', function () {
    Http::fake(['https://example.com/notiser' => Http::response('ok', 200)]);
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$account] = webhookKonto();
    $endpoint = webhookEndpoint($account);
    $leverans = webhookLeverans($endpoint);

    webhookKor();

    // 2xx ger `sent` med `delivered_at` satt (Beslut 6). `attempts` räknas
    // upp också i utfallsvägen som lyckas.
    $rad = $leverans->fresh();
    expect($rad->status)->toBe(WebhookDelivery::STATUS_SENT);
    expect($rad->attempts)->toBe(1);
    expect($rad->response_status)->toBe(200);
    expect($rad->delivered_at)->not->toBeNull();
    expect($rad->last_error)->toBeNull();
});

it('kroppen bär ulid, typ och payload utan text', function () {
    Http::fake(['https://example.com/notiser' => Http::response('ok', 200)]);
    [$account] = webhookKonto();
    $container = Container::factory()->for($account, 'account')->create();
    $endpoint = webhookEndpoint($account);
    $payload = ['due_at' => '2027-05-05', 'title' => 'Byt impeller'];

    $notis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        container: $container,
        payload: $payload,
    );
    $leverans = WebhookDelivery::query()->where('notification_id', $notis->id)->firstOrFail();

    webhookKor();

    Http::assertSent(function ($request) use ($notis, $account, $container, $payload, $leverans): bool {
        $body = json_decode($request->body(), true);

        expect(array_keys($body))->toBe(['id', 'type', 'created_at', 'account', 'container', 'data']);
        expect($body['id'])->toBe($notis->ulid);
        expect($body['type'])->toBe($notis->type);
        expect($body['created_at'])->toBe($notis->created_at->utc()->format('Y-m-d\TH:i:s\Z'));
        expect($body['account'])->toBe($account->ulid);
        expect($body['container'])->toBe($container->ulid);
        // `data` är notisens payload, oförändrad — ingen renderad text, inget
        // språk (Beslut 6). ULID utåt: id/account/container bär ULID, och den
        // exakta nyckelmängden ovan lämnar inget rum för löpnummer.
        expect($body['data'])->toBe($payload);
        expect($leverans->notification_id)->toBe($notis->id);

        return true;
    });
});

it('signaturen verifierar mot hemligheten', function () {
    Http::fake(['https://example.com/notiser' => Http::response('ok', 200)]);
    [$account] = webhookKonto();
    $endpoint = webhookEndpoint($account);
    $leverans = webhookLeverans($endpoint);

    webhookKor();

    Http::assertSent(function ($request) use ($endpoint, $leverans): bool {
        $signatur = $request->header('X-Mimers-Signature')[0] ?? '';

        expect($signatur)->toMatch('/^t=\d+,v1=[a-f0-9]{64}$/');
        expect(app(WebhookSignature::class)->verify($endpoint->secret, $signatur, $request->body()))->toBeTrue();
        expect($request->header('X-Mimers-Event'))->toContain(Notification::TYPE_TASK_DUE);
        expect($request->header('X-Mimers-Delivery'))->toContain($leverans->ulid);
        expect($request->hasHeader('Content-Type', 'application/json'))->toBeTrue();

        return true;
    });
});

it('en ändrad kropp gör signaturen ogiltig', function () {
    $signatur = new WebhookSignature;
    $hemlighet = 'hemlig';
    $header = $signatur->header($hemlighet, 1788508800, '{"a":1}');

    expect($signatur->verify($hemlighet, $header, '{"a":1}'))->toBeTrue();
    expect($signatur->verify($hemlighet, $header, '{"a":2}'))->toBeFalse();
});

it('hemligheten läcker aldrig', function () {
    Http::fake(['https://example.com/notiser' => Http::response('fel', 500)]);
    config()->set('notiser.webhook.max_attempts', 1);
    config()->set('notiser.webhook.deactivate_after_failures', 1);
    $logg = Log::spy();
    [$account] = webhookKonto();
    $endpoint = webhookEndpoint($account, ['secret' => 'hemlig-'.str_repeat('x', 56)]);
    $leverans = webhookLeverans($endpoint);

    webhookKor();

    // En slutgiltigt misslyckad leverans: skälet i `last_error` innehåller
    // inte hemligheten (Beslut 9).
    $rad = $leverans->fresh();
    expect($rad->status)->toBe(WebhookDelivery::STATUS_FAILED);
    expect($rad->last_error)->not->toContain($endpoint->secret);

    // Hemligheten finns inte i kroppen och inte i någon header utöver själva
    // signaturen (och inte heller i signaturen — där står bara hex).
    Http::assertSent(function ($request) use ($endpoint): bool {
        foreach ($request->headers() as $namn => $värden) {
            foreach ($värden as $värde) {
                expect($värde)->not->toContain($endpoint->secret);
            }
        }

        expect($request->body())->not->toContain($endpoint->secret);

        return true;
    });

    // Inaktiveringsvarningen loggar ULID:er, inte hemligheten.
    $logg->shouldHaveReceived('warning')->once()->withArgs(
        fn (string $meddelande, array $kontext) => $meddelande === 'webhook.deactivated'
            && ! str_contains(json_encode($kontext), $endpoint->secret),
    );
});

it('ett 500-svar ger omförsök med backoff', function () {
    Http::fake(['https://example.com/notiser' => Http::response('fel', 500)]);
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$account] = webhookKonto();
    $endpoint = webhookEndpoint($account);
    $leverans = webhookLeverans($endpoint);

    webhookKor();

    // Annat än 2xx med försök kvar: `pending`, `next_attempt_at` en minut
    // fram (Beslut 6 och 8). `response_status` sätts när det fanns ett svar.
    $rad = $leverans->fresh();
    expect($rad->status)->toBe(WebhookDelivery::STATUS_PENDING);
    expect($rad->attempts)->toBe(1);
    expect($rad->response_status)->toBe(500);
    expect($rad->delivered_at)->toBeNull();
    expect($rad->next_attempt_at->eq(Carbon::parse('2026-09-04 12:01:00')))->toBeTrue();
});

it('backoffen växer exponentiellt', function () {
    Http::fake(['https://example.com/notiser' => Http::response('fel', 500)]);
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$account] = webhookKonto();
    $endpoint = webhookEndpoint($account);
    $leverans = webhookLeverans($endpoint);

    // 1, 2, 4 minuter — 2^(försök−1), takad vid 60 (Beslut 8). Försöket i
    // framtiden plockas inte, så `now()` flyttas fram till varje väntetid.
    webhookKor();
    expect($leverans->fresh()->next_attempt_at->eq(Carbon::parse('2026-09-04 12:01:00')))->toBeTrue();

    Carbon::setTestNow('2026-09-04 12:01:00');
    webhookKor();
    expect($leverans->fresh()->next_attempt_at->eq(Carbon::parse('2026-09-04 12:03:00')))->toBeTrue();

    Carbon::setTestNow('2026-09-04 12:03:00');
    webhookKor();
    expect($leverans->fresh()->next_attempt_at->eq(Carbon::parse('2026-09-04 12:07:00')))->toBeTrue();
});

it('en rad med next_attempt_at i framtiden hoppas över', function () {
    Http::fake(['https://example.com/notiser' => Http::response('ok', 200)]);
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$account] = webhookKonto();
    $endpoint = webhookEndpoint($account);
    $leverans = webhookLeverans($endpoint, [], ['next_attempt_at' => now()->addMinutes(5)]);

    webhookKor();

    // `next_attempt_at` i framtiden betyder att raden inte plockas (Beslut
    // 5) — ingen request, inga försök.
    expect($leverans->fresh()->status)->toBe(WebhookDelivery::STATUS_PENDING);
    expect($leverans->fresh()->attempts)->toBe(0);
    Http::assertNothingSent();
});

it('leveransen ger upp efter max_attempts', function () {
    Http::fake(['https://example.com/notiser' => Http::response('fel', 500)]);
    config()->set('notiser.webhook.max_attempts', 2);
    [$account] = webhookKonto();
    $endpoint = webhookEndpoint($account);
    $leverans = webhookLeverans($endpoint, [], ['attempts' => 1]);

    webhookKor();

    // Andra felet passerar max_attempts: `failed`, endpointens
    // `consecutive_failures` +1 (Beslut 6).
    $rad = $leverans->fresh();
    expect($rad->status)->toBe(WebhookDelivery::STATUS_FAILED);
    expect($rad->attempts)->toBe(2);
    expect($rad->response_status)->toBe(500);
    expect($endpoint->fresh()->consecutive_failures)->toBe(1);
});

it('en lyckad leverans nollställer consecutive_failures', function () {
    Http::fake(['https://example.com/notiser' => Http::response('ok', 200)]);
    [$account] = webhookKonto();
    $endpoint = webhookEndpoint($account, ['consecutive_failures' => 19]);
    $leverans = webhookLeverans($endpoint);

    webhookKor();

    // `consecutive_failures` nollställs vid varje lyckad leverans, även om
    // räknaren stod på 19 (Att se upp med) — annars inaktiveras en fungerande
    // endpoint av gamla fel.
    expect($leverans->fresh()->status)->toBe(WebhookDelivery::STATUS_SENT);
    expect($endpoint->fresh()->consecutive_failures)->toBe(0);
});

it('endpointen inaktiveras efter tillräckligt många fel', function () {
    Http::fake(['https://example.com/notiser' => Http::response('fel', 500)]);
    config()->set('notiser.webhook.max_attempts', 1);
    config()->set('notiser.webhook.deactivate_after_failures', 20);
    $logg = Log::spy();
    [$account] = webhookKonto();
    $endpoint = webhookEndpoint($account, ['consecutive_failures' => 19]);
    $leverans = webhookLeverans($endpoint);

    webhookKor();

    // När consecutive_failures når taket sätts is_active = false och en
    // varning loggas med ULID:er (Beslut 8).
    $rad = $leverans->fresh();
    expect($rad->status)->toBe(WebhookDelivery::STATUS_FAILED);

    $endpoint->refresh();
    expect($endpoint->is_active)->toBeFalse();
    expect($endpoint->consecutive_failures)->toBe(20);

    $logg->shouldHaveReceived('warning')->once()->withArgs(
        fn (string $meddelande, array $kontext) => $meddelande === 'webhook.deactivated'
            && ($kontext['endpoint_ulid'] ?? null) === $endpoint->ulid
            && ($kontext['account_ulid'] ?? null) === $account->ulid,
    );
});

it('en omdirigering följs inte', function () {
    // 302 med Location mot molnets metadatatjänst: utan withoutRedirecting()
    // skulle klienten följa med — och SSRF-kontrollen har redan gjorts på den
    // ursprungliga URL:en (Beslut 6). Fake:en svarar även på målet, så ett
    // följt anrop skulle synas som en andra request.
    Http::fake([
        'https://example.com/notiser' => Http::response('', 302, ['Location' => 'http://169.254.169.254/metadata']),
        '169.254.169.254/*' => Http::response('fångad', 200),
    ]);
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$account] = webhookKonto();
    $endpoint = webhookEndpoint($account);
    $leverans = webhookLeverans($endpoint);

    webhookKor();

    $rad = $leverans->fresh();
    expect($rad->status)->toBe(WebhookDelivery::STATUS_PENDING);
    expect($rad->attempts)->toBe(1);
    expect($rad->response_status)->toBe(302);
    Http::assertSentCount(1);
});

it('en url som blivit osäker ger failed direkt', function () {
    Http::fake();
    [$account] = webhookKonto();
    // IP-litralen 169.254.169.254 (molnets metadatatjänst) avvisas av
    // assertSafe utan DNS-uppslag — resolvern i beforeEach nås aldrig.
    $endpoint = webhookEndpoint($account, ['url' => 'https://169.254.169.254/notiser']);
    $leverans = webhookLeverans($endpoint);

    webhookKor();

    // SSRF-kontrollen körs igen före varje anrop (Beslut 7): faller den ger
    // `failed` direkt, utan omförsök och utan request. Endpointens
    // consecutive_failures ökar — en URL som blivit farlig är ett slutgiltigt
    // fel, inte ett tillfälligt.
    $rad = $leverans->fresh();
    expect($rad->status)->toBe(WebhookDelivery::STATUS_FAILED);
    expect($rad->attempts)->toBe(1);
    expect($rad->last_error)->toContain('osäker url');
    expect($endpoint->fresh()->consecutive_failures)->toBe(1);
    Http::assertNothingSent();
});

it('webhookar går ut även inom tysta timmar', function () {
    Http::fake(['https://example.com/notiser' => Http::response('ok', 200)]);
    [$account] = webhookKonto();
    $endpoint = webhookEndpoint($account);
    $notis = Notification::factory()->create([
        'account_id' => $account->id,
        'type' => Notification::TYPE_TASK_DUE,
        'payload' => [],
        'available_at' => now()->addHours(5),
    ]);
    $leverans = WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'notification_id' => $notis->id,
    ]);

    webhookKor();

    // `available_at` (tysta timmar) läses inte av webhook-loopen (Beslut 4):
    // en mottagande server sover inte.
    expect($leverans->fresh()->status)->toBe(WebhookDelivery::STATUS_SENT);
});

it('ett fel på en rad stoppar inte de andra', function () {
    Http::fake([
        'https://example.com/funkar' => Http::response('ok', 200),
        'https://example.com/kastar' => fn () => throw new RuntimeException('nätverksfel'),
    ]);
    [$account] = webhookKonto();
    $fungerande = webhookEndpoint($account, ['url' => 'https://example.com/funkar']);
    $kastande = webhookEndpoint($account, ['url' => 'https://example.com/kastar']);
    $lyckad = webhookLeverans($fungerande);
    $misslyckad = webhookLeverans($kastande);

    webhookKor();

    // En rad i taget, i en egen transaktion, och ett fel stoppar inte de
    // andra (Beslut 5): den kastande raden ligger kvar `pending` med felet
    // bokfört, den andra skickas.
    expect($lyckad->fresh()->status)->toBe(WebhookDelivery::STATUS_SENT);
    expect($misslyckad->fresh()->status)->toBe(WebhookDelivery::STATUS_PENDING);
    expect($misslyckad->fresh()->attempts)->toBe(1);
    expect($misslyckad->fresh()->last_error)->toContain('nätverksfel');
});

it('ett raderat konto tar med sig leveranser och endpoints', function () {
    [$account] = webhookKonto();
    $endpoint = webhookEndpoint($account);
    $container = Container::factory()->for($account, 'account')->create();

    // Både en container-notis och en kontonotis fläks ut — container-notisen
    // är det fall som kräver att leveransraderna försvinner före
    // container-gallringen inuti DeleteAccount (Beslut 10).
    $containerNotis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        container: $container,
        payload: [],
    );
    $kontoNotis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        payload: [],
    );
    expect(WebhookDelivery::query()->count())->toBe(2);
    expect($containerNotis->id)->not->toBe($kontoNotis->id);

    (new DeleteAccount(new PurgeContainer(new PurgeContent(new PurgeAttachment))))->handle($account);

    expect(DB::table('account')->where('id', $account->id)->exists())->toBeFalse();
    expect(DB::table('webhook_endpoint')->count())->toBe(0);
    expect(DB::table('webhook_delivery')->count())->toBe(0);
    expect(DB::table('notification')->count())->toBe(0);
});

it('en endpoint med leveransrader går att ta bort', function () {
    [$account, , $headers] = kontoMedMedlem();
    $endpoint = webhookEndpoint($account);
    webhookLeverans($endpoint);
    webhookLeverans($endpoint);

    $svar = deleteJson("/api/accounts/{$account->ulid}/webhooks/{$endpoint->ulid}", [], $headers);

    // 204 — leveransraderna städas FÖRE endpointen. Utan städningen blockerar
    // webhook_delivery (ON DELETE RESTRICT) varje radering av en endpoint som
    // någon gång tagit emot en händelse.
    $svar->assertNoContent();
    expect(DB::table('webhook_endpoint')->where('id', $endpoint->id)->exists())->toBeFalse();
    expect(DB::table('webhook_delivery')->where('webhook_endpoint_id', $endpoint->id)->count())->toBe(0);
});

it('en container med en utfläkt notis går att gallra', function () {
    [$account] = webhookKonto();
    $container = Container::factory()->for($account, 'account')->create();
    $endpoint = webhookEndpoint($account);

    $notis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        container: $container,
        payload: [],
    );
    expect(DB::table('webhook_delivery')->where('notification_id', $notis->id)->count())->toBe(1);

    // Papperskorgens gallring (20c) raderar mjukraderade containers — samma
    // sluttillstånd som container-raderingen lämnar efter sig.
    $container->delete();

    // Utan webhook_delivery-städningen i PurgeContainer kastar gallringen ett
    // integritetsfel här: leveransraden pekar på notisen.
    (new PurgeContainer(new PurgeContent(new PurgeAttachment)))->handle($container);

    expect(DB::table('notification')->where('id', $notis->id)->exists())->toBeFalse();
    expect(DB::table('webhook_delivery')->where('notification_id', $notis->id)->count())->toBe(0);
    // Endpointen ägs av kontot, inte av containern — gallringen rör den inte,
    // bara de leveransrader som pekar på containerns notiser.
    expect(DB::table('webhook_endpoint')->where('id', $endpoint->id)->exists())->toBeTrue();
});

it('en inaktiv endpoint behåller sina pending-rader', function () {
    Http::fake(['https://example.com/notiser' => Http::response('ok', 200)]);
    [$account] = webhookKonto();
    $endpoint = webhookEndpoint($account, ['is_active' => false]);
    $leverans = webhookLeverans($endpoint);

    webhookKor();

    // En endpoint som inaktiverades i natt ska inte tappa det som redan låg i
    // kön (Att se upp med): `pending`-rader fortsätter även om is_active är
    // false.
    expect($leverans->fresh()->status)->toBe(WebhookDelivery::STATUS_SENT);
});

it('jobbet är schemalagt varje minut', function () {
    // artisan(...) tvingar konsol-kerneln att bootstrapas, vilket i sin tur
    // laddar routes/console.php — den laddas annars inte under en vanlig
    // HTTP-/testrequest. "inspire" är ett ofarligt, redan existerande
    // kommando, valt bara för att trigga bootstrapet.
    artisan('inspire');

    $händelse = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'deliver-webhooks');

    expect($händelse)->not->toBeNull();
    expect($händelse->getExpression())->toBe('* * * * *');

    // Schemalagd som en closure (Schedule::call), inte som ett
    // Artisan-kommando — se AGENTS.md § Driftmiljön saknar proc_open.
    expect($händelse->command ?? null)->toBeNull();
    expect($händelse->withoutOverlapping)->toBeTrue();
});
