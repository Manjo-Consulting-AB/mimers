<?php

namespace App\Actions\User;

use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Attachment\StoreAttachment;
use App\Actions\User\Concerns\ManagesUserImage;
use App\Models\Attachment;
use App\Models\User;
use App\Support\Plan\Entitlements;
use Illuminate\Http\UploadedFile;

/**
 * Sätter personens hjältebild — [[ADR-0090 Dashboardens hjälte]] § 2, issue
 * 434. Bilden följer profilbilden i allt ([[ADR-0076 Profilbilden]] § 1–5):
 * bara `kind = image`, den är personens egen bilaga, en ny bild ersätter den
 * gamla som rensas direkt med App\Actions\Attachment\PurgeAttachment, och
 * `billed_account_id` är personens personkonto.
 *
 * **Kvoten räknas mot personkontot.** Klienten väljer inget konto, precis som
 * för profilbilden: uppslaget är App\Support\Account\ActingAccount:s. Saknas
 * ett personkonto nekas uppladdningen med `avatar.no_personal_account` —
 * hindret är personkontot och inte bildens plats, och koden blir ett fältfel
 * på `file` på webben.
 *
 * **Transaktionen och radlåset är profilbildens.** De bor i
 * App\Actions\User\Concerns\ManagesUserImage sedan issue 434, och prefixet
 * `hero` väljer `hero_attachment_id`, `hero_focus_x/y` och `hero_zoom`. Bytet
 * nollställer utsnittet på samma rad som pekaren byts, så en ny bild aldrig
 * ärver den gamlas fokuspunkt.
 */
class SetUserHero
{
    use ManagesUserImage;

    public function __construct(
        private readonly StoreAttachment $storeAttachment,
        private readonly PurgeAttachment $purgeAttachment,
        private readonly Entitlements $entitlements,
    ) {}

    public function handle(User $user, UploadedFile $file): Attachment
    {
        return $this->storeUserImage(
            $user,
            $file,
            'hero',
            $this->storeAttachment,
            $this->purgeAttachment,
            $this->entitlements,
        );
    }
}
