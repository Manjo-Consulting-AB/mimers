<?php

namespace App\Actions\Notification;

use App\Models\Export;
use App\Models\Notification;

/**
 * Säger till beställaren att PDF-pärmen är klar — `binder.ready` (issue 1518 ·
 * [[ADR-0091 PDF-pärmen]]). Anropas av App\Jobs\BuildContainerExport när en
 * `binder`-rad blir `ready`; ZIP-exporten och en misslyckad pärm får ingen
 * notis.
 *
 * Notisen går bara i appen och bara till den som beställde. Saknas
 * beställaren (raden är anonym eller användaren raderad) finns ingen
 * mottagare och ingen rad skapas. `dedupe_key` bär exportens ulid, så ett
 * omkört jobb ger en rad.
 */
final class NotifyBinderReady
{
    public function __construct(private readonly CreateNotification $createNotification) {}

    public function handle(Export $export): void
    {
        if ($export->kind !== Export::KIND_BINDER) {
            return;
        }

        $recipient = $export->requestedBy;
        $container = $export->container;

        if ($recipient === null || $container === null) {
            return;
        }

        $this->createNotification->handle(
            type: Notification::TYPE_BINDER_READY,
            account: $container->account,
            user: $recipient,
            container: $container,
            payload: [
                'container' => $container->name,
                'item' => $export->item?->name,
                'export' => $export->ulid,
            ],
            dedupeKey: 'binder.ready:'.$export->ulid,
        );
    }
}
