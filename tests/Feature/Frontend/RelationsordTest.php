<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/*
 * Relationsorden, se issue 155 · [[M23 Mobilen och kartan]] § 155,
 * [[ADR-0035 Relationen mellan objekt]] och [[ADR-0048 Mobilen och
 * plusknappen]] § 4.
 *
 * **Ordet *sibling* är struket ur produkten.** Relationen heter `related`,
 * och namnbytet i koden gjordes i issue 87 — kvar var språket, och det är den
 * ytan det här provet vaktar. Det läser STRÄNGARNA i `lang/` och inte
 * filerna i sin helhet: kommentarerna är svenska med flit (AGENTS.md
 * § Språk i koden) och beskriver andra saker än itemrelationer. `syskonen` i
 * lang/en/notiser.php står i en docblock om notisnycklar, där ordet är rätt —
 * ingen itemrelation finns i den filen.
 *
 * Att ordet inte heller står i koden, i kommentarerna eller i nycklarna
 * prövas av tests/Feature/Item/ItemRelationTest.php, som läser `app/`,
 * `lang/`, `routes/` och ItemLinkSection.vue i sin helhet. De två proven
 * överlappar i `lang/` med flit: det ena letar ordet överallt, det andra
 * letar det i det en användare kan möta.
 *
 * Formen är SprakTests: nycklarna läses ur filen i stället för att räknas upp
 * här, så en mening som läggs till och bär det gamla ordet faller utan att
 * provet skrivs om.
 */

/**
 * En språkfil som en platt lista punktnycklar → värde, så att varje STRÄNG
 * kan prövas för sig och kommentarerna faller bort utan att filen behöver
 * parsas. Samma form som `sprakLov` i tests/Feature/Frontend/SprakTest.php,
 * med eget prefix: Pest lägger alla testfiler i samma namnrymd när hela
 * sviten körs, och den här filen ska gå att köra för sig.
 *
 * @param  array<mixed>  $gren
 * @return array<string, string>
 */
function relationsordStrangar(array $gren, string $prefix = ''): array
{
    $lov = [];

    foreach ($gren as $nyckel => $varde) {
        $nyckel = $prefix === '' ? (string) $nyckel : $prefix.'.'.$nyckel;

        $lov = is_array($varde)
            ? [...$lov, ...relationsordStrangar($varde, $nyckel)]
            : [...$lov, $nyckel => (string) $varde];
    }

    return $lov;
}

it('har inte ordet sibling eller syskon i någon sträng i lang/', function () {
    $filer = File::allFiles(lang_path());

    expect($filer)->not->toBeEmpty();

    $granskade = 0;
    $traffar = [];

    foreach ($filer as $fil) {
        if ($fil->getExtension() !== 'php') {
            continue;
        }

        /** @var array<mixed> $gren */
        $gren = Lang::get($fil->getFilenameWithoutExtension(), [], $fil->getRelativePath());

        foreach (relationsordStrangar($gren) as $nyckel => $varde) {
            $granskade++;

            foreach (['sibling', 'syskon'] as $ord) {
                if (stripos($varde, $ord) !== false) {
                    $traffar[] = sprintf(
                        '%s: %s = %s',
                        $fil->getRelativePathname(),
                        $nyckel,
                        $varde,
                    );
                }
            }
        }
    }

    // En tom läsning är grön utan att något är prövat — samma fälla som
    // PlusknappTest stänger med sin räknare.
    expect($granskade)->toBeGreaterThan(500, 'provningen läste nästan inga strängar');

    expect($traffar)->toBe([]);
});

/*
 * Positivt prov, och samma skäl som räknaren ovan: ett prov som letar efter
 * ett ord är grönt även när det letar på fel ställe. Här står de tre
 * relationernas ord på det ställe provet ovanför läser, och de är tre —
 * [[ADR-0035 Relationen mellan objekt]] § Beslut: teckenförklaringen är
 * *Parent · Child · Related*, och mockupens fjärde sort utgår.
 *
 * Nycklarna jämförs och inte meningarna: en omskriven copy ska inte fälla
 * provet, men en fjärde grupp ska det.
 */
it('bär de tre relationernas ord under item.links', function () {
    /** @var array<string, mixed> $ui */
    $ui = Lang::get('ui', [], 'en');

    expect(array_keys($ui['item']['links']['group']))->toBe(['parent', 'child', 'related']);
    expect($ui['item']['links'])->toHaveKey('current');
});
