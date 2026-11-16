<?php

/*
 * Issue 387 · Felrapportering från servern till Sentry, ADR-0079 § 1.
 *
 * SDK:n får en fejkad transport i stället för HTTP, så provet ser exakt de
 * händelser som skulle lämnat servern.
 */

use App\Exceptions\Api\ApiException;
use App\Http\Middleware\ScrubSentryEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\call;
use function Pest\Laravel\get;

/** Slår på Sentry med ett DSN och en fejkad transport. */
function felrapporteringPaslagen(?string $dsn = 'https://nyckel@o0.ingest.de.sentry.io/1'): object
{
    // Samlar händelserna i $handelser i stället för att skicka dem.
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

    config(['sentry.dsn' => $dsn]);
    app()->extend(ClientBuilder::class, fn (ClientBuilder $b) => $b->setTransport($transport));
    app()->forgetInstance(HubInterface::class);
    SentrySdk::setCurrentHub(app(HubInterface::class));

    return $transport;
}

/** Testrutter som kastar undantag; ett anrop till dem går genom hela stacken. */
beforeEach(function () {
    config(['app.debug' => false]);

    Route::middleware('web')->group(function () {
        Route::get('/_fel/kaos', fn () => throw new RuntimeException('kaos'));
        Route::get('/_fel/validering', fn () => throw ValidationException::withMessages(['x' => 'fel']));
        Route::get('/_fel/saknas', fn () => throw new ModelNotFoundException);
        Route::get('/_fel/nekad', fn () => throw new AuthorizationException);
        Route::get('/_fel/apiklient', fn () => throw ApiException::make('x.y', [], 422));
        Route::get('/_fel/apiserver', fn () => throw ApiException::make('x.y', [], 503));
    });
});

it('startar och är avstängd utan DSN', function () {
    get('/_fel/kaos')->assertStatus(500);

    $klient = app(HubInterface::class)->getClient();

    // Utan DSN väljer SDK:n NullTransport, så ingenting kan lämna servern.
    expect(config('sentry.dsn'))->toBeNull()
        ->and($klient?->getOptions()->getDsn())->toBeNull();
});

it('har ingen prestandaspårning, inga SQL-brödsmulor och inga personuppgifter som förval', function () {
    expect(config('sentry.traces_sample_rate'))->toBe(0)
        ->and(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.breadcrumbs.sql_queries'))->toBeFalse()
        ->and(config('sentry.breadcrumbs.sql_bindings'))->toBeFalse();
});

it('rapporterar ett RuntimeException med release och environment', function () {
    config(['sentry.environment' => 'staging', 'app.version' => 'v1.2.3']);
    $transport = felrapporteringPaslagen();
    app(HubInterface::class)->getClient()->getOptions()->setEnvironment('staging');

    get('/_fel/kaos')->assertStatus(500);

    expect($transport->handelser)->toHaveCount(1);
    $handelse = $transport->handelser[0];
    expect($handelse->getExceptions()[0]->getType())->toBe(RuntimeException::class)
        ->and($handelse->getRelease())->toBe('v1.2.3')
        ->and($handelse->getEnvironment())->toBe('staging');
});

it('tar environment ur SENTRY_ENVIRONMENT och reserverar APP_ENV', function () {
    $laes = function (array $env) {
        // Spara och återställ exakt: phpunit.xml sätter APP_ENV i $_ENV/$_SERVER
        // och en kvarglömd ändring skulle ändra varje senare prov.
        $forr = [];
        foreach ($env as $k => $v) {
            $forr[$k] = [$_ENV[$k] ?? null, $_SERVER[$k] ?? null, getenv($k)];
            $_ENV[$k] = $_SERVER[$k] = $v;
            putenv("{$k}={$v}");
        }

        try {
            return require base_path('config/sentry.php');
        } finally {
            foreach ($forr as $k => [$e, $s, $g]) {
                if ($e === null) {
                    unset($_ENV[$k]);
                } else {
                    $_ENV[$k] = $e;
                }
                if ($s === null) {
                    unset($_SERVER[$k]);
                } else {
                    $_SERVER[$k] = $s;
                }
                $g === false ? putenv($k) : putenv("{$k}={$g}");
            }
        }
    };

    expect($laes(['SENTRY_ENVIRONMENT' => 'production', 'APP_ENV' => 'staging'])['environment'])->toBe('production')
        ->and($laes(['SENTRY_ENVIRONMENT' => '', 'APP_ENV' => 'staging'])['environment'])->toBe('staging');
});

it('rapporterar inte validering, 404, 403, 419 eller ApiException under 500', function (string $uri) {
    $transport = felrapporteringPaslagen();

    get($uri);

    expect($transport->handelser)->toBe([]);
})->with(['/_fel/validering', '/_fel/saknas', '/_fel/nekad', '/_fel/apiklient', '/_fel/finns-inte']);

it('rapporterar inte TokenMismatchException (419)', function () {
    $transport = felrapporteringPaslagen();
    Route::middleware('web')->get('/_fel/token', fn () => throw new TokenMismatchException);

    get('/_fel/token');

    expect($transport->handelser)->toBe([]);
});

it('rapporterar ApiException med statuskod 500 eller över', function () {
    $transport = felrapporteringPaslagen();

    get('/_fel/apiserver');

    expect($transport->handelser)->toHaveCount(1);
});

it('skickar ingen IP-adress, inga kakor, ingen request-kropp och användaren som pseudonym', function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    $transport = felrapporteringPaslagen();
    $user = User::factory()->create();

    actingAs($user);
    call('GET', '/_fel/kaos', ['q' => 1], ['hemlig' => 'kaka'], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_COOKIE' => 'hemlig=kaka']);

    expect($transport->handelser)->toHaveCount(1);
    $handelse = $transport->handelser[0];
    $request = $handelse->getRequest();

    expect($request)->not->toHaveKey('data')
        ->and($request)->not->toHaveKey('cookies')
        ->and(json_encode($request))->not->toContain('203.0.113.9')
        ->and(json_encode($request))->not->toContain('kaka');

    $anvandare = $handelse->getUser();
    expect($anvandare->getIpAddress())->toBeNull()
        ->and($anvandare->getEmail())->toBeNull()
        ->and($anvandare->getUsername())->toBeNull()
        ->and($anvandare->getId())->toBe(substr(hash_hmac('sha256', $user->ulid, config('app.key')), 0, 16))
        ->and($anvandare->getId())->toHaveLength(16)
        ->and($anvandare->getId())->not->toBe($user->ulid);

    expect(json_encode($handelse->getUser()))->not->toContain($user->email);
});

it('behåller bara brödsmulor på warning och uppåt', function () {
    $handelse = Event::createEvent();
    $handelse->setBreadcrumb([
        new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'log.info', 'bara info'),
        new Breadcrumb(Breadcrumb::LEVEL_WARNING, Breadcrumb::TYPE_DEFAULT, 'log.warning', 'en varning'),
        new Breadcrumb(Breadcrumb::LEVEL_ERROR, Breadcrumb::TYPE_DEFAULT, 'log.error', 'ett fel'),
    ]);

    $rensad = ScrubSentryEvent::beforeSend($handelse);

    expect(array_map(fn ($b) => $b->getMessage(), $rensad->getBreadcrumbs()))->toBe(['en varning', 'ett fel']);
});

it('bygger ingen text av kommandoraden i brödsmulorna', function () {
    // ADR-0070: command_info => true gör (string) $input, som anropar
    // escapeshellarg() för ord som config:cache. Funktionen står i inleeds
    // disable_functions, så utrullningen faller. Slå inte på funktionen i
    // panelen; den kan återställas.
    expect(config('sentry.breadcrumbs.command_info'))->toBeFalse();
});

it('har klientfelslistan på ett ställe', function () {
    expect(ScrubSentryEvent::isClientError(ApiException::make('a.b', [], 499)))->toBeTrue()
        ->and(ScrubSentryEvent::isClientError(ApiException::make('a.b', [], 500)))->toBeFalse()
        ->and(ScrubSentryEvent::isClientError(new RuntimeException))->toBeFalse();
});

it('transporterar SENTRY_LARAVEL_DSN och SENTRY_ENVIRONMENT i båda utrullningsflödena', function (string $fil) {
    $yml = file_get_contents(base_path(".github/workflows/{$fil}"));

    foreach (['SENTRY_LARAVEL_DSN', 'SENTRY_ENVIRONMENT'] as $nyckel) {
        expect($yml)->toContain("{$nyckel}: \${{ secrets.{$nyckel} }}")
            ->and($yml)->toContain("\"{$nyckel}=\${$nyckel}\"");
    }
})->with(['staging.yml', 'production.yml']);

it('har SENTRY_LARAVEL_DSN och SENTRY_ENVIRONMENT tomma i .env.example', function () {
    $env = file_get_contents(base_path('.env.example'));

    expect($env)->toMatch('/^SENTRY_LARAVEL_DSN=$/m')
        ->and($env)->toMatch('/^SENTRY_ENVIRONMENT=$/m');
});

it('kräver sentry/sentry-laravel i composer.json', function () {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true);

    expect($composer['require'])->toHaveKey('sentry/sentry-laravel');
});
