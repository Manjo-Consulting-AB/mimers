<?php

it('kör alla tre arbetsflödena på PHP 8.5 och nämner inte 8.4', function (string $fil) {
    $innehall = file_get_contents(dirname(__DIR__, 2).'/'.$fil);

    expect($innehall)->toContain("php-version: '8.5'")
        ->and($innehall)->not->toContain("'8.4'");
})->with([
    '.github/workflows/ci.yml',
    '.github/workflows/staging.yml',
    '.github/workflows/migreringar.yml',
]);

it('har PHP 8.5.12 som plattform i composer.json', function () {
    $composer = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect($composer['config']['platform']['php'])->toBe('8.5.12');
});

it('använder php85 och inte php84 i flake.nix', function () {
    $flake = file_get_contents(dirname(__DIR__, 2).'/flake.nix');

    expect($flake)->toContain('php85')
        ->and($flake)->not->toContain('php84');
});
