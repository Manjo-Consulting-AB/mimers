<?php

use Illuminate\Support\Facades\File;

/*
 * Aktivitetsdiagrammets textform är dold för den som ser, men inte med
 * `sr-only` på `<table>`: en tabells `caption` ritas utanför tabellens egen
 * ruta och klipps inte av `sr-only`, så rubriken syntes en gång till under
 * kortets egen rubrik. Omslaget bär klassen, tabellen inte.
 */

function aktivitetsdiagramMall(): string
{
    $source = File::get(base_path('resources/js/components/ActivityTimeChart.vue'));
    $start = strpos($source, '<template>');

    return substr($source, $start === false ? 0 : $start);
}

it('lägger sr-only på ett omslag runt tabellen', function () {
    expect(aktivitetsdiagramMall())
        ->toMatch('/<div class="sr-only">\s*<table>.*<\/table>\s*<\/div>/s');
});

it('låter inte table själv bära sr-only', function () {
    preg_match_all('/<table\b[^>]*>/', aktivitetsdiagramMall(), $matches);

    expect($matches[0])->not->toBeEmpty();

    foreach ($matches[0] as $tag) {
        expect($tag)->not->toContain('sr-only');
    }
});

it('behåller caption i tabellen', function () {
    expect(aktivitetsdiagramMall())
        ->toContain("<caption>{{ t('audit.history.activity_over_time') }}</caption>");
});
