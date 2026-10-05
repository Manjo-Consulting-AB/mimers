<?php

namespace App\Actions\Attachment;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Usage\AdjustUsage;
use App\Exceptions\Api\ApiException;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Account\ActingAccount;
use App\Support\Plan\Entitlements;
use Illuminate\Support\Facades\DB;

/**
 * Kopierar en itembilaga till ett annat item — samma container eller en
 * annan — se [[ADR-0053 Flytt och kopiering]] § 3, 4 och 7.
 *
 * **Kopian är en NY bilaga mot SAMMA `stored_file`**: ny ULID, samma
 * `filename` och `kind`, och `stored_file.reference_count` ökar med ett.
 * Diskbytarna skrivs alltså inte igen — dedupen gäller ([[ADR-0006
 * Innehållsadresserad lagring]]) — men kvoten mäter den LOGISKA storleken,
 * så kopian belastar ägaren med hela sin `byte_size` (ADR-0053 § 4).
 *
 * **`uploaded_by_user_id` blir den som kopierade**, inte den som en gång
 * laddade upp originalet: det är hon som skapade den här bilagan (ADR-0053
 * § 5).
 *
 * **Kvoten prövas alltid**, till skillnad från flytten: en kopia lägger till
 * en bilaga och ökar `usage_counter.storage_bytes` hos ägaren, även inom
 * samma konto och fast filen bara finns en gång på disken (ADR-0053 § 4).
 *
 * Ägaren blir handlingens konto enligt App\Support\Account\ActingAccount::
 * for() — målcontainerns ägarkonto när användaren är medlem i det, annars
 * hennes personkonto (ADR-0053 § 3).
 *
 * **Loggen får EN rad, i målcontainern** (ADR-0053 § 7). Källan får ingen:
 * ingenting ändrades där, och en rad hade beskrivit en händelse som inte
 * hände.
 *
 * `App\Actions\Attachment\StoreAttachment` är förebilden för kvot, räknare
 * och logg — men kopian har ingen fil att hasha och sniffa, så vägen hit är
 * kortare: raden finns, och bara `reference_count` och en ny attachment-rad
 * ska till.
 */
class CopyAttachment
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    public function handle(Attachment $attachment, Item $target, User $actor): Attachment
    {
        return DB::transaction(function () use ($attachment, $target, $actor): Attachment {
            $rad = Attachment::query()
                ->whereKey($attachment->getKey())
                ->lockForUpdate()
                ->first();

            // Samma två spärrar som flytten (ADR-0053 § 1): en mjukraderad
            // bilaga kan inte kopieras, och en containerbilaga är inte en
            // itembilaga.
            if ($rad === null || $rad->trashed() || $rad->item_id === null) {
                throw ApiException::make('attachment.not_copyable', [], 422);
            }

            if ($target->trashed()) {
                throw ApiException::make('attachment.not_copyable', [], 422);
            }

            // `stored_file` låses som i StoreAttachment: en samtidig
            // gallring (17b) läser `reference_count` under samma lås, så
            // ökningen går inte förlorad och bytena rivs inte under kopian.
            $storedFile = StoredFile::query()
                ->whereKey($rad->stored_file_id)
                ->lockForUpdate()
                ->firstOrFail();

            $owner = (new ActingAccount)->for($actor, $target->container);

            // Alltid, även när ägaren är densamma som originalets: kopian
            // lägger till en bilaga (ADR-0053 § 4).
            (new Entitlements)->assertStorageWithinLimit($owner, $storedFile->byte_size);

            // En SQL-ökning, inte läs-ändra-skriv i PHP — samma skäl som i
            // StoreAttachment (§ Att se upp med).
            $storedFile->increment('reference_count', 1);

            $kopia = new Attachment;
            $kopia->item_id = $target->id;
            $kopia->stored_file_id = $storedFile->id;
            $kopia->filename = $rad->filename;
            $kopia->kind = $rad->kind;
            $kopia->uploaded_by_user_id = $actor->id;
            $kopia->billed_account_id = $owner->id;
            $kopia->save();

            (new AdjustUsage)->handle($owner->id, bytesDelta: $storedFile->byte_size);

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_ATTACHMENT_COPIED,
                account: $target->container->account,
                user: $actor,
                container: $target->container,
                item: $target,
                subjectType: 'attachment',
                subjectUlid: $kopia->ulid,
                meta: ['kind' => $kopia->kind],
            );

            $kopia->setRelation('storedFile', $storedFile);
            $kopia->setRelation('billedAccount', $owner);

            return $kopia;
        });
    }
}
