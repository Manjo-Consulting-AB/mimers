<?php

namespace App\Actions\User;

use App\Actions\Attachment\PurgeAttachment;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Tar bort personens profilbild — [[ADR-0076 Profilbilden]] § 2 och 5, issue
 * 380: pekaren nollställs och bilagan rensas.
 *
 * **Rensas, inte mjukraderas.** En profilbild går inte till papperskorgen
 * (ADR-0076 § 2): den som ångrar sig laddar upp bilden igen.
 * PurgeAttachment minskar `reference_count` och lämnar bytena på disken med
 * `purge_after` satt.
 *
 * **Ordningen: pekaren först, bilagan sedan.** PurgeAttachment nollställer
 * själv `user.avatar_attachment_id` innan den raderar bilagan hårt, eftersom
 * nyckeln är RESTRICT. Att nollställa först gör ordningen till actionens
 * egen regel i stället för en bieffekt av en främmande nyckel — samma
 * resonemang som App\Actions\Container\RemoveContainerCover.
 *
 * **Pekaren läses och nollställs under radlås**, av samma skäl som i
 * SetUserAvatar: en samtidig borttagning och ett samtidigt bildbyte som båda
 * läste pekaren ur egna instanser kunde lämna den ena bilagan föräldralös —
 * aldrig nollställd, aldrig rensad, kvar på kontots `reference_count` och
 * kvot.
 *
 * En person UTAN bild är inget fel: anropet är en no-op och returnerar
 * false, så ytan kan visa "ta bort bilden" utan att först behöva veta om det
 * finns någon.
 *
 * `Attachment::withTrashed()` vid uppslaget: pekaren kan peka på en bilaga
 * som redan mjukraderats. PurgeAttachment är idempotent per rad och tål en
 * sådan rad.
 */
class RemoveUserAvatar
{
    public function __construct(private readonly PurgeAttachment $purgeAttachment) {}

    public function handle(User $user): bool
    {
        return DB::transaction(function () use ($user): bool {
            $rad = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->first();

            if ($rad === null || $rad->avatar_attachment_id === null) {
                return false;
            }

            $bilagaId = $rad->avatar_attachment_id;

            $rad->avatar_attachment_id = null;
            $rad->save();

            $bilaga = Attachment::withTrashed()->find($bilagaId);

            if ($bilaga !== null) {
                $this->purgeAttachment->handle($bilaga);
            }

            // Instansen anroparen skickade in bär den nollställda pekaren.
            $user->refresh();

            return true;
        });
    }
}
