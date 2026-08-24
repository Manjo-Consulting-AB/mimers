<?php

use Illuminate\Support\Facades\Route;

use function Pest\Laravel\get;
use function Pest\Laravel\withServerVariables;

/*
 * Issue 4 · Autentisering med lösenord — "Klart när":
 * "Appen genererar korrekta absoluta URL:er bakom LiteSpeed, med
 * TrustProxies konfigurerat."
 *
 * Appen körs bakom LiteSpeed hos inleed (se issue #17 och
 * [[ADR-0020 Plattformsidentitet och frontendgräns]] § Konsekvenser).
 * LiteSpeed sitter lokalt på samma maskin som PHP, så
 * `$middleware->trustProxies(at: ['127.0.0.1', '::1'])` i bootstrap/app.php
 * litar bara på X-Forwarded-*-headrar från loopbacken — inte från vem som
 * helst som råkar skicka dem.
 *
 * Det är avsiktligt snävt: en klient som INTE kommer från en betrodd proxy
 * ska inte kunna styra vilken host/vilket schema appen bygger absoluta
 * URL:er från. Signerade länkar (verifiering här, magic link i issue 5)
 * byggs av just den mekanismen — litar appen på en obetrodd avsändares
 * X-Forwarded-Host pekar länken i mejlet på en domän klienten själv valde,
 * med giltig token och signatur i sig (host header injection).
 */

it('ignorerar X-Forwarded-Proto/-Host från en obetrodd avsändare', function () {
    Route::get('/_test/absolut-url-obetrodd', fn () => response()->json(['url' => url('/')]));

    // 203.0.113.0/24 är TEST-NET-3 (RFC 5737) — en godtycklig, garanterat
    // icke-loopback "publik" avsändare. Testklientens REMOTE_ADDR är annars
    // 127.0.0.1 som standard, vilket självt räknas som betrodd proxy här.
    withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);

    $response = get('/_test/absolut-url-obetrodd', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'angripare.example',
    ]);

    $response->assertOk();
    expect($response->json('url'))->not->toContain('angripare.example');
});

it('litar på X-Forwarded-Proto/-Host från loopbacken, dit LiteSpeed hör', function () {
    Route::get('/_test/absolut-url-betrodd', fn () => response()->json(['url' => url('/')]));

    // Testklientens REMOTE_ADDR är 127.0.0.1 som standard — samma adress en
    // lokal LiteSpeed-proxy skulle koppla ifrån. Sätts uttryckligen ändå,
    // så testet inte tyst förlitar sig på ramverkets default.
    withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);

    $response = get('/_test/absolut-url-betrodd', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'mimers.app',
    ]);

    $response->assertOk();
    expect($response->json('url'))->toBe('https://mimers.app');
});

it('utan X-Forwarded-headrar används anslutningens egna schema och host', function () {
    Route::get('/_test/absolut-url-utan-proxy', fn () => response()->json(['url' => url('/')]));

    $response = get('/_test/absolut-url-utan-proxy');

    $response->assertOk();
    expect($response->json('url'))->not->toContain('mimers.app');
});
