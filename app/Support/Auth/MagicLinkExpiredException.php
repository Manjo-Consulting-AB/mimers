<?php

namespace App\Support\Auth;

use RuntimeException;

/**
 * Kastas av App\Support\Auth\MagicLinkBroker::consume() när token annars är
 * giltigt (rätt hash, rätt e-postadress, oanvänt) men `expires_at` har
 * passerat. Se issue #18 § Beslut som redan är fattade punkt 5 och
 * App\Support\Auth\MagicLinkInvalidException för varför det här är en egen
 * undantagstyp och inte samma som ett ogiltigt token.
 */
final class MagicLinkExpiredException extends RuntimeException {}
