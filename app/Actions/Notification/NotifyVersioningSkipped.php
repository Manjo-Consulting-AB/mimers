<?php

namespace App\Actions\Notification;

use App\Models\Container;
use App\Models\Notification;

/**
 * Säger till containerns ägare att en version inte fick plats i kvoten —
 * `versioning.skipped`, en gång per container och dygn (issue 412 ·
 * [[ADR-0088 Versionshistorik för items]] Riktning 3).
 *
 * **Ägaren, inte skribenten.** Versionen betalas av containerns ägare
 * (Riktning 3), så det är ägaren som ska veta att lagringen är full.
 * Skrivningen som utlöste det går igenom ändå; notisen är information, inte
 * ett fel.
 *
 * **En gång per container och dygn.** `dedupe_key` bär containern och
 * datumet, så en full kvot som stoppar hundra versioner under en dag ger EN
 * rad. Adressen går till ägarkontots ägare — den som kan göra något åt
 * saken — och notisen levereras BARA i appen. Det är ingen mejltyp: kanalen
 * skapas inte ens om inställningen säger annat
 * (App\Support\Notification\NotificationPreferences).
 *
 * **Ett konto har en användare** (Tonys regel 2026-11-18), och det är
 * ägaren. Saknas en `owner` finns ingen mottagare, och då skapas ingen
 * notisrad alls — en rad utan leverans vore bara skräp i utboxen.
 *
 * **Ingenting avslöjar en rättslig spärr.** Under spärr sparas versionen ändå
 * utan kvotprövning (Riktning 6), och den här notisen skapas då aldrig.
 */
final class NotifyVersioningSkipped
{
    public function __construct(private readonly CreateNotification $createNotification) {}

    public function handle(Container $container): void
    {
        $account = $container->account;

        // Ett konto har exakt en användare och den är ägaren. Finns ingen
        // `owner` finns ingen mottagare.
        $recipient = $account->users()->wherePivot('role', 'owner')->first();

        if ($recipient === null) {
            return;
        }

        $this->createNotification->handle(
            type: Notification::TYPE_VERSIONING_SKIPPED,
            account: $account,
            user: $recipient,
            container: $container,
            payload: ['container' => $container->name],
            // Containern och dygnet, inte mottagaren: nyckeln beskriver
            // händelsen, och samma fulla kvot ska ge EN rad.
            dedupeKey: 'versioning.skipped:'.$container->ulid.':'.now()->toDateString(),
        );
    }
}
