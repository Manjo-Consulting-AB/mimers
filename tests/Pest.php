<?php

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
| RefreshDatabase läggs på när det finns tabeller att uppdatera, alltså
| tidigast i issue 2.
|
*/

pest()->extend(TestCase::class)->in('Feature');
