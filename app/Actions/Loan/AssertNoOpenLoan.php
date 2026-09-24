<?php

namespace App\Actions\Loan;

use App\Exceptions\Api\ApiException;
use App\Models\Item;
use App\Models\Loan;

/**
 * Spärren "högst en öppen utlåning per item": hittar den en öppen utlåning —
 * annan än `$except`, när ett lån håller på att återöppnas — avvisas
 * skrivningen med `loan.already_open` och den befintliga utlåningens ULID i
 * `data.loan`, så klienten kan peka ut raden som blockerar.
 *
 * Bryts ut ur App\Actions\Loan\CreateLoan och UpdateLoan (issue 110) av samma
 * skäl som de två actionerna själva: regeln låg förut i fyra kopior — två per
 * kontrolleryta, webb och `/api` — och två formuleringar av samma spärr glider
 * isär. Den är en check-then-act och MÅSTE anropas under `lockForUpdate()` på
 * item-raden (se CreateLoan och UpdateLoan): utan låset passerar två samtidiga
 * skrivningar och bryter regeln.
 *
 * Ett mjukraderat lån räknas inte — SoftDeletes' globala scope gäller genom
 * `$item->loans()`, och en borttagen registrering är ingen utlåning.
 */
class AssertNoOpenLoan
{
    public function handle(Item $item, ?Loan $except = null): void
    {
        $query = $item->loans()->whereNull('returned_at');

        if ($except !== null) {
            $query->whereKeyNot($except->getKey());
        }

        $open = $query->first();

        if ($open !== null) {
            throw ApiException::make('loan.already_open', ['loan' => $open->ulid], 422);
        }
    }
}
