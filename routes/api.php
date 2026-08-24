<?php

use App\Http\Controllers\Api\Auth\AuthenticatedTokenController;
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

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthenticatedTokenController::class, 'destroy']);

    // Samma kontroller som webbens verification.send, se
    // App\Http\Controllers\Auth\EmailVerificationNotificationController.
    Route::post('/email/verification-notification', EmailVerificationNotificationController::class);
});
