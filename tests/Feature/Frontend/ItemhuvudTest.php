<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 331 · Itemets huvud, se [[ADR-0068 Itemets sida och skapandet]] § 3
 * och 7 och [[M45 Itemets sida]] § 331.
 *
 * **Källkodsprov, som grannfilerna.** Att menyn öppnas med ett tryck, att
 * Escape stänger den och att fokus går tillbaka går inte att avgöra utan en
 * webbläsare; det står i PR-kroppens handprov. Det som går att avgöra ur
 * källkoden är att formen finns: vilken rad som står bakom vilken flagga, i
 * vilken ordning, och att ingen av de tre åtgärderna är en textlänk.
 *
 * Kommentarer rensas bort före varje kontroll: filerna är fulla av svensk
 * prosa om just de här orden, och ett prov som letar efter en länk ska inte
 * fällas av en mening i ett docblock.
 *
 * Hjälparna har prefixet `huvud` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

function huvudKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Huvudet i Show.vue: från brödsmulan till förekomstpanelen.
 */
function huvudAvsnitt(): string
{
    $vy = huvudKod('pages/Containers/Items/Show.vue');
    $start = strpos($vy, '<nav');
    $slut = strpos($vy, '<ItemPlacementsPanel');

    expect($start)->not->toBeFalse()
        ->and($slut)->not->toBeFalse();

    return substr($vy, (int) $start, (int) $slut - (int) $start);
}

it('ritar omslagets miniatyr när den finns och annars ikonen', function () {
    $huvud = huvudAvsnitt();
    $vy = huvudKod('pages/Containers/Items/Show.vue');

    // Miniatyren bara när omslaget har varianten `thumb`.
    expect($vy)->toContain("props.cover?.variants?.includes('thumb')")
        ->toContain("coverUrl(props.cover, 'thumb')");

    expect($huvud)->toContain('v-if="thumbUrl"')
        ->toContain(':src="thumbUrl"');

    // Ikonen är den andra grenen, inte en tredje yta bredvid.
    expect($huvud)->toMatch('/<ItemIcon\s+v-else\b/');
    expect(strpos($huvud, '<img'))->toBeLessThan(strpos($huvud, '<ItemIcon'))
        ->and(strpos($huvud, '<ItemIcon'))->toBeLessThan(strpos($huvud, '<h1'));
});

it('ritar kategorin och taggarna som chips under namnet', function () {
    $huvud = huvudAvsnitt();

    $namn = (int) strpos($huvud, '<h1');
    $kategori = (int) strpos($huvud, '{{ categoryName }}');
    $taggar = (int) strpos($huvud, '<ItemTagList');

    expect($kategori)->toBeGreaterThan($namn)
        ->and($taggar)->toBeGreaterThan($kategori);

    expect($huvud)->toContain('v-if="categoryName"')
        ->toContain('<ItemTagList v-if="item.tags.length > 0" :tags="item.tags" />');

    // Och chipet bär tokens, inte en palettfärg.
    expect($huvud)->toContain('bg-surface-sunken');
    expect($huvud)->not->toMatch('/\b(?:bg|text)-(?:slate|gray|red|blue)-\d{2,3}\b/');
});

it('ritar Edit som en primärknapp som bara syns med can.update', function () {
    $huvud = huvudAvsnitt();

    preg_match('/<UiButton\b[^>]*>/s', $huvud, $knapp);

    expect($knapp[0] ?? '')->toContain('v-if="can.update"')
        ->toContain('@click="goEdit"');
    expect($knapp[0] ?? '')->not->toContain('variant=');

    expect($huvud)->toContain("{{ t('item.edit.action') }}");

    $vy = huvudKod('pages/Containers/Items/Show.vue');

    expect($vy)->toContain("import UiButton from '../../../components/UiButton.vue'")
        ->toContain('router.visit(editUrl.value)')
        ->toContain('/items/${props.item.ulid}/edit`');
});

it('har New item under this one före Move to trash, var och en bakom sin rättighet', function () {
    $meny = huvudKod('components/ItemActionMenu.vue');

    preg_match('/<Link\b[^>]*v-if="canCreate"[^>]*>/s', $meny, $skapa);
    preg_match('/<button\b[^>]*v-if="canDelete"[^>]*>/s', $meny, $radera);

    expect($skapa[0] ?? '')->toContain(':href="createHref"')
        ->toContain('role="menuitem"');
    expect($radera[0] ?? '')->toContain('role="menuitem"')
        ->toContain('@click="trash"');

    expect(strpos($meny, "t('item.links.create_child.action')"))
        ->toBeLessThan(strpos($meny, "t('item.menu.trash')"));

    // Vyn matar menyn med flaggorna ur `can`, och raden pekar på föräldern.
    $huvud = huvudAvsnitt();

    expect($huvud)->toContain('<ItemActionMenu')
        ->toContain(':can-create="can.create"')
        ->toContain(':can-delete="can.delete"')
        ->toContain(':create-href="createChildUrl"');

    expect(huvudKod('pages/Containers/Items/Show.vue'))
        ->toContain('/items/create?parent=${props.item.ulid}`');
});

it('ritar ingen ⋯-knapp när varken create, move eller delete finns', function () {
    $meny = huvudKod('components/ItemActionMenu.vue');

    // Hela roten, knappen inräknad, hänger på att minst en rad finns.
    expect($meny)->toContain('<div v-if="canCreate || canMove || canDelete"');

    $start = strpos($meny, '<div v-if="canCreate || canMove || canDelete"');
    $knapp = strpos($meny, 'aria-haspopup="menu"');

    expect($start)->not->toBeFalse()
        ->and($knapp)->toBeGreaterThan((int) $start);
});

it('har en meny med role menu som stängs med Escape', function () {
    $meny = huvudKod('components/ItemActionMenu.vue');

    expect($meny)->toContain('role="menu"')
        ->toContain("event.key === 'Escape'")
        ->toContain('@keydown="onKeydown"')
        ->toContain(':aria-expanded=')
        ->toContain('toggle.value?.focus()');
});

it('öppnar bekräftelsedialogen från Move to trash', function () {
    $meny = huvudKod('components/ItemActionMenu.vue');
    $vy = huvudKod('pages/Containers/Items/Show.vue');

    // Raden raderar ingenting: den lämnar över, och vyn öppnar dialogen.
    expect($meny)->toContain("emit('trash', trigger)");
    expect($meny)->not->toContain('router.delete');

    expect($vy)->toContain('@trash="askDestroy"')
        ->toContain('confirmOpen.value = true')
        ->toContain('<UiConfirmDialog')
        ->toContain(':open="confirmOpen"');
});

it('låter ingen av de tre åtgärderna stå som textlänk', function () {
    $huvud = huvudAvsnitt();
    $vy = huvudKod('pages/Containers/Items/Show.vue');

    // Raden med Edit, Delete och New item under this one är borta.
    expect($huvud)->not->toContain('mt-4 flex flex-wrap gap-4 text-sm');
    expect($huvud)->not->toContain("t('item.links.create_child.action')");
    expect($huvud)->not->toContain('@click="askDestroy"');

    // Ingen `<Link>` i huvudet bär en av åtgärdernas ord.
    preg_match_all('/<Link\b.*?<\/Link>/s', $huvud, $lankar);

    foreach ($lankar[0] as $lank) {
        expect($lank)->not->toContain('item.edit.action');
        expect($lank)->not->toContain('item.destroy.action');
        expect($lank)->not->toContain('create_child');
    }

    expect($vy)->not->toContain("t('item.destroy.action') }}");
});

it('säger att itemet saknar taggar och ger länken bara med can.update', function () {
    $huvud = huvudAvsnitt();

    preg_match('/<p v-else.*?<\/p>/s', $huvud, $rad);

    expect($rad[0] ?? '')->toContain("t('item.show.tags_empty')")
        ->toContain("t('item.show.tags_add')");

    preg_match('/<Link\b[^>]*>/s', $rad[0] ?? '', $lank);

    expect($lank[0] ?? '')->toContain('v-if="can.update"')
        ->toContain(':href="editUrl"');

    // Meningen står utanför länken och därmed utan can.update också.
    expect(strpos($rad[0], "t('item.show.tags_empty')"))
        ->toBeLessThan(strpos($rad[0], '<Link'));

    expect(__('ui.item.show.tags_empty'))->not->toBe('ui.item.show.tags_empty')
        ->and(__('ui.item.show.tags_add'))->not->toBe('ui.item.show.tags_add');
});

it('börjar brödsmulan med containerns namn som länk till itemfliken', function () {
    $huvud = huvudAvsnitt();

    // Första ledet i listan är containern, före loopen över vägens led.
    $forstaLed = (int) strpos($huvud, '<li');
    $container = (int) strpos($huvud, '{{ container.name }}');
    $vagen = (int) strpos($huvud, 'v-for="(node, index) in currentPath.nodes"');

    expect($container)->toBeGreaterThan($forstaLed)
        ->and($container)->toBeLessThan($vagen);

    expect(substr($huvud, $forstaLed, $container - $forstaLed))
        ->toContain(':href="`/containers/${container.ulid}/items`"');

    // Brödsmulan ritas också utan väg: containern är alltid första ledet.
    expect($huvud)->not->toContain('<nav v-if=');
});

it('bär huvudets nya strängar i ui.php', function () {
    expect(__('ui.item.menu.label'))->toBe('More actions')
        ->and(__('ui.item.menu.trash'))->toBe('Move to trash')
        ->and(__('ui.item.links.create_child.action'))->toBe('New item under this one')
        ->and(__('ui.item.edit.action'))->toBe('Edit');
});
