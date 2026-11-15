<?php

use App\Http\Middleware\ScrubSentryEvent;
use App\Models\Account;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Sentry\ClientBuilder;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Issue 388 · Webbläsarens felrapportering, se [[ADR-0079 Övervakning]] § 1.
 *
 * Konfigurationen kommer från servern i propen `sentry`, inte från bygget:
 * samma artefakt rullas ut i staging och produktion.
 */

it('delar sentry med DSN, miljö, release och pseudonym när DSN finns', function () {
    withoutVite();
    config([
        'sentry.tracing.js_dsn' => 'https://nyckel@o1.ingest.de.sentry.io/2',
        'sentry.environment' => 'staging',
        'app.version' => 'v1.2.3',
    ]);

    $user = User::factory()->create();
    Account::factory()->create()->users()->attach($user, ['role' => 'owner']);

    actingAs($user);

    get('/about')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('sentry.dsn', 'https://nyckel@o1.ingest.de.sentry.io/2')
        ->where('sentry.environment', 'staging')
        ->where('sentry.release', 'v1.2.3')
        ->where('sentry.user', ScrubSentryEvent::pseudonym($user->ulid)));
});

it('delar sentry som null utan DSN, och pseudonymen som null för en gäst', function () {
    withoutVite();

    config(['sentry.tracing.js_dsn' => null]);
    get('/about')->assertInertia(fn (AssertableInertia $page) => $page->where('sentry', null));

    config(['sentry.tracing.js_dsn' => 'https://nyckel@o1.ingest.de.sentry.io/2']);
    get('/about')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('sentry.user', null)
        ->has('sentry.dsn'));
});

it('låter serverns Sentry-klient byggas med nyckeln för webbläsaren i konfigurationen', function () {
    config(['sentry.dsn' => 'https://nyckel@o1.ingest.de.sentry.io/1', 'sentry.tracing.js_dsn' => 'https://nyckel@o1.ingest.de.sentry.io/2']);

    // SDK:n kastar på okända toppnivånyckler; nyckeln bor därför under `tracing`.
    expect(app(ClientBuilder::class)->getClient()->getOptions()->getDsn()?->getProjectId())->toBe('1');
});

it('initierar @sentry/vue bara när sentry finns, utan PII, spårning eller replay', function () {
    $js = file_get_contents(base_path('resources/js/app.js'));

    expect($js)->toContain("import * as Sentry from '@sentry/vue'")
        ->and($js)->toContain('if (props.initialPage.props.sentry) {')
        ->and($js)->toContain('sendDefaultPii: false')
        ->and($js)->toContain('tracesSampleRate: 0')
        ->and($js)->toContain('beforeSend: scrubSentryEvent')
        ->and($js)->toContain('delete event.request.cookies')
        ->and($js)->toContain('delete event.user.ip_address')
        ->and($js)->not->toContain('replayIntegration')
        ->and($js)->not->toContain('VITE_SENTRY');

    // Initieringen står inom villkoret, inte före det.
    expect(strpos($js, 'if (props.initialPage.props.sentry) {'))
        ->toBeLessThan(strpos($js, 'initSentry(app, props.initialPage.props.sentry)'));
});

it('skapar dolda källkartor i bygget', function () {
    expect(file_get_contents(base_path('vite.config.js')))->toContain("sourcemap: 'hidden'");
});

it('laddar upp källkartor bara med SENTRY_AUTH_TOKEN och tar bort dem ur artefakten', function () {
    $yml = file_get_contents(base_path('.github/workflows/staging.yml'));
    $vite = file_get_contents(base_path('vite.config.js'));

    expect($yml)->toContain('SENTRY_AUTH_TOKEN: ${{ secrets.SENTRY_AUTH_TOKEN }}')
        ->and($vite)->toContain('Boolean(process.env.SENTRY_AUTH_TOKEN)')
        ->and($vite)->toContain('uploadSourcemaps && sentryVitePlugin(')
        ->and($yml)->toContain("find public/build -name '*.map' -delete");

    // npm ci körs i ett eget steg före det som har token.
    expect(strpos($yml, 'npm ci'))->toBeLessThan(strpos($yml, 'SENTRY_AUTH_TOKEN: ${{ secrets.SENTRY_AUTH_TOKEN }}'));

    // Borttagningen kommer efter bygget och före paketeringen.
    expect(strpos($yml, 'npm run build'))->toBeLessThan(strpos($yml, "-name '*.map' -delete"))
        ->and(strpos($yml, "-name '*.map' -delete"))->toBeLessThan(strpos($yml, 'tar -czf'));
});

it('transporterar SENTRY_JS_DSN i båda utrullningsflödena och har den tom i .env.example', function (string $fil) {
    $yml = file_get_contents(base_path(".github/workflows/{$fil}"));

    expect($yml)->toContain('SENTRY_JS_DSN: ${{ secrets.SENTRY_JS_DSN }}')
        ->and($yml)->toContain('"SENTRY_JS_DSN=$SENTRY_JS_DSN"')
        ->and(file_get_contents(base_path('.env.example')))->toMatch('/^SENTRY_JS_DSN=$/m');
})->with(['staging.yml', 'production.yml']);
