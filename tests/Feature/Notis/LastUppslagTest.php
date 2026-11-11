<?php

use App\Support\Notification\PinnedResolution;

/**
 * Issue 1039 — klienten ansluter till den adress SSRF-kontrollen godkände.
 */
it('fäster värdnamnet i gemener utan avslutande punkt vid adressen', function () {
    expect(PinnedResolution::options('https://Example.Test./hook', ['93.184.216.34']))
        ->toBe(['curl' => [CURLOPT_RESOLVE => ['example.test:443:93.184.216.34']]]);
});

it('skriver IPv6 inom hakparenteser och behåller ordningen', function () {
    expect(PinnedResolution::options('https://example.test/hook', ['93.184.216.34', '2606:4700:4700::1111']))
        ->toBe(['curl' => [CURLOPT_RESOLVE => ['example.test:443:93.184.216.34,[2606:4700:4700::1111]']]]);
});

it('ger inga alternativ för en tom adresslista', function () {
    expect(PinnedResolution::options('https://93.184.216.34/', []))->toBe([]);
});
