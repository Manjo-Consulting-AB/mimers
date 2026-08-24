<?php

namespace App\Support\Auth;

use RuntimeException;

/**
 * Kastas av App\Support\Auth\TotpBroker::generate() och ::confirm() när
 * kontot redan har en bekräftad TOTP (`user.totp_confirmed_at` är satt).
 * Se issue #19 § Beslut som redan är fattade punkt 4: en hemlighet som
 * redan är aktiverad får inte bytas ut eller bekräftas på nytt bara för
 * att någon är inloggad — det skulle låta en kapad session tyst byta ut
 * tvåfaktorn utan det bevis (`TotpBroker::disable()`) som krävs för att
 * stänga av den. Se App\Support\Auth\TotpInvalidException för den andra
 * felkoden, `auth.totp_invalid`.
 */
final class TotpAlreadyConfirmedException extends RuntimeException {}
