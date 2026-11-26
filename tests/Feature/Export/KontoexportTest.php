<?php

use App\Actions\Account\DeleteAccount;
use App\Actions\Privacy\CollectPersonData;
use App\Console\PurgesExpiredExports;
use App\Jobs\BuildAccountExport;
use App\Jobs\BuildContainerExport;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Export;
use App\Models\Item;
use App\Models\LegalHold;
use App\Models\Notification;
use App\Models\SecurityLog;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Export\AccountExportBuilder;
use App\Support\Export\ContainerExportBuilder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

use function Pest\Laravel\artisan;

/*
 * Issue 1701 · Kontoexporten, grunden ([[ADR-0098 Kontoexporten]]): byggaren
 * App\Support\Export\AccountExportBuilder, jobbet App\Jobs\BuildAccountExport,
 * notisen, gallringen och kontoraderingen. Ingen rutt når jobbet än (issue
 * 507), så proven kör jobbet direkt.
 *
 * Varje "Klart när"-punkt i issuen har ett namngivet prov här. Containerexportens
 * och privacy:collects egna prov är oförändrade och bevisar att de beter sig som förut.
 */

beforeEach(function () {
    Storage::fake('files');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En kontoexportrad som beställts av kontots enda medlem.
 *
 * @return array{0: Account, 1: User, 2: Export}
 */
function kontoexportRad(?Account $konto = null, ?User $person = null): array
{
    if ($konto === null) {
        [$konto, $person] = kontoMedMedlem();
    }

    $export = Export::factory()->account($konto)->create([
        'requested_by_user_id' => $person->id,
    ]);

    return [$konto, $person, $export];
}

function kontoexportContainer(Account $konto, string $namn, int $antalItems = 1, bool $medBilaga = false): Container
{
    $container = Container::factory()->for($konto, 'account')->create(['name' => $namn]);

    for ($i = 1; $i <= $antalItems; $i++) {
        $item = Item::factory()->for($container, 'container')->create([
            'name' => "{$namn} item {$i}",
            'created_by_account_id' => $konto->id,
        ]);

        if ($medBilaga && $i === 1) {
            $hash = hash('sha256', "bytes-{$namn}");
            $sökväg = 'ab/cd/'.$hash;
            Storage::disk('files')->put($sökväg, "bytes-{$namn}");

            $stored = StoredFile::factory()->create([
                'content_hash' => $hash,
                'storage_path' => $sökväg,
                'byte_size' => strlen("bytes-{$namn}"),
                'mime_type' => 'text/plain',
                'scan_status' => 'skipped',
            ]);

            Attachment::factory()->create([
                'item_id' => $item->id,
                'stored_file_id' => $stored->id,
                'filename' => 'kvitto.txt',
                'kind' => 'document',
                'billed_account_id' => $konto->id,
            ]);
        }
    }

    return $container;
}

function kontoexportKor(Export $export): Export
{
    (new BuildAccountExport($export))->handle();

    return $export->refresh();
}

/**
 * @return list<string>
 */
function kontoexportPoster(Export $export): array
{
    $zip = new ZipArchive;
    $zip->open(Storage::disk('files')->path($export->storage_path));

    $namn = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $namn[] = $zip->getNameIndex($i);
    }

    $zip->close();

    sort($namn);

    return $namn;
}

function kontoexportLas(Export $export, string $post): string
{
    $zip = new ZipArchive;
    $zip->open(Storage::disk('files')->path($export->storage_path));
    $innehåll = (string) $zip->getFromName($post);
    $zip->close();

    return $innehåll;
}

it('ger en ZIP med index.html, account.json och en mapp per container', function () {
    [$konto, $person, $export] = kontoexportRad();

    $a = kontoexportContainer($konto, 'Verkstaden', 2, true);
    $b = kontoexportContainer($konto, 'Källaren', 1);

    $export = kontoexportKor($export);

    expect($export->status)->toBe(Export::STATUS_READY);

    $poster = kontoexportPoster($export);

    expect($poster)->toContain('index.html', 'account.json');

    foreach ([$a, $b] as $container) {
        $mapp = 'containers/'.Str::slug($container->name).'-'.$container->ulid.'/';

        expect($poster)->toContain($mapp.'container.json', $mapp.'index.html');
    }

    $mappA = 'containers/verkstaden-'.$a->ulid.'/';
    $item = $a->items()->orderBy('id')->first();

    expect($poster)->toContain($mappA.'filer/'.$item->ulid.'/kvitto.txt');
    expect(kontoexportLas($export, $mappA.'filer/'.$item->ulid.'/kvitto.txt'))->toBe('bytes-Verkstaden');

    // Startsidan länkar till varje containers index.html.
    $index = kontoexportLas($export, 'index.html');

    expect($index)->toContain('containers/verkstaden-'.$a->ulid.'/index.html')
        ->toContain('containers/kallaren-'.$b->ulid.'/index.html');

    // Sökvägarna i container.json är relativa till containerns mapp.
    $json = json_decode(kontoexportLas($export, $mappA.'container.json'), true);

    expect($json['container']['ulid'])->toBe($a->ulid)
        ->and($json['items'][0]['attachments'][0]['path'])->toStartWith('filer/');
});

it('listar en delad container utan innehåll och tar inte med en mjukraderad', function () {
    [$konto, $person, $export] = kontoexportRad();

    kontoexportContainer($konto, 'Min');
    $raderad = kontoexportContainer($konto, 'Raderad');
    $raderad->delete();

    $annat = Account::factory()->create(['name' => 'Annas konto']);
    $delad = kontoexportContainer($annat, 'Delad hemlighet');
    beviljaAccess($delad, $person, 'write', 'member');

    $export = kontoexportKor($export);

    $poster = implode("\n", kontoexportPoster($export));

    expect($poster)->not->toContain($raderad->ulid)
        ->and($poster)->not->toContain($delad->ulid);

    $konto = json_decode(kontoexportLas($export, 'account.json'), true);

    expect($konto['shared_with_me'])->toBe([
        ['container' => 'Delad hemlighet', 'owner' => 'Annas konto', 'level' => 'write'],
    ]);

    // Innehållet tillhör ett annat konto: item-namnet finns ingenstans.
    foreach (kontoexportPoster($export) as $post) {
        expect(kontoexportLas($export, $post))->not->toContain('Delad hemlighet item');
    }
});

it('har samma nycklar som privacy:collect och nämner aldrig en tystnadsbelagd spärr', function () {
    [$konto, $person, $export] = kontoexportRad();

    LegalHold::factory()->create([
        'account_id' => $konto->id,
        'case_number' => 'DSA-2026-0042',
        'reason' => 'Utredning enligt DSA artikel 16.',
        'confidential' => true,
    ]);

    SecurityLog::factory()->create([
        'account_id' => $konto->id,
        'user_id' => $person->id,
        'action' => SecurityLog::ACTION_LEGAL_HOLD_PLACED,
        'meta' => ['case_number' => 'DSA-2026-0042'],
    ]);

    $export = kontoexportKor($export);

    $fil = kontoexportLas($export, 'account.json');
    $data = json_decode($fil, true);

    $källa = app(CollectPersonData::class)->collect($person->id);

    expect(array_keys($data))->toBe([...array_keys($källa), 'shared_with_me', 'not_included_note'])
        ->and($data['not_included'])->toBe($källa['not_included'])
        ->and($data['not_included_note'])->toContain('/contact')
        ->and($data['legal_hold'])->toBe([])
        ->and($fil)->not->toContain('DSA-2026-0042')
        ->and($fil)->not->toContain('Utredning enligt DSA');

    expect(array_column($data['security_log'], 'action'))->not->toContain(SecurityLog::ACTION_LEGAL_HOLD_PLACED);
});

it('avvisar i databasen en account-rad med container och en zip-rad utan', function () {
    $konto = Account::factory()->create();
    $container = Container::factory()->for($konto, 'account')->create();

    $avvisad = function (array $rad): bool {
        try {
            Export::factory()->create($rad);

            return false;
        } catch (QueryException) {
            return true;
        }
    };

    expect($avvisad(['kind' => 'account', 'container_id' => $container->id, 'account_id' => $konto->id]))->toBeTrue()
        ->and($avvisad(['kind' => 'zip', 'container_id' => null, 'account_id' => null]))->toBeTrue()
        ->and($avvisad(['kind' => 'account', 'container_id' => null, 'account_id' => null]))->toBeTrue();
})->skip(fn (): bool => Schema::getConnection()->getDriverName() !== 'mysql', 'CHECK-villkoret finns bara på mysql, som schemats övriga.');

it('sätter expires_at 24 timmar fram för kontoexporten och sju dagar för containerexporten', function () {
    Carbon::setTestNow('2026-12-01 10:00:00');

    [$konto, $person, $export] = kontoexportRad();
    $export = kontoexportKor($export);

    expect($export->expires_at->equalTo(now()->addHours(24)))->toBeTrue();

    $container = kontoexportContainer($konto, 'Min');
    $zip = Export::factory()->create([
        'container_id' => $container->id,
        'requested_by_user_id' => $person->id,
    ]);

    (new BuildContainerExport($zip))->handle();

    expect($zip->refresh()->expires_at->equalTo(now()->addDays(7)))->toBeTrue();
});

it('gallrar kontoexporten inom en timme efter expires_at men inte före', function () {
    Carbon::setTestNow('2026-12-01 10:00:00');

    [, , $export] = kontoexportRad();
    $export = kontoexportKor($export);
    $sökväg = $export->storage_path;

    Carbon::setTestNow('2026-12-02 09:59:00');
    (new PurgesExpiredExports)->handle();

    expect(Storage::disk('files')->exists($sökväg))->toBeTrue();

    // Nästa timkörning efter expires_at.
    Carbon::setTestNow('2026-12-02 10:30:00');
    (new PurgesExpiredExports)->handle();

    expect(Storage::disk('files')->exists($sökväg))->toBeFalse()
        ->and($export->refresh()->status)->toBe(Export::STATUS_EXPIRED)
        ->and($export->storage_path)->toBeNull();
});

it('schemalägger gallringen varje timme', function () {
    artisan('inspire');

    $händelse = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'purge-expired-exports');

    expect($händelse->getExpression())->toBe('0 * * * *');
});

it('skapar notisen account_export.ready när jobbet är klart', function () {
    [$konto, $person, $export] = kontoexportRad();

    $export = kontoexportKor($export);

    $notis = Notification::query()->where('type', Notification::TYPE_ACCOUNT_EXPORT_READY)->sole();

    expect($notis->type)->toBe('account_export.ready')
        ->and($notis->user_id)->toBe($person->id)
        ->and($notis->account_id)->toBe($konto->id)
        ->and($notis->payload)->toBe(['export' => $export->ulid]);

    // I appen och som mejl enligt personens val (förvalet är på).
    expect($notis->deliveries()->pluck('channel')->sort()->values()->all())
        ->toBe(['email', 'in_app']);

    // Ett omkört jobb ger fortfarande en rad.
    (new BuildAccountExport($export))->handle();

    expect(Notification::query()->where('type', 'account_export.ready')->count())->toBe(1);
});

it('sätter failed utan notis när byggaren kastar', function () {
    [, , $export] = kontoexportRad();

    app()->bind(AccountExportBuilder::class, fn () => new class(app(ContainerExportBuilder::class), app(CollectPersonData::class)) extends AccountExportBuilder
    {
        public function build(Export $export): string
        {
            throw new RuntimeException('byggfel');
        }
    });

    $export = kontoexportKor($export);

    expect($export->status)->toBe(Export::STATUS_FAILED)
        ->and($export->failure_reason)->toBe('byggfel')
        ->and(Notification::query()->where('type', 'account_export.ready')->count())->toBe(0);
});

it('låter kontoraderingen gå igenom trots en kontoexport', function () {
    [$konto, $person, $export] = kontoexportRad();
    kontoexportContainer($konto, 'Min');

    $export = kontoexportKor($export);
    $sökväg = $export->storage_path;

    expect(Storage::disk('files')->exists($sökväg))->toBeTrue();

    app(DeleteAccount::class)->handle($konto, DeleteAccount::REASON_OWNER_DELETED);

    expect(DB::table('export')->where('account_id', $konto->id)->count())->toBe(0)
        ->and(Storage::disk('files')->exists($sökväg))->toBeFalse()
        ->and(Account::query()->whereKey($konto->id)->exists())->toBeFalse();
});

it('ställer ett fast antal frågor per container oavsett antalet items', function () {
    $räkna = function (int $items): int {
        [$konto, , $export] = kontoexportRad();

        kontoexportContainer($konto, 'A', $items);
        kontoexportContainer($konto, 'B', $items);

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(AccountExportBuilder::class)->build($export);

        $antal = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $antal;
    };

    expect($räkna(6))->toBe($räkna(1));
});
