<?php

use App\Console\WatchesDeliveryQueues;
use App\Models\Account;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Carbon;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\HubInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

/*
 * Issue 389 · Vakten för leveranskörna, ADR-0079 § 2. Sentry får en fejkad
 * transport, så provet ser exakt de meddelanden som skulle lämnat servern.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/** Slår på Sentry med en fejkad transport som samlar händelserna. */
function kovaktTransport(): object
{
    $transport = new class implements TransportInterface
    {
        /** @var list<Event> */
        public array $handelser = [];

        public function send(Event $event): Result
        {
            $this->handelser[] = $event;

            return new Result(ResultStatus::success(), $event);
        }

        public function close(?int $timeout = null): Result
        {
            return new Result(ResultStatus::success());
        }
    };

    config(['sentry.dsn' => 'https://nyckel@o0.ingest.de.sentry.io/1']);
    app()->extend(ClientBuilder::class, fn (ClientBuilder $b) => $b->setTransport($transport));
    app()->forgetInstance(HubInterface::class);
    SentrySdk::setCurrentHub(app(HubInterface::class));

    return $transport;
}

/** @return array{0: Account, 1: User} */
function kovaktKonto(): array
{
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $account->users()->attach($user, ['role' => 'owner']);

    return [$account, $user];
}

function kovaktNotis(Account $account, User $user, int $minuterSedan): Notification
{
    return Notification::factory()->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
        'type' => Notification::TYPE_TASK_DUE,
        'available_at' => now()->subMinutes($minuterSedan),
    ]);
}

function kovaktMejl(Account $account, User $user, int $minuterSedan, array $attribut = []): NotificationDelivery
{
    return NotificationDelivery::factory()->create(array_merge([
        'notification_id' => kovaktNotis($account, $user, $minuterSedan)->id,
    ], $attribut));
}

function kovaktWebhook(Account $account, User $user, int $minuterSedan): WebhookDelivery
{
    $endpoint = WebhookEndpoint::factory()->for($account, 'account')->create([
        'url' => 'https://hemlig-mottagare.example.com/hook',
    ]);

    return WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'notification_id' => kovaktNotis($account, $user, $minuterSedan)->id,
    ]);
}

it('larmar när en e-postleverans väntat i 61 minuter, med kö och ålder', function () {
    $transport = kovaktTransport();
    Carbon::setTestNow('2026-11-15 12:00:00');
    [$account, $user] = kovaktKonto();
    kovaktMejl($account, $user, 61);

    expect(app(WatchesDeliveryQueues::class)->handle())->toBe(['email']);

    expect($transport->handelser)->toHaveCount(1);
    $handelse = $transport->handelser[0];
    expect($handelse->getLevel())->toEqual(Severity::warning());
    expect($handelse->getMessage())->toContain('email')
        ->toContain('1 väntande')
        ->toContain('61 minuter');
    expect($handelse->getFingerprint())->toBe(['delivery-queue', 'email']);
});

it('larmar inte för en leverans som väntat i 59 minuter', function () {
    $transport = kovaktTransport();
    Carbon::setTestNow('2026-11-15 12:00:00');
    [$account, $user] = kovaktKonto();
    kovaktMejl($account, $user, 59);
    kovaktWebhook($account, $user, 59);

    expect(app(WatchesDeliveryQueues::class)->handle())->toBe([]);
    expect($transport->handelser)->toBeEmpty();
});

it('räknar inte en leverans som skjutits upp av tysta timmar', function () {
    $transport = kovaktTransport();
    Carbon::setTestNow('2026-11-15 12:00:00');
    [$account, $user] = kovaktKonto();
    kovaktMejl($account, $user, -480);
    kovaktWebhook($account, $user, -480);

    expect(app(WatchesDeliveryQueues::class)->handle())->toBe([]);
    expect($transport->handelser)->toBeEmpty();
});

it('larmar för en webhookleverans, med egen kö och eget fingeravtryck', function () {
    $transport = kovaktTransport();
    Carbon::setTestNow('2026-11-15 12:00:00');
    [$account, $user] = kovaktKonto();
    kovaktWebhook($account, $user, 90);
    kovaktWebhook($account, $user, 75);

    expect(app(WatchesDeliveryQueues::class)->handle())->toBe(['webhook']);

    $handelse = $transport->handelser[0];
    expect($handelse->getMessage())->toContain('webhook')
        ->toContain('2 väntande')
        ->toContain('90 minuter');
    expect($handelse->getFingerprint())->toBe(['delivery-queue', 'webhook']);
});

it('räknar bara väntande e-postleveranser, inte skickade, sammanfattningar eller andra kanaler', function () {
    $transport = kovaktTransport();
    Carbon::setTestNow('2026-11-15 12:00:00');
    [$account, $user] = kovaktKonto();
    kovaktMejl($account, $user, 120, ['status' => NotificationDelivery::STATUS_SENT]);
    kovaktMejl($account, $user, 120, ['digest' => true]);
    kovaktMejl($account, $user, 120, ['channel' => NotificationDelivery::CHANNEL_IN_APP]);

    expect(app(WatchesDeliveryQueues::class)->handle())->toBe([]);
    expect($transport->handelser)->toBeEmpty();
});

it('lämnar inga id:n eller adresser i meddelandet', function () {
    $transport = kovaktTransport();
    Carbon::setTestNow('2026-11-15 12:00:00');
    [$account, $user] = kovaktKonto();
    kovaktMejl($account, $user, 100);
    $webhook = kovaktWebhook($account, $user, 100);

    app(WatchesDeliveryQueues::class)->handle();

    expect($transport->handelser)->toHaveCount(2);

    foreach ($transport->handelser as $handelse) {
        $allt = json_encode([
            $handelse->getMessage(),
            $handelse->getTags(),
            $handelse->getExtra(),
            $handelse->getUser(),
            $handelse->getFingerprint(),
        ]);

        foreach ([$user->email, 'hemlig-mottagare', $webhook->ulid] as $forbjudet) {
            expect(str_contains($allt, (string) $forbjudet))->toBeFalse();
        }
        expect($handelse->getMessage())->not->toMatch('/@|https?:|\bid\b|\d{5,}/');
    }
});

it('är verkningslös utan DSN', function () {
    Carbon::setTestNow('2026-11-15 12:00:00');
    config(['sentry.dsn' => null]);
    [$account, $user] = kovaktKonto();
    kovaktMejl($account, $user, 200);

    expect(fn () => app(WatchesDeliveryQueues::class)->handle())->not->toThrow(Throwable::class);
});
