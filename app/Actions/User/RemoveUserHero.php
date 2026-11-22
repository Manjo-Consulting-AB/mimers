<?php

namespace App\Actions\User;

use App\Actions\Attachment\PurgeAttachment;
use App\Actions\User\Concerns\ManagesUserImage;
use App\Models\User;

/**
 * Tar bort personens hjältebild — pekaren nollställs och bilagan rensas.
 * Se [[ADR-0090 Dashboardens hjälte]] § 2 och [[ADR-0076 Profilbilden]] § 2
 * och 5, issue 434.
 *
 * **Rensas, inte mjukraderas**, som profilbilden: den som ångrar sig laddar
 * upp bilden igen.
 *
 * En person UTAN hjältebild är inget fel: anropet är en no-op och returnerar
 * false, så ytan kan visa Remove utan att först behöva veta om det finns
 * någon.
 *
 * Radlåset och rensningen delas med profilbilden i
 * App\Actions\User\Concerns\ManagesUserImage; prefixet `hero` väljer
 * kolumnerna.
 */
class RemoveUserHero
{
    use ManagesUserImage;

    public function __construct(private readonly PurgeAttachment $purgeAttachment) {}

    public function handle(User $user): bool
    {
        return $this->removeUserImage($user, 'hero', $this->purgeAttachment);
    }
}
