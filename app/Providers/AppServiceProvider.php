<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureLoginRateLimiting();
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
     * Namnet `login` används av `throttle:login`-middleware på både
     * webbens och API:ets inloggningsrutt (routes/web.php, routes/api.php)
     * så att samma två gränser gäller oavsett yta. Magic link (issue 5)
     * kan återanvända samma middleware rakt av på sin egen inloggningsrutt,
     * så länge den routen också har ett `email`-fält i requesten.
     *
     * Exakta trösklar (5/minut per e-post, 10/minut per IP) är inte
     * specificerade i dokumentationen — se PR:ens "Frågor och antaganden".
     */
    private function configureLoginRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = $request->string('email')->lower()->toString();

            return [
                Limit::perMinute(5)->by('login-email:'.$email),
                Limit::perMinute(10)->by('login-ip:'.$request->ip()),
            ];
        });
    }
}
