<?php

namespace App\Actions\User;

use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Attachment\StoreAttachment;
use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\User;
use App\Support\Plan\Entitlements;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
 * **Kvoten prövas före lagringen**, som för containerns bild
 * (App\Http\Controllers\ContainerCoverController::store): styckstorleken med
 * `assertFileWithinLimit` och totalkvoten med `assertStorageWithinLimit`.
 * Den senare prövas en gång till, auktoritativt, inne i StoreAttachments
 * transaktion — den tidiga kontrollen håller inte mot två samtidiga
 * uppladdningar (issue 27b § Beslut 4). Att kontrollerna ligger HÄR och inte
 * hos anroparen är skillnaden mot containerns bild, där kontot kommer ur
 * kroppen: personkontot härleds ur personen, och både webben och `/api`
 * ska formulera samma gräns en gång.
 *
 * **KIND = IMAGE ÄR ETT KRAV, INTE EN FILTRERING.** En profilbild är alltid
 * en bild (ADR-0076 § 2), och kravet bor i StoreAttachment::handleForUser,
 * som prövar den SNIFFADE MIME-typen innan något skrivs — att gissa ur
 * filändelsen vore en andra sanning om vad filen är (StoreAttachment
 * § Beslut 5).
 *
 * **Allt sker i samma transaktion och under radlås på personen.** Bytet,
 * StoreAttachments rad och rensningen av den förra bilden är ett enda
 * åtagande: fallerar något rullas pekaren, räkningen och bilagan tillbaka
 * tillsammans, och personens förra bild är fortfarande dess bild.
 * `lockForUpdate` är en current read, så två samtidiga anrop på samma person
 * serialiseras — den som kommer sist ser den förstas pekare och rensar den.
 * Utan låset kunde båda läsa samma gamla pekare och lämna den ena av de två
 * nya bilagorna orensad, kvar på kontots `reference_count` och kvot. Samma
 * mönster som App\Actions\Container\SetContainerCover.
 *
 * **Den förra bilden RENSAS** — den går inte till papperskorgen (ADR-0076
 * § 2, [[ADR-0008 Soft delete och papperskorg]] § Uppföljning 2026-09-26).
 * PurgeAttachment minskar `reference_count` exakt en gång och lämnar bytena
 * på disken med `purge_after` satt.
 */
class SetUserAvatar
{
    public function __construct(
        private readonly StoreAttachment $storeAttachment,
        private readonly PurgeAttachment $purgeAttachment,
        private readonly Entitlements $entitlements,
    ) {}

    public function handle(User $user, UploadedFile $file): Attachment
    {
        $account = $this->personalAccount($user);

        $byteSize = $file->getSize();
        if ($byteSize === false) {
            throw new RuntimeException('Den mottagna filen kunde inte läsas.');
        }

        // Billig avvisning före lagringen; StoreAttachment prövar totalkvoten
        // en gång till, auktoritativt, under radlåset på räknaren.
        $this->entitlements->assertFileWithinLimit($account, $byteSize);
        $this->entitlements->assertStorageWithinLimit($account, $byteSize);

        return DB::transaction(function () use ($user, $file, $account): Attachment {
            // Raden läses om under låset: pekaren skrivs på raden och inte på
            // den instans anroparen skickade in, så en samtidig begäran ser
            // den förstas skrivning.
            $rad = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $forra = $rad->avatar_attachment_id;

            $bilaga = $this->storeAttachment->handleForUser($rad, $file, $account);

            $rad->avatar_attachment_id = $bilaga->id;
            $rad->save();

            if ($forra !== null && $forra !== $bilaga->id) {
                // withTrashed() — pekaren kan peka på en bilaga som redan
                // mjukraderats eller rensats. PurgeAttachment är idempotent
                // per rad och en saknad rad är inget att städa.
                $gammal = Attachment::withTrashed()->find($forra);

                if ($gammal !== null) {
                    $this->purgeAttachment->handle($gammal);
                }
            }

            // Instansen anroparen skickade in bär den nya pekaren — annars
            // vore den en förlegad läsning av samma slag som låset stänger.
            $user->refresh();

            return $bilaga;
        }, 3);
    }

    /**
     * Personens personkonto, eller ett fältfel när hon saknar ett.
     *
     * Uppslaget är App\Support\Account\ActingAccount:s ordagrant: `type =
     * 'personal'` bland personens medlemskap, lägst `id` vinner. Flera
     * personkonton är en patologi; den deterministiska ordningen gör att
     * samma konto belastas varje gång. ADR-0076 § 3: en person utan
     * personkonto har ingen kvot att belasta och kan inte ladda upp en
     * profilbild — hon ser initialerna och en förklaring.
     */
    private function personalAccount(User $user): Account
    {
        $personal = $user->accounts()
            ->where('account.type', 'personal')
            ->orderBy('account.id')
            ->first();

        if ($personal === null) {
            throw ApiException::make('avatar.no_personal_account');
        }

        return $personal;
    }
}
