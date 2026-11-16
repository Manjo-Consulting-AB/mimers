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
        ->and($grafen)->toContain('class="hidden h-[32rem] w-full rounded-card border border-border bg-surface md:block"');

    // Under `md:` är kartan Focus: den ritas, grafen inte, och växeln saknas.
    expect($sidan)->toContain("showGraph ? 'md:hidden' : ''")
        ->toContain('<ItemMapDepthSwitch')
        ->toContain('<ItemGraph')
        ->toContain("props.map?.center.kind === 'item'")
        ->toContain("'neighbourhood' : 'focus'");
});

it('lägger nivån i adressen och bara Focus utan depth', function () {
    $sidan = kartanszoomKod('pages/Containers/Items/Index.vue');

    expect($sidan)->toContain('?view=map${focus}${tail}`')
        ->toContain('?view=map${focus}&depth=2${tail}`');

    $grafen = kartanszoomKod('components/ItemGraph.vue');

    expect($grafen)->toContain('?view=map&focus=${node.ulid}&depth=2');
});

it('har d3-force och inget annat nytt paket', function () {
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
    ]);
});

it('ritar grafen utan pilspetsar', function () {
    $grafen = File::get(resource_path('js/components/ItemGraph.vue'));

    foreach (['<marker', '<defs', 'marker-start', 'marker-end', 'markerEnd', 'arrow'] as $spar) {
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
