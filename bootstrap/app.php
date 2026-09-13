<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\UpdateLastActiveAt;
use App\Support\Api\ApiError;
use App\Support\Api\ValidationErrorMapper;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Issue 52: SetLocale ligger FÖRE HandleInertiaRequests — den senares
        // share() läser den locale som redan är satt. Bara i webbgruppen:
        // /api returnerar felkoder, aldrig meningar, och har inget språk att
        // välja (AGENTS.md § Felformat i API:et).
        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
        ]);

        // Issue 3: last_active_at uppdateras av alla autentiserade
        // API-anrop, inte bara inloggning. Se App\Http\Middleware\UpdateLastActiveAt.
        $middleware->api(append: [
            UpdateLastActiveAt::class,
        ]);

        // Issue 4: appen körs bakom LiteSpeed hos inleed, se
        // [[ADR-0020 Plattformsidentitet och frontendgräns]] §
        // Konsekvenser. LiteSpeed sitter lokalt på samma maskin som PHP,
        // så en betrodd proxy kommer alltid från loopbacken — REMOTE_ADDR
        // för ett inkommande anrop är annars den riktiga klienten, inte
        // proxyn. Omfånget hålls därför snävt till loopbacken:
        // `at: '*'` skulle låta VILKEN klient som helst sätta
        // X-Forwarded-Host/-Proto och styra vilka absoluta URL:er appen
        // genererar — inklusive signerade länkar (verifiering här,
        // magic link i issue 5) vars token och signatur då pekar mot en
        // domän klienten själv valde. Se tests/Feature/Auth/TrustProxiesTest.php,
        // som bevisar att en obetrodd avsändares X-Forwarded-* ignoreras.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Issue 7 · Rate limiting och felkodsformat. Höljet
         * `{ "error": { "code", "data" } }` gäller bara `/api`, aldrig
         * webbsidorna — se AGENTS.md § Felformat i API:et och
         * [[ADR-0020 Plattformsidentitet och frontendgräns]] § Konsekvenser:
         * "Felkodsregeln ... gäller därmed /api, inte webbsidorna." Webben
         * kör Inertia och ska behålla Laravels vanliga valideringsfel.
         *
         * Alla closures nedan utom ThrottleRequestsException returnerar null
         * för ett webbanrop, så Laravels vanliga felrendering tar över helt
         * oförändrad — `Handler::renderViaCallbacks()` fortsätter till nästa
         * registrerade closure (och sist till default-rendering) när en
         * closure returnerar null, se
         * vendor/laravel/framework/.../Foundation/Exceptions/Handler.php.
         * Undantaget är takgränsen, som sedan issue 53a § Beslut 6 svarar
         * webben med ett formulärfel i stället för en tom 429-sida.
         * Ordningen nedan spelar roll av samma skäl: mer specifika
         * undantagstyper registreras före den generella
         * Throwable-fångaren sist, som annars skulle vinna över dem.
         *
         * App\Exceptions\Api\ApiException behöver ingen egen closure här —
         * den implementerar Responsable och renderar sig själv innan
         * Laravel ens når fram till renderViaCallbacks(). Den kastas bara
         * från kod som redan vet att den körs på /api, t.ex.
         * App\Http\Controllers\Api\Auth\AuthenticatedTokenController.
         */
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiError::response('validation.failed', [
                'fields' => ValidationErrorMapper::fields($e),
            ], $e->status);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiError::response('auth.unauthenticated', [], 401);
        });

        // En vanlig AuthorizationException utan egen status (t.ex. en nekad
        // policy) mappas av Laravel till AccessDeniedHttpException innan
        // den når hit — se Handler::prepareException().
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiError::response('auth.forbidden', [], 403);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiError::response('resource.not_found', [], 404);
        });

        // Egen kod i stället för att falla igenom till den generella
        // server.error-fångaren nedan — fel HTTP-metod mot en giltig
        // rutt är inte ett oväntat fel, och en klient som får 405 ska
        // inte tro att servern kraschade.
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiError::response('resource.method_not_allowed', [], 405);
        });

        /*
         * Issue 53a § Beslut 6 · Takgränsen på webben.
         *
         * `throttle:login` kastar ThrottleRequestsException INNAN
         * kontrollern körs, så inget try/catch i en kontroller hjälper — en
         * webbsida skulle annars svara en tom 429-sida utan vare sig
         * förklaring eller väg tillbaka. Webben får i stället samma sak som
         * varje annat serverfel i ett formulär: tillbaka till formuläret
         * med felet på fältet `email`, formulerat med antalet sekunder.
         *
         * Det gäller varje webbrutt som kastar undantaget, inte bara
         * inloggningen — magic link-begäran delar samma begränsare, och det
         * är avsiktligt.
         *
         * `except('password')` är inte en detalj: `old()`-värden hamnar i
         * sessionen, och ett lösenord som ligger kvar där tills sessionen
         * töms är en läcka utan nytta.
         */
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            // ThrottleRequests-middlewaret (Illuminate\Routing\Middleware\ThrottleRequests)
            // sätter Retry-After i undantagets headers, inte som ett eget
            // konstruktorargument — se issue 7 § Beslut som redan är
            // fattade punkt 5. Retry-After-headern behålls också på /api,
            // utöver retry_after_seconds i kroppen.
            $retryAfterSeconds = (int) ($e->getHeaders()['Retry-After'] ?? 0);

            if (! $request->is('api/*')) {
                return back()
                    ->withInput($request->except('password'))
                    ->withErrors(['email' => __('auth.throttle', ['seconds' => $retryAfterSeconds])]);
            }

            return ApiError::response('auth.too_many_attempts', [
                'retry_after_seconds' => $retryAfterSeconds,
            ], 429)->withHeaders($e->getHeaders());
        });

        // Sista utväg: ett oväntat fel ska aldrig läcka undantagstext på
        // /api, se issue 7 § Att se upp med. Statuskoden bevaras när
        // undantaget känner till en (t.ex. en HttpException med en ovanlig
        // status) — bara meddelandet byts ut mot en stabil kod.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            // Läcker aldrig i produktion (config('app.debug') är false där),
            // men lokalt och i testsviten (phpunit.xml sätter inget
            // APP_DEBUG, den ärver true från .env) ska Laravels vanliga
            // felsida med stacktrace få rendera — annars felsöks varje
            // framtida API-issue i M1 och framåt mot en ogenomskinlig kod
            // i stället för en riktig stacktrace. De specifika
            // undantagstyperna ovanför den här closuren läcker ingenting
            // och är en del av kontraktet oavsett debug-läge.
            if (config('app.debug')) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            return ApiError::response('server.error', [], $status);
        });

        /*
         * Issue 51 § Beslut 6 · Felsidorna på webben.
         *
         * `respond()` körs EFTER allt ovan — den är sista steget innan
         * svaret lämnar handlern, se
         * Illuminate\Foundation\Exceptions\Handler::finalizeRenderedResponse()
         * — så den kan byta ut ett redan renderat svar utan att en enda av
         * render()-closurerna ovan flyttar sig. `/api`-höljet är alltså
         * orört: ett anrop dit returneras oförändrat, och likaså ett anrop
         * som ber om JSON.
         *
         * Statuskoderna är de fem en besökare kan landa i utan att ha gjort
         * något fel: nekad (403), saknad (404), utgången session (419),
         * för många försök (429) och serverfel (500). Under utveckling
         * (app.debug) behåller Laravel sin egen felsida med stacktrace,
         * precis som AGENTS.md § Felformat i API:et beskriver för
         * `server.error`.
         *
         * 419 är undantaget: användaren har fyllt i ett formulär och ska
         * inte förlora det till en sida som säger "419". Svaret blir
         * i stället en omdirigering tillbaka till formuläret med
         * `flash.status`, samma mekanism som resten av webben använder.
         */
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson() || config('app.debug')) {
                return $response;
            }

            $status = $response->getStatusCode();

            if ($status === 419) {
                return back()->with('status', 'session-expired');
            }

            if (! in_array($status, [403, 404, 429, 500], true)) {
                return $response;
            }

            return Inertia::render('Error', ['status' => $status])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
