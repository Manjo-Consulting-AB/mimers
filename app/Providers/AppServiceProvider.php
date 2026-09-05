<?php

namespace App\Providers;

use App\Support\Auth\LoginRateLimiter;
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
        $this->configureUploadRateLimiting();
        $this->configurePostmarkWebhookRateLimiting();
        $this->configureCalendarFeedRateLimiting();
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
     * Issue 33b · Postmark-webhookens takt. Den enda rutten i systemet som
     * en främmande server anropar — och den är oautentiserad i
     * auth:sanctum-mening, se App\Http\Controllers\Api\PostmarkWebhookController.
     * Utan en spärr kan en okänd avsändare hamra rutten med gissade lösenord
     * i obegränsad takt. Nyckeln är IP:n: det finns ingen inloggad användare
     * att knyta anropet till.
     *
     * Taket ligger högt med flit — Postmark skickar i skurar efter ett
     * utskick, och en spärr som slår i mot vår egen leverantör tappar
     * studsar. `config('notiser.postmark.webhook_rate_limit_per_minute')`
     * defaultar till 300, se config/notiser.php.
     */
    private function configurePostmarkWebhookRateLimiting(): void
    {
        RateLimiter::for('postmark-webhook', function (Request $request) {
            return Limit::perMinute((int) config('notiser.postmark.webhook_rate_limit_per_minute', 300))
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
}
