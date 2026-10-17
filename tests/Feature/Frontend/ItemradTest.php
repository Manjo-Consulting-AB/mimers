<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 285 · Itemraden blir en komponent, se
 * resources/js/components/ItemRow.vue, [[M32 Formspråket]],
 * [[ADR-0050 Desktopdesignen]] och docs/Design/container.jpeg.
 *
 * **Raden ritades två gånger innan den blev en komponent.** Itemlistan bar
 * markupen i pages/Containers/Items/Index.vue, och översiktens itempanel
 * ritade en egen, tunnare rad. Filen här låser att de två nu ritar SAMMA
 * komponent, och att raden bär exakt det mockupen ritar: miniatyr eller
 * `item`-ikonen i en neutral ruta, namnet, beskrivningen och en pil — och
 * ingen status (issue 212).
 *
 * **Provet läser KÄLLAN och inte det renderade svaret**, som
 * `ItemlistaTest::ritar itemets eget omslag …` gjorde före utbrytningen:
 * komponenten ritas i webbläsaren och går inte att rendera här. Kommentarerna
 * rensas bort först, så en mening i ett docblock inte kan nöja en regel —
 * samma grepp som GenomgangTest.
 *
 * Hjälparna har prefixet `itemrad` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * En fil ur resources/js, med kommentarer borta.
 */
function itemradKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * Klart när: `Items/Index.vue` och `Overview.vue` ritar båda `ItemRow`.
 *
 * Formen är den samma på båda ytorna: `v-for` över itemen och `:cover` ur
 * `covers`-uppslaget, nycklat på itemets ULID. Skillnaden är bara vilken
 * propp ytan läser — listan `items`, panelen `props.items` — och att
 * panelen klipper listan på servern.
 */
it('ritar ItemRow i itemlistan och på översikten', function () {
    $listan = itemradKod('pages/Containers/Items/Index.vue');
    $oversikten = itemradKod('pages/Containers/Overview.vue');

    expect($listan)->toContain("import ItemRow from '../../../components/ItemRow.vue'");
    expect($listan)->toContain('<ItemRow');
    expect($listan)->toContain('v-for="item in items"');
    expect($listan)->toContain(':cover="covers[item.ulid]"');
    expect($listan)->toContain(':container-ulid="container.ulid"');

    expect($oversikten)->toContain("import ItemRow from '../../components/ItemRow.vue'");
    expect($oversikten)->toContain('<ItemRow');
    expect($oversikten)->toContain('v-for="item in props.items"');
    expect($oversikten)->toContain(':cover="props.covers[item.ulid]"');
    expect($oversikten)->toContain(':container-ulid="props.container.ulid"');
});

/*
 * Klart när: raden bär miniatyr eller `item`-ikonen i en `neutral` ruta.
 *
 * `cover.hasThumb` är serverns svar på om `?variant=thumb` svarar — en
 * `<img>` mot en variant som inte finns är en trasig bild (issue 61b
 * § Beslut 1). Utan derivat ritar `UiIcon` rollen `item` med tonen `neutral`
 * ([[ADR-0057 Ikoner och typsnitt]] § 3): ett item utan bild har ingen domän
 * som ger en annan ton.
 */
it('bär miniatyr eller item-ikonen i en neutral ruta', function () {
    $raden = itemradKod('components/ItemRow.vue');

    expect($raden)->toContain('props.cover?.hasThumb');
    expect($raden)->toContain('?variant=thumb');
    expect($raden)->toContain('name="item"');
    expect($raden)->toContain('tile="neutral"');

    // Containerns eget omslag läses aldrig i raden — ett item utan bild
    // ärver inte containerns foto.
    expect($raden)->not->toContain('container.cover');
});

/*
 * Klart när: raden bär namn, beskrivning och `chevron-right`.
 *
 * Beskrivningen ritas bara när den finns: en tom rad under namnet hade sett
 * ut som ett ritfel i stället för ett item utan beskrivning.
 */
it('bär namn, beskrivning och chevron-right', function () {
    $raden = itemradKod('components/ItemRow.vue');

    expect($raden)->toContain('props.item.name');
    expect($raden)->toContain('v-if="props.item.description"');
    expect($raden)->toContain('props.item.description');
    expect($raden)->toContain('name="chevron-right"');
});

/*
 * Klart när: raden bär ingen status.
 *
 * Statusen hör till kartan, som ritar den per nod (issue 212 ·
 * [[ADR-0050 Desktopdesignen]]). Raden känner därför varken `statuses` eller
 * ett eget ord om tillstånd.
 */
it('bär ingen status', function () {
    $raden = itemradKod('components/ItemRow.vue');

    expect($raden)->not->toContain('statuses');
    expect($raden)->not->toContain('status_');
    expect($raden)->not->toContain('>OK<');
});

/*
 * Klart när: hela raden är länken till itemet.
 *
 * Raden är en `<li>` i en `<ul>`, och länken är radens enda mål: en andra
 * `<Link` hade delat raden i två mål. Träffytan är `min-h-11` — 44 px ur
 * issue 68a § Beslut 3 — och adressen byggs ur BÅDA ULID:na, för containern
 * är given på ytan som ritar raden.
 */
it('gör hela raden till en länk till itemet', function () {
    $raden = itemradKod('components/ItemRow.vue');

    expect($raden)->toMatch('#<li>\s*<Link#');
    expect(substr_count($raden, '<Link'))->toBe(1);
    expect($raden)->toContain('min-h-11');
    expect($raden)->toContain('props.containerUlid');
    expect($raden)->toContain('props.item.ulid');
});
