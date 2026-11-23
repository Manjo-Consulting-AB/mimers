<?php

namespace App\Actions\Notification;

use App\Models\Container;
use App\Models\Notification;
use App\Models\User;

/**
 * Säger till containerns ägare att en mottagare lämnat containern —
 * `access.left` (issue 1529 · M75). Formen är `versioning.skipped`
 * (App\Actions\Notification\NotifyVersioningSkipped): bara i appen, ingen
 * mejlkanal och ingen växel på inställningssidan.
 *
 * `payload` är `{ container, person }` där `person` är `user.name` — aldrig en
 * e-postadress. `dedupe_key` bär containern, personen och datumet. Saknas en
 * `owner` finns ingen mottagare och ingen rad skapas.
 */
final class NotifyAccessLeft
{
    public function __construct(private readonly CreateNotification $createNotification) {}

    public function handle(Container $container, User $person): void
    {
        $account = $container->account;

        $recipient = $account->users()->wherePivot('role', 'owner')->first();

        if ($recipient === null) {
            return;
        }

        $this->createNotification->handle(
            type: Notification::TYPE_ACCESS_LEFT,
            account: $account,
            user: $recipient,
            container: $container,
            payload: ['container' => $container->name, 'person' => $person->name],
            dedupeKey: 'access.left:'.$container->ulid.':'.$person->ulid.':'.now()->toDateString(),
        );
    }
}
