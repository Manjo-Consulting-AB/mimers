<?php

namespace App\Support\Auth;

use RuntimeException;

/**
 * Kastas av App\Support\Auth\RecoveryCodeBroker::generate() när kontot inte
 * har en bekräftad TOTP (`user.totp_confirmed_at` är NULL). Se issue 6c §
 * Frågor och antaganden: återställningskoder ersätter TOTP-koden vid
 * inloggning (se App\Http\Requests\Auth\LoginRequest::authenticate()), och
 * den kontrollen körs bara när kontot redan har en bekräftad TOTP — koder
 * utfärdade utan det vore aldrig konsumerbara, bara död vikt i databasen.
 */
final class TotpNotConfirmedException extends RuntimeException {}
