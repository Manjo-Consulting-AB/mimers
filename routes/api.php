<?php

use App\Http\Controllers\Api\Auth\AuthenticatedTokenController;
use App\Http\Controllers\Api\Auth\MagicLinkLoginController;
use App\Http\Controllers\Api\Auth\MagicLinkRequestController;
use App\Http\Controllers\Api\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Support\Auth\LoginRateLimiter;
use Illuminate\Support\Facades\Route;

/*
 * Issue 4 · Autentisering med lösenord. Personal access tokens för
 * mobilappar och B2B, se [[ADR-0011 Autentisering]]. Delar
 * RegisterRequest/LoginRequest med webbens sessionsinloggning i
 * routes/web.php — samma ogiltiga indata avvisas likadant på båda ytorna.
 */
Route::post('/register', [RegisteredUserController::class, 'store']);

// throttle:login · issue 7 · Rate limiting och felkodsformat. Se
// App\Providers\AppServiceProvider::configureLoginRateLimiting().
Route::post('/login', [AuthenticatedTokenController::class, 'store'])
    ->middleware('throttle:'.LoginRateLimiter::NAME);

/*
 * Issue 5 · Magic link. throttle:login återanvänds rakt av på
 * begäranrutten, se motsvarande kommentar i routes/web.php och
 * App\Support\Auth\MagicLinkBroker. Konsumtionsrutten utfärdar en
 * personal access token, se App\Http\Controllers\Api\Auth\MagicLinkLoginController.
 */
Route::post('/login/magic-link', [MagicLinkRequestController::class, 'store'])
    ->middleware('throttle:'.LoginRateLimiter::NAME);

Route::post('/login/magic-link/consume', [MagicLinkLoginController::class, 'store']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthenticatedTokenController::class, 'destroy']);

    // Samma kontroller som webbens verification.send, se
    // App\Http\Controllers\Auth\EmailVerificationNotificationController.
    Route::post('/email/verification-notification', EmailVerificationNotificationController::class);
});
