<?php

namespace App\Support\Notification;

use RuntimeException;

/**
 * Kastas av App\Support\Notification\EmailChannel när en notistyp saknar
 * e-postmall, se issue 32a § Beslut 6. M6 lägger till `loan.due` och kommer
 * att glömma språkfilen om felet är tyst — därför kastar kanalen i stället
 * för att skicka ett tomt mejl. Kastet fångas av leveransloopen (34a), som
 * skriver det i `last_error` och sätter `failed` — larmet syns i tabellen i
 * stället för att stoppa nattens övriga leveranser.
 */
final class UnknownNotificationTypeException extends RuntimeException
{
    public static function forType(string $type): self
    {
        return new self("Ingen e-postmall för notistypen [{$type}].");
    }
}
