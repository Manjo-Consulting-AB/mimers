<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 359 · ADR-0074 § 5. FlashMessage ritar en länk efter texten för de
 * koder som har en adress i tabellen, och bara för dem. Provet läser källan,
 * som övriga Frontend-prov.
 */
function flashMeddelandeKalla(): string
{
    return File::get(resource_path('js/components/FlashMessage.vue'));
}

function flashMeddelandeMall(): string
{
    preg_match('#<template>(.*)</template>#s', flashMeddelandeKalla(), $traffar);

    return $traffar[1];
}

it('har en tabell från flashkod till adress med inbox-task-captured', function () {
    expect(flashMeddelandeKalla())
        ->toContain("'inbox-task-captured': '/inbox'");
});

it('ritar en Link med länktexten inuti role="status"-rutan', function () {
    $mall = flashMeddelandeMall();

    expect(flashMeddelandeKalla())->toContain("import { Link, usePage } from '@inertiajs/vue3'")
        ->and($mall)->toContain('<Link')
        ->and($mall)->toContain('t(`flash_link.${code}`)')
        ->and(strpos($mall, 'role="status"'))->toBeLessThan(strpos($mall, '<Link'))
        ->and(strpos($mall, '<Link'))->toBeLessThan(strrpos($mall, '</div>'));
});

it('ritar ingen länk för en kod utan post i tabellen', function () {
    $kalla = flashMeddelandeKalla();

    expect(flashMeddelandeMall())->toContain('v-if="linkHref"')
        ->and($kalla)->toContain('FLASH_LINKS[code.value] ?? null')
        ->and(substr_count($kalla, "': '/"))->toBe(1);
});

it('ger länken min-h-11 och synlig fokusring', function () {
    preg_match('#<Link\b.*?>#s', flashMeddelandeMall(), $traffar);

    expect($traffar[0])->toContain('min-h-11')
        ->toContain('focus-visible:ring-2')
        ->toContain('focus-visible:ring-focus')
        ->toContain('hover:underline');
});
