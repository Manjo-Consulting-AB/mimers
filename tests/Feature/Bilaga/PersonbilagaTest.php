<?php

use App\Actions\Attachment\CopyAttachment;
use App\Actions\Attachment\MoveAttachment;
use App\Actions\Trash\ListAllTrash;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Export;
use App\Models\ImageDerivative;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Export\ContainerExportBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 379 · En bilaga kan tillhöra en person — datamodellen, grinden och
 * leveransen. Se [[ADR-0076 Profilbilden]] § 1 och 4, [[M59 Profilbilden]]
 * § 379 och [[Filer och lagring]] § attachment.
 *
 * Filen prövar § 6 i issuen:
 *
 * 1. CHECK-villkoret nekar en rad med två eller noll ägare och godtar en med
 *    bara `user_id`. Villkoret finns bara på MariaDB — sqlite kan inte lägga
 *    till ett CHECK i en befintlig tabell — så provet hoppar över i sviten
 *    och bevisas av CI:s migreringsjobb, se .github/workflows/migreringar.yml.
 * 2. Leveransen ger 200 till personen själv, till en kontokamrat och till
 *    någon med en grant i en container där personen är medlem, och 403 till
 *    en främling.
 * 3. Papperskorgen, lagringsvyn och exporten visar inte en personbilaga.
 * 4. Flytt och kopia av en personbilaga ger 422.
 *
 * Formen (kolumnerna, nycklarna, `accessSubject()` och `owningContainer()`)
 * prövas först i filen. Skrivvägen (att SÄTTA en profilbild) är issue 380 och
 * ytan § 381; här byggs raden direkt med fabriken, precis som
 * ContainerbildTest bygger sina rader med DB::table() där actionen inte
 * räcker.
 *
 * Hjälparna har prefixet `personbilaga` — Pests funktioner delar namnrymd när
 * hela sviten körs.
 */

beforeEach(function () {
    withoutVite();

    // Inga byten får hamna i den riktiga storage/files/, och ingen
    // miniatyrgenerering ska köras i samma request (QUEUE_CONNECTION=sync).
    Queue::fake();
    Storage::fake('files');

    // Direkt leverans på appdomänen — samma miljö som ContainerbildBehorighetTest.
    config(['files.internal_redirect' => false, 'files.url' => null]);
});

/**
 * En person med ett eget personkonto, och personens profilbild — en bilaga
 * med `user_id` satt och pekaren `user.avatar_attachment_id` på plats.
 *
 * @return array{0: User, 1: Account, 2: Attachment}
 */
function personbilagaKontext(): array
{
    $person = User::factory()->create();

    // AccountFactory drar `type = personal` — samma konto som
    // App\Support\Account\ActingAccount letar upp (ADR-0076 § 3).
    $personkonto = Account::factory()->create();
    $personkonto->users()->attach($person, ['role' => 'owner']);

    return [$person, $personkonto, personbilagaSattBild($person, $personkonto)];
}

/**
 * Skapar profilbilden direkt, utan StoreAttachment — skrivvägen är issue 380.
 * Bytena läggs på den fejkade disken så att leveransen kan svara 200.
 */
function personbilagaSattBild(User $person, Account $konto, string $namn = 'avatar.png'): Attachment
{
    $byten = personbilagaPng();
    $stored = StoredFile::factory()->create([
        'mime_type' => 'image/png',
        'byte_size' => strlen($byten),
    ]);

    Storage::disk('files')->put($stored->storage_path, $byten);

    $bilaga = Attachment::factory()->create([
        'item_id' => null,
        'container_id' => null,
        'user_id' => $person->id,
        'stored_file_id' => $stored->id,
        'filename' => $namn,
        'kind' => 'image',
        'uploaded_by_user_id' => $person->id,
        'billed_account_id' => $konto->id,
    ]);

    // Pekaren sätts explicit — den ligger utanför #[Fillable], som
    // cover_attachment_id på containern.
    $person->avatar_attachment_id = $bilaga->id;
    $person->save();

    return $bilaga;
}

/**
 * En itembilaga med byten på den fejkade disken — exportprovet behöver en
 * riktig bilaga att jämföra personbilagan mot.
 */
function personbilagaItemBilaga(Item $item, Account $konto, User $person): Attachment
{
    $byten = 'manualens byten';
    $stored = StoredFile::factory()->create([
        'mime_type' => 'application/pdf',
        'byte_size' => strlen($byten),
    ]);

    Storage::disk('files')->put($stored->storage_path, $byten);

    return Attachment::factory()->create([
        'item_id' => $item->id,
        'stored_file_id' => $stored->id,
        'filename' => 'manual.pdf',
        'kind' => 'document',
        'uploaded_by_user_id' => $person->id,
        'billed_account_id' => $konto->id,
    ]);
}

/**
 * Miniatyren eller mediumbilden till en bilaga, med bytena på den fejkade
 * disken. Utan raden svarar AttachmentDelivery::storagePath() 404.
 */
function personbilagaDerivat(StoredFile $stored, string $variant): ImageDerivative
{
    $derivat = ImageDerivative::factory()->create([
        'stored_file_id' => $stored->id,
        'variant' => $variant,
        'storage_path' => $stored->storage_path.'_'.$variant.'.jpg',
        'byte_size' => strlen('derivatets byten'),
    ]);

    Storage::disk('files')->put($derivat->storage_path, 'derivatets byten');

    return $derivat;
}

function personbilagaPng(): string
{
    $bild = imagecreatetruecolor(20, 20);
    imagefilledrectangle($bild, 0, 0, 19, 19, imagecolorallocate($bild, 40, 90, 160));

    ob_start();
    imagepng($bild);
    $byten = (string) ob_get_clean();
    imagedestroy($bild);

    return $byten;
}

/**
 * Statuskoden ett anrop kastar, för de två actioner som svarar 422 i stället
 * för att returnera något.
 */
function personbilagaStatus(callable $anrop): int
{
    try {
        $anrop();
    } catch (Throwable $e) {
        if (method_exists($e, 'toResponse')) {
            return $e->toResponse(request())->getStatusCode();
        }

        throw $e;
    }

    return 200;
}

// --- Formen ---------------------------------------------------------------

/*
 * Klart när: `attachment.user_id` och `user.avatar_attachment_id` finns, med
 * rätt nycklar och index.
 *
 * `user_id` är nullbar med ON DELETE RESTRICT och indexet `(user_id,
 * deleted_at)` — samma form som de två andra ägarkolumnerna.
 * `avatar_attachment_id` är nullbar med ON DELETE SET NULL, som
 * `container.cover_attachment_id` (se migrationens docblock om varför
 * issuens ord "RESTRICT" viker för containerns form).
 */
it('har user_id med RESTRICT, avatar_attachment_id med SET NULL och rätt index', function () {
    $userId = collect(Schema::getColumns('attachment'))->firstWhere('name', 'user_id');

    expect($userId)->not->toBeNull();
    expect($userId['nullable'])->toBeTrue();

    $userIdNyckel = collect(Schema::getForeignKeys('attachment'))->firstWhere('columns', ['user_id']);

    expect($userIdNyckel)->not->toBeNull();
    expect($userIdNyckel['foreign_table'])->toBe('user');
    expect($userIdNyckel['on_delete'])->toBe('restrict');

    $index = collect(Schema::getIndexes('attachment'))
        ->first(fn (array $i): bool => $i['columns'] === ['user_id', 'deleted_at']);

    expect($index)->not->toBeNull();

    $pekare = collect(Schema::getColumns('user'))->firstWhere('name', 'avatar_attachment_id');

    expect($pekare)->not->toBeNull();
    expect($pekare['nullable'])->toBeTrue();

    $pekareNyckel = collect(Schema::getForeignKeys('user'))->firstWhere('columns', ['avatar_attachment_id']);

    expect($pekareNyckel)->not->toBeNull();
    expect($pekareNyckel['foreign_table'])->toBe('attachment');
    expect($pekareNyckel['on_delete'])->toBe('set null');
});

it('låter accessSubject svara med personen och owningContainer svara null', function () {
    [$person, , $bilaga] = personbilagaKontext();

    $subject = $bilaga->accessSubject();

    expect($subject)->toBeInstanceOf(User::class);
    expect($subject->is($person))->toBeTrue();
    expect($bilaga->owningContainer())->toBeNull();
});

/*
 * Klart när: CHECK-villkoret kräver exakt en av tre ägare.
 *
 * Villkoret finns bara på MariaDB. sqlite kan bara bära ett CHECK från
 * CREATE TABLE, och migrationen lägger till sitt i en befintlig tabell —
 * samma lucka som 2026_09_27_020000 beskriver. Provet hoppas därför över i
 * sviten och körs av CI:s migreringsjobb mot MariaDB 10.6, samma motor som
 * staging och produktion.
 */
it('CHECK-villkoret nekar två eller noll ägare och godtar bara user_id', function () {
    [$person, $konto] = personbilagaKontext();

    $stored = StoredFile::factory()->create();
    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $rad = fn (string $ulid, ?int $itemId, ?int $containerId, ?int $userId): array => [
        'ulid' => $ulid,
        'item_id' => $itemId,
        'container_id' => $containerId,
        'user_id' => $userId,
        'stored_file_id' => $stored->id,
        'filename' => 'bild.png',
        'kind' => 'image',
        'uploaded_by_user_id' => $person->id,
        'billed_account_id' => $konto->id,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    // Ingen ägare.
    expect(fn () => DB::table('attachment')->insert($rad('01AAAAAAAAAAAAAAAAAAAAAAAA', null, null, null)))
        ->toThrow(QueryException::class);

    // Två ägare, i alla tre par.
    expect(fn () => DB::table('attachment')->insert($rad('01BBBBBBBBBBBBBBBBBBBBBBBB', $item->id, $container->id, null)))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('attachment')->insert($rad('01CCCCCCCCCCCCCCCCCCCCCCCC', $item->id, null, $person->id)))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('attachment')->insert($rad('01DDDDDDDDDDDDDDDDDDDDDDDD', null, $container->id, $person->id)))
        ->toThrow(QueryException::class);

    // Exakt en ägare är de tre giltiga formerna.
    DB::table('attachment')->insert($rad('01EEEEEEEEEEEEEEEEEEEEEEEE', $item->id, null, null));
    DB::table('attachment')->insert($rad('01FFFFFFFFFFFFFFFFFFFFFFFF', null, $container->id, null));
    DB::table('attachment')->insert($rad('01GGGGGGGGGGGGGGGGGGGGGGGG', null, null, $person->id));

    expect(Attachment::withTrashed()->whereIn('ulid', [
        '01EEEEEEEEEEEEEEEEEEEEEEEE',
        '01FFFFFFFFFFFFFFFFFFFFFFFF',
        '01GGGGGGGGGGGGGGGGGGGGGGGG',
    ])->count())->toBe(3);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'mysql',
    'CHECK-villkoret finns bara på MariaDB; sviten kör sqlite.',
);

// --- Leveransen -----------------------------------------------------------

it('levererar profilbilden till personen själv', function () {
    [$person, , $bilaga] = personbilagaKontext();

    somAnvandare($person);

    get("/files/{$bilaga->ulid}")->assertOk();

    // Ingen öppningsrad: en personbilaga är ingen öppnad fil, samma undantag
    // som containerns bild har (ADR-0051 § Beslut 1).
    expect(DB::table('attachment_open')->count())->toBe(0);
});

it('levererar profilbilden till en kontokamrat', function () {
    [, $personkonto, $bilaga] = personbilagaKontext();

    $kamrat = User::factory()->create();
    $personkonto->users()->attach($kamrat, ['role' => 'member']);

    somAnvandare($kamrat);

    get("/files/{$bilaga->ulid}")->assertOk();
});

it('levererar profilbilden till den som har en grant i en gemensam container', function () {
    [$person, , $bilaga] = personbilagaKontext();

    // Personen är medlem i organisationen som äger containern; mottagaren når
    // den bara genom en grant. Båda når alltså containern — ADR-0076 § 4.
    $organisation = Account::factory()->organisation()->create();
    $organisation->users()->attach($person, ['role' => 'member']);

    $container = Container::factory()->for($organisation, 'account')->create();

    $mottagare = User::factory()->create();
    beviljaAccess($container, $mottagare, 'read', 'member');

    somAnvandare($mottagare);

    get("/files/{$bilaga->ulid}")->assertOk();
});

it('nekar profilbilden med 403 till en främling', function () {
    [, , $bilaga] = personbilagaKontext();

    // Ett eget konto, så det inte är avsaknaden av konton som nekar.
    [, $främling] = kontoMedMedlem();

    somAnvandare($främling);

    get("/files/{$bilaga->ulid}")->assertForbidden();
});

it('levererar thumb och medium för en personbilaga', function () {
    [$person, , $bilaga] = personbilagaKontext();

    personbilagaDerivat($bilaga->storedFile, 'thumb');
    personbilagaDerivat($bilaga->storedFile, 'medium');

    somAnvandare($person);

    get("/files/{$bilaga->ulid}?variant=thumb")->assertOk();
    get("/files/{$bilaga->ulid}?variant=medium")->assertOk();

    // Miniatyrer och medium räknas aldrig som öppningar — och en
    // personbilaga har ingen öppningsrad alls.
    expect(DB::table('attachment_open')->count())->toBe(0);
});

// --- Läsarna --------------------------------------------------------------

it('listar aldrig en personbilaga i papperskorgen', function () {
    [$person, , $bilaga] = personbilagaKontext();

    $bilaga->delete();

    $svar = app(ListAllTrash::class)->handle($person, null, null, null);

    expect(collect($svar['entries'])->pluck('ulid'))->not->toContain($bilaga->ulid);
});

it('visar inte personbilagan i lagringsvyn', function () {
    [$person, $konto, $bilaga] = personbilagaKontext();

    somAnvandare($person);

    get("/settings/storage?account={$konto->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Storage')
            ->has('attachments', 0));

    $svar = getJson("/api/accounts/{$konto->ulid}/storage")->assertOk();

    expect($svar->json('data'))->toBe([]);
    expect($svar->json('data'))->not->toContain($bilaga->ulid);
});

it('tar inte med personbilagan i containerexporten', function () {
    [$konto, $person] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $person->id,
        'created_by_account_id' => $konto->id,
    ]);

    $itembilaga = personbilagaItemBilaga($item, $konto, $person);
    $personbilaga = personbilagaSattBild($person, $konto);

    $export = Export::factory()->for($container)->create([
        'requested_by_user_id' => $person->id,
        'status' => Export::STATUS_PENDING,
        'storage_path' => null,
    ]);

    $sökväg = (new ContainerExportBuilder)->build($export);

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('files')->path($sökväg)))->toBeTrue();

    $payload = json_decode($zip->getFromName('container.json'), true);
    $ulider = array_column($payload['items'][0]['attachments'], 'ulid');

    expect($ulider)->toBe([$itembilaga->ulid]);
    expect($ulider)->not->toContain($personbilaga->ulid);
    expect($zip->getFromName('filer/'.$item->ulid.'/avatar.png'))->toBeFalse();

    $zip->close();
});

it('vägrar flytta och kopiera en personbilaga med 422', function () {
    [$person, $konto, $bilaga] = personbilagaKontext();

    $container = Container::factory()->for($konto, 'account')->create();
    $mål = Item::factory()->for($container, 'container')->create();

    expect(personbilagaStatus(fn () => app(MoveAttachment::class)->handle($bilaga, $mål, $person)))
        ->toBe(422);
    expect(personbilagaStatus(fn () => app(CopyAttachment::class)->handle($bilaga, $mål, $person)))
        ->toBe(422);

    // Ingenting skrevs: bilagan är kvar på personen och ingen kopia finns.
    expect($bilaga->refresh()->user_id)->toBe($person->id);
    expect(Attachment::withTrashed()->count())->toBe(1);
});
