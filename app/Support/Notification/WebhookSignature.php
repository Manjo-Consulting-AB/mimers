<?php

namespace App\Support\Notification;

/**
 * HMAC-SHA256-signaturen över webhook-leveransens kropp, issue 37b § Beslut 9
 * och [[Notiser]] § Webhooks. Varje leverans bär headern
 *
 *     X-Mimers-Signature: t=<tidsstämpel>,v1=<hex>
 *
 * där `v1` är hash_hmac('sha256', "{t}.{body}", endpointens hemlighet) och
 * `body` är EXAKT den JSON-sträng som skickas — serialisera en gång, signera
 * den strängen, skicka den strängen (Beslut 9). Tidsstämpeln ingår i det
 * signerade materialet så att mottagaren ska kunna avvisa återuppspelning:
 * ligger den utanför mottagarens fönster förkastas anropet. Det fungerar bara
 * om `t` är med i HMAC-underlaget och inte bara i headern.
 *
 * Klassen är medvetet liten: en metod som bygger headern (används av
 * App\Console\DeliversWebhooks före varje anrop) och en som verifierar
 * (för testerna, och för den dag ett exempel behövs i dokumentationen).
 * Verifieringen använder hash_equals — aldrig en vanlig jämförelse.
 *
 * Hemligheten får aldrig lämna klassen: den hamnar inte i `last_error`, inte
 * i en loggrad och inte i ett undantagsmeddelande (Beslut 9). Båda metoderna
 * tar den som argument; ingen av dem returnerar eller loggar den.
 */
final class WebhookSignature
{
    /**
     * Bygger hela X-Mimers-Signature-värdet, t.ex. "t=1788508800,v1=…".
     */
    public function header(string $secret, int $timestamp, string $body): string
    {
        return sprintf('t=%d,v1=%s', $timestamp, $this->sign($secret, $timestamp, $body));
    }

    /**
     * Verifierar ett mottaget X-Mimers-Signature-värde mot kroppen. Returnerar
     * false för allt som inte är ett giltigt "t=…,v1=…"-värde eller vars v1
     * inte matchar — en felaktig signatur ska aldrig kunna förväxlas med en
     * giltig.
     */
    public function verify(string $secret, string $signature, string $body): bool
    {
        $parts = [];

        foreach (explode(',', $signature) as $piece) {
            [$key, $value] = array_pad(explode('=', $piece, 2), 2, null);

            $parts[$key] = $value;
        }

        if (! isset($parts['t'], $parts['v1'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];

        return hash_equals($this->sign($secret, $timestamp, $body), (string) $parts['v1']);
    }

    /**
     * HMAC-SHA256 över "{tidsstämpel}.{kropp}" mot hemligheten, som hex.
     */
    private function sign(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', sprintf('%d.%s', $timestamp, $body), $secret);
    }
}
