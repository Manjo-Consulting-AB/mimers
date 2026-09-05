<?php

namespace App\Support\Notification;

/**
 * SSRF-valideringen för utgående webhook-URL:er — issue 37a § Beslut 6 och
 * [[Notiser]] § Webhooks: "Utgående URL:er måste valideras mot SSRF — privata
 * IP-intervall, localhost och molnens metadatatjänster ska avvisas både vid
 * registrering och vid varje leverans, eftersom DNS kan ändras däremellan."
 *
 * Klassen skrivs här (37a) men anropas på två ställen: vid registrering och
 * ändring av en endpoint (WebhookEndpointController) och, från 37b, vid varje
 * leverans — den är en stödklass för hela webhook-flödet, inte en
 * request-specifik kontroll.
 *
 * Reglerna, alla obligatoriska:
 * - schemat måste vara https (http skickar HMAC-signaturen i klartext),
 * - ingen användarinfo i URL:en (`https://user:pass@…`),
 * - porten 443 eller saknas,
 * - värdet får inte vara localhost eller sluta på .localhost/.local/.internal,
 * - varje IP värdnamnet slår upp till avvisas om den är privat eller
 *   reserverad — FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
 * - både A- och AAAA-poster kollas (ett värdnamn utan IPv4 men med en
 *   IPv6-loopback tar sig annars förbi),
 * - slår värdnamnet inte upp alls: avvisa.
 *
 * FILTER_FLAG_NO_RES_RANGE täcker 169.254.0.0/16 och därmed molnens
 * metadatatjänst på 169.254.169.254 — men den (och NO_PRIV_RANGE) täcker inte
 * 0.0.0.0/8 i alla PHP-versioner, så den stängs uttryckligen. parse_url() är
 * inte en säkerhetsgräns: den accepterar skräp, så varje fält kontrolleras
 * här, inget antas.
 *
 * DNS-uppslaget görs med PHP:s egna funktioner (dns_get_record), aldrig ett
 * externt program — exec och proc_open är avstängda hos inleed (AGENTS.md §
 * Driftmiljön saknar proc_open). Resolver-callbacken är injicerbar så att
 * testsviten aldrig slår upp riktiga värdnamn (issue 37a § Att se upp med).
 */
final class UrlSafetyValidator
{
    /** @var callable(string): list<string> */
    private $resolver;

    /**
     * @param  (callable(string): list<string>)|null  $resolver  Åsidosätter
     *                                                           DNS-uppslaget, bara för tester
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? fn (string $host): array => $this->resolve($host);
    }

    /**
     * @throws UnsafeUrlException
     */
    public function assertSafe(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeUrlException('unparseable_url');
        }

        if (strtolower((string) $parts['scheme']) !== 'https') {
            throw new UnsafeUrlException('invalid_scheme');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrlException('userinfo');
        }

        if (isset($parts['port']) && $parts['port'] !== 443) {
            throw new UnsafeUrlException('invalid_port');
        }

        // En avslutande punkt är DNS:ens fullt kvalificerade form — "localhost."
        // och "example.com." är samma värd som utan punkten.
        $host = rtrim(strtolower((string) $parts['host']), '.');

        if ($host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            throw new UnsafeUrlException('reserved_hostname');
        }

        // En IPv6-literal står inom hakparenteser i URL:en ("[::1]"); ta bort
        // dem innan filter_var. Ett värdnamn är aldrig en giltig IP och går
        // vidare till DNS-uppslaget nedan.
        $ip = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP);

        if ($ip !== false) {
            $this->assertSafeIp($ip);

            return;
        }

        $ips = ($this->resolver)($host);

        if ($ips === []) {
            throw new UnsafeUrlException('dns_lookup_failed');
        }

        foreach ($ips as $resolvedIp) {
            $this->assertSafeIp($resolvedIp);
        }
    }

    /**
     * Standarduppslaget: A- och AAAA-poster för värdet, som en platt lista IP
     * -adresser. Returnerar [] när värdet inte slår upp alls.
     *
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $ips = [];

        foreach ($records as $record) {
            $type = $record['type'] ?? null;

            if ($type === 'A' && isset($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif ($type === 'AAAA' && isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }

    /**
     * Avvisar en IP-adress om den är privat eller reserverad.
     *
     * @throws UnsafeUrlException
     */
    private function assertSafeIp(string $ip): void
    {
        // 0.0.0.0/8 (och den ospecificerade IPv6-adressen ::) täcks inte av
        // FILTER_FLAG_NO_PRIV_RANGE/NO_RES_RANGE i alla PHP-versioner — stäng
        // dem uttryckligen (issue 37a § Att se upp med).
        if (str_starts_with($ip, '0.') || $ip === '::') {
            throw new UnsafeUrlException('reserved_ip');
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new UnsafeUrlException('reserved_ip');
        }
    }
}
