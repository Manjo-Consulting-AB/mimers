<?php

namespace App\Actions\User;

use App\Actions\Attachment\PurgeAttachment;
use App\Actions\User\Concerns\ManagesUserImage;
use App\Models\User;

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
 * nyckeln är RESTRICT.
 *
 * En person UTAN bild är inget fel: anropet är en no-op och returnerar
 * false, så ytan kan visa "ta bort bilden" utan att först behöva veta om det
 * finns någon.
 *
 * Sedan issue 434 bor transaktionen och radlåset i
 * App\Actions\User\Concerns\ManagesUserImage, tillsammans med hjältebildens
 * likadana väg. Prefixet `avatar` väljer kolumnerna.
 */
class RemoveUserAvatar
{
    use ManagesUserImage;

    public function __construct(private readonly PurgeAttachment $purgeAttachment) {}

    public function handle(User $user): bool
    {
        return $this->removeUserImage($user, 'avatar', $this->purgeAttachment);
    }
}
