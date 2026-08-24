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
    })->create();
