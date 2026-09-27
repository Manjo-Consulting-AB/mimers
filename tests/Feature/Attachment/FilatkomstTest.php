<?php

// rott-pa-basen: issue 149 är en ren testissue — proven A1–A8 dokumenterar
// beteendet som redan är byggt, och ingen produktionskod ändras. Bas och head
// delar alltså applikationskod, och filen kan aldrig bli röd på basen.

use App\Actions\Access\RevokeContainerAccess;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 149 · Filåtkomsten bevisas över alla vägar in — proven A1–A8 i
 * [[Testplan filer]] § Del 2. Se [[ADR-0019 Filleverans]],
 * [[ADR-0028 Åtkomst på itemnivå]] och [[Filer och lagring]].
 *
 * Syskonfilerna prövar var sin gren av samma mekanism: NedladdningTest prövar
 * leveransen på appdomänen, FiloriginTest leveransen på filoriginet och
 * ItemgrindTest omfånget på itemnivå. Den här filen prövar invarianten —
 * "att en fil inte går att nå obehörigt" — genom de vägar in som INTE var
 * bevisade: varianten, den utgångna åtkomsten, det lämnade kontot, den
 * mjukraderade containern på varje yta, länken som överlever en återkallelse,
 * vad ett nekat svar bär, listorna, och resursernas fält.
 *
 * Ingen produktionskod rörs. Visar ett prov ett fel märks det `->todo()` med
 * en rad om vad det visar, och felet beskrivs i PR-kroppen — det blir en egen
 * issue. Ingenting i den här filen "lagar" alltså det den hittar.
 *
 * **Omfånget är en itemgrant på `motorn`.** Fixturen är platt med flit: två
 * items på toppnivå och ingen kant mellan dem, så "utanför omfånget" är
 * `masten` och ingenting annat. Slutningen nedåt prövas av ItemgrindTest och
 * OmfangsupplosningTest; det här provet behöver den inte.
 *
 * Hjälparna har prefixet filatkomst* för att inte krocka med de globala
 * hjälparna i andra Feature-filer (nedladdning* i NedladdningTest, filorigin*
 * i FiloriginTest, grind* i ItemgrindTest). Prefixet håller filen körbar för
 * sig: `php artisan test tests/Feature/Attachment` laddar bara den här
 * katalogen, och en hjälpare som bor i en annan fil vore då odefinierad.
 */

/**
 * Filoriginets bas-URL — [[ADR-0019 Filleverans]] § Uppföljning 2026-09-15.
 *
 * **Miljön sätts i `beforeAll`, inte i `beforeEach`.** `files.deliver`
 * registreras vid appens uppstart och bara när filoriginet är satt, och appen
 * byggs i Tests\TestCase::createApplication() — före varje `beforeEach`. Utan
 * det här vore `config('files.url')` satt i ett test utan att rutten finns,
 * och präglingen hade kastat RouteNotFoundException i stället för att neka.
 *
 * Samma mönster som NedladdningTest och FiloriginTest: hookarna kör före
 * respektive efter den här filens appar och ingen annans, så varje annan fil i
 * sviten möter miljön utan handpåläggning.
 */
function filatkomstBas(): string
{
    return 'https://files.test';
}

beforeAll(function () {
    putenv('FILES_URL='.filatkomstBas());
    $_ENV['FILES_URL'] = filatkomstBas();
    $_SERVER['FILES_URL'] = filatkomstBas();
});

afterAll(function () {
    putenv('FILES_URL');
    unset($_ENV['FILES_URL'], $_SERVER['FILES_URL']);
});

beforeEach(function () {
    Storage::fake('files');
    config([
        'files.internal_redirect' => false,
        'files.url' => filatkomstBas(),
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ägarkontot med sin medlem, en container och två items på toppnivå — `Motorn`
 * och `Masten`. Ingen kant mellan dem: en itemgrant på motorn når motorn och
 * ingenting annat, och masten är därmed det item som ligger utanför omfånget i
 * varje prov.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item, 4: Item}
 */
function filatkomstFixture(): array
{
    $konto = Account::factory()->create();
    $ägare = User::factory()->create();
    $konto->users()->attach($ägare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();

    return [
        $konto,
        $ägare,
        $container,
        filatkomstItem($container, $konto, $ägare, 'Motorn'),
        filatkomstItem($container, $konto, $ägare, 'Masten'),
    ];
}

/**
 * Ett item direkt i containern, med $konto/$ägare som skapare så raderna
 * hänger ihop.
 *
 * @param  array<string, mixed>  $attribut
 */
function filatkomstItem(Container $container, Account $konto, User $ägare, string $namn, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'name' => $namn,
        'created_by_user_id' => $ägare->id,
        'created_by_account_id' => $konto->id,
    ], $attribut));
}

/**
 * En bilaga med byten på den fejkade disken — redo att levereras.
 *
 * @return array{0: Attachment, 1: StoredFile}
 */
function filatkomstBilaga(
    Item $item,
    User $ägare,
    Account $konto,
    string $filnamn = 'manual.pdf',
    string $innehåll = 'originalets byten',
    string $mime = 'application/pdf',
): array {
    $storedFile = StoredFile::factory()->create([
        'mime_type' => $mime,
        'byte_size' => strlen($innehåll),
    ]);

    Storage::disk('files')->put($storedFile->storage_path, $innehåll);

    $bilaga = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'filename' => $filnamn,
        'kind' => str_starts_with($mime, 'image/') ? 'image' : 'document',
        'uploaded_by_user_id' => $ägare->id,
        'billed_account_id' => $konto->id,
    ]);

    return [$bilaga, $storedFile];
}

/**
 * Miniatyren till en bilaga, med bytena på den fejkade disken. Finns varianten
 * är ett 404 för den ett svar av ett annat skäl än "miniatyren saknas" — det
 * är vad A1 och A4 behöver.
 */
function filatkomstDerivat(StoredFile $storedFile, string $variant = 'thumb'): ImageDerivative
{
    $derivat = ImageDerivative::factory()->create([
        'stored_file_id' => $storedFile->id,
        'variant' => $variant,
        'storage_path' => $storedFile->storage_path.'_'.$variant.'.jpg',
        'byte_size' => strlen('miniatyrens byten'),
    ]);

    Storage::disk('files')->put($derivat->storage_path, 'miniatyrens byten');

    return $derivat;
}

/**
 * En grant till $mottagare — container-bred när $item är null, annars en
 * itemgrant på just det itemet ([[ADR-0028 Åtkomst på itemnivå]] § Beslut).
 * `kind` är `member`: en `managed`-rad hade dragit in den mottagande kontots
 * frysning i proven, och det är inte den frågan de ställer.
 */
function filatkomstGrant(
    Container $container,
    User $mottagare,
    string $nivå,
    ?Item $item = null,
    ?Carbon $expiresAt = null,
): ContainerAccess {
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $nivå,
        'kind' => 'member',
        'expires_at' => $expiresAt,
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * Gör nästa begäran kall: användarens konton läses om och
 * App\Actions\Access\ResolveItemScope — registrerad `scoped()` — tappar sina
 * memon. I drift är varje request en egen process och memon alltid tom; i en
 * testprocess överlever den mellan anropen, och ett prov som ändrar
 * åtkomsten mitt i hade annars mätt den gamla.
 */
function filatkomstKall(User $user): void
{
    $user->unsetRelation('accounts');
    app()->forgetScopedInstances();
}

/**
 * Invariant 5 i [[Testplan filer]] § Del 2 som ett påstående: svaret bär
 * varken filnamn, typ, storlek eller något ur lagringen.
 *
 * Filnamnet och typen letas i kroppen, och storleken letas som ett eget tal —
 * en rå `toContain('2048')` hade kunnat matcha en rad i en felsida. Rubrikerna
 * prövas var för sig: ett nekat svar ska inte bära dispositionen (filnamnet),
 * den lagrade typen eller bytenas längd.
 */
function filatkomstLäckerInte(TestResponse $svar, Attachment $bilaga, StoredFile $storedFile): void
{
    $kropp = (string) $svar->getContent();

    // Kroppen finns. Ett tomt svar hade gjort varje frånvaro nedan till en
    // frånvaro av ingenting.
    expect($kropp)->not->toBe('');

    expect($kropp)->not->toContain($bilaga->filename);
    expect($kropp)->not->toContain($storedFile->mime_type);
    expect($kropp)->not->toContain($storedFile->content_hash);
    expect($kropp)->not->toContain($storedFile->storage_path);
    expect($kropp)->not->toMatch('/(?<![0-9])'.$storedFile->byte_size.'(?![0-9])/');

    expect($svar->headers->get('content-disposition'))->toBeNull();
    expect((string) $svar->headers->get('content-type'))->not->toContain($storedFile->mime_type);
    expect((string) $svar->headers->get('content-length'))->not->toBe((string) $storedFile->byte_size);
}

// --- A1: omfånget gäller varianten också -----------------------------------

it('A1 · variant=thumb på ett item utanför omfånget ger 403 och präglar ingen länk', function () {
    [$konto, $ägare, $container, $motor, $mast] = filatkomstFixture();
    [, $mottagare] = kontoMedMedlem();

    filatkomstGrant($container, $mottagare, AccessLevel::READ, $motor);

    [$påMotorn, $motorFil] = filatkomstBilaga($motor, $ägare, $konto, 'motorn.jpg', 'motorns byten', 'image/jpeg');
    [$påMasten, $mastFil] = filatkomstBilaga($mast, $ägare, $konto, 'masten.jpg', 'mastens byten', 'image/jpeg');

    // Miniatyren finns på BÅDA. Det är vad som gör provet till ett
    // omfångsprov: ett 404 för en saknad miniatyr hade varit samma svar av ett
    // helt annat skäl (NedladdningTest § en saknad variant).
    filatkomstDerivat($motorFil, 'thumb');
    filatkomstDerivat($mastFil, 'thumb');

    // Kontrollen: samma begäran på itemet inom omfånget präglar en länk med
    // varianten i sig.
    $inom = actingAs($mottagare)->get("/files/{$påMotorn->ulid}?variant=thumb");

    $inom->assertRedirect();

    parse_str((string) parse_url((string) $inom->headers->get('location'), PHP_URL_QUERY), $fråga);
    expect($fråga['variant'] ?? null)->toBe('thumb');

    // Utanför omfånget: 403, och ingen länk — präglingen ligger efter grinden
    // (AttachmentDownloadController § Behörighetsprövningen sker här).
    $utanför = actingAs($mottagare)->get("/files/{$påMasten->ulid}?variant=thumb");

    $utanför->assertForbidden();
    $utanför->assertHeaderMissing('location');
});

// --- A2: en utgången åtkomst ----------------------------------------------

it('A2 · en utgången tidsbegränsad åtkomst nekas, både vid leveransen och vid präglingen', function () {
    [$konto, $ägare, $container, $motor] = filatkomstFixture();
    [, $mottagare] = kontoMedMedlem();

    // Samma rad som den återkallade i NedladdningTest, med den andra spärren:
    // expires_at passerat. Giltighetsvillkoret bor i
    // ContainerAccess::scopeValidFor(), som omfångsupplösningen frågar.
    filatkomstGrant($container, $mottagare, AccessLevel::READ, expiresAt: now()->subMinute());

    [$bilaga] = filatkomstBilaga($motor, $ägare, $konto, 'manual.pdf', 'manualens byten');

    // Ingen egen origin: rutten levererar bytena själv — och nekar.
    config(['files.url' => null]);
    actingAs($mottagare)->get("/files/{$bilaga->ulid}")->assertForbidden();

    // Egen origin: rutten präglar en kortlivad signerad länk i stället — och
    // nekar innan någon länk blir till.
    config(['files.url' => filatkomstBas()]);
    $svar = actingAs($mottagare)->get("/files/{$bilaga->ulid}");

    $svar->assertForbidden();
    $svar->assertHeaderMissing('location');
});

// --- A3: ett konto som lämnats --------------------------------------------

it('A3 · en användare vars account_user-rad tagits bort når inte längre kontots bilagor', function () {
    [$konto, $ägare, $container, $motor] = filatkomstFixture();

    $lämnad = User::factory()->create();
    $konto->users()->attach($lämnad, ['role' => 'member']);

    [$bilaga] = filatkomstBilaga($motor, $ägare, $konto, 'manual.pdf', 'manualens byten');

    config(['files.url' => null]);

    // Utgångsläget: som medlem i ägarkontot når hon hela containern
    // ([[Konton och åtkomst]] § Behörighetsregler regel 1).
    $före = actingAs($lämnad)->get("/files/{$bilaga->ulid}");

    $före->assertOk();
    expect($före->streamedContent())->toBe('manualens byten');

    // Medlemskapet dras tillbaka. Någon grant finns inte, så det finns ingen
    // andra väg in: raden i account_user ÄR behörigheten.
    $konto->users()->detach($lämnad->id);
    filatkomstKall($lämnad);

    actingAs($lämnad)->get("/files/{$bilaga->ulid}")->assertForbidden();
});

// --- A4: en mjukraderad container -----------------------------------------

it('A4 · en bilaga i en mjukraderad container ger 404 för varianten, för token och i listan', function () {
    [$konto, $ägare, $container, $motor] = filatkomstFixture();

    [$bilaga, $storedFile] = filatkomstBilaga($motor, $ägare, $konto, 'motorn.jpg', 'motorns byten', 'image/jpeg');
    filatkomstDerivat($storedFile, 'thumb');

    $token = $ägare->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    $container->delete();

    // Appdomänen med varianten: containern prövas före varianten, så svaret är
    // 404 — inte en leverans av miniatyren.
    $medVariant = actingAs($ägare)->get("/files/{$bilaga->ulid}?variant=thumb");

    $medVariant->assertNotFound();
    $medVariant->assertHeaderMissing('location');

    // Samma fil genom API-klientens väg — tokenet i stället för sessionen.
    // Rutten ligger inte under /api, men det är samma nedladdning, och en
    // API-klient ska möta samma svar som webbläsaren.
    get("/files/{$bilaga->ulid}", $headers)->assertNotFound();

    // Och listan över bilagor når inte heller fram: containern är mjukraderad,
    // så bindningen löser aldrig upp den.
    getJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/attachments", $headers)
        ->assertNotFound();
});

// --- A5: länken som överlever en återkallelse -----------------------------

it('A5 · en länk som präglats före en återkallelse fungerar till den går ut', function () {
    [$konto, $ägare, $container, $motor] = filatkomstFixture();
    [, $mottagare] = kontoMedMedlem();

    $access = filatkomstGrant($container, $mottagare, AccessLevel::READ);
    [$bilaga] = filatkomstBilaga($motor, $ägare, $konto, 'manual.pdf', 'manualens byten');

    // Länken präglas medan åtkomsten gäller.
    $prägling = actingAs($mottagare)->get("/files/{$bilaga->ulid}");

    $prägling->assertRedirect();

    $länk = (string) $prägling->headers->get('location');

    // Åtkomsten återkallas: nästa begäran på appdomänen nekas, för där finns
    // en användare att pröva mot.
    app(RevokeContainerAccess::class)->handle($ägare, $container, $access);
    filatkomstKall($mottagare);

    actingAs($mottagare)->get("/files/{$bilaga->ulid}")->assertForbidden();

    // Men länken lever vidare. Signaturen är den ENDA grinden på filoriginet
    // ([[ADR-0019 Filleverans]] § Beslut 1 och 4): den som har länken får
    // filen till den går ut, högst femton minuter
    // (config files.signed_url_ttl_minutes). Provet dokumenterar gränsen —
    // ändrar någon livstiden, eller lägger en behörighetsprövning på originet,
    // ska det synas här.
    $leverans = get($länk);

    $leverans->assertOk();
    expect($leverans->streamedContent())->toBe('manualens byten');
});

// --- A6: vad ett nekat svar bär -------------------------------------------

it('A6 · 403 och 404 läcker varken filnamn, typ eller storlek', function () {
    [$konto, $ägare, $container, $motor] = filatkomstFixture();
    [, $främmande] = kontoMedMedlem();

    [$bilaga, $storedFile] = filatkomstBilaga(
        $motor,
        $ägare,
        $konto,
        'hemlig-kundmanual.pdf',
        'byten som ingen obehörig ska se',
        'application/x-hemlig-handling',
    );

    // En storlek som inte råkar stå i en felsida: provet letar efter talet som
    // ett eget ord, och en liten siffra hade kunnat matcha en rad i en
    // stacktrace. Bytena på disken är fortfarande de riktiga.
    $storedFile->update(['byte_size' => 4194304]);

    $nekad = actingAs($främmande)->get("/files/{$bilaga->ulid}");

    $nekad->assertForbidden();
    filatkomstLäckerInte($nekad, $bilaga, $storedFile);

    // Att 403 och 404 skiljer sig åt — och därmed avslöjar att ULID:n finns —
    // är accepterat: en ULID går inte att gissa, och skillnaden ser likadan ut
    // på varje rutt i systemet ([[Testplan filer]] § Del 2, A6).
    $bilaga->delete();

    $saknad = actingAs($främmande)->get("/files/{$bilaga->ulid}");

    $saknad->assertNotFound();
    filatkomstLäckerInte($saknad, $bilaga, $storedFile);
});

// --- A7: listorna ---------------------------------------------------------

it('A7 · ingen av listorna tar med en bilaga på ett item utanför omfånget', function () {
    withoutVite();

    [$konto, $ägare, $container, $motor, $mast] = filatkomstFixture();
    [$mottagarKonto, $mottagare, $headers] = kontoMedMedlem();

    filatkomstGrant($container, $mottagare, AccessLevel::READ, $motor);

    filatkomstBilaga($motor, $ägare, $konto, 'motorns-manual.pdf', 'motorns byten');
    filatkomstBilaga($mast, $ägare, $konto, 'mastens-manual.pdf', 'mastens byten');

    // Itemets bilagelista: motorn är inom omfånget, masten inte — och en tom
    // lista finns inte som svar, mastens rutt nekas helt.
    getJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/attachments", $headers)
        ->assertOk()
        ->assertJsonCount(1, 'data');

    getJson("/api/containers/{$container->ulid}/items/{$mast->ulid}/attachments", $headers)
        ->assertForbidden();

    actingAs($mottagare)->get("/containers/{$container->ulid}/items/{$mast->ulid}")->assertForbidden();

    // Exporten. Grinden är containerns `view`, och en itemgrant passerar den
    // (ContainerPolicy::view sätter aldrig ett item_id-filter) — beställningen
    // går alltså igenom. Innehållet följer ändå beställarens omfång
    // (ContainerExportBuilder § issue 74), och det är vad provet mäter.
    Queue::fake();

    $beställning = postJson("/api/containers/{$container->ulid}/exports", [], $headers);
    $beställning->assertStatus(202);

    $export = Export::query()->where('ulid', $beställning->json('data.ulid'))->firstOrFail();
    (new BuildContainerExport($export))->handle();
    $export->refresh();

    expect($export->status)->toBe(Export::STATUS_READY);

    // ZipArchive skrivs utan import: filen har ingen namnrymd, så namnet är
    // det globala, och en `use ZipArchive;` vore en no-op som PHP varnar för.
    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('files')->path($export->storage_path)))->toBeTrue();

    // Motorns bilaga är med — annars hade provet kunnat passera på en tom
    // export — och mastens finns varken som fil eller som rad.
    expect($zip->getFromName('filer/'.$motor->ulid.'/motorns-manual.pdf'))->toBe('motorns byten');
    expect($zip->locateName('filer/'.$mast->ulid.'/mastens-manual.pdf'))->toBeFalse();

    $payload = json_decode((string) $zip->getFromName('container.json'), true);
    expect(array_column($payload['items'], 'ulid'))->toBe([$motor->ulid]);

    $zip->close();

    // Lagringsvyn. Det belastade kontot är ägarkontot, och hon är inte medlem
    // där — ytan är kontots, och medlemskapet är grinden.
    actingAs($mottagare)->get("/settings/storage?account={$konto->ulid}")->assertForbidden();

    // Hennes egen lagringsvy visar hennes eget kontos bilagor. Den får en egen
    // bilaga så listan inte är tom: provet ska visa att hennes vy bär hennes
    // fil och inte ägarkontots, inte att en tom lista är tom.
    $egenContainer = Container::factory()->for($mottagarKonto, 'account')->create();
    $egetItem = filatkomstItem($egenContainer, $mottagarKonto, $mottagare, 'Egna prylen');
    filatkomstBilaga($egetItem, $mottagare, $mottagarKonto, 'egna-manualen.pdf', 'egna byten');

    $egen = actingAs($mottagare)->get('/settings/storage');

    $egen->assertOk();

    $egnaRader = $egen->inertiaProps('attachments');

    expect($egnaRader)->toHaveCount(1);
    expect($egnaRader[0]['filename'])->toBe('egna-manualen.pdf');
    expect($egen->getContent())->not->toContain('mastens-manual.pdf');
});

// --- A8: resursernas fält -------------------------------------------------

it('A8 · ingen resurs som bär en bilaga innehåller content_hash eller storage_path', function () {
    withoutVite();

    [, $ägare, $container, $motor] = filatkomstFixture();

    filatkomstBilaga($motor, $ägare, $container->account, 'manual.pdf', 'manualens byten');

    // /api:s bilagelista (AttachmentResource) är redan bevisad av
    // BilagelistaTest — *svaret bär aldrig ett löpnummer* — och skrivs inte om
    // här. Kvar var de två ytor som inte var det: webbens bilagelista, som är
    // samma resurs genom itemets detaljvy, och StorageEntryResource i
    // lagringsvyn. Nyckeluppsättningen prövas hel, inte som en svartlista: ett
    // fält som läggs till syns här.
    $detaljvy = actingAs($ägare)->get("/containers/{$container->ulid}/items/{$motor->ulid}");

    $detaljvy->assertOk();

    $bilagerad = $detaljvy->inertiaProps('attachments');

    expect($bilagerad)->toHaveCount(1);
    expect(array_keys($bilagerad[0]))->toBe([
        'ulid',
        'filename',
        'kind',
        'mime_type',
        'byte_size',
        'billed_account',
        'created_at',
        'updated_at',
    ]);

    $lagringsvy = actingAs($ägare)->get('/settings/storage');

    $lagringsvy->assertOk();

    $lagringsrad = $lagringsvy->inertiaProps('attachments');

    expect($lagringsrad)->toHaveCount(1);
    expect(array_keys($lagringsrad[0]))->toBe([
        'ulid',
        'filename',
        'byte_size',
        'kind',
        'container',
        'item',
        'created_at',
        'inTrash',
    ]);
});
