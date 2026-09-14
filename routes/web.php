<?php

use App\Http\Controllers\ActiveContainerController;
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
use App\Http\Controllers\ContainerController;
use App\Http\Controllers\ExportDownloadController;
use App\Http\Controllers\HeartbeatController;
use App\Http\Controllers\Settings\AccountSettingsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\UnsubscribeController;
use App\Support\Auth\LoginRateLimiter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Issue 51 · Frontendskalet (M10). Rutten renderar Inertia-komponenten
 * med samma namn som filen under resources/js/pages/ — `Welcome` här,
 * `Auth/Login` för en nästlad sida — och ingenting annat. Sidnamnet är
 * kontraktet: app.js löser upp det mot import.meta.glob över pages/, så en
 * omdöpning bryter varje Inertia::render() som pekar på det.
 *
 * Den skyddade exempvyn. Avsiktligt tom på innehåll — issue 64 ersätter
 * den med todo-vyn — och därför en closure i stället för en controller
 * som ändå ska bort.
 */
Route::get('/dashboard', fn () => Inertia::render('Dashboard'))
    ->middleware('auth')
    ->name('dashboard');

Route::get('/', fn () => Inertia::render('Welcome'))->name('welcome');

/*
 * Issue 4 · Autentisering med lösenord. Webben kör på Laravels
 * sessionsguard med CSRF, inte Sanctums cookie-läge — se
 * [[ADR-0011 Autentisering]] och [[ADR-0021 Frontendteknik]]. Motsvarande
 * API-endpoints ligger i routes/api.php och delar FormRequests med de här
 * rutterna, se App\Http\Controllers\Api\Auth.
 *
 * `RegisterRequest` och `LoginRequest` (validering + FormRequests) är det
 * som testas i den här gruppen. GET-rutterna som renderar Inertia-formulären
 * kom med M10 (issue 51) och ligger där de hör hemma: inloggningssidan i
 * den här gruppen, eftersom bara en utloggad besökare ska se den.
 */
Route::middleware('guest')->group(function () {
    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->name('register');

    /*
     * Issue 53a · registreringsformuläret. Samma grupp och samma skäl som
     * `login.create` nedan: bara en utloggad besökare ska se det, och en
     * inloggad skickas till /dashboard av `guest`-middlewaren.
     */
    Route::get('/register', [RegisteredUserController::class, 'create'])
        ->name('register.create');

    // throttle:login · issue 7 · Rate limiting och felkodsformat. Se
    // App\Providers\AppServiceProvider::configureLoginRateLimiting().
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:'.LoginRateLimiter::NAME)
        ->name('login');

    /*
     * Issue 51 § Beslut 10 · GET /login. Rutten heter `login.create` och
     * inte `login`: namnet `login` är taget av POST-rutten ovan, som
     * `auth`-middlewaren skickar en utloggad besökare till
     * (route('login')), och att byta namn på den bryter varje
     * route('login') i ramverket. Före den här rutten svarade en sådan
     * omdirigering 405.
     */
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])
        ->name('login.create');

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

    /*
     * Issue 53a · formuläret som begär länken. `back()` i store() ovan
     * landar här, så flashkoden `magic-link-sent` renderas på samma sida
     * som formuläret — se App\Http\Controllers\Auth\MagicLinkRequestController.
     */
    Route::get('/login/magic-link', [MagicLinkRequestController::class, 'create'])
        ->name('magic-link.create');

    Route::get('/login/magic-link/consume', MagicLinkLoginController::class)
        ->name('magic-link.consume');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function () {
    /*
     * Issue 53a · Verifieringssidan. Namnet `verification.notice` är inte
     * fritt valt: Laravels `verified`-middleware skickar en overifierad
     * användare till just route('verification.notice'), och utan den här
     * rutten kraschar ramverket den dag någon sätter `verified` på en rutt.
     * Ingen rutt har `verified` i den här issuen — kravet gäller att ta emot
     * delning, inte att använda appen (routes/api.php).
     */
    Route::get('/email/verify', [VerifyEmailController::class, 'create'])
        ->name('verification.notice');

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

    /*
     * Issue 53b · Säkerhetssidan — tvåfaktorns aktivering, avstängning och
     * återställningskoder. Den ENDA rutt den här issuen lägger till, och
     * den enda den får lägga till: en GET som renderar. Formulären i vyn
     * postar till de fyra rutter som redan ligger i den här gruppen ovan.
     *
     * Ingen rutt för /settings utan underväg. Den som går dit får 404 —
     * med flit, se issue 53b § Beslut 2: en tom mellansida ingen sedan tar
     * bort är sämre än en ärlig 404, och issue 53c gör profilen till
     * inställningarnas förstasida.
     */
    Route::get('/settings/security', SecurityController::class)
        ->name('settings.security');

    /*
     * Issue 53c · Kontoinställningarna — profil och konton, se
     * App\Http\Controllers\Settings\ProfileController och
     * App\Http\Controllers\Settings\AccountSettingsController.
     *
     * Här får /settings äntligen ett hem. 53b lämnade den som en ärlig 404
     * med flit (issue 53b § Beslut 2) och utlovade att 53c gör profilen till
     * inställningarnas förstasida. Omdirigeringen är en egen rutt och inte en
     * sida, så /settings aldrig renderar något eget — den som bokmärkt
     * adressen hamnar rätt, och en tom mellansida uppstår aldrig.
     *
     * `{account}` binds på kontots ULID via #[RouteKey('ulid')] på
     * App\Models\Account — aldrig på löpnumret, och aldrig ur kroppen
     * (Beslut 1). Ett konto som identifieras i kroppen är en rutt utan objekt
     * att auktorisera mot; här finns objektet i rutten och
     * App\Policies\AccountPolicy::update() prövas mot det i kontrollern.
     *
     * En ny inställningssida får också en egen rad i
     * resources/js/layouts/settingsSections.js — navigationen renderas ur den
     * listan, och en sida ingen kan navigera till är en sida ingen hittar.
     */
    Route::redirect('/settings', '/settings/profile')->name('settings');

    Route::get('/settings/profile', [ProfileController::class, 'edit'])
        ->name('settings.profile');

    Route::patch('/settings/profile', [ProfileController::class, 'update'])
        ->name('settings.profile.update');

    Route::get('/settings/accounts', [AccountSettingsController::class, 'index'])
        ->name('settings.accounts');

    Route::patch('/settings/accounts/{account}', [AccountSettingsController::class, 'update'])
        ->name('settings.accounts.update');

    /*
     * Issue 54 · Containerytan — listan, skapandet och redigeringen, se
     * App\Http\Controllers\ContainerController och
     * App\Http\Controllers\ActiveContainerController.
     *
     * Sex rutter, och den första webbytan mot en domänresurs `/api` redan
     * äger. Ingenting av API:et byggs om: StoreContainerRequest,
     * UpdateContainerRequest och ContainerResource delas rakt av, och
     * skapandet går genom App\Actions\Container\CreateContainer — samma
     * action som Api\ContainerController::store() anropar (Beslut 3).
     * Vägen till `/api` är alltså inte kopierad hit, den är delad.
     *
     * `{container}` binds på ULID via #[RouteKey('ulid')] på
     * App\Models\Container, som överallt annars.
     *
     * **Ingen GET /containers/{container}.** Pärmens egen sida är itemlistan
     * och den är issue 57 (Beslut 2). En tom detaljvy nu blir en sida 57
     * skriver om ändå, och två sidor som slåss om samma URL är dyrare än en
     * URL som ännu inte finns. Listan länkar till edit, ingenting annat.
     *
     * **Ingen DELETE.** Papperskorgen som återställer en raderad pärm är
     * issue 62, och ingen issue i M10 beställer en raderingsknapp innan dess.
     *
     * `/containers/create` ligger före `/containers/{container}/edit` i
     * filen för läsbarhetens skull — `create` är ett fast segment och
     * `{container}` en parameter, så de kan inte kollidera.
     */
    Route::get('/containers', [ContainerController::class, 'index'])
        ->name('containers.index');

    Route::get('/containers/create', [ContainerController::class, 'create'])
        ->name('containers.create');

    Route::post('/containers', [ContainerController::class, 'store'])
        ->name('containers.store');

    Route::get('/containers/{container}/edit', [ContainerController::class, 'edit'])
        ->name('containers.edit');

    Route::patch('/containers/{container}', [ContainerController::class, 'update'])
        ->name('containers.update');

    /*
     * Den aktiva pärmen sätts på tre ställen (Beslut 6): här, i store() ovan,
     * och i App\Support\Frontend\ActiveContainer::set() som är den enda som
     * rör sessionsnyckeln. `view`-grinden och inte `update`: att välja vilken
     * pärm man arbetar i är att läsa.
     */
    Route::put('/containers/{container}/active', ActiveContainerController::class)
        ->name('containers.active');
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

/*
 * Issue 51 § Beslut 6 · Felsidan kräver en matchad rutt.
 *
 * `web`-middlewaregruppen hänger på rutter, inte på routerns väg för "ingen
 * träff" (ApplicationBuilder::buildRoutingCallback() registrerar
 * routes/web.php med Route::middleware('web')->group(...)). En URL som inte
 * matchar någon rutt alls når därför aldrig HandleInertiaRequests, och
 * respond()-closuren i bootstrap/app.php skulle rendera Error utan `auth` —
 * en krasch i klienten
 * (`props.auth.user` är undefined) för precis det scenario felsidan finns
 * till. Fallback-rutten ligger i samma grupp, så de delade propsen hinner
 * delas innan 404:an kastas och fångas.
 *
 * `(?!api/)` håller /api utanför: en oregistrerad /api-URL ska svara 404
 * eller 405 ur routern, precis som förut — annars skuggas
 * MethodNotAllowed-uppslaget (GET-fallbacken matchar då en POST-väg och
 * 405 blir 404, eller tvärtom) och felkodshöljets tester faller.
 */
Route::fallback(fn () => abort(404))
    ->where('fallbackPlaceholder', '(?!api/).*');
