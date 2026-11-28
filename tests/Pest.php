<?php

use Illuminate\Filesystem\Filesystem;
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

/*
|--------------------------------------------------------------------------
| Egen katalog för de falska diskarna per testprocess
|--------------------------------------------------------------------------
|
| Storage::fake() lägger rötterna under storage/framework/testing/disks och
| tömmer dem vid varje anrop. Två samtidiga körningar i samma checkout
| skrev därför i varandras filer (issue 1798). Laravel lägger redan till
| "_test_{token}" på roten när ParallelTesting::token() ger ett värde, så vi
| sätter token till processens PID om ingen redan finns. Katalogen tas bort
| när processen avslutas. Under `artisan test --parallel` är token redan
| satt och vi rör den inte.
|
*/

if (empty($_SERVER['TEST_TOKEN'])) {
    $_SERVER['TEST_TOKEN'] = $_ENV['TEST_TOKEN'] = 'pid'.getmypid();

    register_shutdown_function(static function (): void {
        $suffix = '_test_'.$_SERVER['TEST_TOKEN'];
        $disks = dirname(__DIR__).'/storage/framework/testing/disks';

        foreach (glob($disks.'/*'.$suffix) ?: [] as $dir) {
            (new Filesystem)->deleteDirectory($dir);
        }
    });
}
