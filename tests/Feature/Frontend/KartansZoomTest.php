<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/*
 * Issue 393 · Kartans zoomnivåer — källkodsprov. Se
 * resources/js/components/ItemMapDepthSwitch.vue,
 * resources/js/components/ItemGraph.vue och
 * resources/js/pages/Containers/Items/Index.vue, [[ADR-0082 Kartan är
 * relationernas yta]] § 4, 5, 7 och 9.
 *
 * Servern prövas i tests/Feature/Item/ItemetsGrannskapTest.php. Det här är
 * den halva som ingen webbläsare prövar här: vad som syns från `md:`, vilka
 * paket som drogs in och att linjerna saknar spetsar.
 */

/** Källkoden utan kommentarer, så att ett ord i en förklaring inte räknas. */
function kartanszoomKod(string $fil): string
{
    $kod = File::get(resource_path("js/{$fil}"));
    $kod = preg_replace('~/\*.*?\*/~s', '', $kod);
    $kod = preg_replace('~<!--.*?-->~s', '', $kod);

    return (string) preg_replace('~^\s*//.*$~m', '', $kod);
}

it('ritar växeln och grafen bara från md:', function () {
    $vaxeln = kartanszoomKod('components/ItemMapDepthSwitch.vue');
    $grafen = kartanszoomKod('components/ItemGraph.vue');
    $sidan = kartanszoomKod('pages/Containers/Items/Index.vue');

    expect($vaxeln)->toContain('class="hidden md:block"')
        ->and($grafen)->toContain('class="hidden h-[32rem] w-full touch-none cursor-grab rounded-card border border-border bg-surface md:block"');

    // Under `md:` är kartan Focus: den ritas, grafen inte, och växeln saknas.
    expect($sidan)->toContain("showGraph || showWhole ? 'md:hidden' : ''")
        ->toContain('<ItemMapDepthSwitch')
        ->toContain('<ItemGraph')
        ->toContain("props.map?.center.kind === 'item'")
        ->toContain("showGraph.value ? 'neighbourhood' : 'focus'");
});

it('lägger nivån i adressen och bara Focus utan depth', function () {
    $sidan = kartanszoomKod('pages/Containers/Items/Index.vue');

    expect($sidan)->toContain('?view=map${focus}${tail}`')
        ->toContain('?view=map${focus}&depth=linked${tail}`')
        ->toContain('?view=map${focus}&depth=all${tail}`');

    $grafen = kartanszoomKod('components/ItemGraph.vue');

    expect($grafen)->toContain('?view=map&focus=${node.ulid}&depth=linked');
});

it('har d3-force för Whole container och inget annat nytt paket', function () {
    $paket = json_decode(File::get(base_path('package.json')), true);

    expect($paket['dependencies']['d3-force'] ?? null)->not->toBeNull();

    $alla = array_merge(array_keys($paket['dependencies'] ?? []), array_keys($paket['devDependencies'] ?? []));

    // Inte hela d3, och inget annat som drar in en graf eller en annan layout.
    expect($alla)->not->toContain('d3');

    expect(array_values(array_filter($alla, fn (string $namn): bool => str_starts_with($namn, 'd3'))))->toBe(['d3-force']);

    $grafen = kartanszoomKod('components/ItemGraph.vue');

    preg_match_all('/^import .* from \'([^\']+)\';$/m', $grafen, $importer);

    expect($importer[1])->toBe([
        'vue',
        '@inertiajs/vue3',
        'd3-force',
        '../composables/useTranslations.js',
        './itemIconPresentation.js',
    ]);
});

it('ritar grafen utan pilspetsar', function () {
    $grafen = File::get(resource_path('js/components/ItemGraph.vue'));

    foreach (['<marker', '<defs', 'marker-start', 'marker-end', 'markerEnd', 'arrowhead'] as $spar) {
        expect(strtolower($grafen))->not->toContain(strtolower($spar));
    }
});

it('ritar linjerna som kartan: heldragna till struktur och streckade till relaterade', function () {
    $grafen = kartanszoomKod('components/ItemGraph.vue');

    expect($grafen)->toContain("line.related ? 'var(--color-ink-subtle)' : 'var(--color-accent)'")
        ->toContain("line.related ? '6 5' : undefined");

    // Färgerna är designsystemets token och aldrig hex.
    expect($grafen)->not->toMatch('/#[0-9a-fA-F]{3,6}\b/');
});

it('gör noderna till länkar som går att nå med tangentbordet och som visar namn och status', function () {
    $grafen = kartanszoomKod('components/ItemGraph.vue');

    expect($grafen)->toContain(':href="node.href"')
        ->toContain('@focus="active = node.ulid"')
        ->toContain('@blur="active = null"')
        ->toContain('@mouseenter="active = node.ulid"')
        ->toContain('router.visit(href)');

    expect($grafen)->not->toContain('tabindex="-1"')
        ->and($grafen)->not->toContain('Math.random');
});

it('hämtar växelns och grafens ord ur ui.php', function () {
    $sidan = File::get(resource_path('js/pages/Containers/Items/Index.vue'));
    $grafen = File::get(resource_path('js/components/ItemGraph.vue'));

    expect($sidan)->toContain("t('item.depth.focus')")
        ->toContain("t('item.depth.neighbourhood')")
        ->toContain("t('item.depth.label')");

    expect(Lang::get('ui.item.depth.focus', [], 'en'))->toBe('Focus')
        ->and(Lang::get('ui.item.depth.neighbourhood', [], 'en'))->toBe('Neighbourhood');

    foreach (['item.graph.label', 'item.graph.center', 'item.graph.status.ok', 'item.graph.status.overdue'] as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}");
    }

    expect($grafen)->toContain("t('item.graph.label'");
});

it('lägger Whole container i växeln, med och utan mitt (issue 394)', function () {
    $sidan = kartanszoomKod('pages/Containers/Items/Index.vue');

    expect($sidan)->toContain("t('item.depth.all')")
        ->toContain('props.graph !== null')
        ->toContain('mode="container"')
        ->toContain(':query="filter.q"');

    expect($sidan)->not->toContain('v-if="map.center.kind === \'item\'"\n                    :levels');

    expect(Lang::get('ui.item.depth.all', [], 'en'))->toBe('Whole container');
});

it('ritar hela containern med panorering, zoom, namn först inzoomat och sökträffar', function () {
    $grafen = kartanszoomKod('components/ItemGraph.vue');

    // Mus, nyp, knappar och tangentbord.
    expect($grafen)->toContain('@wheel="onWheel($event)"')
        ->toContain('event.preventDefault()')
        ->toContain('@pointerdown')
        ->toContain('@pointermove')
        ->toContain('pinchDistance')
        ->toContain('zoomButton(ZOOM_STEP)')
        ->toContain('zoomButton(1 / ZOOM_STEP)')
        ->toContain('@keydown')
        ->toContain('ArrowLeft')
        ->toContain(':tabindex="0"');

    // Namnen över en zoomnivå, eller från början i en liten container (issue 397).
    expect($grafen)->toContain('view.value.k >= LABEL_ZOOM');

    // Vyn börjar centrerad på `focus`, och sökningen tonar ned i stället för att gömma.
    expect($grafen)->toContain('layout.value[props.center]')
        ->toContain('props.query')
        ->toContain('data-hit');

    expect($grafen)->not->toContain('v-if="node.hit"');
    expect($grafen)->not->toContain('@wheel.prevent');

    expect($grafen)->toContain("event.type === 'pointerleave'");

    // Ett klick går till Focus, utan depth; ingen redigering och ingen plusmeny.
    expect($grafen)->toContain('?view=map&focus=${node.ulid}`');

    expect($grafen)->not->toContain('CreateTarget')
        ->and($grafen)->not->toContain('UiMenu');
});

it('ritar en mening vid truncated och nämner inget antal', function () {
    $grafen = kartanszoomKod('components/ItemGraph.vue');

    expect($grafen)->toContain('v-if="truncated"')
        ->toContain("t('item.graph.truncated')");

    $mening = Lang::get('ui.item.graph.truncated', [], 'en');

    expect($mening)->not->toBe('ui.item.graph.truncated')
        ->and($mening)->not->toMatch('/\d/')
        ->and($mening)->not->toContain(':');
});

/** Texten i en namngiven funktion i ItemGraph.vue, till nästa funktion på översta nivån. */
function kartanszoomFunktion(string $namn): string
{
    $grafen = kartanszoomKod('components/ItemGraph.vue');
    $start = strpos($grafen, "function {$namn}(");

    expect($start)->not->toBeFalse();

    preg_match('~^\}~m', substr($grafen, (int) $start), $slut, PREG_OFFSET_CAPTURE);

    return substr($grafen, (int) $start, $slut[0][1] + 1);
}

it('lägger Neighbourhood ut radiellt utan simulering, och ritar ringarna (issue 396)', function () {
    $radiellt = kartanszoomFunktion('radialLayout');

    foreach (['forceSimulation', 'forceLink', 'forceManyBody', 'forceCollide', 'Math.random', 'Date', 'performance'] as $forbjudet) {
        expect($radiellt)->not->toContain($forbjudet);
    }

    // Ringens radie växer med antalet noder och är aldrig mindre än föregående plus ett avstånd.
    expect($radiellt)->toContain('radii[ring - 1] + RING_GAP')
        ->toContain('perRing.get(ring)');

    // Kraftlayouten är kvar för Whole container, och bara där.
    $grafen = kartanszoomKod('components/ItemGraph.vue');

    expect(kartanszoomFunktion('forceLayout'))->toContain('forceSimulation(simNodes)')
        ->and($grafen)->toContain('whole.value ? forceLayout() : radial.value.positions');

    // Ringarna ritas under kanterna i en dämpad token.
    expect($grafen)->toContain('data-item-graph-rings')
        ->toContain('v-for="(radius, index) in rings"')
        ->toContain('stroke="var(--color-border)"');

    expect(strpos($grafen, 'data-item-graph-rings'))->toBeLessThan((int) strpos($grafen, 'v-for="line in lines"'));
});

it('förankrar varje nod i första grannen på ringen innanför utan att läsa kantens slag (issue 396)', function () {
    $radiellt = kartanszoomFunktion('radialLayout');

    expect($radiellt)->toContain('ringOf(other) === ringOf(node.ulid) - 1')
        ->toContain('order.get(a) - order.get(b)')
        ->toContain('children.get(inner[0] ?? root).push(node.ulid)')
        ->toContain('weight.get(child)');

    // Ankaret ser aldrig `kind`; det gör bara kantens ritning.
    expect($radiellt)->not->toContain('kind')
        ->and($radiellt)->not->toContain('parent');
});

it('ritar kanter inom en ring som bågar och mellan ringar som raka linjer (issue 396)', function () {
    $linje = kartanszoomFunktion('pathBetween');

    expect($linje)->toContain('L ${to.x} ${to.y}')
        ->toContain('Q ');

    $grafen = kartanszoomKod('components/ItemGraph.vue');

    expect($grafen)->not->toContain('<line');
});

it('ritar noderna som ikon i icon_color med namnet under och mitten i accent (issue 396)', function () {
    $grafen = kartanszoomKod('components/ItemGraph.vue');

    expect($grafen)->toContain('loadIcon(name)')
        ->toContain('TONE_CLASS[node.icon_color]')
        ->toContain('text-tint-violet')
        ->toContain("node.isCenter ? 'var(--color-accent)' : 'var(--color-border)'")
        ->toContain('node.short')
        ->toContain('…');
});

it('delar panoreringen och zoomen mellan lägena, och vyn börjar inpassad (issue 396)', function () {
    $grafen = kartanszoomKod('components/ItemGraph.vue');

    foreach (['whole && onWheel', 'whole && onPointer', 'whole && onKeydown', '<div v-if="whole" class="absolute right-3'] as $gammal) {
        expect(str_contains($grafen, $gammal))->toBeFalse();
    }

    expect($grafen)->toContain('<div class="absolute right-3')
        ->toContain('const reach = rings.value[rings.value.length - 1] + pad');

    // Namnen visas från samma zoomnivå i båda lägena.
    expect($grafen)->toContain('(showNames || node.hit || node.isCenter) && node.ulid !== active');
    expect(str_contains($grafen, '<g v-if="whole" aria-hidden="true" class="pointer-events-none">'))->toBeFalse();
});

it('ritar den nya etiketten ur ui.php (issue 396)', function () {
    $etikett = (string) Lang::get('ui.item.graph.label', ['name' => 'X'], 'en');

    expect(str_contains($etikett, 'two steps'))->toBeFalse();
    expect($etikett)->toContain('X');
});

it('ritar Whole container med samma nod som Neighbourhood (issue 397)', function () {
    $grafen = kartanszoomKod('components/ItemGraph.vue');

    // Ingen egen nod för läget: plattan, ikonen och namnet ritas en gång.
    expect(substr_count($grafen, ':r="node.isCenter ? CENTER_PLATE_RADIUS : PLATE_RADIUS"'))->toBe(1)
        ->and($grafen)->not->toContain('<template v-if="whole">')
        ->and($grafen)->not->toContain('CENTER_RADIUS :')
        ->and($grafen)->toContain('loadIcon(name)')
        ->and($grafen)->toContain('TONE_CLASS[node.icon_color]')
        ->and($grafen)->not->toContain('whole ? node.name : node.short');

    // Ikonerna hämtas i båda lägena.
    expect(kartanszoomKod('components/ItemGraph.vue'))->not->toMatch('/if \(whole\.value\) \{\s*return;/');

    // Statusen är en prick i danger med en ring i surface, bara för overdue.
    expect($grafen)->toContain("v-if=\"node.status === 'overdue'\"")
        ->toContain('data-item-graph-overdue')
        ->toContain('fill="var(--color-danger)"')
        ->and($grafen)->not->toContain("node.status === 'overdue' ? 'var(--color-danger)'");

    // Namnet: fast skärmstorlek, kontur i surface bakom, ellips.
    expect($grafen)->toContain(':font-size="12 / view.k"')
        ->toContain(':stroke-width="4 / view.k"')
        ->toContain('paint-order="stroke"')
        ->toContain('font-weight="600"')
        ->toContain('shortName(node.name)');
});

it('ritar alla namn under 40 noder och annars från LABEL_ZOOM (issue 397)', function () {
    $grafen = kartanszoomKod('components/ItemGraph.vue');

    expect($grafen)->toContain('const ALL_NAMES_BELOW = 40;')
        ->toContain('view.value.k >= LABEL_ZOOM')
        ->toContain('props.nodes.length < ALL_NAMES_BELOW')
        ->toContain('(showNames || node.hit || node.isCenter) && node.ulid !== active');
});

it('ritar linjerna i Whole container raka och utan spetsar (issue 397)', function () {
    $linje = kartanszoomFunktion('pathBetween');

    expect($linje)->toContain('whole.value ||')
        ->toContain('L ${to.x} ${to.y}');

    $grafen = kartanszoomKod('components/ItemGraph.vue');

    expect(str_contains($grafen, '<marker'))->toBeFalse()
        ->and(str_contains($grafen, 'marker-end'))->toBeFalse();
});

it('håller zoomens tak på fem steg och låter plattan styra layoutens avstånd (issue 397)', function () {
    $grafen = kartanszoomKod('components/ItemGraph.vue');

    expect($grafen)->toContain('const ZOOM_STEP = 1.25;')
        ->toContain('const MAX_ZOOM = ZOOM_STEP ** 5;')
        ->toContain('const MIN_ZOOM = 0.4;')
        ->and($grafen)->not->toContain('MAX_ZOOM = 8');

    $kraft = kartanszoomFunktion('forceLayout');

    expect($kraft)->toContain('forceCollide(COLLIDE_RADIUS)')
        ->toContain('.distance(LINK_DISTANCE)');

    expect($grafen)->toContain('COLLIDE_RADIUS = PLATE_RADIUS *')
        ->toContain('LINK_DISTANCE = PLATE_RADIUS *');
});

it('drar inte in något nytt paket för noden (issue 397)', function () {
    $paket = json_decode(File::get(base_path('package.json')), true);
    $alla = array_merge(array_keys($paket['dependencies'] ?? []), array_keys($paket['devDependencies'] ?? []));

    expect(array_values(array_filter($alla, fn (string $namn): bool => str_starts_with($namn, 'd3'))))->toBe(['d3-force']);

    preg_match_all('/^import .* from \'([^\']+)\';$/m', kartanszoomKod('components/ItemGraph.vue'), $importer);

    expect($importer[1])->toBe(['vue', '@inertiajs/vue3', 'd3-force', '../composables/useTranslations.js', './itemIconPresentation.js']);
});
