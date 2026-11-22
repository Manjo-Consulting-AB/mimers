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
 * Sätter personens profilbild — [[ADR-0076 Profilbilden]] § 2 och 3, issue
 * 380. Laddar upp genom App\Actions\Attachment\StoreAttachment med `user_id`
 * satt, pekar `user.avatar_attachment_id` på den nya bilagan och rensar den
 * förra med App\Actions\Attachment\PurgeAttachment.
 *
 * **Kontot som betalar är personens personkonto.** Klienten väljer inget
 * konto (ADR-0076 § 3): uppslaget är App\Support\Account\ActingAccount:s —
 * `type = 'personal'` bland personens medlemskap, lägst `id` vinner. Saknas
 * ett personkonto finns ingen kvot att belasta, och uppladdningen nekas med
 * `avatar.no_personal_account`. Koden blir ett fältfel på `file` på webben
 * (App\Http\Controllers\Settings\ProfileAvatarController) och ett felsvar i
 * höljet på `/api` — samma kod, två ytor (AGENTS.md § Felformat).
 *
 * **Kvoten prövas före lagringen**, som för containerns bild: styckstorleken
 * med `assertFileWithinLimit` och totalkvoten med `assertStorageWithinLimit`.
 * Den senare prövas en gång till, auktoritativt, inne i StoreAttachments
 * transaktion — den tidiga kontrollen håller inte mot två samtidiga
 * uppladdningar (issue 27b § Beslut 4).
 *
 * **KIND = IMAGE ÄR ETT KRAV, INTE EN FILTRERING.** En profilbild är alltid
 * en bild (ADR-0076 § 2), och kravet bor i StoreAttachment::handleForUser,
 * som prövar den SNIFFADE MIME-typen innan något skrivs.
 *
 * **Den förra bilden RENSAS** — den går inte till papperskorgen (ADR-0076
 * § 2, [[ADR-0008 Soft delete och papperskorg]] § Uppföljning 2026-09-26).
 *
 * Sedan issue 434 bor själva transaktionen, radlåset och nollställningen av
 * utsnittet i App\Actions\User\Concerns\ManagesUserImage, tillsammans med
 * hjältebildens likadana väg. Prefixet `avatar` väljer kolumnerna.
 */
class SetUserAvatar
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
            'avatar',
            $this->storeAttachment,
            $this->purgeAttachment,
            $this->entitlements,
        );
    }
}
