<?php

use App\Http\Controllers\AttachmentDownloadController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\MagicLinkLoginController;
use App\Http\Controllers\Auth\MagicLinkRequestController;
use App\Http\Controllers\Auth\RecoveryCodeController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TotpController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\CalendarFeedDownloadController;
use App\Http\Controllers\ExportDownloadController;
use App\Http\Controllers\HeartbeatController;
use App\Http\Controllers\UnsubscribeController;
use App\Support\Auth\LoginRateLimiter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome', [
    'version' => app()->version(),
]))->name('welcome');

/*
 * Issue 4 · Autentisering med lösenord. Webben kör på Laravels
 * sessionsguard med CSRF, inte Sanctums cookie-läge — se
 * [[ADR-0011 Autentisering]] och [[ADR-0021 Frontendteknik]]. Motsvarande
 * API-endpoints ligger i routes/api.php och delar FormRequests med de här
 * rutterna, se App\Http\Controllers\Api\Auth.
 *
 * Inga GET-rutter som renderar Inertia-formulär läggs till här — de hör
 * till frontend-milstolpen (M10), inte den här issuen. `RegisterRequest`
 * och `LoginRequest` (validering + FormRequests) är det som testas.
 */
Route::middleware('guest')->group(function () {
    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->name('register');

    // throttle:login · issue 7 · Rate limiting och felkodsformat. Se
    // App\Providers\AppServiceProvider::configureLoginRateLimiting().
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:'.LoginRateLimiter::NAME)
        ->name('login');

    /*
     * Issue 5 · Magic link. throttle:login återanvänds rakt av på
     * begäranrutten — se AppServiceProvider::configureLoginRateLimiting(),
     * som uttryckligen namnger magic link som en tilltänkt återanvändare,
     * och issue #18 § Beslut som redan är fattade punkt 4. Konsumtionsrutten
     * (mejllänken, App\Support\Auth\MagicLinkBroker::url()) begränsas inte
     * separat — se App\Support\Auth\MagicLinkBroker § Beslut 4 för
     * resonemanget om entropi i stället för en gräns.
     */
    Route::post('/login/magic-link', [MagicLinkRequestController::class, 'store'])
        ->middleware('throttle:'.LoginRateLimiter::NAME)
        ->name('magic-link.request');

    Route::get('/login/magic-link/consume', MagicLinkLoginController::class)
        ->name('magic-link.consume');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
        ->middleware('signed')
        ->name('verification.verify');

    Route::post('/email/verification-notification', EmailVerificationNotificationController::class)
        ->name('verification.send');

    /*
     * Issue #19 · TOTP-hemlighet: aktivering och verifiering. Se
     * App\Http\Controllers\Auth\TotpController och
     * App\Support\Auth\TotpBroker. Inloggningskravet (en bekräftad TOTP
     * måste anges vid inloggning) är issue 6b (#31) och rörs inte här.
     */
    Route::post('/totp', [TotpController::class, 'store'])
        ->name('totp.setup');

    Route::post('/totp/confirm', [TotpController::class, 'confirm'])
        ->name('totp.confirm');

    Route::delete('/totp', [TotpController::class, 'destroy'])
        ->name('totp.destroy');

    /*
     * Issue 6c · Återställningskoder. Se
     * App\Http\Controllers\Auth\RecoveryCodeController och
     * App\Support\Auth\RecoveryCodeBroker. Ingen egen bekräftelsekod krävs
     * — se RecoveryCodeBroker § Beslut 4.
     */
    Route::post('/totp/recovery-codes', [RecoveryCodeController::class, 'store'])
        ->name('totp.recovery-codes.store');
});

/*
 * Issue 19a · Nedladdning av bilagor, se
 * App\Http\Controllers\AttachmentDownloadController och [[ADR-0019
 * Filleverans]]. En rutt på appdomänen utanför /api (Beslut 1) — den klickas
 * i en webbläsare, behöver sessionen och lämnar inga JSON-fel. Guarden är
 * auth:sanctum (Beslut 2): samma rutt autentiserar en inloggad webbsession
 * och en Authorization: Bearer-token, så en kommande mobilapp får inte en
 * andra väg till samma bytes. `{attachment}` binds på bilagans ULID via
 * #[RouteKey('ulid')] — ingen nästling under container och item.
 */
Route::get('/files/{attachment}', AttachmentDownloadController::class)
    ->middleware('auth:sanctum')
    ->name('files.download');

/*
 * Issue 41b · Nedladdning av en färdig export, se
 * App\Http\Controllers\ExportDownloadController och [[Backlog]] M6 § 41.
 * Samma placering och middleware som /files/{attachment}, av samma skäl
 * (Beslut 1): den klickas i en webbläsare och ska inte svara med JSON-fel.
 * `{export}` binds på exportens ULID via #[RouteKey('ulid')].
 */
Route::get('/exports/{export}/download', ExportDownloadController::class)
    ->middleware('auth:sanctum')
    ->name('exports.download');

/*
 * Issue 32b · Avanmälan från en notistyp utan inloggning, se
 * App\Http\Controllers\UnsubscribeController och
 * App\Support\Notification\UnsubscribeLink. Rutterna är signerade och ligger
 * medvetet utanför `auth`-gruppen — mottagaren klickar i en mejlklient och har
 * ingen session (Beslut 1).
 *
 * CSRF är avstängt på POST:en (PreventRequestForgery): List-Unsubscribe
 * One-Click (RFC 8058) skickar en POST utan användarmedverkan och utan någon
 * session, och den signerade URL:en är hela skyddet (Beslut 1, riskklassen
 * bygger på det). `{user}` binds på `ulid`, `{type}` valideras i kontrollern.
 */
Route::get('/notifications/unsubscribe/{user}/{type}', [UnsubscribeController::class, 'confirm'])
    ->middleware('signed')
    ->name('notifications.unsubscribe.confirm');

Route::post('/notifications/unsubscribe/{user}/{type}', [UnsubscribeController::class, 'store'])
    ->middleware('signed')
    ->withoutMiddleware(PreventRequestForgery::class)
    ->name('notifications.unsubscribe');

/*
 * Issue 36b · ICS-kalenderfeed, se App\Http\Controllers\CalendarFeedDownloadController,
 * App\Support\Notification\IcsDocument och [[Notiser]] § ICS-kalenderfeed.
 * Rutten ligger medvetet utanför `auth`-gruppen: kalenderklienten har varken
 * session eller cookie och hämtar i bakgrunden — tokenet i URL:en är
 * autentiseringen (Beslut 1). Sökvägen `/kalender/{token}.ics` är kontraktet
 * 36a § Beslut 6 utlovade och får inte ändras — en URL som redan ligger i
 * någons kalenderapp går inte att döpa om.
 *
 * `where('token', '[A-Za-z0-9]{64}')` gör en manipulerad URL till en 404
 * från routern, utan databasfråga. `throttle:calendar` är taket mot den som
 * gissar token i loop, se
 * App\Providers\AppServiceProvider::configureCalendarFeedRateLimiting().
 */
Route::get('/kalender/{token}.ics', CalendarFeedDownloadController::class)
    ->middleware('throttle:'.'calendar')
    ->where('token', '[A-Za-z0-9]{64}')
    ->name('calendar.feed');

/*
 * Issue 43 · Dead man's switch-ytan, se
 * App\Http\Controllers\HeartbeatController och issue 43 § Beslut 6. Rutten
 * ligger medvetet utanför `auth`-gruppen: vakten på VPS:en har varken session
 * eller cookie — den delade hemligheten i X-Drift-Token är autentiseringen
 * (Beslut 1). Kontrollern avvisar med 404 när headern saknas, är fel, eller
 * när config('drift.token') är osatt.
 *
 * `throttle:60,1` är en spärr mot den som hamrar ytan i gissningssyfte —
 * inline-formen nycklar på IP:n, vilket räcker här: den enda tänkta
 * anroparen (vakten, en gång i timmen) delar inte utgående IP med någon.
 */
Route::get('/drift/heartbeat', HeartbeatController::class)
    ->middleware('throttle:60,1');
