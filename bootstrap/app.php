<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\UpdateLastActiveAt;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // Issue 3: last_active_at uppdateras av alla autentiserade
        // API-anrop, inte bara inloggning. Se App\Http\Middleware\UpdateLastActiveAt.
        $middleware->api(append: [
            UpdateLastActiveAt::class,
        ]);

        // Issue 4: appen körs bakom LiteSpeed hos inleed, så absoluta
        // URL:er (verifieringslänkar, magic links senare) blir bara
        // korrekta om Laravel litar på X-Forwarded-*-headrarna från
        // proxyn framför den, se [[ADR-0020 Plattformsidentitet och
        // frontendgräns]] § Konsekvenser. `at: '*'` litar på proxyn
        // oavsett IP — den exakta adressen står inte i läslistan (se
        // PR:ens "Frågor och antaganden"), och eftersom LiteSpeed sitter
        // lokalt framför PHP på samma server, inte bakom ett publikt
        // proxyfleet, är det den konservativa tolkningen av "sätt upp
        // TrustProxies" snarare än att gissa en specifik IP.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
