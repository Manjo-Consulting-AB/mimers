<?php

use App\Actions\Account\DeleteAccount;
use App\Actions\Attachment\ListRecentOpens;
use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Attachment\RecordAttachmentOpen;
use App\Actions\Trash\PurgeContainer;
use App\Actions\Trash\PurgeContent;
use App\Jobs\BuildContainerExport;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Export;
use App\Models\ImageDerivative;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Access\AccessLevel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * Issue 177 · Senast öppnade filer — tabellen, skrivningen, läsningen och
 * raderingen. Se [[ADR-0051 Senast öppnade filer]],
 * App\Http\Controllers\AttachmentDownloadController,
 * App\Actions\Attachment\RecordAttachmentOpen och
 * App\Actions\Attachment\ListRecentOpens.
 *
 * Varje "Klart när"-punkt i issuen motsvaras av ett namngivet test här, med
 * två undantag som står i sina egna filer: raderingen med personen prövas i
 * tests/Feature/Konto/PersonraderingTest.php, och frågeräkningen i
 * tests/Feature/Attachment/NedladdningTest.php.
 *
 * **Provet "en saknad variant skriver ingenting" är det som håller
 * variantvalideringen före skrivningen.** Flyttas `recordOpen()` före
 * `AttachmentDelivery::storagePath()` i kontrollern faller det: en
 * `?variant=medium` utan derivat hade då lämnat en rad om en fil ingen fick
 * se. Det är samma sorts vakt som "skriver ingenting när visningen nekas"
 * håller över grinden.
 *
 * Proven kör WEBBENS rutt (`files.download`), för det är den som skriver:
 * `/api` och `files.deliver` får ingen motsvarighet.
 *
 * beforeAll/afterAll sätter FILES_URL och tar bort den, precis som
 * NedladdningTest: rutten `files.deliver` registreras vid appens uppstart och
 * bara när filoriginet är satt, och appen byggs i Tests\TestCase före varje
 * test. beforeEach nollställer `files.url`, så appdomänens leverans är
 * filens standard och det ena provet sätter originet själv.
 *
 * Hjälparna har prefixet `senast` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

beforeAll(function () {
    putenv('FILES_URL=https://files.test');
    $_ENV['FILES_URL'] = 'https://files.test';
    $_SERVER['FILES_URL'] = 'https://files.test';
});

afterAll(function () {
    putenv('FILES_URL');
    unset($_ENV['FILES_URL'], $_SERVER['FILES_URL']);
});

beforeEach(function () {
    Storage::fake('files');
    config([
        'files.internal_redirect' => false,
        'files.url' => null,
    ]);
});

/**
 * Ägarkontot, dess medlem och en container i den.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function senastKontext(): array
{
    $konto = Account::factory()->create();
    $ägare = User::factory()->create();
    $konto->users()->attach($ägare, ['role' => 'owner']);

    return [$konto, $ägare, Container::factory()->for($konto, 'account')->create()];
}

/**
 * Item, bilaga och stored_file redo för en hämtning — bytena ligger på den
 * fejkade disken.
 *
 * `$variant` skriver ett derivat med sina byten, så att `?variant=` svarar
 * 200 i stället för 404. Utan den finns ingen derivatrad, och det är
 * utgångsläget för "en saknad variant skriver ingenting".
 *
 * @return array{0: Item, 1: Attachment, 2: StoredFile}
 */
function senastFörberedelse(
    Container $container,
    string $filnamn = 'manual.pdf',
    string $innehåll = 'originalets byten',
    string $mime = 'application/pdf',
    ?string $variant = null,
): array {
    $storedFile = StoredFile::factory()->create([
        'mime_type' => $mime,
        'byte_size' => strlen($innehåll),
    ]);

    Storage::disk('files')->put($storedFile->storage_path, $innehåll);

    $item = Item::factory()->for($container, 'container')->create();
    $bilaga = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'filename' => $filnamn,
        'kind' => str_starts_with($mime, 'image/') ? 'image' : 'document',
    ]);

    if ($variant !== null) {
        $derivat = ImageDerivative::factory()->create([
            'stored_file_id' => $storedFile->id,
            'variant' => $variant,
            'storage_path' => $storedFile->storage_path."_{$variant}.jpg",
            'byte_size' => strlen('mini'),
        ]);

        Storage::disk('files')->put($derivat->storage_path, 'mini');
    }

    return [$item, $bilaga, $storedFile];
}

/**
 * Antalet öppningsrader i tabellen, utan filter — proven nedan frågar efter
 * "ingen rad alls" lika ofta som efter en viss persons.
 */
function senastAntalRader(): int
{
    return (int) DB::table('attachment_open')->count();
}

/*
 * --- Skrivningen ---------------------------------------------------------
 */

it('en nedladdning skriver en rad och en andra uppdaterar opened_at utan en ny rad', function () {
    [, $ägare, $container] = senastKontext();
    [, $bilaga] = senastFörberedelse($container);

    Carbon::setTestNow('2026-09-30 10:00:00');

    actingAs($ägare)->get("/files/{$bilaga->ulid}")->assertOk();

    $rad = DB::table('attachment_open')->where('user_id', $ägare->id)->sole();

    expect((int) $rad->attachment_id)->toBe($bilaga->id)
        ->and($rad->opened_at)->toBe('2026-09-30 10:00:00');

    Carbon::setTestNow('2026-09-30 11:00:00');

    // Samma fil en gång till. Det unika paret `(user_id, attachment_id)` är
    // garanten: raden finns redan, så upserten flyttar `opened_at` i stället
    // för att skriva en andra.
    actingAs($ägare)->get("/files/{$bilaga->ulid}")->assertOk();

    expect(senastAntalRader())->toBe(1)
        ->and(DB::table('attachment_open')->where('user_id', $ägare->id)->value('opened_at'))
        ->toBe('2026-09-30 11:00:00');

    Carbon::setTestNow();
});

it('en förhandsvisning i medium skriver en rad', function () {
    [, $ägare, $container] = senastKontext();
    [, $bilaga] = senastFörberedelse($container, filnamn: 'foto.jpg', mime: 'image/jpeg', variant: 'medium');

    actingAs($ägare)->get("/files/{$bilaga->ulid}?variant=medium")->assertOk();

    expect(senastAntalRader())->toBe(1)
        ->and((int) DB::table('attachment_open')->value('attachment_id'))->toBe($bilaga->id);
});

it('en miniatyr skriver ingenting', function () {
    [, $ägare, $container] = senastKontext();
    [, $bilaga] = senastFörberedelse($container, filnamn: 'foto.jpg', mime: 'image/jpeg', variant: 'thumb');

    actingAs($ägare)->get("/files/{$bilaga->ulid}?variant=thumb")->assertOk();

    // Miniatyren ritas i en lista utan att någon öppnat något. Provet är inte
    // tomt: samma bilaga SKRIVER när den hämtas utan variant, så en
    // kontroller som slutat skriva helt faller på raden nedan.
    expect(senastAntalRader())->toBe(0);

    actingAs($ägare)->get("/files/{$bilaga->ulid}")->assertOk();

    expect(senastAntalRader())->toBe(1);
});

it('containerns egen bild skriver ingenting', function () {
    [, $ägare, $container] = senastKontext();

    $storedFile = StoredFile::factory()->create(['mime_type' => 'image/jpeg', 'byte_size' => 4]);
    Storage::disk('files')->put($storedFile->storage_path, 'bild');

    // `item_id = NULL` är vad som gör raden till en containerbilaga
    // ([[ADR-0047 Containerns bild]]). Den ritas i skalet, och ingen har
    // öppnat en fil i en lista.
    $bild = Attachment::factory()->create([
        'item_id' => null,
        'container_id' => $container->id,
        'stored_file_id' => $storedFile->id,
        'filename' => 'hjalte.jpg',
        'kind' => 'image',
    ]);

    actingAs($ägare)->get("/files/{$bild->ulid}")->assertOk();

    expect(senastAntalRader())->toBe(0);
});

it('en nekad förfrågan skriver ingenting', function () {
    [, , $container] = senastKontext();
    [, $bilaga] = senastFörberedelse($container);

    $främling = User::factory()->create();

    // Grinden kastar, och `recordOpen()` ligger efter den.
    actingAs($främling)->get("/files/{$bilaga->ulid}")->assertForbidden();

    expect(senastAntalRader())->toBe(0);
});

it('en saknad variant skriver ingenting', function () {
    [, $ägare, $container] = senastKontext();

    // Ingen derivatrad alls: `?variant=medium` ger 404, och en 404 är ingen
    // öppning. Provet håller variantvalideringen FÖRE skrivningen.
    [, $bilaga] = senastFörberedelse($container, filnamn: 'foto.jpg', mime: 'image/jpeg');

    actingAs($ägare)->get("/files/{$bilaga->ulid}?variant=medium")->assertNotFound();

    expect(senastAntalRader())->toBe(0);
});

it('files.deliver skriver ingenting', function () {
    [, $ägare, $container] = senastKontext();
    [, $bilaga] = senastFörberedelse($container);

    config(['files.url' => 'https://files.test']);

    $omdirigerad = actingAs($ägare)->get("/files/{$bilaga->ulid}");
    $omdirigerad->assertRedirect();

    // Raden kommer från files.download, som präglade länken.
    expect(senastAntalRader())->toBe(1);

    $innan = DB::table('attachment_open')->sole();

    // Sedan följs 302:an hela vägen. Leveransen bär en signerad URL och vet
    // inte vem som frågar — den lägger ingen rad och rör ingen.
    get($omdirigerad->headers->get('location'))->assertOk();

    $efter = DB::table('attachment_open')->sole();

    expect(senastAntalRader())->toBe(1)
        ->and($efter->id)->toBe($innan->id)
        ->and($efter->opened_at)->toBe($innan->opened_at);
});

it('den femtioförsta raden raderar den äldsta', function () {
    [, $ägare, $container] = senastKontext();

    // Femtioen bilagor, öppnade i ordning — den första är den äldsta och ska
    // inte finnas kvar när den femtioförsta skrivits.
    $bilagor = collect(range(1, RecordAttachmentOpen::LIMIT + 1))
        ->map(fn (int $nummer): Attachment => senastFörberedelse($container, filnamn: "fil-{$nummer}.pdf")[1]);

    foreach ($bilagor as $index => $bilaga) {
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00')->addMinutes($index));

        actingAs($ägare)->get("/files/{$bilaga->ulid}")->assertOk();
    }

    Carbon::setTestNow();

    $kvar = DB::table('attachment_open')
        ->where('user_id', $ägare->id)
        ->orderBy('opened_at')
        ->pluck('attachment_id');

    expect($kvar)->toHaveCount(RecordAttachmentOpen::LIMIT)
        ->and($kvar)->not->toContain($bilagor->first()->id)
        ->and((int) $kvar->last())->toBe($bilagor->last()->id);
});

/*
 * --- Läsningen -----------------------------------------------------------
 */

it('listan visar de senaste i containern nyast först', function () {
    [, $ägare, $container] = senastKontext();

    [, $första] = senastFörberedelse($container, filnamn: 'a.pdf');
    [, $andra] = senastFörberedelse($container, filnamn: 'b.pdf');

    Carbon::setTestNow('2026-09-30 10:00:00');
    actingAs($ägare)->get("/files/{$första->ulid}")->assertOk();

    Carbon::setTestNow('2026-09-30 11:00:00');
    actingAs($ägare)->get("/files/{$andra->ulid}")->assertOk();

    Carbon::setTestNow();

    $rader = app(ListRecentOpens::class)->handle($ägare, $container);

    expect($rader->pluck('attachment_id')->all())->toBe([$andra->id, $första->id]);
});

it('en annan persons öppningar syns inte', function () {
    [$konto, $ägare, $container] = senastKontext();

    $medlem = User::factory()->create();
    $konto->users()->attach($medlem, ['role' => 'member']);

    [, $ägarens] = senastFörberedelse($container, filnamn: 'agarens.pdf');
    [, $medlemmens] = senastFörberedelse($container, filnamn: 'medlemmens.pdf');

    actingAs($ägare)->get("/files/{$ägarens->ulid}")->assertOk();
    actingAs($medlem)->get("/files/{$medlemmens->ulid}")->assertOk();

    expect(senastAntalRader())->toBe(2)
        ->and(app(ListRecentOpens::class)->handle($ägare, $container)->pluck('attachment_id')->all())
        ->toBe([$ägarens->id]);
});

it('en bilaga utanför omfånget eller i papperskorgen syns inte och syns igen när den kommer tillbaka', function () {
    [, $ägare, $container] = senastKontext();
    [$motorn, $bilaga] = senastFörberedelse($container);

    $gäst = User::factory()->create();

    $grant = ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $motorn->id,
        'grantee_type' => 'user',
        'grantee_id' => $gäst->id,
        'level' => AccessLevel::READ,
        'kind' => 'guest',
        'granted_by_user_id' => $ägare->id,
    ]);

    // `revoked_at` står med flit utanför `#[Fillable]` (se
    // App\Models\ContainerAccess), så den sätts som egenskap och sparas — ett
    // `update(['revoked_at' => …])` hade tigit och gjort ingenting, och
    // provet hade då passerat på en åtkomst som aldrig drogs in.
    $återkalla = function (bool $återkallad) use ($grant): void {
        $grant->revoked_at = $återkallad ? now() : null;
        $grant->save();
    };

    actingAs($gäst)->get("/files/{$bilaga->ulid}")->assertOk();

    // ResolveItemScope är `scoped()` och memoiserar sitt svar per användare
    // och container, även mellan anropen i EN testprocess — i drift är varje
    // request en egen process och memon alltid tom. Utan nollställningen
    // hade de fyra lägena nedan läst samma gamla omfång.
    $visas = function () use ($gäst, $container): array {
        app()->forgetScopedInstances();

        return app(ListRecentOpens::class)->handle($gäst, $container)->pluck('attachment_id')->all();
    };

    expect($visas())->toBe([$bilaga->id]);

    // Åtkomsten dras in. Raden står kvar — det är hela skillnaden mot att
    // filtrera vid skrivningen.
    $återkalla(true);

    expect($visas())->toBe([])
        ->and(senastAntalRader())->toBe(1);

    // Och blir synlig igen när åtkomsten kommer tillbaka.
    $återkalla(false);

    expect($visas())->toBe([$bilaga->id]);

    // Papperskorgen: samma sak, filtrerat i bilagans led i stället.
    $bilaga->delete();

    expect($visas())->toBe([])
        ->and(senastAntalRader())->toBe(1);

    $bilaga->restore();

    expect($visas())->toBe([$bilaga->id]);
});

/*
 * --- Raderingen ----------------------------------------------------------
 */

it('raderna raderas när bilagan rensas', function () {
    [$konto, $ägare, $container] = senastKontext();
    [, $bilaga] = senastFörberedelse($container);

    // Två personer har öppnat samma bilaga. Nyckeln gäller bilagan, så BÅDA
    // raderna ska bort — inte bara den som råkade gallra.
    $medlem = User::factory()->create();
    $konto->users()->attach($medlem, ['role' => 'member']);

    actingAs($ägare)->get("/files/{$bilaga->ulid}")->assertOk();
    actingAs($medlem)->get("/files/{$bilaga->ulid}")->assertOk();

    expect(senastAntalRader())->toBe(2);

    app(PurgeAttachment::class)->handle($bilaga);

    expect(senastAntalRader())->toBe(0)
        ->and(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeFalse();
});

it('raderna raderas när itemet rensas', function () {
    [, $ägare, $container] = senastKontext();
    [$item, $bilaga] = senastFörberedelse($container);

    actingAs($ägare)->get("/files/{$bilaga->ulid}")->assertOk();
    expect(senastAntalRader())->toBe(1);

    $item->delete();
    app(PurgeContent::class)->item($item);

    expect(senastAntalRader())->toBe(0)
        ->and(Item::withTrashed()->whereKey($item->id)->exists())->toBeFalse();
});

it('raderna raderas när containern rensas', function () {
    [, $ägare, $container] = senastKontext();
    [, $bilaga] = senastFörberedelse($container);

    actingAs($ägare)->get("/files/{$bilaga->ulid}")->assertOk();
    expect(senastAntalRader())->toBe(1);

    $container->delete();
    app(PurgeContainer::class)->handle($container);

    expect(senastAntalRader())->toBe(0)
        ->and(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse();
});

it('raderna raderas när kontot raderas', function () {
    [$konto, $ägare, $container] = senastKontext();
    [, $bilaga] = senastFörberedelse($container);

    actingAs($ägare)->get("/files/{$bilaga->ulid}")->assertOk();
    expect(senastAntalRader())->toBe(1);

    // DeleteAccount går genom PurgeContainer → PurgeAttachment och behöver
    // ingen egen rad för `attachment_open`. Provet står här för att hålla
    // det påståendet: faller kedjan någonstans fastnar hela raderingen på
    // RESTRICT-nyckeln och transaktionen rullas tillbaka.
    app(DeleteAccount::class)->handle($konto);

    expect(senastAntalRader())->toBe(0)
        ->and(Account::query()->whereKey($konto->id)->exists())->toBeFalse();
});

/*
 * --- Exporten ------------------------------------------------------------
 */

it('exporten bär inga öppningar', function () {
    [, $ägare, $container] = senastKontext();
    [, $bilaga] = senastFörberedelse($container);

    actingAs($ägare)->get("/files/{$bilaga->ulid}")->assertOk();
    expect(senastAntalRader())->toBe(1);

    $export = Export::factory()->create([
        'container_id' => $container->id,
        'requested_by_user_id' => $ägare->id,
    ]);

    (new BuildContainerExport($export))->handle();
    $export->refresh();

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('files')->path($export->storage_path)))->toBeTrue();

    $json = $zip->getFromName('container.json');
    $payload = json_decode((string) $json, true);

    // Ingen nyckel någonstans i nyttolasten, och bilageraden har exakt sina
    // sju fält — sex ur `attachmentEntry()` plus `path`, som byggaren lägger
    // till för en fil som finns på disken. Läggs `opened_at` till i byggaren
    // faller båda påståendena.
    expect($json)->not->toContain('opened_at')
        ->and($payload['items'][0]['attachments'][0])->toHaveCount(7)
        ->and($payload['items'][0]['attachments'][0])->toHaveKeys([
            'ulid', 'filename', 'kind', 'byte_size', 'mime_type', 'content_hash', 'path',
        ]);
});
