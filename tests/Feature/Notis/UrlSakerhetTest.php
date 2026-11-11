<?php

use App\Support\Notification\UnsafeUrlException;
use App\Support\Notification\UrlSafetyValidator;

/**
 * Issue 1034 — SSRF-valideraren avvisar IPv4-mappade, IPv4-kompatibla och
 * CGNAT-adresser. Resolvern är injicerad; inga riktiga värdnamn slås upp.
 */
function avvisasSom(string $url, array $svar = []): ?string
{
    try {
        (new UrlSafetyValidator(fn (string $host): array => $svar))->assertSafe($url);
    } catch (UnsafeUrlException $e) {
        return $e->reason;
    }

    return null;
}

it('avvisar inbäddade, delade och multicastadresser med reserved_ip', function (string $url) {
    expect(avvisasSom($url))->toBe('reserved_ip');
})->with([
    'https://[::ffff:7f00:1]/',
    'https://[::ffff:169.254.169.254]/',
    'https://[::ffff:10.0.0.1]/',
    'https://[::127.0.0.1]/',
    'https://[::a00:1]/',
    'https://100.64.0.1/',
    'https://100.127.255.254/',
    'https://192.0.0.8/',
    'https://198.18.0.1/',
    'https://224.0.0.1/',
    'https://[ff02::1]/',
    'https://[64:ff9b:1::7f00:1]/',
]);

it('avvisar ett värdnamn som slår upp till en IPv4-mappad adress', function () {
    expect(avvisasSom('https://example.com/', ['::ffff:a9fe:a9fe']))->toBe('reserved_ip');
});

it('prövar varje uppslagen adress', function () {
    expect(avvisasSom('https://example.com/', ['93.184.216.34', '::ffff:a00:1']))->toBe('reserved_ip');
});

it('släpper fortfarande igenom publika adresser', function (string $url, array $svar) {
    expect(avvisasSom($url, $svar))->toBeNull();
})->with([
    ['https://[::ffff:8.8.8.8]/', []],
    ['https://100.63.255.255/', []],
    ['https://100.128.0.1/', []],
    ['https://[2606:4700:4700::1111]/', []],
    ['https://example.com/', ['93.184.216.34']],
]);
