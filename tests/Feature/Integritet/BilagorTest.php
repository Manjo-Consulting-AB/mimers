<?php

use App\Actions\Privacy\AttachPrivacyAttachment;
use App\Actions\Privacy\RecordPrivacyEvent;
use App\Models\Account;
use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestAttachment;
use App\Models\PrivacyRequestEvent;
use App\Models\StoredFile;
use App\Models\UsageCounter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Issue 486 · M87 Integritetsbegäranden, bilagorna på ärendet. Se
 * App\Actions\Privacy\AttachPrivacyAttachment och DetachPrivacyAttachment,
 * kommandona PrivacyAttach, PrivacyDetach och PrivacyShow, disken `privacy` i
 * config/filesystems.php, [[ADR-0095 Integritetsbegäranden]] § 10 och
 * [[Konton och åtkomst]] § privacy_request_attachment.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 * Kommandona prövas genom Artisan och utdata som text — det är utdatan och
 * raderna i tabellerna som är kontraktet — som tests/Feature/Integritet/
 * ArendetTest.php.
 *
 * Disken fejkas: inga bilagor får hamna i den riktiga storage-katalogen. Ett
 * eget prov bygger disken ur konfigurationen och mäter fil- och
 * kataloglägena, eftersom Storage::fake() inte bär diskens `permissions`.
 *
 * Källfilerna skrivs i en egen katalog under storage/framework/testing/ och
 * städas i afterEach. Hjälparna har prefixet `bilagor` — Pests funktioner är
 * globala och hela sviten körs i en process.
 *
 * Tiden är fryst: `created_at` på bilagan och händelserna jämförs i utdatan.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    Storage::fake('privacy');

    $katalog = storage_path('framework/testing/bilagor-'.bin2hex(random_bytes(6)));
    File::ensureDirectoryExists($katalog);
    $GLOBALS['__bilagor_katalog'] = $katalog;
});

afterEach(function () {
    Carbon::setTestNow();

    File::deleteDirectory($GLOBALS['__bilagor_katalog']);
});

/** Katalogen källfilerna skrivs i. */
function bilagorKatalog(): string
{
    return $GLOBALS['__bilagor_katalog'];
}

/** Ett ärende att lägga bilagor på; vägen dit prövas i ArendetTest. */
function bilagorArende(array $attribut = []): PrivacyRequest
{
    return PrivacyRequest::factory()->create($attribut);
}

/**
 * Skriv en källfil med ett bestämt namn och innehåll.
 *
 * @return string Sökvägen till filen.
 */
function bilagorKalla(string $namn, string $innehåll): string
{
    $path = bilagorKatalog().'/'.$namn;

    File::put($path, $innehåll);

    return $path;
}

/**
 * Kör ett kommando och returnerar [utgångskod, utdata].
 *
 * @param  array<string, mixed>  $argument
 * @param  array<string, mixed>  $val
 * @return array{0: int, 1: string}
 */
function bilagorKor(string $kommando, array $argument, array $val = []): array
{
    $utgång = Artisan::call($kommando, array_merge($argument, $val));

    return [$utgång, Artisan::output()];
}

/** Filrättigheterna för en sökväg, utan PHP:s stat-cache. */
function bilagaLage(string $sökväg): int
{
    clearstatcache(true, $sökväg);

    return fileperms($sökväg) & 0777;
}

/*
 * Klart när: privacy:attach lägger filen på disken privacy under
 * {ärendets ulid}/{bilagans ulid}, skriver en rad med namn, storlek och en
 * sha256 som räknats på servern, och en händelse attachment_added.
 */
it('lägger filen på disken privacy och skriver rad och händelse', function () {
    $ärende = bilagorArende();
    $innehåll = "Svaret som skickades.\n";
    $källa = bilagorKalla('svar.txt', $innehåll);

    [$utgång, $utdata] = bilagorKor('privacy:attach', [
        'reference' => $ärende->reference,
        'path' => $källa,
    ], ['--by' => 'Anna Handläggare']);

    expect($utgång)->toBe(Command::SUCCESS);

    $bilaga = PrivacyRequestAttachment::query()->sole();
    $väg = $ärende->ulid.'/'.$bilaga->ulid;

    expect($bilaga->ulid)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($bilaga->privacy_request_id)->toBe($ärende->id)
        ->and($bilaga->original_name)->toBe('svar.txt')
        ->and($bilaga->size_bytes)->toBe(strlen($innehåll))
        ->and($bilaga->sha256)->toBe(hash('sha256', $innehåll))
        ->and($bilaga->added_by)->toBe('Anna Handläggare');

    // Sökvägen är ärendets ulid och bilagans ulid, utan filändelse.
    expect($väg)->not->toContain('.');

    expect(Storage::disk('privacy')->exists($väg))->toBeTrue();
    expect(Storage::disk('privacy')->get($väg))->toBe($innehåll);

    // Källfilen lämnas orörd.
    expect(File::get($källa))->toBe($innehåll);

    $händelse = PrivacyRequestEvent::query()
        ->where('kind', 'attachment_added')
        ->sole();

    expect($händelse->actor)->toBe('Anna Handläggare')
        ->and($händelse->privacy_request_id)->toBe($ärende->id)
        ->and($händelse->data)->toBe([
            'ulid' => $bilaga->ulid,
            'original_name' => 'svar.txt',
            'size_bytes' => strlen($innehåll),
            'sha256' => hash('sha256', $innehåll),
        ]);

    // Utskriften bär kontraktet och påminner om källfilen.
    expect($utdata)->toContain($bilaga->ulid);
    expect($utdata)->toContain('svar.txt');
    expect($utdata)->toContain((string) strlen($innehåll));
    expect($utdata)->toContain(hash('sha256', $innehåll));
    expect($utdata)->toContain('Radera');
});

/*
 * Klart när: ingen rad skrivs i stored_file, och kontots förbrukade lagring
 * ändras inte.
 */
it('skriver ingen stored_file och ändrar inte kontots förbrukade lagring', function () {
    $konto = Account::factory()->create();
    $ärende = bilagorArende(['account_id' => $konto->id]);

    UsageCounter::factory()->create([
        'account_id' => $konto->id,
        'storage_bytes' => 1000,
        'metadata_bytes' => 0,
    ]);

    $källa = bilagorKalla('fullmakt.pdf', 'FULLMAKT');

    [$utgång] = bilagorKor('privacy:attach', [
        'reference' => $ärende->reference,
        'path' => $källa,
    ], ['--by' => 'Anna Handläggare']);

    expect($utgång)->toBe(Command::SUCCESS);

    // Bilagan hör inte till den innehållsadresserade lagringen.
    expect(StoredFile::query()->count())->toBe(0);

    $räknare = UsageCounter::query()->where('account_id', $konto->id)->sole();

    expect($räknare->storage_bytes)->toBe(1000)
        ->and($räknare->metadata_bytes)->toBe(0);
});

/*
 * Klart när: en fil över gränsen ger exit 1, och varken rad eller fil.
 */
it('vägrar en fil över gränsen utan rad och fil', function () {
    // Förvalet är 25 MB; provet sänker gränsen så att fem byten räcker.
    config(['privacy.attachment_max_bytes' => 4]);

    $ärende = bilagorArende();
    $källa = bilagorKalla('för-stor.txt', '12345');

    [$utgång, $utdata] = bilagorKor('privacy:attach', [
        'reference' => $ärende->reference,
        'path' => $källa,
    ], ['--by' => 'Anna Handläggare']);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('gränsen');

    expect(PrivacyRequestAttachment::query()->count())->toBe(0);
    expect(Storage::disk('privacy')->allFiles())->toBe([]);
});

it('har 25 MB som förval för bilagans storlek', function () {
    expect(config('privacy.attachment_max_bytes'))->toBe(25 * 1024 * 1024);
});

/*
 * Hashen och storleken är beviset på kopian: de räknas på bytena som
 * faktiskt ligger på disken, inte på källfilen. Disken lurar fram en kopia
 * med annat innehåll än källan — samma längd, så gränsen och
 * storlekskontrollen passerar.
 */
it('räknar hashen och storleken på kopian som ligger på disken', function () {
    $ärende = bilagorArende();
    $källa = bilagorKalla('svar.txt', 'ABCDE');

    $disk = Storage::disk('privacy');
    $spion = Mockery::mock($disk)->makePartial();
    $spion->shouldReceive('writeStream')->andReturnUsing(function (string $väg) use ($disk): bool {
        $disk->put($väg, 'FGHIJ');

        return true;
    });
    Storage::set('privacy', $spion);

    [$utgång] = bilagorKor('privacy:attach', [
        'reference' => $ärende->reference,
        'path' => $källa,
    ], ['--by' => 'Anna Handläggare']);

    expect($utgång)->toBe(Command::SUCCESS);

    $bilaga = PrivacyRequestAttachment::query()->sole();

    expect($bilaga->size_bytes)->toBe(5)
        ->and($bilaga->sha256)->toBe(hash('sha256', 'FGHIJ'))
        ->and(Storage::disk('privacy')->get($ärende->ulid.'/'.$bilaga->ulid))->toBe('FGHIJ');
});

/*
 * Källfilen kan växa mellan storlekskontrollen och kopieringen. Gränsen
 * prövas därför mot de skrivna byten: kopian tas bort och ingen rad skrivs.
 */
it('vägrar när kopian växte förbi gränsen efter kontrollen', function () {
    config(['privacy.attachment_max_bytes' => 8]);

    $ärende = bilagorArende();
    $källa = bilagorKalla('svar.txt', '12345');

    $disk = Storage::disk('privacy');
    $spion = Mockery::mock($disk)->makePartial();
    $spion->shouldReceive('writeStream')->andReturnUsing(function (string $väg) use ($disk): bool {
        $disk->put($väg, '123456789');

        return true;
    });
    Storage::set('privacy', $spion);

    [$utgång, $utdata] = bilagorKor('privacy:attach', [
        'reference' => $ärende->reference,
        'path' => $källa,
    ], ['--by' => 'Anna Handläggare']);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('gränsen');

    expect(PrivacyRequestAttachment::query()->count())->toBe(0);
    expect(Storage::disk('privacy')->allFiles())->toBe([]);
});

/*
 * Ändras källfilen mellan den första avläsningen och kopieringen stämmer
 * inte storleken. Kopian gäller och vägras — kvar blir varken rad eller fil.
 */
it('vägrar när kopian inte är lika stor som filen som lästes först', function () {
    $ärende = bilagorArende();
    $källa = bilagorKalla('svar.txt', '12345');

    $disk = Storage::disk('privacy');
    $spion = Mockery::mock($disk)->makePartial();
    $spion->shouldReceive('writeStream')->andReturnUsing(function (string $väg) use ($disk): bool {
        $disk->put($väg, '123');

        return true;
    });
    Storage::set('privacy', $spion);

    [$utgång, $utdata] = bilagorKor('privacy:attach', [
        'reference' => $ärende->reference,
        'path' => $källa,
    ], ['--by' => 'Anna Handläggare']);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('kopieringen');

    expect(PrivacyRequestAttachment::query()->count())->toBe(0);
    expect(Storage::disk('privacy')->allFiles())->toBe([]);
});

/*
 * En städning som själv kastar (disken har `throw => true`) får inte dölja
 * felet som ledde dit. Samma regel som i StoreAttachment.
 */
it('låter det ursprungliga felet gå före en städning som kastar', function () {
    $ärende = bilagorArende();
    $källa = bilagorKalla('svar.txt', 'Svaret');

    $spion = Mockery::mock(Storage::disk('privacy'))->makePartial();
    $spion->shouldReceive('delete')->andThrow(new RuntimeException('disken vägrar'));
    Storage::set('privacy', $spion);

    $händelser = Mockery::mock(RecordPrivacyEvent::class);
    $händelser->shouldReceive('handle')
        ->andThrow(new RuntimeException('ursprungliga felet'));
    app()->instance(RecordPrivacyEvent::class, $händelser);

    $fångad = null;

    try {
        app(AttachPrivacyAttachment::class)->handle($ärende, $källa, 'Anna Handläggare');
    } catch (Throwable $e) {
        $fångad = $e;
    }

    expect($fångad)->toBeInstanceOf(RuntimeException::class)
        ->and($fångad?->getMessage())->toBe('ursprungliga felet');
});

/*
 * Klart när: en katalog ger exit 1, och varken rad eller fil.
 */
it('vägrar en katalog utan rad och fil', function () {
    $ärende = bilagorArende();

    $katalog = bilagorKatalog().'/undermapp';
    File::ensureDirectoryExists($katalog);

    [$utgång, $utdata] = bilagorKor('privacy:attach', [
        'reference' => $ärende->reference,
        'path' => $katalog,
    ], ['--by' => 'Anna Handläggare']);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('läsbar');

    expect(PrivacyRequestAttachment::query()->count())->toBe(0);
    expect(Storage::disk('privacy')->allFiles())->toBe([]);
});

/*
 * Klart när: en sökväg som inte finns ger exit 1, och varken rad eller fil.
 */
it('vägrar en sökväg som inte finns utan rad och fil', function () {
    $ärende = bilagorArende();

    [$utgång, $utdata] = bilagorKor('privacy:attach', [
        'reference' => $ärende->reference,
        'path' => bilagorKatalog().'/finns-inte.txt',
    ], ['--by' => 'Anna Handläggare']);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('läsbar');

    expect(PrivacyRequestAttachment::query()->count())->toBe(0);
    expect(Storage::disk('privacy')->allFiles())->toBe([]);
});

/*
 * Klart när: ett saknat --by ger exit 1 och ingenting skrivs. Regeln gäller
 * varje privacy-kommando (ADR-0095 § 1).
 */
it('nekar ett saknat --by utan att skriva något', function () {
    $ärende = bilagorArende();
    $källa = bilagorKalla('svar.txt', 'Svaret');

    [$utgång, $utdata] = bilagorKor('privacy:attach', [
        'reference' => $ärende->reference,
        'path' => $källa,
    ]);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('--by');

    expect(PrivacyRequestAttachment::query()->count())->toBe(0);
    expect(Storage::disk('privacy')->allFiles())->toBe([]);
});

/*
 * Klart när: privacy:detach raderar filen och raden och skriver
 * attachment_removed med skälet.
 */
it('tar bort filen och raden och skriver attachment_removed med skälet', function () {
    $ärende = bilagorArende();
    $innehåll = 'Svaret som skickades.';
    $källa = bilagorKalla('svar.txt', $innehåll);

    bilagorKor('privacy:attach', [
        'reference' => $ärende->reference,
        'path' => $källa,
    ], ['--by' => 'Anna Handläggare']);

    $bilaga = PrivacyRequestAttachment::query()->sole();
    $väg = $ärende->ulid.'/'.$bilaga->ulid;

    // Senare än attachment_added, så att ordningen syns.
    Carbon::setTestNow('2026-06-02 09:00:00');

    [$utgång, $utdata] = bilagorKor('privacy:detach', [
        'reference' => $ärende->reference,
        'attachment_ulid' => $bilaga->ulid,
    ], [
        '--reason' => 'Fel fil lades till.',
        '--by' => 'Bo Granskare',
    ]);

    expect($utgång)->toBe(Command::SUCCESS);

    expect(PrivacyRequestAttachment::query()->count())->toBe(0);
    expect(Storage::disk('privacy')->exists($väg))->toBeFalse();

    $händelse = PrivacyRequestEvent::query()
        ->where('kind', 'attachment_removed')
        ->sole();

    expect($händelse->actor)->toBe('Bo Granskare')
        ->and($händelse->body)->toBe('Fel fil lades till.')
        ->and($händelse->data)->toBe([
            'original_name' => 'svar.txt',
            'sha256' => hash('sha256', $innehåll),
        ]);

    expect($utdata)->toContain($bilaga->ulid);
    expect($utdata)->toContain('svar.txt');
});

/*
 * Klart när: ett privacy:detach utan --reason ger exit 1, och varken raden
 * eller filen rörs.
 */
it('vägrar ta bort en bilaga utan skäl', function () {
    $ärende = bilagorArende();
    $källa = bilagorKalla('svar.txt', 'Svaret');

    bilagorKor('privacy:attach', [
        'reference' => $ärende->reference,
        'path' => $källa,
    ], ['--by' => 'Anna Handläggare']);

    $bilaga = PrivacyRequestAttachment::query()->sole();
    $väg = $ärende->ulid.'/'.$bilaga->ulid;

    [$utgång, $utdata] = bilagorKor('privacy:detach', [
        'reference' => $ärende->reference,
        'attachment_ulid' => $bilaga->ulid,
    ], ['--by' => 'Bo Granskare']);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('--reason');

    expect(PrivacyRequestAttachment::query()->count())->toBe(1);
    expect(Storage::disk('privacy')->exists($väg))->toBeTrue();
});

/*
 * En okänd bilaga på ett ärende som finns ger exit 1 och rör ingenting. Ett
 * ULID som inte hör till ärendet är samma fel som ett som inte finns.
 */
it('nekar en bilaga som inte hör till ärendet', function () {
    $ärende = bilagorArende();
    $källa = bilagorKalla('svar.txt', 'Svaret');

    bilagorKor('privacy:attach', [
        'reference' => $ärende->reference,
        'path' => $källa,
    ], ['--by' => 'Anna Handläggare']);

    $bilaga = PrivacyRequestAttachment::query()->sole();
    $väg = $ärende->ulid.'/'.$bilaga->ulid;

    [$utgång, $utdata] = bilagorKor('privacy:detach', [
        'reference' => $ärende->reference,
        'attachment_ulid' => (string) Str::ulid(),
    ], [
        '--reason' => 'Finns inte.',
        '--by' => 'Bo Granskare',
    ]);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('Ingen bilaga');

    expect(PrivacyRequestAttachment::query()->count())->toBe(1);
    expect(Storage::disk('privacy')->exists($väg))->toBeTrue();
});

/*
 * Klart när: privacy:show listar bilagorna med ulid, namn, storlek och när
 * de lades till, men inte innehållet och inte sökvägen på disken.
 */
it('privacy:show listar bilagorna utan sökvägen på disken', function () {
    $ärende = bilagorArende();
    $källa = bilagorKalla('sammanstallning.txt', 'Det hemliga innehållet');

    bilagorKor('privacy:attach', [
        'reference' => $ärende->reference,
        'path' => $källa,
    ], ['--by' => 'Anna Handläggare']);

    $bilaga = PrivacyRequestAttachment::query()->sole();
    $väg = $ärende->ulid.'/'.$bilaga->ulid;

    [$utgång, $utdata] = bilagorKor('privacy:show', [
        'reference' => $ärende->reference,
    ], ['--by' => 'Bo Granskare']);

    expect($utgång)->toBe(Command::SUCCESS);

    // Ulid, namn, storlek och när den lades till.
    expect($utdata)->toContain($bilaga->ulid);
    expect($utdata)->toContain('sammanstallning.txt');
    expect($utdata)->toContain((string) $bilaga->size_bytes);
    expect($utdata)->toContain('2026-06-01 12:00');

    // Varken sökvägen på disken eller innehållet skrivs ut.
    expect($utdata)->not->toContain($väg);
    expect($utdata)->not->toContain('storage/app');
    expect($utdata)->not->toContain('Det hemliga innehållet');
});

it('visar Inga när ärendet saknar bilagor', function () {
    $ärende = bilagorArende();

    [$utgång, $utdata] = bilagorKor('privacy:show', [
        'reference' => $ärende->reference,
    ], ['--by' => 'Bo Granskare']);

    expect($utgång)->toBe(Command::SUCCESS);
    expect($utdata)->toContain('Bilagor:');
    expect($utdata)->toContain('Inga');
});

/*
 * Ett avslutat ärende tar fortfarande emot bilagor: svaret arkiveras ofta
 * efter avslutet (ADR-0095 § 10).
 */
it('tar emot en bilaga på ett avslutat ärende', function () {
    $ärende = bilagorArende([
        'status' => PrivacyRequest::STATUS_COMPLETED,
        'closed_at' => now(),
    ]);

    $källa = bilagorKalla('svar-efter-avslut.txt', 'Arkiverat svar');

    [$utgång] = bilagorKor('privacy:attach', [
        'reference' => $ärende->reference,
        'path' => $källa,
    ], ['--by' => 'Anna Handläggare']);

    expect($utgång)->toBe(Command::SUCCESS);
    expect(PrivacyRequestAttachment::query()->count())->toBe(1);
});

/*
 * Klart när: ingen rutt i php artisan route:list nämner privacy.
 *
 * /privacy finns redan som informationssida (M86 · issue 202) och ligger
 * utanför den här issuen. Provet låser att ingen ANNAN rutt nämner privacy:
 * ärendena har ingen yta, och bilagorna levereras aldrig av en rutt.
 */
it('har ingen rutt för ärendena — bara informationssidan nämner privacy', function () {
    [$utgång, $utdata] = bilagorKor('route:list', []);

    expect($utgång)->toBe(Command::SUCCESS);

    $privacyRader = array_values(array_filter(
        explode("\n", $utdata),
        fn (string $rad): bool => str_contains($rad, 'privacy')
    ));

    foreach ($privacyRader as $rad) {
        expect($rad)->toContain('info.privacy');
    }

    expect($utdata)->not->toContain('privacy_request');
    expect($utdata)->not->toContain('privacy:');
});

/*
 * Disken byggs ur konfigurationen med roten i en egen katalog under
 * sys_get_temp_dir(): Storage::fake() ignorerar diskens `permissions` och
 * hade inte bevisat något om lägena. Roten ligger utanför storage/ därför
 * att arbetskatalogen i det här repot är en NFS-montering som skriver om
 * kataloglägen; samma grepp som
 * tests/Feature/Filleverans/KatalogbehorigheterTest.php.
 */
it('skapar filer med 0600 och kataloger med 0700 på disken privacy', function () {
    $rot = sys_get_temp_dir().'/bilagor-disk-'.bin2hex(random_bytes(6));
    mkdir($rot, 0755, true);

    try {
        $disk = Storage::build([
            ...config('filesystems.disks.privacy'),
            'root' => $rot,
        ]);

        $disk->put('ab/cd/fil', 'x');

        expect(bilagaLage($rot.'/ab'))->toBe(0700);
        expect(bilagaLage($rot.'/ab/cd'))->toBe(0700);
        expect(bilagaLage($rot.'/ab/cd/fil'))->toBe(0600);
    } finally {
        File::deleteDirectory($rot);
    }
})->skipOnWindows();
