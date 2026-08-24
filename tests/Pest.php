<?php

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

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
