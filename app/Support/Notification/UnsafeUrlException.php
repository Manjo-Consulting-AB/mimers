<?php

namespace App\Support\Notification;

use RuntimeException;

/**
 * Kastas av App\Support\Notification\UrlSafetyValidator när en URL inte får
 * användas som webhook-mottagare — privata eller reserverade IP-intervall,
 * localhost, fel schema eller port (issue 37a § Beslut 6). `reason` är en
 * maskinläsbar token som anger vilken regel som slog i.
 *
 * Ärver inte ApiException: kastet sker på TVÅ ställen med olika höljen.
 * I 37a fångas det av WebhookEndpointController och översätts till
 * `webhook.unsafe_url` med `reason` i data (422 — begäran är ogiltig, inte
 * nekad). I 37b kastas det av samma validator vid varje leverans, eftersom
 * DNS kan ändras mellan registrering och leverans ([[ADR-0010 Notisarkitektur]]
 * § Konsekvenser) — där fångas det av leveransloopen, inte av ett API-hölje.
 * Samma mönster som AddressSuppressedException.
 */
final class UnsafeUrlException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("URL:en är inte säker att anropa: [{$reason}].");
    }
}
