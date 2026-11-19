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
 * saken — och notisen levereras i appen; e-postens förval är avstängt.
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

        $recipient = $account->users()
            ->wherePivot('role', 'owner')
            ->orderBy('user.id')
            ->first();

        $this->createNotification->handle(
            type: Notification::TYPE_VERSIONING_SKIPPED,
            account: $account,
            user: $recipient,
            container: $container,
            payload: ['container' => $container->name],
            // Containern och dygnet, inte mottagaren: raden gäller ägarens
            // lagring, och en andra ägare ska inte få en andra notis för
            // samma fulla kvot.
            dedupeKey: 'versioning.skipped:'.$container->ulid.':'.now()->toDateString(),
        );
    }
}
