<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 1378. Relationsarket i resources/js/components/ItemMap.vue fästs vid
 * mittkortets plusknapp (ItemMapNode.vue), så att UiSheet räknar sitt läge på
 * desktop som CreateMenu gör. Källkodsprov: UiSheet.vue ändras inte.
 */

function relationsarketMap(): string
{
    return File::get(base_path('resources/js/components/ItemMap.vue'));
}

it('låter relationsarkets trigger läsa mittkortets plusknapp', function () {
    $map = relationsarketMap();

    expect($map)
        ->toContain("querySelector('[data-item-map-menu]')")
        ->toContain('getBoundingClientRect: () => menuButton()?.getBoundingClientRect()')
        ->toContain('focus: () => menuButton()?.focus()')
        ->toContain('ref="mapRoot"')
        ->toContain(':trigger="sheetTrigger"');
});

it('märker plusknappen i ItemMapNode med markören som triggern söker', function () {
    $node = File::get(base_path('resources/js/components/ItemMapNode.vue'));

    expect($node)->toMatch('/<button\s[^<]*?data-item-map-menu[^<]*?@click="emit\(\'open-menu\'/s');
});
