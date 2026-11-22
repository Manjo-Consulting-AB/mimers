<?php

namespace App\Actions\User;

use App\Actions\User\Concerns\ManagesUserImage;
use App\Models\User;

/**
 * Sätter fokuspunkt och zoom på personens profilbild — den del av bilden som
 * ska stå i mitten av avataren och hur mycket som ska skäras bort runt den.
 * Se [[ADR-0076 Profilbilden]] § 7 och [[M59 Profilbilden]] § 382.
 *
 * **Ingen Gate: objektet är anroparen själv.** Till skillnad från
 * App\Actions\Container\SetContainerCoverFocus finns här ingen container att
 * auktorisera mot — båda anroparytorna pekar på `$request->user()`, och det
 * finns ingen ruttparameter att peka någon annan med. Samma form som
 * App\Actions\User\SetUserAvatar.
 *
 * **En person UTAN bild har ingen punkt att sätta.** Saknar raden
 * `avatar_attachment_id` returneras `false` och ingenting skrivs —
 * kontrollerna gör det till en 404, för ett utsnitt på en bild som inte finns
 * är ett anrop mot ett objekt som inte finns. Det är inte samma no-op som
 * RemoveUserAvatar gör när bilden redan är borta.
 *
 * Sedan issue 434 bor radlåset och skrivningen i
 * App\Actions\User\Concerns\ManagesUserImage, tillsammans med hjältebildens
 * likadana väg. Prefixet `avatar` väljer kolumnerna.
 */
class SetUserAvatarCrop
{
    use ManagesUserImage;

    public function handle(User $user, int $x, int $y, int $zoom): bool
    {
        return $this->cropUserImage($user, $x, $y, $zoom, 'avatar');
    }
}
