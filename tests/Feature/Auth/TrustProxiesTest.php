<?php

use Illuminate\Support\Facades\Route;

use function Pest\Laravel\get;

/*
 * Issue 4 · Autentisering med lösenord — "Klart när":
 * "Appen genererar korrekta absoluta URL:er bakom LiteSpeed, med
 * TrustProxies konfigurerat."
 *
 * Appen körs bakom LiteSpeed hos inleed (se issue #17 och
 * [[ADR-0020 Plattformsidentitet och frontendgräns]] § Konsekvenser), så
 * verifieringslänkar och framtida magic links måste bygga sin absoluta URL
 * från proxyns X-Forwarded-*-headrar, inte från den interna anslutningen
 * mellan LiteSpeed och PHP. Se bootstrap/app.php,
 * `$middleware->trustProxies(at: '*')`.
 */

it('litar på X-Forwarded-Proto/-Host, så genererade absoluta URL:er speglar proxyn framför appen', function () {
    Route::get('/_test/absolut-url', fn () => response()->json(['url' => url('/')]));

    $response = get('/_test/absolut-url', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'mimers.app',
    ]);

    $response->assertOk();
    expect($response->json('url'))->toBe('https://mimers.app');
});

it('utan X-Forwarded-headrarna används anslutningens egna schema och host, inte proxyns', function () {
    Route::get('/_test/absolut-url-utan-proxy', fn () => response()->json(['url' => url('/')]));

    $response = get('/_test/absolut-url-utan-proxy');

    $response->assertOk();
    expect($response->json('url'))->not->toContain('mimers.app');
});
