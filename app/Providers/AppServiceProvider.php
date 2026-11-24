<?php

namespace App\Providers;

use App\Actions\Access\ResolveItemScope;
use App\Http\Controllers\Mcp\AuthorizeController;
use App\Http\Controllers\Mcp\Middleware\ApproveAgentConnection;
use App\Http\Controllers\Mcp\Middleware\RequireS256CodeChallenge;
use App\Listeners\RecordsScheduleHeartbeat;
use App\Support\Api\ApiError;
use App\Support\Auth\LoginRateLimiter;
use App\Support\Monitoring\QueryTracker;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Issue 70 § Beslut 10. ItemPolicy anropas en gång per item i en
        // listning, så upplösningen memoiserar sitt svar — men `scoped()`,
        // inte `singleton()`: en singleton hade burit en användares omfång
        // vidare till nästa kö-jobb i samma worker, vilket är ett läckage
        // och inte en optimering. Se App\Actions\Access\ResolveItemScope.
        $this->app->scoped(ResolveItemScope::class);
        $this->app->scoped(QueryTracker::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureLoginRateLimiting();
        $this->configureUploadRateLimiting();
        $this->configureMailgunWebhookRateLimiting();
        $this->configureCalendarFeedRateLimiting();
        $this->configureRegistrationRateLimiting();
        $this->configureHelpSearchRateLimiting();
        $this->configureHelpFeedbackRateLimiting();
        $this->configureScheduleHeartbeat();
        $this->configurePerformanceLogging();
        $this->configureMcp();
    }

    /**
     * Issue 400 · OAuth-servern för MCP-klienterna, se [[ADR-0087
     * MCP-server för agenter]] Riktning 5 och 12.
     *
     * **Livslängderna.** Access-token gäller en timme och refresh-token trettio
     * dagar; Passport roterar refresh-token vid varje användning
     * (`Passport::$revokeRefreshTokenAfterUse` är sann som standard), så en
     * stulen refresh-token går inte att använda två gånger.
     *
     * **Godkännandevyn.** Passports egen blade ersätts av appens sida i
     * `resources/js/pages/Mcp/Authorize.vue`, renderad av
     * App\Http\Controllers\Mcp\AuthorizeController. `SimpleViewResponse`
     * accepterar en `Responsable`, men kontraktet säger en Symfony-respons —
     * därför `toResponse()` här i stället för att lita på att mellanlagret
     * råkar finnas.
     *
     * **Två grindar läggs på Passports rutter.** `RequireS256CodeChallenge`
     * stänger `plain` före auktoriseringsservern, och
     * `ApproveAgentConnection` gör godkännandet till en agentrad.
     * `booted()` behövs: Passport registrerar sina rutter i sin egen
     * `boot()`, och rutterna finns inte förrän alla leverantörer har bootat.
     * Middlewaren läggs EFTER rutternas egna — `web` och `auth:web` — så
     * sessionen finns när den läser `authToken`.
     */
    private function configureMcp(): void
    {
        if (! class_exists(Passport::class)) {
            return;
        }

        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(30));

        Passport::authorizationView(
            fn (array $parameters): SymfonyResponse => app(AuthorizeController::class)($parameters)->toResponse(request()),
        );

        $this->app->booted(function (): void {
            $this->appendMiddlewareToRoute(
                'passport.authorizations.authorize',
                RequireS256CodeChallenge::class,
            );

            $this->appendMiddlewareToRoute(
                'passport.authorizations.approve',
                ApproveAgentConnection::class,
            );
        });
    }

    /**
     * @param  class-string  $middleware
     */
    private function appendMiddlewareToRoute(string $name, string $middleware): void
    {
        $route = Route::getRoutes()->getByName($name);

        if (! $route instanceof RoutingRoute) {
            return;
        }

        $route->middleware([$middleware]);
    }

    /**
     * Issue 390 · ADR-0079 § 3. Lyssnaren på databasfrågor räknar frågorna per
     * förfrågan och loggar de långsamma, se App\Support\Monitoring\QueryTracker.
     * Den gör ingenting i testmiljön om inte
     * `monitoring.track_queries_in_tests` är sant.
     */
    private function configurePerformanceLogging(): void
    {
        DB::listen(fn ($query) => $this->app->make(QueryTracker::class)->record($query));
    }

    /**
     * Issue 43 · Dead man's switch, Beslut 4. Lyssnaren på
     * Illuminate\Console\Events\ScheduledTaskFinished skriver en
     * heartbeat-rad per schemapost som kört klart utan fel — se
     * App\Listeners\RecordsScheduleHeartbeat och App\Models\Heartbeat.
     * Registreringen ligger här i stället för som en rad per post i
     * routes/console.php: en post till är en `Schedule::call(...)` till, utan
     * att någon behöver komma ihåg en femtonde rad någon annanstans.
     */
    private function configureScheduleHeartbeat(): void
    {
        Event::listen(ScheduledTaskFinished::class, RecordsScheduleHeartbeat::class);
    }

    /**
     * Issue 7 · Rate limiting och felkodsformat. Begränsar inloggning per
     * e-postadress OCH per IP samtidigt — vilken gräns som helst räcker
     * för att blockera, se issue 7 § Beslut som redan är fattade punkt 5.
     * `$request->ip()` går att lita på: TrustProxies skärptes i issue 4
     * till att bara betro loopbacken (LiteSpeed sitter lokalt hos inleed),
     * se bootstrap/app.php och tests/Feature/Auth/TrustProxiesTest.php —
     * ingen egen headerhantering byggs här, se punkt 6.
     *
     * Namnet App\Support\Auth\LoginRateLimiter::NAME används av
     * `throttle:login`-middleware på både webbens och API:ets
     * inloggningsrutt (routes/web.php, routes/api.php) så att samma två
     * gränser gäller oavsett yta. Magic link (issue 5) kan återanvända
     * samma middleware rakt av på sin egen inloggningsrutt, så länge den
     * routen också har ett `email`-fält i requesten.
     *
     * Nyckelkonstruktionen delas med App\Support\Auth\LoginRateLimiter,
     * som webbens och API:ets inloggningskontroller använder för att rensa
     * båda gränserna efter en lyckad inloggning — se den klassens
     * docblock för varför nycklarna måste byggas exakt likadant på båda
     * ställena.
     *
     * Exakta trösklar (5/minut per e-post, 10/minut per IP) är inte
     * specificerade i dokumentationen — se PR:ens "Frågor och antaganden".
     */
    private function configureLoginRateLimiting(): void
    {
        RateLimiter::for(LoginRateLimiter::NAME, function (Request $request) {
            $email = $request->string('email')->toString();

            return [
                Limit::perMinute(5)->by(LoginRateLimiter::emailKey($email)),
                Limit::perMinute(10)->by(LoginRateLimiter::ipKey($request)),
            ];
        });
    }

    /**
     * Issue 16a · Uppladdningstakten. Uppladdningsrutten är den första som
     * skriver obegränsat med byte — utan en spärr kan en autentiserad
     * användare loopa unikt (icke-dedupbart) innehåll och fylla kontots
     * disk, se kodgranskningsfynd 5. Kvoten är issue 27; det här är bara en
     * teknisk spärr på anropsfrekvensen.
     *
     * Nyckeln är användaren, inte IP:n: rutten är autentiserad, och flera
     * personer kan dela en IP (NAT) utan att de ska dela varandras budget.
     * `config('files.upload_rate_limit_per_minute')` defaultar till 60, se
     * config/files.php.
     */
    private function configureUploadRateLimiting(): void
    {
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute((int) config('files.upload_rate_limit_per_minute', 60))
                ->by($request->user()->id ?? $request->ip());
        });
    }

    /**
     * Issue 38b · Mailgun-webhookens takt. Den enda rutten i systemet som
     * en främmande server anropar — och den är oautentiserad i
     * auth:sanctum-mening, se App\Http\Controllers\Api\MailgunWebhookController.
     * Utan en spärr kan en okänd avsändare hamra rutten i obegränsad takt.
     * Nyckeln är IP:n: det finns ingen inloggad användare att knyta anropet
     * till.
     *
     * Taket ligger högt med flit — Mailgun skickar i skurar efter ett
     * utskick, och en spärr som slår i mot vår egen leverantör tappar
     * studsar. `config('notiser.mailgun.webhook_rate_limit_per_minute')`
     * defaultar till 300, se config/notiser.php.
     */
    private function configureMailgunWebhookRateLimiting(): void
    {
        RateLimiter::for('mailgun-webhook', function (Request $request) {
            return Limit::perMinute((int) config('notiser.mailgun.webhook_rate_limit_per_minute', 300))
                ->by($request->ip());
        });
    }

    /**
     * Issue 36b · ICS-kalenderfeedens takt. Feedrutten
     * (App\Http\Controllers\CalendarFeedDownloadController) är oautentiserad
     * — tokenet i URL:en är autentiseringen — och en spärr ska hindra den
     * som gissar token i loop. Nyckeln är TOKENET, inte IP:n: kalenderklienter
     * delar utgående IP i mobilnät och bakom brandväggar, och en spärr per IP
     * skulle stänga av alla i samma nät (Beslut 7).
     *
     * `config('notiser.calendar.rate_limit_per_minute')` defaultar till 60,
     * se config/notiser.php.
     */
    private function configureCalendarFeedRateLimiting(): void
    {
        RateLimiter::for('calendar', fn (Request $request) => Limit::perMinute(
            (int) config('notiser.calendar.rate_limit_per_minute', 60)
        )->by((string) $request->route('token')));
    }

    /**
     * Issue 1041 · registreringens takt. `unique:user,email` i
     * RegisterRequest körs före kodkontrollen, så utan spärr kunde vem som
     * helst pröva obegränsat många adresser utan inbjudningskod och få veta
     * vilka som har konto (uppräkning av adresser, ADR-0017). Registreringen
     * var den enda oautentiserade skrivrutten utan spärr.
     *
     * Nyckeln är IP:n: det finns ingen användare att knyta anropet till
     * ännu. `$request->ip()` går att lita på tack vare `trustProxies` i
     * bootstrap/app.php. Begränsaren räknar alla försök, också lyckade, och
     * rensas aldrig.
     */
    private function configureRegistrationRateLimiting(): void
    {
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(10)->by('register-ip:'.$request->ip()));
    }

    /**
     * M81 · issue 466 · sökningens takt, `throttle:help-search`.
     *
     * Rutten svarar JSON från web.php och är inget formulär. Den inbyggda
     * formen `throttle:60,1` kastar ThrottleRequestsException, som
     * bootstrap/app.php (issue 53a § Beslut 6) gör om till en omdirigering
     * tillbaka till ett formulär — men sökrutan har inget formulär att gå
     * tillbaka till. En namngiven begränsare med en egen svarskropp ger i
     * stället `429` med `auth.too_many_attempts`: middlewaret kastar
     * HttpResponseException med svaret i handen, och API-höljet når klienten
     * som vilket annat felsvar som helst (AGENTS.md § Felformat i API:et).
     *
     * Tröskeln är 60 per minut och IP — samma som issue 466 anger.
     */
    private function configureHelpSearchRateLimiting(): void
    {
        RateLimiter::for('help-search', fn (Request $request) => Limit::perMinute(60)
            ->by('help-search-ip:'.$request->ip())
            ->response(static fn (Request $request, array $headers): JsonResponse => ApiError::response(
                'auth.too_many_attempts',
                ['retry_after_seconds' => (int) ($headers['Retry-After'] ?? 0)],
                429,
            )->withHeaders($headers)));
    }

    /**
     * M82 · issue 471 · återkopplingens takt, `throttle:help-feedback`.
     *
     * Två gränser, en per anropare: en gäst räknas per IP (20 i timmen), en
     * inloggad per person (60 i timmen). Personnyckeln gäller framför IP:n
     * därför att flera personer kan dela en adress bakom NAT — samma skäl
     * som uppladdningens begränsare. `$request->ip()` går att lita på tack
     * vare `trustProxies` i bootstrap/app.php.
     *
     * Svarskroppen är API-höljet, som för sökningen: rutterna svarar JSON
     * och ytan översätter koden. Den inbyggda 429-renderingen på webben
     * omdirigerar till ett formulär, och det finns inget formulär här.
     *
     * `ThrottleWrites` hoppar över en rutt som redan har `throttle:...` i sin
     * middleware (App\Http\Middleware\ThrottleWrites::hasOwnLimiter), så
     * anropet räknas en gång och inte två.
     */
    private function configureHelpFeedbackRateLimiting(): void
    {
        RateLimiter::for('help-feedback', function (Request $request): Limit {
            $user = $request->user();

            $limit = $user === null
                ? Limit::perHour(20)->by('help-feedback-ip:'.$request->ip())
                : Limit::perHour(60)->by('help-feedback-user:'.$user->getKey());

            return $limit->response(static fn (Request $request, array $headers): JsonResponse => ApiError::response(
                'auth.too_many_attempts',
                ['retry_after_seconds' => (int) ($headers['Retry-After'] ?? 0)],
                429,
            )->withHeaders($headers));
        });
    }
}
