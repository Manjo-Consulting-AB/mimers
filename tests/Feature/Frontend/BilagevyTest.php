<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Actions\Usage\AdjustUsage;
use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\from;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 60a · Bilagesektionen på itemets detaljvy — listan, uppladdningen av
 * EN fil och den mjuka raderingen. Se
 * App\Http\Controllers\AttachmentController,
 * App\Http\Controllers\ItemController::show(),
 * App\Support\Frontend\ApiErrorTranslator och
 * resources/js/components/ItemAttachmentSection.vue.
 *
 * Filen bevisar de fyra gränserna issuen är byggd kring:
 *
 * 1. **Listan** — bilagorna kommer med detaljvyns props, nyast först, i samma
 *    ordning som `/api` ger dem, och till ett konstant antal frågor oavsett
 *    antal rader (Beslut 2 och 10).
 * 2. **Skrivningarna** — uppladdningen går genom App\Actions\Attachment\
 *    StoreAttachment och raderingen genom TrashAttachment (Beslut 2 och 7):
 *    hashen, dedupen, referensräkningen och förbrukningen är `/api`:s, inte
 *    en andra upplaga.
 * 3. **Grindarna** — `create` för att lägga till, `delete` för att ta bort,
 *    båda på ITEMET (Beslut 3), plus medlemsprövningen på betalkontot
 *    (Beslut 4).
 * 4. **Kvotfelet** — en fil över gränsen blir ett fältfel på `file` med
 *    gränsen och värdet i läsbar form, aldrig en rå JSON-kropp (Beslut 5 och
 *    6).
 *
 * Att `/api`:s tre bilagerutter svarar exakt som förut prövas i sista testet.
 * Att ingen svensk sträng står kvar i en Vue-komponent och att varje ny nyckel
 * finns prövas av tests/Feature/Frontend/SprakTest.php, som
 * läser varenda fil under resources/js.
 *
 * Hjälparna har prefixet `bilagevy` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

beforeEach(function () {
    Storage::fake('files');
});

/**
 * Ett konto med en medlem, och en container med ett item under kontot. Båda på
 * svenska, så meningarna nedan kan jämföras mot `Lang::get(…, 'en')`.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function bilagevyKontext(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

/**
 * En mottagare UTANFÖR ägarkontot, med en itemgrant på angiven nivå.
 *
 * @return array{0: User, 1: Account} mottagaren och hennes EGET konto, som
 *                                    `account`-fältet i uppladdningen pekar på
 */
function bilagevyMottagare(Container $container, Item $item, string $niva): array
{
    $mottagare = User::factory()->create(['locale' => 'sv_SE']);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $niva,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    $egetKonto = Account::factory()->create();
    $egetKonto->users()->attach($mottagare, ['role' => 'owner']);

    return [$mottagare, $egetKonto];
}

/**
 * En bilaga med känd storlek och känd tidsstämpel, så sorteringen går att
 * pröva. Bytena ligger bara som en rad — inget test här läser dem ur disken
 * utom nedladdningen, som använder samma rad.
 */
function bilagevyBilaga(Item $item, Account $konto, User $uppladdare, string $namn, int $byteSize, string $skapad): Attachment
{
    $stored = StoredFile::factory()->create(['byte_size' => $byteSize]);

    return Attachment::factory()->create([
        'item_id' => $item->id,
        'stored_file_id' => $stored->id,
        'filename' => $namn,
        'kind' => 'document',
        'uploaded_by_user_id' => $uppladdare->id,
        'billed_account_id' => $konto->id,
        'created_at' => Carbon::parse($skapad),
    ]);
}

function bilagevyUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}";
}

/**
 * Kör ett uttryck mot resources/js/components/attachmentPresentation.js i
 * node och returnerar det som skrivs på stdout — samma teknik som
 * itemdetaljKör() i ItemdetaljTest.php. Formateringen är en ren funktion i en
 * egen modul just för att gå att köra så här; en mall går inte att pröva.
 */
function bilagevyKör(string $anrop): string
{
    $kod = implode("\n", [
        "const { pathToFileURL } = await import('node:url');",
        'const m = await import(pathToFileURL('
            .json_encode(resource_path('js/components/attachmentPresentation.js'), JSON_UNESCAPED_SLASHES).').href);',
        "process.stdout.write(String({$anrop}));",
    ]);

    $rader = [];
    $status = 0;

    exec('node --input-type=module -e '.escapeshellarg($kod).' 2>&1', $rader, $status);

    expect($status)->toBe(0, implode("\n", $rader));

    return implode("\n", $rader);
}

/**
 * Förbrukningen på ett konto, läst ur räknaren. `AdjustUsage` skapar raden
 * vid behov, så ett konto utan uppladdningar har ingen rad och svaret är 0.
 */
function bilagevyForbrukning(Account $konto): int
{
    return (int) UsageCounter::query()->where('account_id', $konto->id)->value('storage_bytes');
}

// --- listan: detaljvyns props, nyast först ------------------------------

it('formaterar storleken byte-identiskt med serverns Number::fileSize()', function () {
    // Sanningen är Illuminate\Support\Number::fileSize(), som
    // App\Support\Frontend\ApiErrorTranslator använder i kvotmeningarna
    // (Beslut 6). Samma tal ska bli samma sträng i listan och i meningen —
    // annars tvivlar läsaren på vilket tal som är sant.
    //
    // 950 prövar tröskeln `> 0.9` (inte `>= 1024`), 2560 prövar half-even
    // (2,5 KB blir `2 KB`, inte `3 KB`), och 5368709120 prövar enheten.
    $byteStorlekar = [0, 1, 921, 950, 1023, 1024, 1536, 2048, 2560, 3584, 5 * 1024 * 1024 * 1024];

    foreach ($byteStorlekar as $bytes) {
        expect(bilagevyKör("m.formatByteSize({$bytes})"))
            ->toBe(Number::fileSize($bytes), "byte_size {$bytes}");
    }

    // Ett värde som inte är ett tal ger `null`, och vyn utelämnar raden i
    // stället för att visa påhittat innehåll — samma regel som `itemFields`.
    expect(bilagevyKör('m.formatByteSize(null)'))->toBe('null');
});

it('listar bilagorna nyast först med filnamn, typ och storlek', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = bilagevyKontext();

    // Två bilagor uppladdade samma sekund: andrasorteringen på `id` fallande
    // ger ändå en stabil ordning, precis som /api (issue 16b § Beslut 3).
    bilagevyBilaga($item, $konto, $anvandare, 'gammal-manual.pdf', 2048, '2026-09-01 10:00:00');
    bilagevyBilaga($item, $konto, $anvandare, 'ny-manual.pdf', 1024, '2026-09-02 10:00:00');

    actingAs($anvandare)->get(bilagevyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Show')
            ->has('attachments', 2)
            ->where('attachments.0.filename', 'ny-manual.pdf')
            ->where('attachments.0.byte_size', 1024)
            ->where('attachments.0.kind', 'document')
            ->where('attachments.1.filename', 'gammal-manual.pdf')
    );
});

it('ger samma lista och samma ordning som /api', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = bilagevyKontext();

    $aldre = bilagevyBilaga($item, $konto, $anvandare, 'aldre.pdf', 2048, '2026-09-01 10:00:00');
    $nyare = bilagevyBilaga($item, $konto, $anvandare, 'nyare.pdf', 1024, '2026-09-02 10:00:00');

    $token = $anvandare->createToken('api');

    $api = getJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/attachments",
        ['Authorization' => "Bearer {$token->plainTextToken}"],
    )->assertOk();

    actingAs($anvandare)->get(bilagevyUrl($container, $item))->assertOk()->assertInertia(
        function (AssertableInertia $page) use ($nyare, $aldre) {
            $page->has('attachments', 2)
                ->where('attachments.0.ulid', $nyare->ulid)
                ->where('attachments.0.filename', 'nyare.pdf')
                ->where('attachments.1.ulid', $aldre->ulid);
        }
    );

    // Samma lista ur samma resurs, i samma ordning: webben sorterar inte om
    // något och har ingen egen väg till listan (Beslut 2).
    expect($api->json('data.0.ulid'))->toBe($nyare->ulid);
    expect($api->json('data.1.ulid'))->toBe($aldre->ulid);
});

it('kostar ett konstant antal frågor oavsett antal bilagor', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = bilagevyKontext();

    $url = bilagevyUrl($container, $item);

    // Jämförelsepunkten är EN bilaga och inte noll: en tom relation hoppar
    // Eloquent över sin eager load-fråga helt, så noll rader kostar mindre av
    // ett skäl som inte har med per-rad-arbete att göra. Det som prövas är att
    // rad TIO inte kostar mer än rad ETT.
    bilagevyBilaga($item, $konto, $anvandare, 'forsta.pdf', 1024, '2026-09-01 10:00:00');

    // Värm sessionen så att den första frågan för `last_active_at` inte
    // räknas med, samma resonemang som ItemrelationvyTest.
    actingAs($anvandare)->get($url)->assertOk();

    $antal = 0;

    DB::listen(function ($query) use (&$antal) {
        if (! str_contains($query->sql, 'last_active_at')) {
            $antal++;
        }
    });

    $antal = 0;
    actingAs($anvandare)->get($url)->assertOk();
    $medEn = $antal;

    foreach (range(2, 10) as $i) {
        bilagevyBilaga($item, $konto, $anvandare, "bilaga-{$i}.pdf", 1024, '2026-09-02 10:00:00');
    }

    $antal = 0;
    actingAs($anvandare)->get($url)->assertOk();
    $medTio = $antal;

    // Bilagorna hämtas i EN fråga med `storedFile` och `billedAccount` eager,
    // precis som Api\AttachmentController::index() (Beslut 2 och 10): ingen
    // fråga per rad, och resursen kör ingen oplanerad lazy-load.
    expect($medTio)->toBe($medEn);
});

// --- uppladdningen: samma rader som /api --------------------------------

it('laddar upp en fil och visar den i listan direkt efteråt', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = bilagevyKontext();

    $innehall = 'PDF-innehåll som faktiskt ser ut som en manual';

    $svar = from(bilagevyUrl($container, $item))->actingAs($anvandare)->post(
        bilagevyUrl($container, $item).'/attachments',
        [
            'file' => UploadedFile::fake()->createWithContent('victron-manual.pdf', $innehall),
            'account' => $konto->ulid,
        ],
    );

    $svar->assertRedirect(bilagevyUrl($container, $item));
    $svar->assertSessionHas('status', 'attachment-uploaded');

    $attachment = Attachment::query()->firstOrFail();

    expect($attachment->filename)->toBe('victron-manual.pdf');
    expect($attachment->item_id)->toBe($item->id);
    expect($attachment->uploaded_by_user_id)->toBe($anvandare->id);
    expect($attachment->storedFile->content_hash)->toBe(hash('sha256', $innehall));
    expect(Storage::disk('files')->exists($attachment->storedFile->storage_path))->toBeTrue();

    actingAs($anvandare)->get(bilagevyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('attachments', 1)
            ->where('attachments.0.filename', 'victron-manual.pdf')
            ->where('attachments.0.kind', 'document')
    );
});

it('belastar det valda kontot och inte containerns ägare', function () {
    withoutVite();

    [$agarkonto, $anvandare, $container, $item] = bilagevyKontext();

    // Användaren är medlem i två konton: containerns ägarkonto och sitt eget.
    $egetKonto = Account::factory()->create();
    $egetKonto->users()->attach($anvandare, ['role' => 'owner']);

    actingAs($anvandare)->post(bilagevyUrl($container, $item).'/attachments', [
        'file' => UploadedFile::fake()->createWithContent('manual.pdf', str_repeat('a', 1500)),
        'account' => $egetKonto->ulid,
    ])->assertRedirect();

    // Kvoten räknas på det uppladdande kontot, inte på containerns ägare
    // ([[Filer och lagring]] § attachment, Beslut 4).
    expect(bilagevyForbrukning($egetKonto))->toBe(1500);
    expect(bilagevyForbrukning($agarkonto))->toBe(0);
    expect(Attachment::query()->firstOrFail()->billed_account_id)->toBe($egetKonto->id);
});

it('skapar ingen ny stored_file när innehållet redan finns', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = bilagevyKontext();

    $url = bilagevyUrl($container, $item).'/attachments';
    $innehall = 'samma victron-manual';

    actingAs($anvandare)->post($url, [
        'file' => UploadedFile::fake()->createWithContent('victron-manual.pdf', $innehall),
        'account' => $konto->ulid,
    ])->assertRedirect();

    actingAs($anvandare)->post($url, [
        'file' => UploadedFile::fake()->createWithContent('garmin-handbok.pdf', $innehall),
        'account' => $konto->ulid,
    ])->assertRedirect();

    // Dedupen är osynlig i vyn: två rader, ett innehåll. Bytena skrevs en
    // gång, och förbrukningen räknas två gånger — den logiska storleken, inte
    // diskförbrukningen ([[Filer och lagring]] § Kvot kontra faktisk lagring).
    expect(StoredFile::count())->toBe(1);
    expect(StoredFile::query()->firstOrFail()->reference_count)->toBe(2);
    expect(Attachment::count())->toBe(2);
    expect(count(Storage::disk('files')->allFiles()))->toBe(1);
    expect(bilagevyForbrukning($konto))->toBe(2 * strlen($innehall));

    actingAs($anvandare)->get(bilagevyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->has('attachments', 2)
    );
});

// --- grindarna ----------------------------------------------------------

it('visar listan för en read-mottagare men ingen skrivyta, och nekar posten', function () {
    withoutVite();

    [$konto, $agaren, $container, $item] = bilagevyKontext();
    bilagevyBilaga($item, $konto, $agaren, 'manual.pdf', 1024, '2026-09-01 10:00:00');

    [$lasare, $hennesKonto] = bilagevyMottagare($container, $item, 'read');

    $svar = actingAs($lasare)->post(bilagevyUrl($container, $item).'/attachments', [
        'file' => UploadedFile::fake()->createWithContent('manual.pdf', 'innehåll'),
        'account' => $hennesKonto->ulid,
    ]);

    $svar->assertForbidden();
    expect(Attachment::query()->count())->toBe(1);

    // Läsning räcker för att SE listan (grinden är `view` på itemet) men inte
    // för att lägga till eller ta bort: `create` och `delete` är egna pinnar
    // (Beslut 3).
    actingAs($lasare)->get(bilagevyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('attachments', 1)
            ->where('attachments.0.filename', 'manual.pdf')
            ->where('can.create', false)
            ->where('can.delete', false)
    );

    // Grinden är presentation i vyn och auktorisering i kontrollern: en
    // användare som inte får ladda upp ser ingen uppladdningsyta, och en yta
    // som inte ritas prövas inte heller i webbläsaren.
    $vy = File::get(resource_path('js/components/ItemAttachmentSection.vue'));

    expect($vy)->toContain('v-if="can.create"');
    expect($vy)->toContain('v-if="can.delete"');
});

it('ger 403 på DELETE för en write-mottagare', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = bilagevyKontext();
    $bilaga = bilagevyBilaga($item, $konto, $anvandare, 'manual.pdf', 1024, '2026-09-01 10:00:00');

    [$skrivare] = bilagevyMottagare($container, $item, 'write');

    // `delete` är en egen pinne: en write-mottagare ändrar itemet men tar inte
    // bort dess bilagor (Beslut 3, ADR-0028).
    actingAs($skrivare)
        ->delete(bilagevyUrl($container, $item)."/attachments/{$bilaga->ulid}")
        ->assertForbidden();

    expect($bilaga->fresh()->deleted_at)->toBeNull();

    // Och flaggan som ritar knappen är samma grind, så knappen ritas inte.
    actingAs($skrivare)->get(bilagevyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('attachments', 1)
            ->where('can.delete', false)
    );
});

it('ger 403 när användaren inte är medlem i betalkontot', function () {
    withoutVite();

    [, $anvandare, $container, $item] = bilagevyKontext();

    $frammande = Account::factory()->create();

    $svar = actingAs($anvandare)->post(bilagevyUrl($container, $item).'/attachments', [
        'file' => UploadedFile::fake()->createWithContent('manual.pdf', 'innehåll'),
        'account' => $frammande->ulid,
    ]);

    $svar->assertForbidden();

    // Ingenting sparas: ingen rad och ingen byte (Beslut 4).
    expect(Attachment::query()->count())->toBe(0);
    expect(StoredFile::query()->count())->toBe(0);
    expect(Storage::disk('files')->allFiles())->toBe([]);
});

it('ger 404 för en bilaga på ett annat item', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = bilagevyKontext();

    $annat = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    $frammande = bilagevyBilaga($annat, $konto, $anvandare, 'frammande.pdf', 1024, '2026-09-01 10:00:00');

    // `{attachment}` binds genom App\Models\Item::attachments() via
    // scopeBindings(), så en ULID från ett annat item löser aldrig upp
    // (Beslut 1).
    actingAs($anvandare)
        ->delete(bilagevyUrl($container, $item)."/attachments/{$frammande->ulid}")
        ->assertNotFound();

    expect($frammande->fresh()->deleted_at)->toBeNull();
});

// --- kvot- och storleksfelet -------------------------------------------

it('gör en för stor fil till ett fältfel med gräns och filstorlek', function () {
    withoutVite();

    sättPlangräns('free', 'max_file_bytes', 1024);
    [$konto, $anvandare, $container, $item] = bilagevyKontext();

    $svar = from(bilagevyUrl($container, $item))->actingAs($anvandare)->post(
        bilagevyUrl($container, $item).'/attachments',
        [
            'file' => UploadedFile::fake()->createWithContent('stor.pdf', str_repeat('a', 2048)),
            'account' => $konto->ulid,
        ],
    );

    // Formuläret, inte en felsida och inte en JSON-kropp mitt i sidan.
    $svar->assertRedirect(bilagevyUrl($container, $item));
    $svar->assertSessionHasErrors('file');
    expect($svar->getContent())->not->toContain('"error"');

    $mening = session('errors')->get('file')[0];

    // Meningen bär BÅDE gränsen och filens storlek, i läsbar form — ett
    // meddelande som slänger bort `data` är sämre än felkoden det ersatte
    // (Beslut 5 och 6).
    expect($mening)->toBe(Lang::get('ui.error.quota.max_file_size_exceeded', [
        'limit_bytes' => Number::fileSize(1024),
        'file_bytes' => Number::fileSize(2048),
    ], 'en'));

    expect($mening)->toContain(Number::fileSize(1024));
    expect($mening)->toContain(Number::fileSize(2048));
    expect($mening)->not->toContain('1024');

    // En fil som ändå nekas ska varken skrivas till disken eller få en rad.
    expect(Attachment::query()->count())->toBe(0);
    expect(StoredFile::query()->count())->toBe(0);
    expect(Storage::disk('files')->allFiles())->toBe([]);
});

it('gör en sprängd totalkvot till ett fältfel med gräns, förbrukning och filstorlek', function () {
    withoutVite();

    sättPlangräns('free', 'storage_bytes', 3000);
    [$konto, $anvandare, $container, $item] = bilagevyKontext();

    (new AdjustUsage)->handle($konto->id, bytesDelta: 2000);

    $svar = from(bilagevyUrl($container, $item))->actingAs($anvandare)->post(
        bilagevyUrl($container, $item).'/attachments',
        [
            'file' => UploadedFile::fake()->createWithContent('stor.pdf', str_repeat('a', 2000)),
            'account' => $konto->ulid,
        ],
    );

    $svar->assertRedirect(bilagevyUrl($container, $item));
    $svar->assertSessionHasErrors('file');

    $mening = session('errors')->get('file')[0];

    expect($mening)->toBe(Lang::get('ui.error.quota.storage_exceeded', [
        'limit_bytes' => Number::fileSize(3000),
        'used_bytes' => Number::fileSize(2000),
        'file_bytes' => Number::fileSize(2000),
    ], 'en'));

    expect($mening)->toContain(Number::fileSize(3000));

    // Ingen byte skrivs och ingen rad skapas: kvoten prövas före
    // StoreAttachment (Beslut 5).
    expect(Attachment::query()->count())->toBe(0);
    expect(StoredFile::query()->count())->toBe(0);
    expect(Storage::disk('files')->allFiles())->toBe([]);
    expect(bilagevyForbrukning($konto))->toBe(2000);
});

it('formaterar bytes i feldata och lämnar övriga nycklar orörda', function () {
    $translator = app(ApiErrorTranslator::class);

    // 5 GiB: talet en människa inte läser, och talet hon gör det.
    $med = ApiException::make('quota.storage_exceeded', [
        'limit_bytes' => 5 * 1024 * 1024 * 1024,
        'used_bytes' => 1024,
        'file_bytes' => 2048,
    ], 403);

    $mening = $translator->message($med);

    expect($mening)->toContain(Number::fileSize(5 * 1024 * 1024 * 1024));
    expect($mening)->toContain(Number::fileSize(1024));
    expect($mening)->toContain(Number::fileSize(2048));
    expect($mening)->not->toContain('5368709120');

    // Nycklar som inte slutar på `_bytes` lämnas orörda: `:limit` och `:used`
    // är tal läsaren redan förstår, och en formatering av dem vore en
    // förändring av en befintlig mening.
    $utan = ApiException::make('quota.containers_exceeded', [
        'limit' => 1,
        'used' => 2,
    ], 403);

    expect($translator->message($utan))->toBe(Lang::get('ui.error.quota.containers_exceeded', [
        'limit' => 1,
        'used' => 2,
    ]));
});

// --- raderingen: mjuk ---------------------------------------------------

it('mjukraderar bilagan och drar av bytena utan att röra referensräknaren', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = bilagevyKontext();

    from(bilagevyUrl($container, $item))->actingAs($anvandare)->post(
        bilagevyUrl($container, $item).'/attachments',
        [
            'file' => UploadedFile::fake()->createWithContent('manual.pdf', str_repeat('a', 1500)),
            'account' => $konto->ulid,
        ],
    )->assertRedirect();

    $bilaga = Attachment::query()->firstOrFail();
    $stored = StoredFile::query()->firstOrFail();

    expect(bilagevyForbrukning($konto))->toBe(1500);

    $svar = from(bilagevyUrl($container, $item))
        ->actingAs($anvandare)
        ->delete(bilagevyUrl($container, $item)."/attachments/{$bilaga->ulid}");

    $svar->assertRedirect(bilagevyUrl($container, $item));
    $svar->assertSessionHas('status', 'attachment-deleted');

    // Mjuk: `deleted_at` sätts och ingenting annat gallras (Beslut 7).
    expect($bilaga->fresh()->deleted_at)->not->toBeNull();

    // Förbrukningen dras av direkt, i samma transaktion som raden.
    expect(bilagevyForbrukning($konto))->toBe(0);

    // Men `reference_count` rörs INTE — den minskas först när bilagan lämnar
    // papperskorgen (ADR-0008, issue 17a).
    expect($stored->fresh()->reference_count)->toBe(1);
});

it('tar bort bilagan ur listan och ger 404 på nedladdningslänken', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = bilagevyKontext();

    // En riktig uppladdning, så att bytena finns på disken och nedladdningen
    // kan bevisas fungera FÖRE raderingen — annars vet testet inte om 404:an
    // efteråt kommer av mjukraderingen eller av något annat.
    from(bilagevyUrl($container, $item))->actingAs($anvandare)->post(
        bilagevyUrl($container, $item).'/attachments',
        [
            'file' => UploadedFile::fake()->createWithContent('manual.pdf', 'innehåll'),
            'account' => $konto->ulid,
        ],
    )->assertRedirect();

    $bilaga = Attachment::query()->firstOrFail();

    actingAs($anvandare)->get("/files/{$bilaga->ulid}")->assertOk();

    from(bilagevyUrl($container, $item))
        ->actingAs($anvandare)
        ->delete(bilagevyUrl($container, $item)."/attachments/{$bilaga->ulid}")
        ->assertRedirect();

    actingAs($anvandare)->get(bilagevyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->has('attachments', 0)
    );

    // Rutten från issue 19a binder `{attachment}` på ULID, och en mjukraderad
    // bilaga syns inte av bindningen — 404, inte en tom fil.
    actingAs($anvandare)->get("/files/{$bilaga->ulid}")->assertNotFound();

    // Och en redan raderad bilaga går inte att radera en gång till.
    actingAs($anvandare)
        ->delete(bilagevyUrl($container, $item)."/attachments/{$bilaga->ulid}")
        ->assertNotFound();
});

// --- /api är oförändrat -------------------------------------------------

it('lämnar /api:s tre bilagerutter oförändrade', function () {
    [$konto, $user, $headers] = kontoMedMedlem();

    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/attachments";

    $skapat = postJson($url, [
        'file' => UploadedFile::fake()->createWithContent('manual.pdf', 'innehåll'),
        'account' => $konto->ulid,
    ], $headers)->assertCreated();

    // Samma kropp som förut: resursens fält och ingenting mer.
    expect(array_keys($skapat->json('data')))->toBe([
        'ulid', 'filename', 'kind', 'mime_type', 'byte_size', 'billed_account', 'created_at', 'updated_at',
    ]);

    getJson($url, $headers)->assertOk()->assertJsonCount(1, 'data');

    deleteJson($url.'/'.$skapat->json('data.ulid'), [], $headers)->assertNoContent();

    // Mjukraderingen är densamma: 204, ingen kropp, raden kvar med deleted_at.
    expect(Attachment::query()->onlyTrashed()->count())->toBe(1);

    // Och kvotfelet svarar fortfarande med felkoden i höljet på /api — den
    // översatta meningen hör till webben och till App\Support\Frontend\
    // ApiErrorTranslator, aldrig till /api (ADR-0013).
    sättPlangräns('free', 'max_file_bytes', 1024);

    postJson($url, [
        'file' => UploadedFile::fake()->createWithContent('stor.pdf', str_repeat('a', 2048)),
        'account' => $konto->ulid,
    ], $headers)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'quota.max_file_size_exceeded')
        ->assertJsonPath('error.data', ['limit_bytes' => 1024, 'file_bytes' => 2048]);
});
