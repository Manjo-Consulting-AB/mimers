<?php

// rott-pa-basen: hjälparfil utan egna tester - beforeEach-kroken nedan är
// ingen acceptanskriterie-yta. Filen innehåller inga tester alls, så
// `php artisan test tests/Pest.php` går igenom oavsett vad koden gör; det
// finns inget rött läge att vara i på basen. Se issue 61a.

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Testfall
|--------------------------------------------------------------------------
|
| Test under "Feature" binds till Laravels TestCase och har därmed hela
| applikationen tillgänglig. Test under "Unit" kör mot ren PHPUnit utan
| ramverket — lägg bara sådant där som inte behöver boota appen.
|
| RefreshDatabase migrerar sqlite-minnesdatabasen (se phpunit.xml) inför
| varje test som behöver den. Tillagd i issue 2, då de första tabellerna
| kom in.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Issue 61a: filoriginet är AVSTÄNGT som standard i sviten, precis
        // som i en miljö där handpåläggningen inte är gjord. `files.url` osatt
        // betyder att leveransen ligger på appdomänen, att `files.download`
        // skickar bytena själv och att allt är `attachment`
        // ([[ADR-0019 Filleverans]] § Uppföljning 2026-08-31, som gäller
        // ordagrant i det läget).
        //
        // phpunit.xml sätter ändå FILES_URL: rutten `files.deliver`
        // registreras vid appens uppstart och bara när filoriginet är satt,
        // och registreringen går inte att styra per test. Den som prövar
        // originet sätter `files.url` i sin egen beforeEach — se
        // tests/Feature/Filleverans/FiloriginTest.php.
        config(['files.url' => null]);
    })
    ->in('Feature');
