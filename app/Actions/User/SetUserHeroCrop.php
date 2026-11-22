<?php

namespace App\Actions\User;

use App\Actions\User\Concerns\ManagesUserImage;
use App\Models\User;

/**
 * Sparar fokuspunkt och zoom på personens hjältebild — vilken del av bilden
 * som ska stå i hjältens mitt, och hur mycket som ska skäras bort runt den.
 * Se [[ADR-0090 Dashboardens hjälte]] § 2 och [[ADR-0076 Profilbilden]] § 7,
 * issue 434.
 *
 * **Ingen Gate: objektet är anroparen själv.** Samma form som
 * App\Actions\User\SetUserAvatarCrop — raden som skrivs är `$request->user()`.
 *
 * **En person UTAN hjältebild har ingen punkt att sätta.** Saknas
 * `hero_attachment_id` returneras `false` och kontrollern svarar 404.
 *
 * Radlåset och skrivningen delas med profilbilden i
 * App\Actions\User\Concerns\ManagesUserImage; prefixet `hero` väljer
 * kolumnerna.
 */
class SetUserHeroCrop
{
    use ManagesUserImage;

    public function handle(User $user, int $x, int $y, int $zoom): bool
    {
        return $this->cropUserImage($user, $x, $y, $zoom, 'hero');
    }
}
