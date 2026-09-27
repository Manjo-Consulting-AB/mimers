<?php

use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Container\RemoveContainerCover;
use App\Actions\Container\SetContainerCover;
use App\Actions\Trash\PurgeContainer;
use App\Actions\Trash\PurgeContent;
use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 158 · En bilaga kan tillhöra en container — datamodellen och
 * åtgärderna. Se [[ADR-0047 Containerns bild]] och [[M23 Mobilen och kartan]]
 * § 158.
 *
 * Filen prövar formen och åtgärderna: kolumnerna, CHECK-villkoret,
 * SetContainerCover och RemoveContainerCover, och referensräkningen vid ett
 * byte. Syskonfilen ContainerbildBehorighetTest prövar VEM som får — grinden,
 * leveransen och 404:an. Trash/, Export/, Konto/ och Agarbyte/ prövar vad som
 * händer med bilden när containern rensas, säljs eller kontot raderas.
 *
 * Tre saker är värda att se efter:
 *
 * 1. **CHECK-villkoret körs bara mot MariaDB.** sqlite kan inte lägga till ett
 *    CHECK i efterhand, så provet hoppar över där — och CI:s migreringsjobb
 *    kör därför den här filen mot MariaDB, se .github/workflows/migreringar.yml.
 *    Ett prov som aldrig kör är ingen bevisning.
 * 2. **Byten räknas mot det UPPLADDANDE kontot** och `reference_count` rörs
 *    bara av den gamla bildens rensning — exakt en gång.
 * 3. **En containerbild är alltid en bild.** Ett dokument avvisas av
 *    StoreAttachment::handleForContainer och lämnar varken rad, loggrad eller
 *    byten på disken.
 *
 * Hjälparna har prefixet `containerbild` — Pests funktioner delar namnrymd när
 * hela sviten körs.
 */

beforeEach(function () {
    withoutVite();

    // Queue::fake() — annars kör GenerateImageDerivatives i samma request
    // (QUEUE_CONNECTION=sync i phpunit.xml) och ritar miniatyrer av bilder
    // provet inte handlar om. Inga byten får heller hamna i den riktiga
    // storage/files/, samma mönster som MiniatyrerTest och UppladdningTest.
    Queue::fake();
    Storage::fake('files');
});

/**
 * Ett konto med en medlem, och en container ägd av kontot.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function containerbildKontext(): array
{
    [$konto, $anvandare] = kontoMedMedlem();

    return [$konto, $anvandare, Container::factory()->for($konto, 'account')->create()];
}

/**
 * Deterministiska byten med en bestämd färg — samma teknik som
 * miniatyrPng i MiniatyrerTest. Två anrop med olika färg ger olika
 * `content_hash`, så ett bildbyte kan skilja de två `stored_file`-raderna åt.
 */
function containerbildPng(int $röd, int $grön, int $blå): string
{
    $bild = imagecreatetruecolor(20, 20);
    $färg = imagecolorallocate($bild, $röd, $grön, $blå);
    imagefilledrectangle($bild, 0, 0, 19, 19, $färg);

    ob_start();
    imagepng($bild);
    $byten = (string) ob_get_clean();
    imagedestroy($bild);

    return $byten;
}

/**
 * En uppladdad bild. `getMimeType()` i Illuminate\Http\Testing\File läser
 * filNAMNET och inte innehållet, så ändelsen är det som avgör `kind` här —
 * samma väg som `createWithContent` tar i UppladdningTest.
 */
function containerbildFil(string $namn = 'bild.png', int $röd = 255, int $grön = 0, int $blå = 0): UploadedFile
{
    return UploadedFile::fake()->createWithContent($namn, containerbildPng($röd, $grön, $blå));
}

/**
 * Sätter containerns bild genom actionen, som den inloggade medlemmen.
 */
function containerbildSätt(User $användare, Container $container, Account $konto, UploadedFile $fil): Attachment
{
    actingAs($användare);

    return app(SetContainerCover::class)->handle($container, $fil, $användare, $konto);
}

/*
 * Klart när: kolumnerna finns med rätt form.
 *
 * `attachment.item_id` blir NULLBAR, `attachment.container_id` är nullbar mot
 * `container` med ON DELETE RESTRICT, och `container.cover_attachment_id` är
 * nullbar mot `attachment` med ON DELETE SET NULL — samma avvikelse från
 * husets RESTRICT som itemets omslag, och av samma skäl: en preferens får
 * aldrig hindra papperskorgens gallring ([[ADR-0041 Itemets vy]]
 * § Konsekvenser).
 */
it('har en nullbar item_id, en container_id med RESTRICT och en nullbar pekare på containern', function () {
    $itemId = collect(Schema::getColumns('attachment'))->firstWhere('name', 'item_id');
    $containerId = collect(Schema::getColumns('attachment'))->firstWhere('name', 'container_id');

    expect($itemId['nullable'])->toBeTrue();
    expect($containerId)->not->toBeNull();
    expect($containerId['nullable'])->toBeTrue();

    $containerNyckel = collect(Schema::getForeignKeys('attachment'))->firstWhere('columns', ['container_id']);

    expect($containerNyckel)->not->toBeNull();
    expect($containerNyckel['foreign_table'])->toBe('container');
    expect($containerNyckel['on_delete'])->toBe('restrict');

    $pekare = collect(Schema::getColumns('container'))->firstWhere('name', 'cover_attachment_id');

    expect($pekare)->not->toBeNull();
    expect($pekare['nullable'])->toBeTrue();

    $pekareNyckel = collect(Schema::getForeignKeys('container'))->firstWhere('columns', ['cover_attachment_id']);

    expect($pekareNyckel)->not->toBeNull();
    expect($pekareNyckel['foreign_table'])->toBe('attachment');
    expect($pekareNyckel['on_delete'])->toBe('set null');
});

/*
 * Klart när: migreringen går på MariaDB och CHECK-villkoret avvisar en rad
 * med båda eller ingen satt.
 *
 * Provet hoppar över på sqlite, där villkoret inte finns: sqlite kan bara
 * bära ett CHECK från CREATE TABLE och 2026_09_27_020000 lägger till sitt i
 * en befintlig tabell (samma lucka som 2026_09_10_030000 beskriver). CI:s
 * migreringsjobb kör den här filen mot MariaDB 10.6, samma motor som
 * staging och produktion — se .github/workflows/migreringar.yml.
 *
 * Raden sätts med DB::table() och inte genom en action: actionen sätter
 * alltid exakt en av kolumnerna, och det är just det villkoret ska prövas
 * runt omkring.
 */
it('CHECK-villkoret avvisar en rad med båda eller ingen satt', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('CHECK-villkoret finns bara på MariaDB; sviten kör sqlite.');
    }

    [$konto, $användare, $container] = containerbildKontext();

    $stored = StoredFile::factory()->create();
    $item = Item::factory()->for($container, 'container')->create();

    // Ett ULID per rad; `ulid` är unikt och en kollision hade varit ett annat
    // fel än det villkoret ska ge.
    $rad = fn (string $ulid, ?int $itemId, ?int $containerId): array => [
        'ulid' => $ulid,
        'item_id' => $itemId,
        'container_id' => $containerId,
        'stored_file_id' => $stored->id,
        'filename' => 'bild.png',
        'kind' => 'image',
        'uploaded_by_user_id' => $användare->id,
        'billed_account_id' => $konto->id,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    // Ingen satt.
    expect(fn () => DB::table('attachment')->insert($rad('01AAAAAAAAAAAAAAAAAAAAAAAA', null, null)))
        ->toThrow(QueryException::class);

    // Båda satta.
    expect(fn () => DB::table('attachment')->insert($rad('01BBBBBBBBBBBBBBBBBBBBBBBB', $item->id, $container->id)))
        ->toThrow(QueryException::class);

    // Exakt en satt är de två giltiga formerna.
    DB::table('attachment')->insert($rad('01CCCCCCCCCCCCCCCCCCCCCCCC', $item->id, null));
    DB::table('attachment')->insert($rad('01DDDDDDDDDDDDDDDDDDDDDDDD', null, $container->id));

    expect(Attachment::withTrashed()->count())->toBe(2);
});

/*
 * Klart när: en containerbild kan sättas.
 *
 * Bilagan hamnar på containern och INTE på ett item, pekaren sätts, och
 * `kind` blir `image` ur den sniffade typen. `billed_account_id` är det
 * uppladdande kontot — samma regel som för en itembilaga.
 */
it('sätter containerns bild med container_id satt och ingen item_id', function () {
    [$konto, $användare, $container] = containerbildKontext();

    $bilaga = containerbildSätt($användare, $container, $konto, containerbildFil());

    expect($bilaga->container_id)->toBe($container->id);
    expect($bilaga->item_id)->toBeNull();
    expect($bilaga->kind)->toBe('image');
    expect($bilaga->billed_account_id)->toBe($konto->id);
    expect($container->refresh()->cover_attachment_id)->toBe($bilaga->id);
    expect($bilaga->storedFile->reference_count)->toBe(1);
});

/*
 * Klart när: ett dokument avvisas.
 *
 * En containerbilaga är alltid en bild (ADR-0047 § Beslut, tredje stycket).
 * Avvisandet sker på den SNIFFADE typen och FÖRE varje skrivning: ingen rad,
 * ingen `stored_file`, inga byten på disken och ingen loggrad. Provet vaktar
 * alla fyra, för det är hela skillnaden mot att städa efteråt.
 */
it('avvisar ett dokument och lämnar ingenting efter sig', function () {
    [$konto, $användare, $container] = containerbildKontext();

    actingAs($användare);

    expect(fn () => app(SetContainerCover::class)->handle(
        $container,
        UploadedFile::fake()->createWithContent('manual.pdf', 'en manual'),
        $användare,
        $konto,
    ))->toThrow(ApiException::class);

    expect(Attachment::withTrashed()->count())->toBe(0);
    expect(StoredFile::count())->toBe(0);
    expect($container->refresh()->cover_attachment_id)->toBeNull();
});

/*
 * Klart när: en containerbild kan bytas, och att byta bild minskar den gamla
 * filens reference_count exakt en gång.
 *
 * Den gamla bilagan RENSAS — den går inte till papperskorgen (ADR-0047
 * § Beslut, fjärde stycket) — så raden är borta på riktigt och räknaren har
 * gått från ett till noll, en gång. Den nya bildens fil är en annan, så de två
 * `stored_file`-raderna är skilda.
 */
it('byter bild och minskar den gamla filens reference_count exakt en gång', function () {
    [$konto, $användare, $container] = containerbildKontext();

    $första = containerbildSätt($användare, $container, $konto, containerbildFil('första.png', 255, 0, 0));
    $förstaFil = $första->storedFile;

    $andra = containerbildSätt($användare, $container, $konto, containerbildFil('andra.png', 0, 0, 255));

    expect($andra->id)->not->toBe($första->id);
    expect($container->refresh()->cover_attachment_id)->toBe($andra->id);

    expect(Attachment::withTrashed()->whereKey($första->id)->exists())->toBeFalse();
    expect($förstaFil->refresh()->reference_count)->toBe(0);
    expect($andra->storedFile->refresh()->reference_count)->toBe(1);
});

/*
 * Klart när: en containerbild kan tas bort.
 *
 * Pekaren nollställs och bilagan rensas. Ett andra anrop är en no-op och
 * svarar false — ytan ska kunna visa "ta bort bilden" utan att först veta om
 * det finns någon.
 */
it('tar bort bilden, nollställer pekaren och tål att köras igen', function () {
    [$konto, $användare, $container] = containerbildKontext();

    $bilaga = containerbildSätt($användare, $container, $konto, containerbildFil());
    $fil = $bilaga->storedFile;

    actingAs($användare);

    expect(app(RemoveContainerCover::class)->handle($container))->toBeTrue();

    expect($container->refresh()->cover_attachment_id)->toBeNull();
    expect(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeFalse();
    expect($fil->refresh()->reference_count)->toBe(0);

    expect(app(RemoveContainerCover::class)->handle($container))->toBeFalse();
});

/*
 * Klart när: samma bild på två containers delar en stored_file.
 *
 * Dedupen hänger på `stored_file` och gäller en containerbild som varje annan
 * bilaga (ADR-0047 § Beslut, femte stycket). Räknaren blir två, och den enas
 * borttagning lämnar den andras byten i fred — det är invariant 1 och 2 i
 * [[Testplan filer]] § Del 1, nu över containergränsen.
 */
it('samma bild på två containers delar en stored_file', function () {
    [$konto, $användare, $förstaContainer] = containerbildKontext();
    $andraContainer = Container::factory()->for($konto, 'account')->create();

    $byten = containerbildPng(0, 255, 0);

    $första = containerbildSätt($användare, $förstaContainer, $konto, UploadedFile::fake()->createWithContent('båt.png', $byten));
    $andra = containerbildSätt($användare, $andraContainer, $konto, UploadedFile::fake()->createWithContent('båt.png', $byten));

    expect($andra->stored_file_id)->toBe($första->stored_file_id);
    expect(StoredFile::count())->toBe(1);
    expect($första->storedFile->refresh()->reference_count)->toBe(2);

    actingAs($användare);
    app(RemoveContainerCover::class)->handle($förstaContainer);

    expect($första->storedFile->refresh()->reference_count)->toBe(1);
    expect(Storage::disk('files')->exists($första->storedFile->storage_path))->toBeTrue();
});

/*
 * Klart när: resurserna klarar `item_id = NULL`.
 *
 * Lagringslistan visar kontots bilagor, och en containerbild är en av dem —
 * den belastar det uppladdande kontot precis som en itembilaga. Raden får
 * `item: null` och containern ur bilagans egen kolumn i stället. Provet täcker
 * båda ytorna: webbens sida och `/api`, som delar StorageEntryResource.
 *
 * Ett svar utan fel är hela beviset — före issue 158 hade `$this->item->name`
 * fällt sidan på en bilaga utan item.
 */
it('visar en containerbilaga i lagringslistan med item satt till null', function () {
    [$konto, $användare, $container] = containerbildKontext();
    $bilaga = containerbildSätt($användare, $container, $konto, containerbildFil());

    actingAs($användare);

    get("/settings/storage?account={$konto->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Storage')
            ->has('attachments', 1)
            ->where('attachments.0.ulid', $bilaga->ulid)
            ->where('attachments.0.item', null)
            ->where('attachments.0.container.ulid', $container->ulid));

    $svar = getJson("/api/accounts/{$konto->ulid}/storage")->assertOk();

    expect($svar->json('data.0.ulid'))->toBe($bilaga->ulid);
    expect($svar->json('data.0.item'))->toBeNull();
    expect($svar->json('data.0.container.ulid'))->toBe($container->ulid);
});

/*
 * Klart när: en container som rensas tar sin bild med sig.
 *
 * PurgeContainer når containerns bild genom bilagans EGEN kolumn — den enda
 * bilaga `item()`-loopen inte når — och `attachment.container_id` är
 * RESTRICT, så utan den raden hade `forceDelete()` fallit varje natt.
 */
it('tar med sig bilden när containern gallras', function () {
    [$konto, $användare, $container] = containerbildKontext();

    $bilaga = containerbildSätt($användare, $container, $konto, containerbildFil());
    $fil = $bilaga->storedFile;

    $container->delete();

    (new PurgeContainer(new PurgeContent(new PurgeAttachment)))->handle($container);

    expect(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeFalse();
    expect(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse();
    expect($fil->refresh()->reference_count)->toBe(0);
});
