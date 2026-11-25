<?php

namespace App\Actions\Privacy;

use App\Models\PrivacyRequestAttachment;
use RuntimeException;
use Throwable;

/**
 * Bilagan med sammanställningen skrevs, men händelsen `action` som
 * antecknar exporten kunde inte skrivas (ADR-0095 § 7 och § 12).
 *
 * Undantaget finns för att handläggaren ska få bilagans ulid och kunna
 * följa upp ärendet för hand: filen finns, men beviset på exporten gör det
 * inte, och kommandot ska ge exit 1 i stället för att krascha.
 */
final class PrivacyCollectionNotRecordedException extends RuntimeException
{
    public function __construct(
        public readonly PrivacyRequestAttachment $attachment,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "Bilagan {$attachment->ulid} skrevs, men händelsen kunde inte antecknas. Följ upp ärendet för hand.",
            0,
            $previous,
        );
    }
}
