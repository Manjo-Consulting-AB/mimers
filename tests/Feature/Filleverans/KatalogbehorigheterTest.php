<?php

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
 * Issue 651 · Katalogbehörigheter. Se config/filesystems.php § disken `files`
 * och [[Pipeline]] § Filleverans.
 *
 * Flysystem skapar en lokal disks kataloger med Laravels privata standard
 * (`0700`) när `directory_visibility` inte säger annat. Appen (PHP, kontots
 * användare) når dem ändå, men LiteSpeed, som levererar bytena vid den interna
 * omdirigeringen, gör det inte — och svaret blir LiteSpeeds egen 404 i stället
 * för bytena (issue 651).
 *
 * Disken byggs ur konfigurationen med rötterna under sys_get_temp_dir() i
 * stället för med `Storage::fake('files')`: en fejkad disk ignorerar diskens
 * konfiguration och hade därför inte bevisat något om standarden. Provet mäter
 * alltså samma inställningar som servern får.
 *
 * `->skipOnWindows()`: filrättigheter finns inte där.
 *
 * Ingen `$this->` i closures (ADR-0022): PHPStan kan inte härleda vad `$this`
 * är bundet till i en Pest-closure, så hjälpare och lokala variabler används.
 */

/**
 * Kör $prov mot en `files`-disk byggd ur konfigurationen, med roten i en egen
 * katalog under sys_get_temp_dir(). Katalogen städas alltid, även om ett
 * påstående faller.
 */
function katalogbehorighetProva(Closure $prov): void
{
    $rot = sys_get_temp_dir().'/katalogbehorigheter-'.bin2hex(random_bytes(8));
    mkdir($rot, 0755, true);

    try {
        $disk = Storage::build([
            ...config('filesystems.disks.files'),
            'root' => $rot,
        ]);

        $prov($rot, $disk);
    } finally {
        File::deleteDirectory($rot);
    }
}

/** Filrättigheterna för en sökväg, utan PHP:s stat-cache. */
function katalogbehorighetLäge(string $sökväg): int
{
    clearstatcache(true, $sökväg);

    return fileperms($sökväg) & 0777;
}

it('skapar filkatalogerna med läget 0711', function () {
    katalogbehorighetProva(function (string $rot, Filesystem $disk) {
        $disk->put('ab/cd/hash', 'x');

        // 0711 och inte 0755: webbservern behöver kunna gå igenom katalogen,
        // inte lista den (Beslut 1).
        expect(katalogbehorighetLäge($rot.'/ab'))->toBe(0711);
        expect(katalogbehorighetLäge($rot.'/ab/cd'))->toBe(0711);
    });
})->skipOnWindows();

it('skriver filerna med läget 0644', function () {
    katalogbehorighetProva(function (string $rot, Filesystem $disk) {
        $disk->put('ab/cd/hash', 'x');

        expect(katalogbehorighetLäge($rot.'/ab/cd/hash'))->toBe(0644);
    });
})->skipOnWindows();
