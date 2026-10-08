<?php

use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 261 (GitHub #786) · QR-koden vid tvåstegsverifieringens uppsättning.
 * Se resources/js/components/TotpQrCode.vue, resources/js/pages/Settings/Security.vue
 * och [[ADR-0023 TOTP-bibliotek]] § Beslut.
 *
 * ADR:en säger att servern bara exponerar en `otpauth://`-URI och att klienten
 * ritar QR-koden ur den. Ett senare issuebeslut lämnade koden utanför; det
 * beslutet ersätts här, och `uqr` är paketet Tony godkände 2026-10-07.
 *
 * Det här är till största delen en källkodsprov, som SakerhetsvyTest: att
 * komponenten ritar matrisen som `<svg>` i stället för att bygga en
 * svg-sträng, att vyn placerar koden bredvid nyckeln, och att etiketten är en
 * riktig mening — allt sådant syns bara i källkoden. Serverns halva — att
 * `totpUri` bara bärs under uppsättningen — prövas mot det verkliga svaret.
 */

/*
 * Klart när: package.json har uqr och inget annat nytt paket. Nycklarna
 * listas i stället för att räknas: en ny rad ska fälla provet, inte bara ändra
 * ett tal. `uqr` ligger i `devDependencies` — allt Vite buntar in hör dit,
 * precis som `vue`, och paketet når appen bara genom bundeln — så
 * `dependencies` ska vara tomt.
 */
it('har uqr och inget annat nytt paket', function () {
    $paket = json_decode(File::get(base_path('package.json')), true);

    $beroenden = array_keys($paket['dependencies'] ?? []);
    sort($beroenden);

    expect($beroenden)->toBe([]);

    $utvecklingsberoenden = array_keys($paket['devDependencies'] ?? []);
    sort($utvecklingsberoenden);

    expect($utvecklingsberoenden)->toBe([
        '@inertiajs/vue3',
        '@tailwindcss/vite',
        '@vitejs/plugin-vue',
        'laravel-vite-plugin',
        'tailwindcss',
        'uqr',
        'vite',
        'vue',
    ]);
});

/*
 * Klart när: komponenten ritar ingen v-html. `v-html` och `renderSVG()` är
 * samma sak — markup byggd ur data och tolkad som HTML. Provet fångar också
 * att ritningen faktiskt sker: en `<rect>` per mörk modul ur uqr:s matris, så
 * frånvaron av `v-html` inte är frånvaron av en ritning.
 */
it('ritar qr-koden som svg och inte genom v-html', function () {
    $komponent = File::get(resource_path('js/components/TotpQrCode.vue'));

    expect($komponent)->not->toContain('v-html')
        ->and($komponent)->not->toContain('renderSVG');

    expect($komponent)->toContain("import { encode } from 'uqr'")
        ->and($komponent)->toContain('v-for="module in darkModules"')
        ->and($komponent)->toContain('<rect')
        // Fyra modulers tyst zon, så kameran hittar hörnen.
        ->and($komponent)->toContain('{ border: 4 }');
});

/*
 * Klart när: uppsättningen ritar qr-koden bredvid nyckeln. QR-koden står i
 * samma gren som `secretGroups` — efter grenens öppning och före nyckeln,
 * alltså till vänster om den.
 */
it('ritar qr-koden bredvid nyckeln i uppsättningen', function () {
    $vy = File::get(resource_path('js/pages/Settings/Security.vue'));

    expect($vy)->toContain("import TotpQrCode from '../../components/TotpQrCode.vue'")
        ->and($vy)->toContain('<TotpQrCode :uri="props.totpUri" />');

    $grenen = strpos($vy, 'v-else-if="showsSetup"');
    $koden = strpos($vy, '<TotpQrCode');
    // Mallens användning av nyckeln, inte `const secretGroups` i scriptet.
    $nyckeln = strpos($vy, 'secretGroups.join');

    expect($grenen)->not->toBeFalse()
        ->and($koden)->not->toBeFalse()
        ->and($nyckeln)->not->toBeFalse()
        ->and($koden)->toBeGreaterThan($grenen)
        ->and($koden)->toBeLessThan($nyckeln);
});

/*
 * Klart när: qr-koden har en etikett. Meningen ska finnas i katalogen och
 * komponenten ska läsa den — en nyckel som finns men inte används är en
 * etikett ingen skärmläsare hör.
 */
it('ger qr-koden en etikett', function () {
    $mening = trans('ui.settings.security.totp.qr_label', [], 'en');

    expect($mening)->not->toBe('ui.settings.security.totp.qr_label');
    expect(trim($mening))->not->toBe('');

    expect(File::get(resource_path('js/components/TotpQrCode.vue')))
        ->toContain('settings.security.totp.qr_label');
});

/*
 * Klart när: totpUri skickas bara under uppsättningen. Med tvåfaktorn
 * aktiverad finns ingen hemlighet att visa — proppen är null, och vyn hamnar i
 * läge 3 (SecurityController läser `totp_uri` ur flash, som bara POST /totp
 * fyller).
 */
it('skickar totpUri bara under uppsättningen', function () {
    withoutVite();

    [$user] = användareMedBekräftadTotp();

    actingAs($user)->get('/settings/security')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('totpEnabled', true)
            ->where('totpUri', null)
        );
});
