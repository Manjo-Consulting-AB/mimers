<?php

namespace App\Actions\Notification;

use App\Models\Export;
use App\Models\Notification;

/**
 * Säger till beställaren att kontoexporten är klar — `account_export.ready`
 * (issue 1701 · [[ADR-0098 Kontoexporten]] § 8). Anropas av
 * App\Jobs\BuildAccountExport när en `account`-rad blir `ready`; en
 * misslyckad export får ingen notis.
 *
 * Notisen går i appen och som mejl enligt personens notisval, och bara till
 * den som beställde. Saknas beställaren eller kontot finns ingen mottagare
 * och ingen rad skapas. `dedupe_key` bär exportens ulid, så ett omkört jobb
 * ger en rad.
 */
final class NotifyAccountExportReady
{
    public function __construct(private readonly CreateNotification $createNotification) {}

    public function handle(Export $export): void
    {
        if ($export->kind !== Export::KIND_ACCOUNT) {
            return;
        }

        $recipient = $export->requestedBy;
        $account = $export->account;

        if ($recipient === null || $account === null) {
            return;
        }

        $this->createNotification->handle(
            type: Notification::TYPE_ACCOUNT_EXPORT_READY,
            account: $account,
            user: $recipient,
            payload: ['export' => $export->ulid],
            dedupeKey: 'account_export.ready:'.$export->ulid,
        );
    }
}
