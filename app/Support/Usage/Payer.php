<?php

namespace App\Support\Usage;

use App\Models\Container;
use App\Models\User;
use App\Support\Account\ActingAccount;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;

/**
 * Kontot som betalar för en ny räknad rad ([[ADR-0081 All lagrad data räknas
 * mot kvoten]] § 3): det agerande kontot, alltså samma regel som för
 * bilagor — containerns ägarkonto när användaren är medlem i det, annars
 * hennes personkonto (App\Support\Account\ActingAccount).
 *
 * Raden skapas av `$actor`. Saknas aktören — den färdiga kategorimallen
 * anropar CreateCategory utan en, eftersom anroparen skriver loggraden själv —
 * är det den inloggade användaren som handlar. Finns ingen inloggad (en
 * konsolkörning), eller saknar hon konto, betalar containerns ägare.
 */
final class Payer
{
    public static function idFor(?User $actor, Container $container): int
    {
        $actor ??= Auth::user();

        if (! $actor instanceof User) {
            return $container->account_id;
        }

        try {
            return (new ActingAccount)->for($actor, $container)->id;
        } catch (ModelNotFoundException) {
            // En användare helt utan konto (hennes personkonto är raderat men
            // hon har kvar en åtkomst) har inget eget konto att belasta. Då
            // betalar containerns ägare, som när en betalare försvinner
            // (ADR-0081 § 4) — i stället för att skrivningen svarar 404.
            return $container->account_id;
        }
    }
}
