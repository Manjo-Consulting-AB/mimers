<?php

namespace App\Support\Auth;

use RuntimeException;

/**
 * Kastas av App\Http\Requests\Auth\LoginRequest::authenticate() (issue 6b
 * · TOTP vid inloggning) när kontot har en bekräftad TOTP
 * (`totp_confirmed_at` är satt) men requesten inte skickade någon kod
 * alls. Skild från App\Support\Auth\TotpInvalidException — se issue 6b §
 * Beslut som redan är fattade punkt 5: "auth.totp_required när koden
 * saknas, auth.totp_invalid när den är fel." En klient som ser
 * `auth.totp_required` vet att den ska visa ett kodfält, i stället för
 * att felaktigt tolka svaret som en fel kod.
 *
 * Kastas bara EFTER att lösenordet redan är kontrollerat — se
 * LoginRequest::authenticate() och issue 6b § Beslut som redan är fattade
 * punkt 2: fel lösenord ska aldrig avslöja om kontot har tvåfaktor
 * påslagen.
 */
final class TotpRequiredException extends RuntimeException {}
