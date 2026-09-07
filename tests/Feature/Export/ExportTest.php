<?php

use App\Jobs\BuildContainerExport;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\Export;
use App\Models\Item;
use App\Models\Loan;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\StoredFile;
use App\Models\Tag;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\Export\ContainerExportBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 41 · Export — beställningen, jobbet och artefakten, se
 * App\Http\Controllers\Api\ExportController, App\Jobs\BuildContainerExport,
 * App\Support\Export\ContainerExportBuilder och App\Models\Export.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 *
 * Storage::fake('files') i beforeEach — inga bytes får hamna i den riktiga
 * storage/files/ när sviten körs. Hjälparna exportering* bor i den här filen
 * eftersom bara den använder dem (tests/Support/Testhjalpare.php-doktrinen).
 */

beforeEach(function () {
    Storage::fake('files');
});

// --- Hjälpare --------------------------------------------------------------

/**
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container}
 */
function exporteringKontext(): array
{
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    return [$account, $user, $headers, $container];
}

function exporteringItem(Container $container, Account $account, User $user, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ], $attribut));
}

/**
 * En bilaga på itemet. Är $innehåll satt skrivs bytena till den fejkade
 * disken under stored_file:ns sökväg — med dedup, precis som StoreAttachment:
 * samma innehåll två gånger i ett test återanvänder stored_file-raden i
 * stället för att krocka på content_hash. Är det null pekar bilagan på en
 * stored_file vars byten INTE finns — utgångsläget för missing-testet.
 */
function exporteringsBilaga(Item $item, Account $account, User $user, string $filnamn, ?string $innehåll = null, array $attribut = []): Attachment
{
    $stored = null;

    if ($innehåll !== null) {
        $hash = hash('sha256', $innehåll);

        $stored = StoredFile::query()->where('content_hash', $hash)->first();

        if ($stored === null) {
            $storagePath = substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/'.$hash;

            Storage::disk('files')->put($storagePath, $innehåll);

            $stored = StoredFile::factory()->create([
                'content_hash' => $hash,
                'storage_path' => $storagePath,
                'byte_size' => strlen($innehåll),
                'mime_type' => 'application/pdf',
                'scan_status' => 'skipped',
            ]);
        } else {
            $stored->increment('reference_count');
        }
    }

    if ($stored === null) {
        $stored = StoredFile::factory()->create(['scan_status' => 'skipped']);
    }

    return Attachment::factory()->create(array_merge([
        'item_id' => $item->id,
        'stored_file_id' => $stored->id,
        'filename' => $filnamn,
        'kind' => 'document',
        'uploaded_by_user_id' => $user->id,
        'billed_account_id' => $account->id,
    ], $attribut));
}

/**
 * Skapar exporten genom POST (med fejkad kö) och kör jobbet på riktigt mot
 * den fejkade disken — minst ett test i sviten måste bevisa artefakten, se
 * issue 41 § Testerna.
 */
function exporteringBeställOchKör(array $headers, Container $container): Export
{
    Queue::fake();

    $response = postJson("/api/containers/{$container->ulid}/exports", [], $headers);
    $response->assertStatus(202);

    $export = Export::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();
    (new BuildContainerExport($export))->handle();
    $export->refresh();

    return $export;
}

// --- Klart när -------------------------------------------------------------

it('post skapar en pending-rad, lägger jobbet på kön och svarar 202 med ulid och status', function () {
    Queue::fake();
    [$account, $user, $headers, $container] = exporteringKontext();

    $response = postJson("/api/containers/{$container->ulid}/exports", [], $headers);

    $response->assertStatus(202);
    $response->assertJsonPath('data.status', Export::STATUS_PENDING);

    Queue::assertPushed(BuildContainerExport::class);

    $export = Export::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();
    expect($export->status)->toBe(Export::STATUS_PENDING);
    expect($export->container_id)->toBe($container->id);
    expect($export->requested_by_user_id)->toBe($user->id);
});

it('jobbet lämnar raden ready och artefakten är en ZIP med container.json, index.html och filerna', function () {
    [$account, $user, $headers, $container] = exporteringKontext();

    $category = Category::factory()->for($container, 'container')->create(['name' => 'Drift']);
    $tag = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);

    $item = exporteringItem($container, $account, $user, ['name' => 'Impeller']);
    $item->category_id = $category->id;
    $item->save();
    $item->tags()->attach($tag);

    $schedule = Schedule::factory()->for($item, 'item')->create([
        'title' => 'Byt impeller',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
    ]);
    $occurrence = ScheduleOccurrence::factory()->create([
        'schedule_id' => $schedule->id,
        'status' => ScheduleOccurrence::STATUS_OPEN,
        'due_at' => '2027-05-05',
        'visible_from' => '2027-04-05',
    ]);
    $loan = Loan::factory()->for($item, 'item')->create([
        'borrower_name' => 'Kalle',
        'lent_at' => '2026-09-01',
        'due_at' => '2026-09-10',
    ]);
    $innehåll = 'manualens byten';
    $bilaga = exporteringsBilaga($item, $account, $user, 'manual.pdf', $innehåll);

    $export = exporteringBeställOchKör($headers, $container);

    expect($export->status)->toBe(Export::STATUS_READY);
    expect($export->storage_path)->not->toBeNull();
    expect($export->byte_size)->toBeGreaterThan(0);
    expect($export->expires_at)->not->toBeNull();
    expect(Storage::disk('files')->exists($export->storage_path))->toBeTrue();

    // show() pollas till ready
    getJson("/api/containers/{$container->ulid}/exports/{$export->ulid}", $headers)
        ->assertOk()
        ->assertJsonPath('data.status', Export::STATUS_READY);

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('files')->path($export->storage_path)))->toBeTrue();

    expect($zip->getFromName('container.json'))->not->toBeFalse();
    expect($zip->getFromName('index.html'))->not->toBeFalse();
    expect($zip->getFromName('filer/'.$item->ulid.'/manual.pdf'))->toBe($innehåll);

    $payload = json_decode($zip->getFromName('container.json'), true);

    expect($payload['format_version'])->toBe(1);
    expect($payload['container']['ulid'])->toBe($container->ulid);
    expect($payload['container']['name'])->toBe($container->name);

    $exportedItem = $payload['items'][0];
    expect($exportedItem['name'])->toBe('Impeller');
    expect($exportedItem['category_ulid'])->toBe($category->ulid);
    expect($exportedItem['tags'])->toContain($tag->ulid);
    expect($exportedItem['schedules'][0]['ulid'])->toBe($schedule->ulid);
    expect($exportedItem['schedules'][0]['occurrences'][0]['ulid'])->toBe($occurrence->ulid);
    expect($exportedItem['loans'][0]['ulid'])->toBe($loan->ulid);
    expect($exportedItem['attachments'][0]['ulid'])->toBe($bilaga->ulid);
    expect($exportedItem['attachments'][0]['path'])->toBe('filer/'.$item->ulid.'/manual.pdf');

    $zip->close();
});

it('container.json bär varje items innehåll och inga åtkomst-, inbjudnings- eller kontouppgifter', function () {
    [$account, $user, $headers, $container] = exporteringKontext();

    $item = exporteringItem($container, $account, $user, ['name' => 'Enda prylen']);
    exporteringsBilaga($item, $account, $user, 'manual.pdf', 'byten');

    // Ovidkommande rader som exporten INTE får bära med sig: en delegerad
    // åtkomst, en inbjudan och en användare med e-postadress.
    $annan = User::factory()->create(['email' => 'delad@example.com']);
    beviljaAccess($container, $annan, 'read', 'member');
    bjudInRad($container, 'bjuden@example.com');
    $user->update(['email' => 'agare@example.com']);

    $export = exporteringBeställOchKör($headers, $container);

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('files')->path($export->storage_path)))->toBeTrue();
    $json = $zip->getFromName('container.json');
    $zip->close();

    $payload = json_decode($json, true);
    expect($payload['items'])->toHaveCount(1);
    expect($payload['items'][0])->not->toHaveKey('id');
    expect($payload['items'][0])->not->toHaveKey('container_access');

    expect($json)->not->toContain('delad@example.com');
    expect($json)->not->toContain('bjuden@example.com');
    expect($json)->not->toContain('agare@example.com');
    expect($json)->not->toContain('container_access');
    expect($json)->not->toContain('invitation');
});

it('index.html är fristående: bara relativa sökvägar, ingen extern resurs', function () {
    [$account, $user, $headers, $container] = exporteringKontext();

    $item = exporteringItem($container, $account, $user, ['name' => 'Prylen']);
    exporteringsBilaga($item, $account, $user, 'manual.pdf', 'byten');

    $export = exporteringBeställOchKör($headers, $container);

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('files')->path($export->storage_path)))->toBeTrue();
    $html = $zip->getFromName('index.html');
    $zip->close();

    expect($html)->not->toBeFalse();
    expect($html)->toContain('href="filer/'.$item->ulid.'/manual.pdf"');
    expect($html)->not->toContain('http://');
    expect($html)->not->toContain('https://');
    expect($html)->not->toContain('src=');
    expect($html)->not->toContain('//');
});

it('ett mjukraderat item och en mjukraderad bilaga följer inte med', function () {
    [$account, $user, $headers, $container] = exporteringKontext();

    $levande = exporteringItem($container, $account, $user, ['name' => 'Levande']);
    exporteringsBilaga($levande, $account, $user, 'levande.pdf', 'byten');

    $raderatItem = exporteringItem($container, $account, $user, ['name' => 'Raderad']);
    exporteringsBilaga($raderatItem, $account, $user, 'raderad.pdf', 'byten');
    $raderatItem->delete();

    $levandeBilaga = exporteringsBilaga($levande, $account, $user, 'finns.pdf', 'byten');
    $raderadBilaga = exporteringsBilaga($levande, $account, $user, 'borta.pdf', 'byten');
    $raderadBilaga->delete();

    $export = exporteringBeställOchKör($headers, $container);

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('files')->path($export->storage_path)))->toBeTrue();
    $json = $zip->getFromName('container.json');
    $zip->close();

    $payload = json_decode($json, true);
    expect($payload['items'])->toHaveCount(1);
    expect($payload['items'][0]['ulid'])->toBe($levande->ulid);

    $attachmentUlids = array_column($payload['items'][0]['attachments'], 'ulid');
    expect($attachmentUlids)->toContain($levandeBilaga->ulid);
    expect($attachmentUlids)->not->toContain($raderadBilaga->ulid);
});

it('innehåll ur en annan container följer inte med', function () {
    [$account, $user, $headers, $första] = exporteringKontext();
    $andra = Container::factory()->for($account, 'account')->create();

    $förstaItem = exporteringItem($första, $account, $user, ['name' => 'Unik för första']);
    exporteringsBilaga($förstaItem, $account, $user, 'forsta.pdf', 'byten');

    $andraItem = exporteringItem($andra, $account, $user, ['name' => 'Unik för andra']);
    exporteringsBilaga($andraItem, $account, $user, 'andra.pdf', 'byten');

    $export = exporteringBeställOchKör($headers, $första);

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('files')->path($export->storage_path)))->toBeTrue();
    $json = $zip->getFromName('container.json');
    $zip->close();

    $payload = json_decode($json, true);
    expect($payload['container']['ulid'])->toBe($första->ulid);
    expect($payload['items'])->toHaveCount(1);
    expect($payload['items'][0]['ulid'])->toBe($förstaItem->ulid);
    expect($json)->not->toContain('Unik för andra');
    expect($json)->not->toContain($andraItem->ulid);
});

it('ett read_only-konto kan beställa och färdigställa en export', function () {
    $account = Account::factory()->create(['status' => 'read_only']);
    $user = User::factory()->create();
    $account->users()->attach($user, ['role' => 'owner']);
    $container = Container::factory()->for($account, 'account')->create();

    $headers = ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];

    $export = exporteringBeställOchKör($headers, $container);

    expect($export->status)->toBe(Export::STATUS_READY);
    expect(Storage::disk('files')->exists($export->storage_path))->toBeTrue();
});

it('ett konto på gratisplanen kan exportera utan plan.feature_unavailable', function () {
    [$account, $user, $headers, $container] = exporteringKontext();

    expect($account->currentPlan()->code)->toBe('free');

    Queue::fake();
    $response = postJson("/api/containers/{$container->ulid}/exports", [], $headers);

    $response->assertStatus(202);
    expect($response->json('error'))->toBeNull();

    $export = Export::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();
    (new BuildContainerExport($export))->handle();
    $export->refresh();

    expect($export->status)->toBe(Export::STATUS_READY);
});

it('en användare utan åtkomst till containern får auth.forbidden på post', function () {
    [$account, , , $container] = exporteringKontext();

    $främling = User::factory()->create();
    $headers = ['Authorization' => 'Bearer '.$främling->createToken('api')->plainTextToken];

    postJson("/api/containers/{$container->ulid}/exports", [], $headers)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'auth.forbidden');
});

it('en andra post under pending/running avvisas, efter ready går en ny igenom', function () {
    Queue::fake();
    [$account, $user, $headers, $container] = exporteringKontext();

    $första = postJson("/api/containers/{$container->ulid}/exports", [], $headers);
    $första->assertStatus(202);

    $andra = postJson("/api/containers/{$container->ulid}/exports", [], $headers);
    $andra->assertStatus(422);
    $andra->assertJsonPath('error.code', 'export.already_running');
    $andra->assertJsonPath('error.data.export', $första->json('data.ulid'));

    $export = Export::query()->where('ulid', $första->json('data.ulid'))->firstOrFail();
    (new BuildContainerExport($export))->handle();
    $export->refresh();

    expect($export->status)->toBe(Export::STATUS_READY);

    postJson("/api/containers/{$container->ulid}/exports", [], $headers)
        ->assertStatus(202);
});

it('en bilaga vars byten saknas på disken fäller inte exporten utan märks missing', function () {
    [$account, $user, $headers, $container] = exporteringKontext();

    $item = exporteringItem($container, $account, $user, ['name' => 'Prylen']);
    $hel = exporteringsBilaga($item, $account, $user, 'hel.pdf', 'byten');
    $saknad = exporteringsBilaga($item, $account, $user, 'saknad.pdf', null);

    $export = exporteringBeställOchKör($headers, $container);

    expect($export->status)->toBe(Export::STATUS_READY);

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('files')->path($export->storage_path)))->toBeTrue();
    $payload = json_decode($zip->getFromName('container.json'), true);
    $zip->close();

    $attachments = collect($payload['items'][0]['attachments'])->keyBy('ulid');
    expect($attachments[$hel->ulid]['missing'] ?? false)->toBeFalse();
    expect($attachments[$hel->ulid]['path'])->toBe('filer/'.$item->ulid.'/hel.pdf');
    expect($attachments[$saknad->ulid]['missing'])->toBeTrue();
    expect($attachments[$saknad->ulid])->not->toHaveKey('path');
});

it('ett fel i byggaren lämnar raden failed med failure_reason och ingen .part-fil', function () {
    [$account, $user, , $container] = exporteringKontext();
    $export = Export::factory()->create([
        'container_id' => $container->id,
        'requested_by_user_id' => $user->id,
        'status' => Export::STATUS_PENDING,
    ]);

    $containerUlid = $container->ulid;
    $exportUlid = $export->ulid;
    $part = 'exports/'.$containerUlid.'/'.$exportUlid.'.zip.part';

    // Byggaren byts ut mot en underklass som hinner skriva en halv fil innan
    // den kastar — jobbet ska fånga felet, sätta failed och städa bort filen.
    $builder = new class extends ContainerExportBuilder
    {
        public function build(Export $export): string
        {
            $partial = 'exports/'.$export->container->ulid.'/'.$export->ulid.'.zip.part';
            Storage::disk('files')->put($partial, 'halvskriven');
            throw new RuntimeException('Simulerat byggfel');
        }
    };
    app()->instance(ContainerExportBuilder::class, $builder);

    (new BuildContainerExport($export))->handle();

    $export->refresh();
    expect($export->status)->toBe(Export::STATUS_FAILED);
    expect($export->failure_reason)->toBe('Simulerat byggfel');
    expect($export->storage_path)->toBeNull();
    expect(Storage::disk('files')->exists($part))->toBeFalse();
});

it('exporten rör inte kontots usage_counter', function () {
    [$account, $user, $headers, $container] = exporteringKontext();
    UsageCounter::factory()->create(['account_id' => $account->id, 'storage_bytes' => 5000]);

    $item = exporteringItem($container, $account, $user, ['name' => 'Prylen']);
    exporteringsBilaga($item, $account, $user, 'manual.pdf', 'byten');

    $export = exporteringBeställOchKör($headers, $container);

    expect($export->status)->toBe(Export::STATUS_READY);
    expect((int) UsageCounter::query()->where('account_id', $account->id)->value('storage_bytes'))->toBe(5000);
});

it('antalet databasfrågor för att bygga exporten växer inte med antalet items', function () {
    [$accountA, $userA, , $liten] = exporteringKontext();
    for ($i = 0; $i < 2; $i++) {
        $item = exporteringItem($liten, $accountA, $userA, ['name' => 'Liten '.$i]);
        exporteringsBilaga($item, $accountA, $userA, 'fil-'.$i.'.pdf', 'innehåll '.$i);
    }

    [$accountB, $userB, , $stor] = exporteringKontext();
    for ($i = 0; $i < 6; $i++) {
        $item = exporteringItem($stor, $accountB, $userB, ['name' => 'Stor '.$i]);
        exporteringsBilaga($item, $accountB, $userB, 'fil-'.$i.'.pdf', 'innehåll '.$i);
    }

    $litenExport = Export::factory()->create([
        'container_id' => $liten->id,
        'requested_by_user_id' => $userA->id,
    ]);
    $storExport = Export::factory()->create([
        'container_id' => $stor->id,
        'requested_by_user_id' => $userB->id,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    (new BuildContainerExport($litenExport))->handle();
    $frågorLiten = count(DB::getQueryLog());

    DB::flushQueryLog();
    (new BuildContainerExport($storExport))->handle();
    $frågorStor = count(DB::getQueryLog());

    expect($frågorLiten)->toBeGreaterThan(0);
    expect($frågorStor)->toBe($frågorLiten);
});
