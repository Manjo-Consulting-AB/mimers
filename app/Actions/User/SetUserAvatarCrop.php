<?php

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;

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
 * kontrollerna gör det till en 404, för ett utsnitt på en bild som inte
 * finns är ett anrop mot ett objekt som inte finns. Det är inte samma no-op
 * som RemoveUserAvatar gör när bilden redan är borta.
 *
 * **Raden läses och skrivs under radlås** (samma form som SetUserAvatar):
 * bytet ligger i en transaktion och `avatar_attachment_id` läses ur en färsk
 * rad med `lockForUpdate` — inte ur instansen anroparen skickade in. Utan
 * låset kunde en samtidig borttagning av bilden hinna emellan, och utsnittet
 * hade skrivits på en person som inte längre har någon bild.
 *
 * Alla tre kolumnerna skrivs i samma `save()`, så CHECK-villkoret
 * `user_avatar_focus_pair` (2026_11_15_000000) aldrig ser en halv punkt:
 * `x` utan `y` når aldrig databasen.
 */
class SetUserAvatarCrop
{
    public function handle(User $user, int $x, int $y, int $zoom): bool
    {
        return DB::transaction(function () use ($user, $x, $y, $zoom): bool {
            $rad = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->first();

            if ($rad === null || $rad->avatar_attachment_id === null) {
                return false;
            }

            $rad->avatar_focus_x = $x;
            $rad->avatar_focus_y = $y;
            $rad->avatar_zoom = $zoom;
            $rad->save();

            // Instansen anroparen skickade in bär det nya utsnittet — annars
            // vore den en förlegad läsning av samma slag som låset stänger.
            $user->refresh();

            return true;
        });
    }
}
