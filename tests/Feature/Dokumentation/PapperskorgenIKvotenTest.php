<?php

/*
 * Issue 1061 · Papperskorgen räknas i kvoten: ADR-0009 har uppföljningen och
 * datamodellsdokumenten säger inte längre att bara levande bilagor räknas.
 */

it('dokumenterar att papperskorgen räknas i kvoten', function () {
    $adr = file_get_contents(base_path('docs/ADR/ADR-0009 Kvoter och livscykel.md'));
    $filer = file_get_contents(base_path('docs/Datamodell/Filer och lagring.md'));
    $planer = file_get_contents(base_path('docs/Datamodell/Planer och kvoter.md'));

    expect($adr)->toContain('## Uppföljning 2026-11-11 — papperskorgen räknas i kvoten');
    expect($filer)->not->toContain('hens levande attachments');
    expect($filer)->toContain('papperskorgen inräknad');
    expect($planer)->toContain('De nyslängda ligger kvar i papperskorgen i 30 dagar');
});
