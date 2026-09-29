<?php

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\ImageDerivative;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

/*
 * Issue 19a · Nedladdning av bilagor. Se
 * App\Http\Controllers\AttachmentDownloadController, routes/web.php,
 * config/files.php och [[ADR-0019 Filleverans]].
 *
 * kontoMedMedlem() och beviljaAccess() återanvänds via Pests globala
 * namnrymd, samma mönster som UppladdningTest (16a). Varje "Klart när"-punkt
 * i issuen motsvarar ett namngivet test här.
 *
 * Storage::fake('files') i beforeEach — inga bytes får hamna i den riktiga
 * storage/files/ när sviten körs. config('files.internal_redirect') nollställs
 * i beforeEach: testerna för den sanna grenen sätter den i testet, och ett
 * läckage till nästa test skulle annars ge svitberoende (Beslut 6).
 *
 * Sedan issue 61a prövar filen också filoriginets gren, och den kräver att
 * `files.deliver` finns. Rutten registreras vid appens uppstart och bara när
 * filoriginet är satt, och appen byggs i Tests\TestCase::createApplication()
 * — före varje beforeEach. Miljön sätts därför i beforeAll och tas bort i
 * afterAll: hookarna kör före respektive efter den här filens appar och ingen
 * annans, så varje annan fil i sviten möter miljön utan handpåläggning precis
 * som förut. beforeEach nollställer `files.url`: appdomänens leverans är
 * filens standard, och den sanna grenen sätter originet själv.
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
 * Container, item, bilaga och stored_file redo för en nedladdning — bytena
 * ligger på den fejkade disken.
 *
 * @return array{0: Item, 1: Attachment, 2: StoredFile}
 */
function nedladdningFörberedelse(
    Container $container,
    string $filnamn = 'manual.pdf',
    string $innehåll = 'originalets byten',
    string $mime = 'application/pdf',
): array {
    $storedFile = StoredFile::factory()->create([
        'mime_type' => $mime,
        'byte_size' => strlen($innehåll),
    ]);

    Storage::disk('files')->put($storedFile->storage_path, $innehåll);

    $item = Item::factory()->for($container, 'container')->create();
    $attachment = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'filename' => $filnamn,
        'kind' => str_starts_with($mime, 'image/') ? 'image' : 'document',
    ]);

    return [$item, $attachment, $storedFile];
}

/**
 * Laddar upp en bilaga på ett item GENOM RUTTEN — POST
 * /api/containers/{container}/items/{item}/attachments — och ger tillbaka
 * raden så som den blev, inte som en fabrik hade byggt den (issue 167).
 *
 * Skillnaden är hela poängen med proven nedan. `nedladdningFörberedelse`
 * ovan bygger raden med `Attachment::factory()`, och en sådan rad har
 * fabrikens standardvärden för varje kolumn. En rad som
 * App\Actions\Attachment\StoreAttachment har skrivit bär de värden
 * produktionen faktiskt ger den: `container_id = NULL`, `item_id` satt,
 * `storage_path` ur innehållets hash. Ett fel som bara syns på den ena
 * formen är precis vad de här proven letar efter.
 *
 * Bytena skrivs av actionen, så `Storage::fake('files')` i beforeEach räcker
 * — inget behöver läggas på disken för hand.
 *
 * @return array{0: Attachment, 1: StoredFile}
 */
function laddaUppGenomRutten(
    Container $container,
    Item $item,
    Account $account,
    array $headers,
    string $filnamn,
    string $innehåll,
): array {
    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent($filnamn, $innehåll),
        'account' => $account->ulid,
    ], $headers)->assertCreated();

    $attachment = Attachment::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();

    return [$attachment, $attachment->storedFile];
}

/*
 * Issue 167 · Bilagor på ett item går att ladda ner och förhandsvisa.
 *
 * Den rapporterade raden var att BÅDE nedladdningslänken och
 * förhandsvisningen gav 404 — de bygger samma URL, `/files/{ulid}`
 * (resources/js/components/attachmentPresentation.js), så ett fel på den
 * vägen förklarar båda. Proven nedan bygger fallet så som användaren gör
 * det: bilagan laddas upp genom rutten, inte med `Attachment::factory()`,
 * och hämtas sedan som medlemmen. Kombinationerna är de fem ställen i
 * issuen där ett 404 kan uppstå: rutten utan egen origin, samma rutt med
 * `FILES_URL` satt och 302:an följd, och förhandsvisningens variant.
 */
it('en itembilaga som laddats upp genom rutten kan hämtas av medlemmen', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    // En riktig PDF, så typen sniffas till application/pdf precis som i
    // drift — en påhittad fil hade blivit text/plain.
    [$attachment] = laddaUppGenomRutten(
        $container, $item, $account, $headers,
        'victron-manual.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF",
    );

    // Raden är en itembilaga: container_id är NULL, item_id satt. Det är
    // förutsättningen Attachment::accessSubject() väljer gren på
    // ([[ADR-0047 Containerns bild]] § Beslut).
    expect($attachment->item_id)->not->toBeNull();
    expect($attachment->container_id)->toBeNull();

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertOk();
    expect($response->streamedContent())->toBe("%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF");
    $response->assertHeader('Content-Type', 'application/pdf');
});

it('en itembilaga som laddats upp genom rutten omdirigeras till filoriginet och kan hämtas där', function (bool $internalRedirect) {
    config(['files.internal_redirect' => $internalRedirect]);

    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $innehåll = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF";

    [$attachment, $storedFile] = laddaUppGenomRutten($container, $item, $account, $headers, 'manual.pdf', $innehåll);

    // Egen origin påslagen: appdomänens rutt levererar inte bytena själv utan
    // präglar en signerad länk. Rutten files.deliver finns eftersom filens
    // beforeAll satte FILES_URL innan appen byggdes — samma villkor som i
    // drift, där deploy.sh bygger ruttabellen med FILES_URL i shared/.env
    // ([[Pipeline]] § Filleverans).
    config(['files.url' => 'https://files.test']);

    $omdirigerad = actingAs($user)->get("/files/{$attachment->ulid}");

    $omdirigerad->assertRedirect();

    // Målet är filoriginet och samma sökväg — värdnamnet är det som skiljer
    // rutterna åt, och det är där ett fel i registreringen hade synts.
    // Scheme och port kommer ur appens URL i testsviten och prövas därför
    // inte; värden och sökväg är det som är rutten.
    $mål = $omdirigerad->headers->get('location');
    expect(parse_url($mål, PHP_URL_HOST))->toBe('files.test');
    expect(parse_url($mål, PHP_URL_PATH))->toBe("/files/{$attachment->ulid}");
    expect($mål)->toContain('signature=');

    // Följ 302:an hela vägen: signaturen är den enda grinden på originet.
    $leverans = get($mål);

    $leverans->assertOk();

    if ($internalRedirect) {
        // Samma svar som appdomänen hade gett, och samma sökväg under
        // /_protected. Det är den sökvägen deploy.sh symlänkar till
        // shared/storage/files — finns länken inte på servern ger LiteSpeed
        // 404 på ett svar appen själv kallade 200 ([[Pipeline]]
        // § Filleverans). Provet namnger målet; att länken finns är en
        // driftfråga, inte en kodfråga.
        $leverans->assertHeader('X-LiteSpeed-Location', '/_protected/'.$storedFile->storage_path);
        expect($leverans->getContent())->toBe('');
    } else {
        expect($leverans->streamedContent())->toBe($innehåll);
    }
})->with([
    'strömning' => [false],
    'intern omdirigering' => [true],
]);

it('förhandsvisningen av en uppladdad bild ger 200 för variant thumb och medium', function (string $variant, int $förväntadLängstaSida) {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    // En riktig PNG, större än båda målen, så att det synkrona
    // derivatjobbet (QUEUE_CONNECTION=sync) skriver BÅDA varianterna.
    // Det är skillnaden mot en liten bild: en bild under målet får inget
    // derivat alls, och då är 404:an på `?variant=` korrekt.
    $bild = imagecreatetruecolor(1200, 800);
    imagefill($bild, 0, 0, imagecolorallocate($bild, 10, 20, 30));
    ob_start();
    imagepng($bild);
    $png = (string) ob_get_clean();
    imagedestroy($bild);

    [$attachment, $storedFile] = laddaUppGenomRutten($container, $item, $account, $headers, 'foto.png', $png);

    $derivat = ImageDerivative::query()
        ->where('stored_file_id', $storedFile->id)
        ->where('variant', $variant)
        ->first();

    // Utan raden hade provet varit tomt: 404:an det letar efter kommer ur
    // AttachmentDelivery::storagePath(), och den grenen nås bara när
    // varianten efterfrågas.
    expect($derivat)->not->toBeNull();
    expect(Storage::disk('files')->exists($derivat->storage_path))->toBeTrue();

    $response = actingAs($user)->get("/files/{$attachment->ulid}?variant={$variant}");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/png');

    $byten = $response->streamedContent();
    $mått = getimagesizefromstring($byten);

    // Rätt variant, inte originalet: längsta sidan är skalad till målet.
    expect($mått)->not->toBeFalse();
    expect(max($mått[0], $mått[1]))->toBe($förväntadLängstaSida);
})->with([
    'thumb' => ['thumb', 320],
    'medium' => ['medium', 1024],
]);

it('en itembilaga i en container som medlemmen ser men inte äger kan hämtas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $innehåll = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF";

    [$attachment] = laddaUppGenomRutten($container, $item, $account, $headers, 'manual.pdf', $innehåll);

    // Gästen är medlem i ett ANNAT konto och når itemet bara genom en
    // itemgrant — den omfångsbegränsade mottagaren i [[ADR-0028 Åtkomst på
    // itemnivå]] § Konsekvenser. Grinden är ItemPolicy::view på itemet och
    // är oförändrad; provet vaktar att leveransvägen inte börjar grinda mot
    // containern i stället.
    $gäst = User::factory()->create();
    $gästKonto = Account::factory()->create();
    $gästKonto->users()->attach($gäst, ['role' => 'member']);

    // Raden byggs här i stället för med OmfangsupplosningTest.php:s
    // itemgrant(): den hjälparen är global och nås i hela sviten, men inte
    // när filen körs ensam med --filter. Provet ska stå på egna ben.
    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $gäst->id,
        'level' => 'read',
        'kind' => 'member',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    $response = actingAs($gäst)->get("/files/{$attachment->ulid}");

    $response->assertOk();
    expect($response->streamedContent())->toBe($innehåll);
});

it('en behörig användare får filen', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, innehåll: 'manualens byten');

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertOk();
    expect($response->streamedContent())->toBe('manualens byten');
    $response->assertHeaderMissing('X-LiteSpeed-Location');
});

it('svaret bär X-LiteSpeed-Location när intern omdirigering är på', function () {
    config(['files.internal_redirect' => true]);

    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment, $storedFile] = nedladdningFörberedelse($container, innehåll: 'byten som LiteSpeed ska leverera');

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertOk();
    $response->assertHeader('X-LiteSpeed-Location', '/_protected/'.$storedFile->storage_path);
    expect($response->getContent())->toBe('');
});

it('Content-Type sätts explicit i båda lägena', function (bool $internalRedirect) {
    config(['files.internal_redirect' => $internalRedirect]);

    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, innehåll: 'pdf-innehåll');

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    // Aldrig text/html — LiteSpeed sätter inte typen efter innehållet vid
    // intern omdirigering, så PHP:s standard hade följt med hela vägen ut.
    $response->assertHeader('Content-Type', 'application/pdf');
})->with([
    'strömning' => [false],
    'intern omdirigering' => [true],
]);

/*
 * Påståendet här var "Content-Disposition är ALLTID attachment — även för en
 * bild", och det var sant så länge leveransen låg på appdomänen. Issue 61a
 * lyfter precis det villkoret: på filoriginet får en bild ur tillåt-listan
 * visas inline. Testet håller därför båda vägarna — attachment när originet är
 * appens, inline för en bild när det inte är det (issue 61a § Beslut 5).
 */
it('en bild är attachment på appdomänen och inline på filoriginet', function (bool $internalRedirect) {
    config(['files.internal_redirect' => $internalRedirect]);

    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, filnamn: 'foto.png', innehåll: 'bildbyten', mime: 'image/png');

    // Ingen egen origin: appdomänen levererar bytena själv, och där är
    // dispositionen attachment utan undantag. Det var hela påståendet före
    // issue 61a, och det gäller fortfarande så länge leveransen ligger här.
    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    expect($response->headers->get('content-disposition'))->toStartWith('attachment');

    // Egen origin: appdomänen präglar en länk i stället för att leverera, och
    // på originet får bilden visas inline. Det är villkoret som lyfts.
    config(['files.url' => 'https://files.test']);

    $omdirigerad = actingAs($user)->get("/files/{$attachment->ulid}");

    $omdirigerad->assertRedirect();

    $leverans = get($omdirigerad->headers->get('location'));

    $leverans->assertOk();
    expect($leverans->headers->get('content-disposition'))->toStartWith('inline');
})->with([
    'strömning' => [false],
    'intern omdirigering' => [true],
]);

it('ett filnamn med citattecken och å-ä-ö kodas korrekt', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, filnamn: 'kvitto "sommar" återbetalning.pdf', innehåll: 'byten');

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertOk();
    $disposition = $response->headers->get('content-disposition');
    expect($disposition)->toContain('filename*=');
    expect($disposition)->toContain('kvitto%20%22sommar%22%20%C3%A5terbetalning.pdf');
});

it('ett filnamn helt utan ascii-tecken laddas ändå ner', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, filnamn: '写真', innehåll: 'byten');

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    // Str::ascii('写真') är tom, och en tom fallback i filename= hade kastat
    // InvalidArgumentException i Symfonys hjälpare. Filen är ändå
    // nedladdningsbar: filename*=UTF-8''… bär det riktiga namnet, och
    // fallbacken blir 'download' (Beslut 4).
    $response->assertOk();
    $disposition = $response->headers->get('content-disposition');
    expect($disposition)->toStartWith('attachment');
    expect($disposition)->toContain('%E5%86%99%E7%9C%9F');
});

it('X-Content-Type-Options är nosniff', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, innehåll: 'byten');

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('en svg levereras som attachment och inte inline', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, filnamn: 'bild.svg', innehåll: '<svg xmlns="http://www.w3.org/2000/svg"></svg>', mime: 'image/svg+xml');

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertOk();
    $disposition = $response->headers->get('content-disposition');
    expect($disposition)->toStartWith('attachment');
    expect($disposition)->not->toContain('inline');
});

it('variant=thumb levererar miniatyren', function (bool $internalRedirect) {
    config(['files.internal_redirect' => $internalRedirect]);

    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment, $storedFile] = nedladdningFörberedelse($container, filnamn: 'bild.jpg', innehåll: 'originalets byten', mime: 'image/jpeg');

    $derivative = ImageDerivative::factory()->create([
        'stored_file_id' => $storedFile->id,
        'variant' => 'thumb',
        'storage_path' => $storedFile->storage_path.'_thumb.jpg',
        'byte_size' => strlen('miniatyrens byten'),
    ]);
    Storage::disk('files')->put($derivative->storage_path, 'miniatyrens byten');

    $response = actingAs($user)->get("/files/{$attachment->ulid}?variant=thumb");

    $response->assertHeader('Content-Type', 'image/jpeg');

    if ($internalRedirect) {
        $response->assertOk();
        $response->assertHeader('X-LiteSpeed-Location', '/_protected/'.$derivative->storage_path);
        expect($response->getContent())->toBe('');
    } else {
        $response->assertOk();
        expect($response->streamedContent())->toBe('miniatyrens byten');
    }
})->with([
    'strömning' => [false],
    'intern omdirigering' => [true],
]);

it('en saknad variant ger 404 och aldrig originalet', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, filnamn: 'bild.jpg', innehåll: 'originalets byten', mime: 'image/jpeg');

    // Ingen thumb-rad trots att originalets byten ligger på disken.
    $response = actingAs($user)->get("/files/{$attachment->ulid}?variant=thumb");

    $response->assertNotFound();
});

/*
 * Issue 167 · Bytena borta, raden kvar.
 *
 * Det femte stället i issuen där ett 404 kan uppstå är disken: raden och
 * sökvägen är riktiga medan filen inte ligger där den ska. Det läget uppstår
 * av en gallring som hann före, av en återläsning ur backup som stannade vid
 * databasen, eller av att `_protected`-symlänken inte löser ut.
 *
 * Provet slår fast vad svaret SKA vara — 404 — och det är inte vad koden
 * svarade före rättelsen: den strömmande grenen gav 500 (Storage::disk
 * ('files')->response() hämtar filens storlek när svaret byggs, och ett
 * saknat filuppslag kastar), och den interna omdirigeringen gav 200 med en
 * X-LiteSpeed-Location som webbservern sedan svarade 404 på. Den senare är
 * den tysta felmoden: appen påstod att leveransen lyckades, och 404:an såg
 * för användaren likadan ut som appens egen — utan ett spår i appens logg.
 */
it('en bilaga vars byten saknas på disken ger 404 och inte 500', function (bool $internalRedirect) {
    config(['files.internal_redirect' => $internalRedirect]);

    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    [$attachment, $storedFile] = laddaUppGenomRutten(
        $container, $item, $account, $headers,
        'manual.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF",
    );

    // Raden och sökvägen är orörda — bara bytena försvinner.
    Storage::disk('files')->delete($storedFile->storage_path);
    expect(Storage::disk('files')->exists($storedFile->storage_path))->toBeFalse();

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertNotFound();
})->with([
    'strömning' => [false],
    'intern omdirigering' => [true],
]);

it('en variant vars byten saknas på disken ger 404', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment, $storedFile] = nedladdningFörberedelse($container, filnamn: 'bild.jpg', innehåll: 'originalets byten', mime: 'image/jpeg');

    // Derivatraden finns — det är bara filen den pekar på som är borta. Det
    // är skillnaden mot "en saknad variant ger 404": där saknas raden, här
    // saknas byten, och svaret ska vara detsamma.
    $derivative = ImageDerivative::factory()->create([
        'stored_file_id' => $storedFile->id,
        'variant' => 'thumb',
        'storage_path' => $storedFile->storage_path.'_thumb.jpg',
        'byte_size' => strlen('miniatyrens byten'),
    ]);

    expect(Storage::disk('files')->exists($derivative->storage_path))->toBeFalse();

    $response = actingAs($user)->get("/files/{$attachment->ulid}?variant=thumb");

    $response->assertNotFound();
});

it('ett okänt variant-värde ger 404', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, filnamn: 'bild.jpg', innehåll: 'originalets byten', mime: 'image/jpeg');

    $response = actingAs($user)->get("/files/{$attachment->ulid}?variant=stor");

    $response->assertNotFound();
});

it('en användare utan åtkomst nekas', function () {
    [, $user] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, innehåll: 'någon annans manual');

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertForbidden();
});

it('en read-deltagare får ladda ner', function () {
    [, $user] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member');
    [, $attachment] = nedladdningFörberedelse($container, innehåll: 'läsarens byten');

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertOk();
    expect($response->streamedContent())->toBe('läsarens byten');
});

it('en återkallad åtkomst slutar fungera omedelbart', function () {
    [, $user] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member', revokedAt: now());
    [, $attachment] = nedladdningFörberedelse($container, innehåll: 'byten bakom en återkallad access');

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertForbidden();
});

it('oautentiserad begäran nekas', function () {
    [$account] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, innehåll: 'byten som aldrig får nå ut');

    $response = get("/files/{$attachment->ulid}");

    expect($response->getStatusCode())->toBeIn([302, 401]);

    if ($response->getStatusCode() === 302) {
        $response->assertRedirect();
    }
});

it('en mjukraderad bilaga ger 404', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, innehåll: 'byten i papperskorgen');
    $attachment->delete();

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertNotFound();
});

it('en bilaga vars item är mjukraderat ger 404', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [$item, $attachment] = nedladdningFörberedelse($container, innehåll: 'byten på ett raderat item');
    $item->delete();

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertNotFound();
});

it('en okänd ulid ger 404', function () {
    [$account, $user] = kontoMedMedlem();
    Container::factory()->for($account, 'account')->create();

    $response = actingAs($user)->get('/files/01ARZ3NDEKTSV4RRFFQ69G5FAV');

    $response->assertNotFound();
});

it('en bearer-token fungerar på samma rutt som en session', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, innehåll: 'tokenets byten');

    $response = get("/files/{$attachment->ulid}", $headers);

    $response->assertOk();
    expect($response->streamedContent())->toBe('tokenets byten');
});

it('nedladdningen gör ett konstant antal frågor', function () {
    // Frys tiden runt mätningarna så eventuella tidsstämplar skriver
    // deterministiskt, samma mönster som BilagelistaTest.
    Carbon::setTestNow(now());

    [$account, $user] = kontoMedMedlem();
    $containerA = Container::factory()->for($account, 'account')->create();
    [, $attachmentA] = nedladdningFörberedelse($containerA, innehåll: 'byten A');

    // Bilaga B är en bild med båda derivaten och hämtas med ?variant=thumb —
    // en annan form än den första mätningen, så jämförelsen mäter något som
    // kan skilja sig. Derivatuppslaget kostar exakt en fråga till.
    $containerB = Container::factory()->for($account, 'account')->create();
    [, $attachmentB, $storedFileB] = nedladdningFörberedelse($containerB, filnamn: 'bild.jpg', innehåll: 'byten B', mime: 'image/jpeg');

    foreach (['thumb', 'medium'] as $variant) {
        $derivative = ImageDerivative::factory()->create([
            'stored_file_id' => $storedFileB->id,
            'variant' => $variant,
            'storage_path' => $storedFileB->storage_path."_{$variant}.jpg",
            'byte_size' => 5,
        ]);
        Storage::disk('files')->put($derivative->storage_path, 'mini');
    }

    // Värm guarden med ett omätt anrop.
    actingAs($user)->get("/files/{$attachmentA->ulid}")->assertOk();

    // Sedan issue 71 går grinden genom App\Actions\Access\ResolveItemScope,
    // som är registrerad `scoped()` och därför memoiserar sitt svar per
    // användare och container — även mellan anropen i EN testprocess. I drift
    // är varje request en egen process och memon alltid tom; här nollställs
    // den så båda mätningarna är kalla och talen jämförbara.
    $kall = function () use ($user): void {
        $user->unsetRelation('accounts');
        app()->forgetScopedInstances();
    };

    $kall();
    DB::enableQueryLog();
    actingAs($user)->get("/files/{$attachmentA->ulid}")->assertOk();
    $frågorFörsta = count(DB::getQueryLog());
    DB::flushQueryLog();

    $kall();
    actingAs($user)->get("/files/{$attachmentB->ulid}?variant=thumb")->assertOk();
    $frågorAndra = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Sju frågor för en helt vanlig nedladdning: bindningen, de tre
    // eagerladdade relationerna (stored_file, item, container) och de tre som
    // omfångsupplösningen kostar (användarens konton, containerns ägare och
    // grant-raderna — ingen itemgrant finns, så graf-frågan hoppas över).
    // Inget löst tak med glapp där en N+1 kan gömma sig — variant-begäran ovan
    // lägger exakt en fråga till.
    expect($frågorFörsta)->toBe(7);
    expect($frågorAndra)->toBe($frågorFörsta + 1);

    Carbon::setTestNow();
});
