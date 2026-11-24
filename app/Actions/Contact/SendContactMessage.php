<?php

namespace App\Actions\Contact;

use App\Mail\ContactMessageMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Skickar kontaktformulärets meddelande — M89 ·
 * [[ADR-0096 Kontaktformuläret]] § 3 och § 4.
 *
 * **Synkront, aldrig genom kön.** Det finns ingen tabell att falla tillbaka
 * på: ett köjobb som misslyckas i det tysta hade tappat meddelandet. Vägrar
 * mailern kastar `send()` vidare till kontrollern, som loggar felet och
 * svarar `503 contact.send_failed`.
 *
 * Adressen kommer ur `config('support.address')`, så staging kan peka om
 * brevlådan utan att koden ändras. Personens namn och adress är redan
 * uträknade av kontrollern: för en inloggad kommer de ur `user` och aldrig
 * ur begäran.
 *
 * Kontots ULID slås upp här och inte i mailet — en `Mailable` ska bygga sitt
 * innehåll och inte fråga databasen.
 */
final class SendContactMessage
{
    public function handle(
        string $category,
        string $name,
        string $email,
        string $message,
        ?User $user,
    ): void {
        Mail::to((string) config('support.address'))->send(new ContactMessageMail(
            category: $category,
            name: $name,
            email: $email,
            message: $message,
            user: $user,
            accountUlid: $user?->accounts()->first()?->ulid,
            contentLocale: (string) app()->getLocale(),
            sentAt: now(),
        ));
    }
}
