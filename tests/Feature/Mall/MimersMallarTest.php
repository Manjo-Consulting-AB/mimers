<?php

use App\Http\Requests\Template\TemplateContentRules;
use App\Support\Template\MimersTemplates;
use Illuminate\Support\Facades\Validator;

/*
 * Mimers egna mallar i resources/templates/en.php, se [[ADR-0101 Mallarna]].
 * Innehållet går genom TemplateContentRules här, så att en felaktig mall i
 * koden fångas av sviten och inte i produktion.
 */

it('har mallar med unik kebab-case-nyckel, namn och agent_rules som sträng eller null', function () {
    $rader = require resource_path('templates/en.php');

    expect($rader)->not->toBeEmpty();

    $nycklar = array_column($rader, 'key');

    expect($nycklar)->toBe(array_values(array_unique($nycklar)));

    foreach ($rader as $rad) {
        expect($rad['key'])->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/')
            ->and(trim($rad['name']))->not->toBe('')
            ->and(array_key_exists('agent_rules', $rad))->toBeTrue()
            ->and($rad['agent_rules'] === null || is_string($rad['agent_rules']))->toBeTrue();
    }
});

it('har mallar vars innehåll klarar TemplateContentRules', function () {
    foreach (app(MimersTemplates::class)->all() as $mall) {
        $validator = Validator::make($mall->content, TemplateContentRules::rules());

        expect($validator->fails())->toBeFalse("{$mall->key}: ".json_encode($validator->errors()->all()));

        expect(count($mall->content['categories']))->toBeGreaterThanOrEqual(6)->toBeLessThanOrEqual(12);
    }
});

it('slår upp en mall på nyckel och faller tillbaka på en för andra språk', function () {
    $mallar = app(MimersTemplates::class);

    expect($mallar->find('sailboat')?->name)->toBe('Boat')
        ->and($mallar->find('finns-inte'))->toBeNull()
        ->and($mallar->all('sv_SE'))->toEqual($mallar->all('en'))
        ->and($mallar->all('../../etc/passwd'))->toEqual($mallar->all('en'))
        ->and($mallar->keys())->toContain('sailboat', 'other');
});
