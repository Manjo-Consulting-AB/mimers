<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 1254 · Ytorna för containerns valutor, se [[ADR-0078 Valutor ur ISO
 * 4217]] § 4 och 5.
 *
 * **Källkodsprov, som grannfilerna.** Det som går att avgöra på serversidan
 * är formen: att avsnittet sparar direkt i stället för med en *Save*, att
 * *Remove* följer det sparade urvalet (även för standardvalutan), och att
 * kostnadsformuläret väljer mellan en enkel väljare och sökrutan på urvalets
 * längd. Det som kräver en webbläsare — att chipset ritas, att ett klick
 * verkligen sparar, att fokusordningen håller — står i PR:ns handprov.
 *
 * Kommentarer rensas före varje kontroll, som i GenomgangTest och SprakTest:
 * filerna är fulla av svenska docblock, och en regel som letar efter
 * `router.put` ska inte kunna nöjas av en mening.
 *
 * Hjälparna har prefixet `tillatnavy` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

function tillatnavyKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Det avsnitt i Edit.vue som handlar om valutor, från `<section>` till nästa
 * `</section>`.
 */
function tillatnavyAvsnitt(): string
{
    $kod = tillatnavyKod('pages/Containers/Edit.vue');

    $start = strpos($kod, 'container.edit.currencies');

    expect($start)->not->toBeFalse('Containers/Edit.vue saknar avsnittet Currencies for costs');

    $sektion = strrpos(substr($kod, 0, (int) $start), '<section');

    expect($sektion)->not->toBeFalse();

    $slut = strpos($kod, '</section>', (int) $sektion);

    return substr($kod, (int) $sektion, (int) $slut - (int) $sektion);
}

it('sparar valutarändringen direkt med en PUT och hela listan', function () {
    $kod = tillatnavyKod('pages/Containers/Edit.vue');
    $avsnitt = tillatnavyAvsnitt();

    // Direktsparningen: ett router.put mot currencies-rutten, med vänteläge
    // (GenomgangTest kräver onStart/onFinish och en stängd kontroll).
    expect($kod)->toContain('router.put(`/containers/${props.container.ulid}/currencies`, { currencies: codes }')
        ->toContain('onStart:')
        ->toContain('onFinish:')
        ->toContain(':disabled="currenciesPending"');

    // Hela listan skickas: add lägger till, remove filtrerar bort — ingen
    // enskild rad skrivs för sig.
    expect($kod)->toContain('saveCurrencies([...selectedCurrencies.value, code])')
        ->toContain('selectedCurrencies.value.filter((currency) => currency !== code)');

    // Statusraden i avsnittet, och ingen *Save*: formulärets knapp ligger
    // ovanför och hör till textfälten.
    expect($avsnitt)->toContain('role="status"')
        ->and($avsnitt)->not->toContain('type="submit"');
});

it('ger Remove åt varje chip som står i urvalet, även standardvalutan', function () {
    $kod = tillatnavyKod('pages/Containers/Edit.vue');
    $avsnitt = tillatnavyAvsnitt();

    // Standardvalutan läggs till i chipset när den saknas i urvalet ...
    expect($kod)->toContain('new Set([...selectedCurrencies.value, defaultCurrency.value])');

    // ... men *Remove* hänger på om koden står i det sparade urvalet, inte på
    // om den är standardvaluta. Annars gick urvalet inte att tömma när
    // standardvalutan också fanns i det — vare sig den valdes direkt eller
    // blev standard efter ett byte av containerns valuta.
    expect($avsnitt)->toContain('v-if="selectedCurrencies.includes(code)"')
        ->toContain("t('container.edit.currencies_standard'");

    expect($avsnitt)->not->toContain('v-if="code !== defaultCurrency"');

    // Ett tomt urval betyder alla — meningen står i avsnittet.
    expect($avsnitt)->toContain("t('container.edit.currencies_all')");
});

it('ritar avsnittet efter ikonen och bilden', function () {
    $kod = tillatnavyKod('pages/Containers/Edit.vue');

    $ikon = strpos($kod, "t('item.form.icon')");
    $bild = strpos($kod, "t('container.cover.heading')");
    $valutor = strpos($kod, "t('container.edit.currencies')");

    expect($ikon)->not->toBeFalse()
        ->and($bild)->not->toBeFalse()
        ->and($valutor)->not->toBeFalse();

    expect($ikon)->toBeLessThan($bild)
        ->and($bild)->toBeLessThan($valutor);
});

it('gör valutafältet till en väljare vid högst sex valutor och en sökruta annars', function () {
    $kod = tillatnavyKod('components/CostEntryForm.vue');

    // Tröskeln räknas på det markerade urvalet, och ett tomt urval betyder
    // alla — då är fältet sökrutan utan begränsning.
    expect($kod)->toContain('allowedCodes.value.length > 0 && allowedCodes.value.length <= 6')
        ->toContain('<select')
        ->toContain('v-if="simpleCurrencySelect"')
        ->toContain('v-else')
        ->toContain(':options="allowedCodes.length === 0 ? null : currencyChoicesWithNames"');

    // Väljaren bär standardvalutan även när den inte står i urvalet.
    expect($kod)->toContain('...new Set([props.costDefaults.currency, ...allowedCodes.value])');
});

it('låter redigeringen behålla radens egen valuta i valen', function () {
    $kod = tillatnavyKod('components/ItemCostSection.vue');

    // Radens currency läggs till i mängden för just den raden — annars hade
    // en rad i en valuta som tagits bort ur urvalet inte gått att spara om.
    expect($kod)->toContain('cost.currency,')
        ->toContain('editCurrencyOptions(cost)')
        ->toContain(':options="editCurrencyOptions(cost)"');
});
