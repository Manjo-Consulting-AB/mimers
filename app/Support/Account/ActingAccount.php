<?php

namespace App\Support\Account;

use App\Models\Account;
use App\Models\Container;
use App\Models\User;

/**
 * Handlingens konto — kontot en handling tillskrivs, se [[ADR-0053 Flytt och
 * kopiering]] § 3.
 *
 * Regeln är avbockningens ([[Scheman och uppgifter]], 63b § Beslut 4), men
 * här på servern och med modeller i stället för listan av ULID:er:
 * **målcontainerns ägarkonto när användaren är medlem i det, annars hennes
 * personkonto**. En anställd som flyttar en fil till företagets container,
 * där hon är medlem, gör företaget till ägare; en frilansare som når en
 * främmande container genom en grant äger filen själv, inte kunden.
 *
 * Medlemskapet prövas mot MÅLET och inte mot källan: ägarskapet följer
 * handlingen, och handlingen sker där filen hamnar (ADR-0053 § 3
 * "Ägarskapet följer handlingen, inte åtkomsten").
 *
 * Klassen är en injicerbar stödklass av samma form som
 * App\Support\Plan\Entitlements, och den skriver ingenting — den svarar bara
 * på vems konto bytena ska räknas på. Prövningen mot planen och flytten av
 * räknarna är anroparens.
 */
final class ActingAccount
{
    public function for(User $user, Container $target): Account
    {
        if ($target->account->users()->whereKey($user->id)->exists()) {
            return $target->account;
        }

        // Personkontot är användarens EGET, och en person kan ha flera: en
        // medlem i en organisation ärver inget personkonto av den, men en
        // användare som skapats utanför inbjudan har alltid ett
        // (App\Actions\Auth\CreatesUserWithPersonalAccount). Flera
        // personkonton är en patologi; lägst `id` vinner, deterministiskt —
        // samma förval som App\Actions\Cost\CreateCostEntry::attributedAccountId().
        $personal = $user->accounts()
            ->where('account.type', 'personal')
            ->orderBy('account.id')
            ->first();

        if ($personal !== null) {
            return $personal;
        }

        // Ingen medlem alls i något personkonto: användarens första konto
        // duger, för ett konto måste handlingen ha. Saknas även det är
        // användaren trasig och felet ska höras — firstOrFail() ger 404, inte
        // en påhittad ägare.
        return $user->accounts()->orderBy('account.id')->firstOrFail();
    }
}
