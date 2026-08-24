<?php

namespace App\Support\Auth;

use RuntimeException;

/**
 * Kastas av App\Support\Auth\MagicLinkBroker::consume() när token inte kan
 * lösas in av ett annat skäl än att giltighetstiden gått ut — hittas inte,
 * hör till en annan e-postadress, eller är redan förbrukad. Se issue #18
 * § Beslut som redan är fattade punkt 5: skiljs åt från
 * MagicLinkExpiredException eftersom API:et har en egen felkod för vardera,
 * `auth.magic_link_invalid` respektive `auth.magic_link_expired`.
 */
final class MagicLinkInvalidException extends RuntimeException {}
