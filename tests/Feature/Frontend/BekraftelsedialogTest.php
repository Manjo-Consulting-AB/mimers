<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 330 · Bekräftelsedialogen och raderingen av ett item, se
 * [[ADR-0068 Itemets sida och skapandet]] § 6 och [[M45 Itemets sida]] § 330.
 *
 * **Källkodsprov, som grannfilerna.** Att dialogen faktiskt tar fokus i en
 * webbläsare, att Esc stänger den och att fokus hamnar på knappen går inte att
 * avgöra utan en; det står i PR-kroppens handprov. Det som går att avgöra ur
 * källkoden är att formen finns: `<dialog>` med `showModal()`, `autofocus` på
 * *Cancel*, Esc och bakgrunden på samma väg som *Cancel*, och fokus
 * återlämnad uttryckligen.
 *
 * Kommentarer rensas bort före varje kontroll: filerna är fulla av svensk prosa
 * om just de här orden, och en regel som letar efter `window.confirm` ska inte
 * kunna fällas av en mening i ett docblock.
 *
 * Hjälparna har prefixet `bekraftelse` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

function bekraftelseKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

it('har en UiConfirmDialog som bara bär tokens', function () {
    expect(File::exists(resource_path('js/components/UiConfirmDialog.vue')))->toBeTrue();

    $kod = bekraftelseKod('components/UiConfirmDialog.vue');

    $palett = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';
    $verktyg = 'text|bg|border|ring|outline|divide|from|via|to|fill|stroke|placeholder|decoration|shadow|accent|caret';

    expect(preg_match(
        '/\b(?:'.$verktyg.')-(?:(?:'.$palett.')-\d{2,3}|white|black)\b/',
        $kod,
    ))->toBe(0);

    // Bygger på `<dialog>` med `showModal()`, som UiSheet, och bär rubrik,
    // brödtext, etikett, ton och väntläge som proppar.
    expect($kod)->toContain('<dialog')
        ->toContain('showModal()');

    foreach (['heading', 'body', 'confirmLabel', 'tone', 'pending'] as $prop) {
        expect($kod)->toMatch("/\\b{$prop}:\\s*\\{/");
    }

    expect($kod)->toContain("'danger'")
        ->toContain("'neutral'")
        ->toContain("defineEmits(['confirm', 'cancel'])");
});

it('ger Cancel fokus när dialogen öppnas', function () {
    $kod = bekraftelseKod('components/UiConfirmDialog.vue');

    // Det är Cancel som bär `autofocus`, och bara den: bekräftelsen får inte
    // vara förvald för tangentbordet.
    preg_match_all('/<UiButton\b[^>]*>/s', $kod, $knappar);

    $medFokus = array_values(array_filter($knappar[0], fn (string $tagg): bool => str_contains($tagg, 'autofocus')));

    expect($medFokus)->toHaveCount(1);
    expect($medFokus[0])->toContain('@click="cancel"');
});

it('stänger utan att bekräfta på Escape och bakgrunden', function () {
    $kod = bekraftelseKod('components/UiConfirmDialog.vue');

    // Esc: webbläsarens `cancel`-händelse går till `cancel()`, inte till
    // `confirm`.
    expect($kod)->toContain('@cancel="onEscape"');
    preg_match('/function onEscape\(event\) \{(.*?)\n\}/s', $kod, $esc);
    expect($esc[1] ?? '')->toContain('event.preventDefault()')
        ->toContain('cancel()')
        ->not->toContain("emit('confirm')");

    // Bakgrunden är en `<button>` som avbryter.
    preg_match('/<button\b[^>]*absolute inset-0[^>]*>/s', $kod, $bakgrund);
    expect($bakgrund[0] ?? '')->toContain('@click="cancel"');

    // Och `confirm` fyras bara från bekräftelseknappen.
    expect(substr_count($kod, "emit('confirm')"))->toBe(1);
    expect($kod)->toContain("emit('cancel')");
});

it('lämnar tillbaka fokus till utlösaren', function () {
    $kod = bekraftelseKod('components/UiConfirmDialog.vue');

    expect($kod)->toContain('@close="onClosed"');

    preg_match('/function onClosed\(\) \{(.*?)\n\}/s', $kod, $stang);
    expect($stang[1] ?? '')->toContain('props.trigger')->toContain('.focus');

    // Och Show.vue ger den knappen som utlösare.
    $vy = bekraftelseKod('pages/Containers/Items/Show.vue');

    expect($vy)->toContain('destroyTrigger.value = event.currentTarget')
        ->toContain(':trigger="destroyTrigger"');
});

it('låter itemets sida sakna window.confirm', function () {
    $vy = bekraftelseKod('pages/Containers/Items/Show.vue');

    expect($vy)->not->toContain('window.confirm')
        ->not->toContain('confirm(');
    expect($vy)->toContain('<UiConfirmDialog');
});

it('nämner itemets namn och 30 dagar i dialogen', function () {
    $en = require lang_path('en/ui.php');
    $vy = bekraftelseKod('pages/Containers/Items/Show.vue');

    expect($vy)->toContain("t('item.destroy.confirm_heading', { name: item.name })")
        ->toContain("t('item.destroy.confirm_body', { name: item.name, days: TRASH_DAYS })")
        ->toContain('const TRASH_DAYS = 30;');

    expect($en['item']['destroy']['confirm_heading'])->toContain(':name');
    expect($en['item']['destroy']['confirm_body'])->toContain(':name')->toContain(':days');
    expect(strtolower($en['item']['destroy']['confirm_body']))->toContain('trash')->not->toContain('permanent');
});

it('raderar itemet som förut när dialogen bekräftas', function () {
    $vy = bekraftelseKod('pages/Containers/Items/Show.vue');

    expect($vy)->toContain('@confirm="destroy"')
        ->toContain('router.delete(`/containers/${props.container.ulid}/items/${props.item.ulid}`')
        ->toContain('onStart: () => { pending.value = true; }');

    // Knappen öppnar bara dialogen; raderingen går inte direkt från klicket.
    preg_match('/<button\b[^>]*@click="askDestroy"[^>]*>/s', $vy, $knapp);
    expect($knapp[0] ?? '')->toContain('v-if="can.delete"');

    // Avbrott stänger dialogen och rör inte raderingen.
    expect($vy)->toContain('@cancel="confirmOpen = false"');
});

it('har ersatt item.destroy.confirm och bär dialogens egna strängar i lang', function () {
    $en = require lang_path('en/ui.php');

    expect($en['item']['destroy'])->not->toHaveKey('confirm');
    expect(trim($en['item']['destroy']['confirm_heading']))->not->toBe('');
    expect(trim($en['confirm']['cancel']))->not->toBe('');

    // Ingen kod läser den gamla nyckeln.
    foreach (File::allFiles(resource_path('js')) as $fil) {
        expect(File::get($fil->getPathname()))->not->toContain("'item.destroy.confirm'");
    }
});
