<?php

use App\Actions\Access\ResolveItemScope;
use App\Jobs\BuildContainerExport;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Export;
use App\Models\ImageDerivative;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Access\ItemScope;
use App\Support\Export\BinderBuilder;
use App\Support\Notification\NotificationTarget;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Fpdi;

/*
 * Issue 445 · PDF-pärmens grund — App\Support\Export\BinderBuilder och
 * kolumnerna kind/item_id/options på export. Varje "Klart när"-punkt har ett
 * namngivet prov. Storage::fake('files'): inga bytes i riktiga storage/.
 */

beforeEach(function () {
    Storage::fake('files');
});

// --- Hjälpare --------------------------------------------------------------

/**
 * @return array{0: Account, 1: User, 2: Container}
 */
function parmKontext(): array
{
    [$account, $user] = kontoMedMedlem();

    return [$account, $user, Container::factory()->for($account, 'account')->create(['name' => 'Båten'])];
}

function parmItem(Container $container, Account $account, User $user, string $namn, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'name' => $namn,
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ], $attribut));
}

function parmRad(Container $container, User $user, array $attribut = []): Export
{
    return Export::factory()->create(array_merge([
        'container_id' => $container->id,
        'requested_by_user_id' => $user->id,
        'kind' => Export::KIND_BINDER,
    ], $attribut));
}

function parmKör(Export $export): Export
{
    (new BuildContainerExport($export))->handle();

    $export->refresh();

    return $export;
}

/**
 * Byggaren med en spion på renderPart(): varje del som renderas lämnar sin
 * vy och sina data i `$rendered`, och renderas sedan på riktigt.
 *
 * @return object{rendered: array<int, array{0: string, 1: array<string, mixed>}>}
 */
function parmSpion(): object
{
    $builder = new class extends BinderBuilder
    {
        /** @var array<int, array{0: string, 1: array<string, mixed>}> */
        public array $rendered = [];

        protected function renderPart(FilesystemAdapter $disk, string $path, string $view, array $data): string
        {
            if (isset($data['item'])) {
                $data['occurrenceCount'] = $data['item']->schedules->flatMap->occurrences->count();
            }

            $this->rendered[] = [$view, $data];

            return parent::renderPart($disk, $path, $view, $data);
        }
    };

    app()->instance(BinderBuilder::class, $builder);

    return $builder;
}

/**
 * Datan till första delen som renderades med vyn.
 *
 * @return array<string, mixed>
 */
function parmDel(object $spion, string $vy): array
{
    foreach ($spion->rendered as [$namn, $data]) {
        if ($namn === $vy) {
            return $data;
        }
    }

    throw new RuntimeException("Ingen del renderades med {$vy}.");
}

/**
 * Namnen på items i den ordning pärmen ritade dem.
 *
 * @return list<string>
 */
function parmItemOrdning(object $spion): array
{
    return collect($spion->rendered)
        ->filter(fn (array $r): bool => $r[0] === 'export.binder.item')
        ->map(fn (array $r): string => $r[1]['item']->name)
        ->values()
        ->all();
}

/** All text som FPDF skrivit i klartext, efter att strömmarna packats upp. */
function parmText(string $pdfBytes): string
{
    $text = $pdfBytes;

    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdfBytes, $m)) {
        foreach ($m[1] as $stream) {
            $uppackad = @gzuncompress($stream);

            if ($uppackad !== false) {
                $text .= $uppackad;
            }
        }
    }

    return $text;
}

function parmSidor(string $sokvag): int
{
    $pdf = new Fpdi;

    return $pdf->setSourceFile($sokvag);
}

// --- Klart när: kolumnerna -------------------------------------------------

it('export har kind, item_id och options, och en rad utan dem är zip', function () {
    expect(Schema::hasColumns('export', ['kind', 'item_id', 'options']))->toBeTrue();

    [, $user, $container] = parmKontext();

    // En rad skapad som förut, utan de nya kolumnerna.
    $id = DB::table('export')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'container_id' => $container->id,
        'requested_by_user_id' => $user->id,
        'status' => 'ready',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $gammal = Export::query()->findOrFail($id);

    expect($gammal->kind)->toBe(Export::KIND_ZIP)
        ->and($gammal->item_id)->toBeNull()
        ->and($gammal->options)->toBeNull()
        ->and(Export::EXPORT_KINDS)->toBe(['zip', 'binder', 'account']);
});

it('den gamla exporten fungerar som förut: en zip-rad blir en ZIP', function () {
    [$account, $user, $container] = parmKontext();
    parmItem($container, $account, $user, 'Prylen');

    $export = Export::factory()->create([
        'container_id' => $container->id,
        'requested_by_user_id' => $user->id,
    ]);

    $export = parmKör($export);

    expect($export->status)->toBe(Export::STATUS_READY)
        ->and($export->storage_path)->toEndWith('.zip');
});

// --- Klart när: pärmen -----------------------------------------------------

it('en binder-rad blir ready med en PDF med försättsblad, förteckning, items i parent/child-ordning och sidnummer', function () {
    [$account, $user, $container] = parmKontext();

    $zebra = parmItem($container, $account, $user, 'Zebra');
    $barnB = parmItem($container, $account, $user, 'Barn B');
    $barnA = parmItem($container, $account, $user, 'Barn A');
    $barnbarn = parmItem($container, $account, $user, 'Barnbarn');
    $alfa = parmItem($container, $account, $user, 'Alfa');

    foreach ([[$alfa, $barnB], [$alfa, $barnA], [$barnA, $barnbarn]] as [$forälder, $barn]) {
        ItemLink::factory()->create([
            'from_item_id' => $forälder->id,
            'to_item_id' => $barn->id,
            'relation' => 'parent',
        ]);
    }

    $spion = parmSpion();
    $export = parmKör(parmRad($container, $user));

    expect($export->status)->toBe(Export::STATUS_READY)
        ->and($export->storage_path)->toEndWith('.pdf')
        ->and($export->byte_size)->toBeGreaterThan(0)
        ->and($export->expires_at)->not->toBeNull();

    // Toppnivåerna efter namn, syskon efter namn, barnen direkt under föräldern.
    expect(parmItemOrdning($spion))->toBe(['Alfa', 'Barn A', 'Barnbarn', 'Barn B', 'Zebra']);

    $djup = parmDel($spion, 'export.binder.toc')['rows'];
    expect(collect($djup)->pluck('depth')->all())->toBe([0, 1, 2, 1, 0])
        ->and(collect($djup)->pluck('page')->all())->toBe([3, 4, 5, 6, 7]);

    // Försättsblad + förteckning + fem items = sju sidor, med sidnummer.
    $sokvag = Storage::disk('files')->path($export->storage_path);
    $sidor = parmSidor($sokvag);
    expect($sidor)->toBe(7);

    $text = parmText((string) file_get_contents($sokvag));
    expect($text)->toContain('Page 1 of 7')->toContain('Page 7 of 7');

    // Rubriknivån följer djupet.
    $item = collect($spion->rendered)->first(fn (array $r): bool => $r[0] === 'export.binder.item' && $r[1]['item']->name === 'Barnbarn');
    expect($item[1]['level'])->toBe(3);
});

it('en pärm lämnar ingen temporärmapp och ingen .part efter sig', function () {
    [$account, $user, $container] = parmKontext();
    parmItem($container, $account, $user, 'Prylen');

    $export = parmKör(parmRad($container, $user));
    $disk = Storage::disk('files');
    $dir = 'exports/'.$container->ulid;

    expect($disk->exists($dir.'/'.$export->ulid.'.pdf'))->toBeTrue()
        ->and($disk->exists($dir.'/'.$export->ulid.'.pdf.part'))->toBeFalse()
        ->and($disk->exists($dir.'/'.$export->ulid.'.parts'))->toBeFalse();
});

it('försättsbladet bär containerns bild som medium-variant, aldrig originalet', function () {
    [$account, $user, $container] = parmKontext();
    $item = parmItem($container, $account, $user, 'Prylen');

    $stored = StoredFile::factory()->create(['mime_type' => 'image/png', 'storage_path' => 'orig/original.png']);
    Storage::disk('files')->put('orig/original.png', 'ORIGINALET');

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    ImageDerivative::factory()->create(['stored_file_id' => $stored->id, 'variant' => 'medium', 'storage_path' => 'orig/medium.png']);
    Storage::disk('files')->put('orig/medium.png', $png);

    $bilaga = Attachment::factory()->create([
        'item_id' => null,
        'container_id' => $container->id,
        'stored_file_id' => $stored->id,
        'kind' => 'image',
    ]);
    $container->cover_attachment_id = $bilaga->id;
    $container->save();

    $spion = parmSpion();
    parmKör(parmRad($container, $user));

    $omslag = parmDel($spion, 'export.binder.cover');

    expect($omslag['image'])->toStartWith('data:image/png;base64,')
        ->and(base64_decode(substr($omslag['image'], strlen('data:image/png;base64,'))))->toBe($png)
        ->and($omslag['owner'])->toBe($account->name);
});

it('pärmen tar med utgifter men inte intäkter', function () {
    [$account, $user, $container] = parmKontext();
    $item = parmItem($container, $account, $user, 'Prylen');
    $rad = ['item_id' => $item->id, 'container_id' => $container->id, 'currency' => $container->effectiveCurrency(), 'created_by_user_id' => $user->id, 'created_by_account_id' => $account->id];
    CostEntry::factory()->create($rad + ['amount' => 12345, 'counterparty' => 'Leverantören']);
    CostEntry::factory()->create($rad + ['amount' => 99900, 'kind' => 'income', 'counterparty' => 'Köparen']);

    $spion = parmSpion();
    parmKör(parmRad($container, $user, ['options' => ['costs' => true]]));
    $data = collect($spion->rendered)->first(fn (array $r): bool => $r[0] === 'export.binder.item')[1];

    expect($data['costs']['rows'])->toHaveCount(1)
        ->and($data['costs']['rows'][0]['supplier'])->toBe('Leverantören');
});

it('options.sections styr vad som tas med och kostnader är av som förval', function () {
    [$account, $user, $container] = parmKontext();
    $item = parmItem($container, $account, $user, 'Prylen');
    $annat = parmItem($container, $account, $user, 'Grannen');

    ItemLink::factory()->create([
        'from_item_id' => min($item->id, $annat->id),
        'to_item_id' => max($item->id, $annat->id),
        'relation' => 'related',
    ]);
    CostEntry::factory()->create(['item_id' => $item->id, 'container_id' => $container->id, 'amount' => 12345, 'currency' => $container->effectiveCurrency(), 'created_by_user_id' => $user->id, 'created_by_account_id' => $account->id]);

    // Förval: allt utom kostnader.
    $spion = parmSpion();
    parmKör(parmRad($container, $user));
    $data = collect($spion->rendered)->first(fn (array $r): bool => $r[0] === 'export.binder.item')[1];
    expect($data['sections'])->toBe(['items', 'relations', 'schedules', 'history'])
        ->and($data['costs'])->toBeNull();

    // Kostnader slås på bara med options.costs.
    $spion = parmSpion();
    parmKör(parmRad($container, $user, ['options' => ['costs' => true]]));
    $data = collect($spion->rendered)->first(fn (array $r): bool => $r[0] === 'export.binder.item' && $r[1]['item']->id === $item->id)[1];
    expect($data['sections'])->toContain('costs')
        ->and($data['costs']['rows'])->toHaveCount(1)
        ->and($data['costs']['totals'][0]['amount'])->toContain('123');

    // sections väljer: utan relations syns ingen relation, utan items inga items.
    $spion = parmSpion();
    parmKör(parmRad($container, $user, ['options' => ['sections' => ['history']]]));
    expect(parmItemOrdning($spion))->toBe([]);

    $spion = parmSpion();
    parmKör(parmRad($container, $user, ['options' => ['sections' => ['items']]]));
    $data = collect($spion->rendered)->first(fn (array $r): bool => $r[0] === 'export.binder.item')[1];
    expect($data['relations'])->toBe([])->and($data['history'])->toBe([]);

    // Ett sektionsnamn som inte finns, och costs i sections utan options.costs, ignoreras.
    $spion = parmSpion();
    parmKör(parmRad($container, $user, ['options' => ['sections' => ['items', 'costs', 'bogus']]]));
    $data = collect($spion->rendered)->first(fn (array $r): bool => $r[0] === 'export.binder.item')[1];
    expect($data['sections'])->toBe(['items'])->and($data['costs'])->toBeNull();
});

it('schemats genomförda förekomster och anteckningen kommer med', function () {
    [$account, $user, $container] = parmKontext();
    $item = parmItem($container, $account, $user, 'Motorn', ['notes' => 'Byt **olja**']);
    $schema = Schedule::factory()->create(['item_id' => $item->id, 'title' => 'Oljebyte']);
    ScheduleOccurrence::factory()->completed()->create(['schedule_id' => $schema->id, 'completion_note' => 'Klart i maj']);
    ScheduleOccurrence::factory()->create(['schedule_id' => $schema->id]);

    $spion = parmSpion();
    parmKör(parmRad($container, $user));
    $data = collect($spion->rendered)->first(fn (array $r): bool => $r[0] === 'export.binder.item')[1];

    expect($data['notesHtml'])->toContain('<strong>olja</strong>')
        ->and($data['occurrenceCount'])->toBe(1);
});

it('ett item utanför beställarens omfång finns inte i pärmen', function () {
    [$account, $user, $container] = parmKontext();
    $synligt = parmItem($container, $account, $user, 'Synligt');
    $dolt = parmItem($container, $account, $user, 'Dolt');
    $raderat = parmItem($container, $account, $user, 'Raderat');
    $raderat->delete();

    // Beställarens omfång är bara det synliga itemet.
    $gäst = User::factory()->create();
    $upplösare = Mockery::mock(ResolveItemScope::class)->makePartial();
    $upplösare->shouldReceive('handle')->andReturn(ItemScope::restricted([$synligt->id => 'read']));
    app()->instance(ResolveItemScope::class, $upplösare);

    $spion = parmSpion();
    $export = parmKör(parmRad($container, $gäst));

    expect($export->status)->toBe(Export::STATUS_READY)
        ->and(parmItemOrdning($spion))->toBe(['Synligt'])
        ->and($dolt->exists)->toBeTrue();

    $toc = parmDel($spion, 'export.binder.toc')['rows'];
    expect(collect($toc)->pluck('name')->all())->toBe(['Synligt']);
});

// --- Klart när: minne och fel ----------------------------------------------

it('en pärm med 200 items byggs under arbetarens minnesgräns på 96 MB', function () {
    [$account, $user, $container] = parmKontext();

    foreach (range(1, 200) as $n) {
        parmItem($container, $account, $user, sprintf('Item %03d', $n), [
            'description' => str_repeat('Beskrivning av pryl. ', 20),
            'notes' => "# Rubrik\n\n".str_repeat("Anteckning med *betoning* och text.\n\n", 10),
        ]);
    }

    // Testprocessen bär redan ramverket och fabrikerna; det som mäts är byggets
    // egen toppväxt. Arbetaren (`--memory=96`) bär själv omkring 40 MB för
    // ramverket, så toppväxten måste ligga under återstoden, 56 MB.
    gc_collect_cycles();
    memory_reset_peak_usage();

    $bas = memory_get_usage();
    $export = parmKör(parmRad($container, $user));

    $tillväxt = memory_get_peak_usage() - $bas;

    expect($export->status)->toBe(Export::STATUS_READY)
        ->and($tillväxt)->toBeLessThan(56 * 1024 * 1024);

    expect(parmSidor(Storage::disk('files')->path($export->storage_path)))->toBeGreaterThanOrEqual(202);
});

it('ett fel i pärmbyggaren lämnar raden failed och tar bort .part och temporärmappen', function () {
    [$account, $user, $container] = parmKontext();
    parmItem($container, $account, $user, 'Prylen');
    $export = parmRad($container, $user);

    $disk = Storage::disk('files');
    $base = 'exports/'.$container->ulid.'/'.$export->ulid;

    app()->instance(BinderBuilder::class, new class extends BinderBuilder
    {
        public function build(Export $export): string
        {
            $base = 'exports/'.$export->container->ulid.'/'.$export->ulid;
            Storage::disk('files')->put($base.'.pdf.part', 'halvskriven');
            Storage::disk('files')->put($base.'.parts/cover.pdf', 'del');

            throw new RuntimeException('Simulerat pärmfel');
        }
    });

    $export = parmKör($export);

    expect($export->status)->toBe(Export::STATUS_FAILED)
        ->and($export->failure_reason)->toBe('Simulerat pärmfel')
        ->and($export->storage_path)->toBeNull()
        ->and($disk->exists($base.'.pdf.part'))->toBeFalse()
        ->and($disk->exists($base.'.parts'))->toBeFalse();
});

it('en markdown-bild i en anteckning ger inga bildbytes i pärmen', function () {
    [$account, $user, $container] = parmKontext();
    $disk = Storage::disk('files');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $disk->put('annat-konto/hemlig.png', $png);
    $sokvag = $disk->path('annat-konto/hemlig.png');
    parmItem($container, $account, $user, 'Motorn', ['notes' => "Se ![x]({$sokvag})"]);

    $spion = parmSpion();
    $export = parmKör(parmRad($container, $user));
    $data = collect($spion->rendered)->first(fn (array $r): bool => $r[0] === 'export.binder.item')[1];

    expect($data['notesHtml'])->not->toContain('<img')
        ->and($export->status)->toBe(Export::STATUS_READY)
        ->and(parmText($disk->get($export->storage_path)))->not->toContain('/Subtype /Image');
});

it('kostnader summeras per valuta med huvudvalutan först och ingen rad utelämnas', function () {
    [$account, $user, $container] = parmKontext();
    $item = parmItem($container, $account, $user, 'Prylen');
    $main = $container->effectiveCurrency();
    $andra = $main === 'EUR' ? 'USD' : 'EUR';

    foreach ([[$andra, 500], [$main, 1000], [$main, 250]] as [$valuta, $belopp]) {
        CostEntry::factory()->create(['item_id' => $item->id, 'container_id' => $container->id, 'amount' => $belopp, 'currency' => $valuta, 'created_by_user_id' => $user->id, 'created_by_account_id' => $account->id]);
    }

    $spion = parmSpion();
    parmKör(parmRad($container, $user, ['options' => ['costs' => true]]));
    $data = collect($spion->rendered)->first(fn (array $r): bool => $r[0] === 'export.binder.item')[1];

    expect(array_column($data['costs']['totals'], 'currency'))->toBe([$main, $andra]);
});

it('pärmens historik har den översatta meningen och inte åtgärdskoden', function () {
    [$account, $user, $container] = parmKontext();
    $item = parmItem($container, $account, $user, 'Prylen');
    AuditLog::factory()->create(['action' => 'item.created', 'item_id' => $item->id, 'container_id' => $container->id, 'account_id' => $account->id, 'user_id' => $user->id]);

    $spion = parmSpion();
    parmKör(parmRad($container, $user));
    $data = collect($spion->rendered)->first(fn (array $r): bool => $r[0] === 'export.binder.item')[1];
    $meningar = array_column($data['history'], 'sentence');

    expect($meningar)->not->toBeEmpty()
        ->and(implode(' ', $meningar))->toContain(__('ui.audit.action.item.created', ['user' => $user->name, 'item' => 'Prylen']))
        ->and(implode(' ', $meningar))->not->toContain('item.created');
});

// --- Bilagorna (issue 444) -------------------------------------------------

/** En giltig PDF med $sidor sidor, skriven av FPDI. */
function parmPdf(int $sidor): string
{
    $pdf = new Fpdi('P', 'pt');
    $pdf->SetFont('Helvetica', '', 12);

    for ($i = 1; $i <= $sidor; $i++) {
        $pdf->AddPage();
        $pdf->Cell(0, 20, "Bilagesida {$i}");
    }

    return $pdf->Output('S');
}

/** En PDF som FPDI:s gratisversion vägrar: trailern pekar på en kryptering. */
function parmKrypteradPdf(): string
{
    return str_replace('trailer', "trailer\n<< /Encrypt 99 0 R >>\ntrailer", parmPdf(1));
}

/** En PNG som är mycket större än en sida, så skalningen syns. */
function parmStorPng(): string
{
    $bild = imagecreatetruecolor(1600, 2400);
    imagefill($bild, 0, 0, imagecolorallocate($bild, 200, 40, 40));

    ob_start();
    imagepng($bild);

    return (string) ob_get_clean();
}

function parmBilaga(Item $item, string $namn, string $mime, string $bytes, ?string $medium = null): Attachment
{
    $stored = StoredFile::factory()->create([
        'mime_type' => $mime,
        'byte_size' => strlen($bytes),
        'storage_path' => 'bilagor/'.Str::random(12),
    ]);

    Storage::disk('files')->put($stored->storage_path, $bytes);

    if ($medium !== null) {
        ImageDerivative::factory()->create([
            'stored_file_id' => $stored->id,
            'variant' => 'medium',
            'storage_path' => 'bilagor/'.Str::random(12).'-medium',
        ]);

        Storage::disk('files')->put(ImageDerivative::query()->where('stored_file_id', $stored->id)->value('storage_path'), $medium);
    }

    return Attachment::factory()->create([
        'item_id' => $item->id,
        'stored_file_id' => $stored->id,
        'filename' => $namn,
        'kind' => str_starts_with($mime, 'image/') ? 'image' : 'document',
    ]);
}

it('options.attachment_mode link ger en lista med länkar under itemet och inga extra sidor', function () {
    [$account, $user, $container] = parmKontext();
    $item = parmItem($container, $account, $user, 'Prylen');
    $pdf = parmBilaga($item, 'manual.pdf', 'application/pdf', parmPdf(3));
    parmBilaga($item, 'ovald.pdf', 'application/pdf', parmPdf(1));

    $spion = parmSpion();
    $export = parmKör(parmRad($container, $user, ['options' => ['attachments' => [$pdf->ulid]]]));

    $lankar = parmDel($spion, 'export.binder.item')['attachments'];

    expect($export->status)->toBe(Export::STATUS_READY)
        ->and($lankar)->toHaveCount(1)
        ->and($lankar[0]['filename'])->toBe('manual.pdf')
        ->and($lankar[0]['type'])->toBe('application/pdf')
        ->and($lankar[0]['url'])->toBe(route('files.download', $pdf))
        ->and($lankar[0]['url'])->toStartWith('http')
        ->and($lankar[0]['note'])->toBeNull()
        ->and(parmSidor(Storage::disk('files')->path($export->storage_path)))->toBe(3);
});

it('merge lägger in PDF-sidor och bilder, med rader i förteckningen och sidnummer över allt', function () {
    [$account, $user, $container] = parmKontext();
    $item = parmItem($container, $account, $user, 'Prylen');
    $pdf = parmBilaga($item, 'manual.pdf', 'application/pdf', parmPdf(3));
    $bild = parmBilaga($item, 'foto.png', 'image/png', 'ORIGINAL', parmStorPng());
    $zip = parmBilaga($item, 'data.zip', 'application/zip', 'PK');

    $spion = parmSpion();
    $export = parmKör(parmRad($container, $user, [
        'options' => ['attachments' => [$pdf->ulid, $bild->ulid, $zip->ulid], 'attachment_mode' => 'merge'],
    ]));

    $sokvag = Storage::disk('files')->path($export->storage_path);

    // försättsblad + förteckning + item + (avdelare + 3 sidor) + avdelare med bilden = 8
    expect($export->status)->toBe(Export::STATUS_READY)
        ->and(parmSidor($sokvag))->toBe(8);

    $rader = parmDel($spion, 'export.binder.toc')['rows'];

    expect(array_column($rader, 'name'))->toBe(['Prylen', 'foto.png', 'manual.pdf'])
        ->and(array_column($rader, 'page'))->toBe([3, 4, 5]);

    // Zip-filen kan inte slås ihop och står kvar som länk; de sammanslagna gör det inte.
    $lankar = parmDel($spion, 'export.binder.item')['attachments'];

    expect(array_column($lankar, 'filename'))->toBe(['data.zip'])
        ->and($lankar[0]['note'])->toBeNull()
        ->and(parmText((string) file_get_contents($sokvag)))->toContain('Page 8 of 8')
        ->and(Storage::disk('files')->exists($export->storage_path))->toBeTrue();
});

it('en krypterad eller oläsbar PDF och en fil över taket står som länk och pärmen blir ready', function () {
    [$account, $user, $container] = parmKontext();
    $item = parmItem($container, $account, $user, 'Prylen');
    $krypterad = parmBilaga($item, 'a-krypterad.pdf', 'application/pdf', parmKrypteradPdf());
    $trasig = parmBilaga($item, 'b-trasig.pdf', 'application/pdf', 'inte en pdf');
    $stor = parmBilaga($item, 'c-stor.pdf', 'application/pdf', parmPdf(2));
    $bildUtanVariant = parmBilaga($item, 'd-bild.png', 'image/png', 'PNG');

    config(['files.binder_merge_bytes' => strlen(parmPdf(2)) - 1]);

    $spion = parmSpion();
    $export = parmKör(parmRad($container, $user, [
        'options' => [
            'attachments' => [$krypterad->ulid, $trasig->ulid, $stor->ulid, $bildUtanVariant->ulid],
            'attachment_mode' => 'merge',
        ],
    ]));

    $lankar = collect(parmDel($spion, 'export.binder.item')['attachments'])->keyBy('filename');

    expect($export->status)->toBe(Export::STATUS_READY)
        ->and($lankar['a-krypterad.pdf']['note'])->toBe('Could not be merged — opened from Mimers')
        ->and($lankar['b-trasig.pdf']['note'])->toBe('Could not be merged — opened from Mimers')
        ->and($lankar['d-bild.png']['note'])->toBe('Could not be merged — opened from Mimers')
        ->and($lankar['c-stor.pdf']['note'])->toContain('size limit')
        ->and(parmSidor(Storage::disk('files')->path($export->storage_path)))->toBe(3);
});

it('taket räknas över alla bilagor i pärmen', function () {
    [$account, $user, $container] = parmKontext();
    $a = parmItem($container, $account, $user, 'A');
    $b = parmItem($container, $account, $user, 'B');
    $forsta = parmBilaga($a, 'ett.pdf', 'application/pdf', parmPdf(1));
    $andra = parmBilaga($b, 'tva.pdf', 'application/pdf', parmPdf(1));

    config(['files.binder_merge_bytes' => strlen(parmPdf(1)) + 10]);

    $spion = parmSpion();
    parmKör(parmRad($container, $user, [
        'options' => ['attachments' => [$forsta->ulid, $andra->ulid], 'attachment_mode' => 'merge'],
    ]));

    $itemDelar = collect($spion->rendered)->filter(fn (array $r): bool => $r[0] === 'export.binder.item')->values();

    expect($itemDelar[0][1]['attachments'])->toBe([])
        ->and($itemDelar[1][1]['attachments'])->toHaveCount(1);
});

it('en bilage-ULID utanför pärmen eller en saknad nyckel ger inga bilagor', function () {
    [$account, $user, $container] = parmKontext();
    $item = parmItem($container, $account, $user, 'Prylen');
    parmBilaga($item, 'med.pdf', 'application/pdf', parmPdf(1));

    $annan = Container::factory()->for($account, 'account')->create();
    $annatItem = parmItem($annan, $account, $user, 'Grannen');
    $utanfor = parmBilaga($annatItem, 'utanfor.pdf', 'application/pdf', parmPdf(1));

    $spion = parmSpion();
    $export = parmKör(parmRad($container, $user, ['options' => ['attachments' => [$utanfor->ulid, 'finns-inte', 7]]]));

    expect(parmDel($spion, 'export.binder.item')['attachments'])->toBe([]);

    $spion = parmSpion();
    parmKör(parmRad($container, $user, ['options' => ['attachment_mode' => 'merge']]));

    expect(parmDel($spion, 'export.binder.item')['attachments'])->toBe([])
        ->and($export->status)->toBe(Export::STATUS_READY);
});

// --- Notisen när pärmen är klar (issue 1518) -------------------------------

it('en pärm som blir ready ger beställaren binder.ready i appen, länkad till exportsidan', function () {
    [$account, $user, $container] = parmKontext();

    $export = parmKör(parmRad($container, $user));

    expect($export->status)->toBe(Export::STATUS_READY);

    $notis = Notification::query()->where('type', Notification::TYPE_BINDER_READY)->sole();

    expect($notis->user_id)->toBe($user->id)
        ->and($notis->payload)->toBe(['container' => 'Båten', 'item' => null, 'export' => $export->ulid])
        ->and(app(NotificationTarget::class)->for($notis))
        ->toBe('/containers/'.$container->ulid.'/export')
        ->and($notis->deliveries()->pluck('channel')->all())->toBe([NotificationDelivery::CHANNEL_IN_APP]);
});

it('en itempärm bär itemets namn i notisen', function () {
    [$account, $user, $container] = parmKontext();
    $item = parmItem($container, $account, $user, 'Motorn');

    parmKör(parmRad($container, $user, ['item_id' => $item->id]));

    $notis = Notification::query()->where('type', Notification::TYPE_BINDER_READY)->sole();

    expect($notis->payload['item'])->toBe('Motorn');
});

it('ZIP-exporten och en misslyckad pärm ger ingen notis', function () {
    [, $user, $container] = parmKontext();

    parmKör(parmRad($container, $user, ['kind' => Export::KIND_ZIP]));

    app()->instance(BinderBuilder::class, Mockery::mock(BinderBuilder::class, fn ($m) => $m->shouldReceive('build')->andThrow(new RuntimeException('fel'))));
    $failed = parmKör(parmRad($container, $user));

    expect($failed->status)->toBe(Export::STATUS_FAILED)
        ->and(Notification::query()->count())->toBe(0);
});
