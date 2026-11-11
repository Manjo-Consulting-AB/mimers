<?php

namespace App\Support\Notification;

/**
 * Fäster HTTP-klientens DNS-uppslag vid de adresser SSRF-kontrollen godkände
 * (CURLOPT_RESOLVE), så att en DNS-post som byter svar mellan kontrollen och
 * anslutningen (DNS rebinding) inte når en intern adress. Porten är alltid
 * 443: UrlSafetyValidator har redan avvisat allt annat.
 */
final class PinnedResolution
{
    /**
     * @param  list<string>  $ips
     * @return array<string, mixed> alternativ till Http::withOptions()
     */
    public static function options(string $url, array $ips): array
    {
        if ($ips === []) {
            return [];
        }

        $host = rtrim(strtolower((string) parse_url($url, PHP_URL_HOST)), '.');

        $addresses = array_map(
            fn (string $ip): string => str_contains($ip, ':') ? '['.$ip.']' : $ip,
            $ips,
        );

        return ['curl' => [CURLOPT_RESOLVE => [$host.':443:'.implode(',', $addresses)]]];
    }
}
