<?php

namespace App\Support\Auth;

use RuntimeException;

/**
 * Kastas av App\Support\Auth\TotpBroker::confirm() och ::disable() när
 * koden från appen inte stämmer mot den lagrade hemligheten — eller när
 * det inte finns någon hemlighet att pröva koden mot alls (t.ex.
 * `disable()` anropas utan att TOTP någonsin aktiverats). Se issue #19
 * § Beslut som redan är fattade punkt 5, felkoden `auth.totp_invalid`.
 */
final class TotpInvalidException extends RuntimeException {}
