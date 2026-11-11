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
 * metadatatjänst på 169.254.169.254 — men filtren täcker inte allt, så följande
 * stängs uttryckligen:
 * - 0.0.0.0/8 (inte i alla PHP-versioner),
 * - CGNAT 100.64.0.0/10, 192.0.0.0/24, 198.18.0.0/15 och IPv4-multicast
 *   224.0.0.0/4, samt IPv6-multicast ff00::/8,
 * - en IPv4-adress som bäddats in i IPv6: IPv4-mappat ::ffff:0:0/96,
 *   IPv4-kompatibelt ::/96, NAT64 64:ff9b::/96 och 6to4 2002::/16 avkodas och
 *   prövas mot samma regler (filtren godkänner dem, i punkt- som hexform),
 * - NAT64:s lokala prefix 64:ff9b:1::/48, som avvisas i sin helhet. parse_url() är inte en
 * säkerhetsgräns: den accepterar skräp, så varje fält kontrolleras här, inget
 * antas.
 *
 * DNS-uppslaget görs med PHP:s egna funktioner (dns_get_record), aldrig ett
 * externt program — exec är avstängt hos inleed (AGENTS.md §
 * Subprocesser i driftmiljön). Resolver-callbacken är injicerbar så att
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

        $this->assertNotExtraReserved($ip);

        // IPv4-mappade adresser (::ffff:0:0/96) avkodas före filtret: det
        // avvisar hela prefixet i vissa PHP-versioner (8.4) och släpper det
        // igenom i andra (8.3), så bara den inbäddade adressen avgör.
        $mappad = $this->mappedIpv4($ip);

        if ($mappad !== null) {
            $this->assertSafeIp($mappad);

            return;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new UnsafeUrlException('reserved_ip');
        }

        // NAT64 (64:ff9b::/96) och 6to4 (2002::/16) bäddar in en IPv4-adress i
        // prefixet, och i sin hexadecimala form passerar en privat eller
        // reserverad sådan filterkontrollen ovan — 64:ff9b::a9fe:a9fe ÄR
        // 169.254.169.254. Avkoda den inbäddade adressen och pröva den mot
        // samma regler.
        $this->assertSafeEmbeddedIpv4($ip);
    }

    /**
     * Avvisar en IPv6-adress vars prefix bäddar in en privat eller reserverad
     * IPv4-adress. FILTER_FLAG_NO_PRIV_RANGE/NO_RES_RANGE ser bara de
     * adresser vars inbäddade IPv4 står i punktform; den hexadecimala formen
     * av samma adress passerar, och IPv4-kompatibla adresser (::/96)
     * passerar filtret i båda formerna — de avkodas därför här. IPv4-mappade
     * adresser avkodas i assertSafeIp().
     *
     * @throws UnsafeUrlException
     */
    private function assertSafeEmbeddedIpv4(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return;
        }

        $paket = inet_pton($ip);

        if ($paket === false || strlen($paket) !== 16) {
            return;
        }

        $inbäddad = null;

        // NAT64:s lokala prefix 64:ff9b:1::/48: avvisas i sin helhet.
        if (substr($paket, 0, 6) === "\x00\x64\xff\x9b\x00\x01") {
            throw new UnsafeUrlException('reserved_ip');
        }

        // IPv4-kompatibelt ::/96: tolv nollbyte, sedan IPv4-adressen. :: och
        // ::1 hanteras redan av assertSafeIp().
        if (substr($paket, 0, 12) === str_repeat("\x00", 12)
            && $paket !== str_repeat("\x00", 16)
            && $paket !== str_repeat("\x00", 15)."\x01") {
            $inbäddad = substr($paket, 12, 4);
        }

        // NAT64-prefixet 64:ff9b::/96: hexteten 0064 och ff9b följt av nollor,
        // och de sista 32 bitarna är IPv4-adressen.
        if (substr($paket, 0, 12) === "\x00\x64\xff\x9b".str_repeat("\x00", 8)) {
            $inbäddad = substr($paket, 12, 4);
        }

        // 6to4-prefixet 2002::/16: bitarna 16–48 bär IPv4-adressen.
        if ($paket[0] === "\x20" && $paket[1] === "\x02") {
            $inbäddad = substr($paket, 2, 4);
        }

        if ($inbäddad !== null) {
            $this->assertSafeIp(inet_ntop($inbäddad));
        }
    }

    /**
     * Intervall som FILTER_FLAG_NO_PRIV_RANGE/NO_RES_RANGE släpper igenom:
     * CGNAT 100.64.0.0/10, 192.0.0.0/24, 198.18.0.0/15, IPv4-multicast
     * 224.0.0.0/4 och IPv6-multicast ff00::/8. Jämförs numeriskt.
     *
     * @throws UnsafeUrlException
     */
    private function assertNotExtraReserved(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);

            if ($long === false) {
                return;
            }

            $ranges = [
                [ip2long('100.64.0.0'), ip2long('100.127.255.255')],
                [ip2long('192.0.0.0'), ip2long('192.0.0.255')],
                [ip2long('198.18.0.0'), ip2long('198.19.255.255')],
                [ip2long('224.0.0.0'), ip2long('239.255.255.255')],
            ];

            foreach ($ranges as [$from, $to]) {
                if ($long >= $from && $long <= $to) {
                    throw new UnsafeUrlException('reserved_ip');
                }
            }

            return;
        }

        $paket = inet_pton($ip);

        if ($paket !== false && strlen($paket) === 16 && ord($paket[0]) === 0xFF) {
            throw new UnsafeUrlException('reserved_ip');
        }
    }

    /**
     * Den inbäddade IPv4-adressen i en IPv4-mappad IPv6-adress
     * (::ffff:0:0/96: tio nollbyte, ffff, sedan adressen), annars null.
     */
    private function mappedIpv4(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        $paket = inet_pton($ip);

        if ($paket === false || strlen($paket) !== 16
            || substr($paket, 0, 12) !== str_repeat("\x00", 10)."\xff\xff") {
            return null;
        }

        return inet_ntop(substr($paket, 12, 4)) ?: null;
    }
}
