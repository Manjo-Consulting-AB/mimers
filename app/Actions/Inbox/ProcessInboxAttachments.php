<?php

namespace App\Actions\Inbox;

use App\Actions\Attachment\MoveAttachment;
use App\Exceptions\Api\ApiException;
use App\Models\Attachment;
use App\Models\Item;
use App\Models\User;
use App\Support\Account\ActingAccount;
use App\Support\Plan\Entitlements;
use Illuminate\Support\Facades\DB;

/**
 * Bearbetar flera bilagor från användarens inbox till SAMMA item — M27 ·
 * issue 245, se [[ADR-0054 Inboxen]] § 6 och
 * App\Actions\Attachment\MoveAttachment.
 *
 * **Allt eller inget.** Hela arbetet ligger i EN transaktion: antingen flyttas
 * varje bilaga till målet, eller står alla kvar i inboxen. `MoveAttachment`
 * öppnar sin egen transaktion — den blir en savepoint i den här — så ett kast
 * på bilaga tre rullar tillbaka både dess egen skrivning och de två första
 * flyttarnas.
 *
 * **Kvoten prövas för SUMMAN, en gång, före den första flytten.** Annars hade
 * den sista bilagan kunnat fälla hela satsen efter att de tidigare redan
 * flyttats — och även om transaktionen rullar tillbaka dem är en prövning i
 * förväg det som gör felet begripligt: "kvoten räcker inte för de här filerna"
 * i stället för ett kast mitt i en halvfärdig flytt. Summan räknas bara på de
 * bilagor vars ägare faktiskt BYTS: byten inom samma konto rör ingen räknare
 * ([[ADR-0053 Flytt och kopiering]] § 4), och en bilaga som redan belastar
 * målkontot ska inte räknas en gång till.
 *
 * **Varje bilaga måste ligga i användarens EGEN inbox.** Ligger någon
 * annanstans — på ett riktigt item, i någon annans inbox, eller finns inte
 * alls — nekas HELA förfrågan med 422 `attachment.not_in_inbox` och ingen
 * flyttas. Spärren är det som gör att bearbetningen inte kan användas som en
 * flytt bakvägen: rutten tar emot ULID:er ur kroppen, och utan den hade vilken
 * bilaga som helst gått att flytta.
 *
 * **Raderna läses om under lås innan spärren ställs** — samma mönster som
 * `MoveAttachment` och `ProcessInboxTask`: två samtidiga bearbetningar ska
 * inte båda se samma bilaga i inboxen och flytta den.
 *
 * **Målitemet väljs av anroparen** bland de items där hon har `create`; väljaren
 * (issue 242) visar aldrig en inbox. Behörigheten (`delete` på inbox-itemet,
 * `create` på målet) prövas av anroparen — den här actionen är verktyget, inte
 * grinden.
 */
class ProcessInboxAttachments
{
    public function __construct(
        private readonly ResolveInbox $resolveInbox,
        private readonly MoveAttachment $moveAttachment,
        private readonly ActingAccount $actingAccount,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * @param  list<string>  $ulids  Bilagornas ULID:er. Att listan inte är tom
     *                               och bär unika värden prövas av anroparens
     *                               FormRequest.
     */
    public function handle(User $actor, Item $target, array $ulids): void
    {
        DB::transaction(function () use ($actor, $target, $ulids): void {
            $inbox = $this->resolveInbox->existing($actor);

            if ($inbox === null) {
                // Utan en inbox finns ingenting att bearbeta ur den, och ingen
                // bilaga kan ligga där. Samma svar som en bilaga utanför.
                throw ApiException::make('attachment.not_in_inbox', [], 422);
            }

            $attachments = Attachment::query()
                ->whereIn('ulid', $ulids)
                ->with('storedFile')
                ->lockForUpdate()
                ->get();

            // Färre rader än ULID:er betyder att någon av dem inte finns — då
            // ligger den inte i inboxen heller.
            if ($attachments->count() !== count($ulids)) {
                throw ApiException::make('attachment.not_in_inbox', [], 422);
            }

            foreach ($attachments as $attachment) {
                if ((int) $attachment->item_id !== (int) $inbox->id) {
                    throw ApiException::make('attachment.not_in_inbox', [], 422);
                }
            }

            $owner = $this->actingAccount->for($actor, $target->container);

            $bytes = $attachments
                ->filter(fn (Attachment $attachment): bool => (int) $attachment->billed_account_id !== (int) $owner->id)
                ->sum(fn (Attachment $attachment): int => (int) $attachment->storedFile->byte_size);

            if ($bytes > 0) {
                // Den AUKTORITATIVA prövningen, före varje skrivning
                // (ADR-0053 § 4). Kastet rullar tillbaka hela satsen.
                $this->entitlements->assertStorageWithinLimit($owner, (int) $bytes);
            }

            foreach ($attachments as $attachment) {
                $this->moveAttachment->handle($attachment, $target, $actor);
            }
        });
    }
}
