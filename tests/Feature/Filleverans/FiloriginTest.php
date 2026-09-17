<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ImageDerivative;
use App\Models\Item;
use App\Models\StoredFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * Issue 61a · Filoriginet. Se App\Http\Controllers\FileDeliveryController,
 * App\Http\Controllers\AttachmentDownloadController,
 * App\Http\Middleware\RestrictFileOriginToDelivery, App\Support\Files\*,
 * routes/web.php, config/files.php och [[ADR-0019 Filleverans]]
 * § Uppföljning 2026-09-15.
 *
 * Två rutter, två värdnamn (Beslut 1):
 *
 *   <app>/files/{ulid}          files.download   auth:sanctum  → 302
 *   <filorigin>/files/{ulid}    files.deliver    signed        → bytena
 *
 * **Miljön sätts i `beforeAll`, inte i `beforeEach`.** `files.deliver`
 * registreras vid appens uppstart och bara när filoriginet är satt (Beslut 2),
 * och appen byggs i Tests\TestCase::createApplication() — före varje
 * `beforeEach`, och innan ett enskilt test hinner sätta configen. Pests
 * `beforeAll`/`afterAll` kör i setUpBeforeClass/tearDownAfterClass, alltså före
 * respektive efter den här filens appar och ingen annans: miljön är orörd för
 * varje annan fil i sviten, och `files.url` osatt — miljön utan handpåläggning
 * — är fortfarande standarden där.
 *
 * Appdomänens gren prövas genom att nollställa `files.url` inne i testet, och
 * den grenen levererar bytena själv oavsett vad miljön sa vid uppstarten —
 * bara registreringen av rutten är bunden till uppstarten.
 *
 * Storage::fake('files') i beforeEach: inga bytes får hamna i den riktiga
 * storage/files/ när sviten körs.
 */

/** Filoriginets bas-URL, den enda som får stå i miljön under den här filen. */
function filoriginBas(): string
{
    return 'https://files.test';
}

beforeAll(function () {
    putenv('FILES_URL='.filoriginBas());
    $_ENV['FILES_URL'] = filoriginBas();
    $_SERVER['FILES_URL'] = filoriginBas();
});

afterAll(function () {
    putenv('FILES_URL');
    unset($_ENV['FILES_URL'], $_SERVER['FILES_URL']);
});

beforeEach(function () {
    Storage::fake('files');
    config([
        'files.internal_redirect' => false,
        'files.url' => filoriginBas(),
    ]);
});

/**
 * Container, item, bilaga och stored_file redo för en leverans — bytena
 * ligger på den fejkade disken. Samma uppställning som nedladdningFörberedelse
 * i NedladdningTest, med ett eget namn: Pests hjälpfunktioner ligger i en
 * global namnrymd och får inte krocka mellan filerna.
 *
 * @return array{0: Item, 1: Attachment, 2: StoredFile}
 */
function filoriginBilaga(
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
 * Den signerade leveranslänken, exakt som rutten präglar den. $utgår styr
 * giltigheten — ett tidigare datum ger en utgången men korrekt signerad länk.
 */
function filoriginLänk(Attachment $attachment, ?string $variant = null, ?Carbon $utgår = null): string
{
    $parametrar = ['attachment' => $attachment->ulid];

    if ($variant !== null) {
        $parametrar['variant'] = $variant;
    }

    return URL::temporarySignedRoute('files.deliver', $utgår ?? now()->addMinutes(15), $parametrar);
}

/**
 * En absolut URL till filoriginet, för de anrop som inte är signerade.
 */
function filoriginUrl(string $sökväg): string
{
    return filoriginBas().'/'.ltrim($sökväg, '/');
}

/** En bilaga i en container som $user får läsa. */
function filoriginÅtkomst(): array
{
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    return [$user, $container];
}

// --- Appdomänens rutt präglar länken (Beslut 1) ----------------------------

it('appdomänens rutt svarar 302 till en signerad länk på filoriginet', function () {
    [$user, $container] = filoriginÅtkomst();
    [, $attachment] = filoriginBilaga($container);

    $svar = actingAs($user)->get("/files/{$attachment->ulid}");

    $svar->assertRedirect();

    $mål = $svar->headers->get('location');

    expect(parse_url($mål, PHP_URL_HOST))->toBe('files.test');
    expect(parse_url($mål, PHP_URL_PATH))->toBe('/files/'.$attachment->ulid);
    parse_str((string) parse_url($mål, PHP_URL_QUERY), $fråga);
    expect($fråga)->toHaveKeys(['expires', 'signature']);
});

it('länken lever i femton minuter som standard', function () {
    config(['files.signed_url_ttl_minutes' => 15]);

    [$user, $container] = filoriginÅtkomst();
    [, $attachment] = filoriginBilaga($container);

    // Frys tiden: annars glider now() mellan präglingen och jämförelsen.
    Carbon::setTestNow(now());

    $svar = actingAs($user)->get("/files/{$attachment->ulid}");
    parse_str((string) parse_url($svar->headers->get('location'), PHP_URL_QUERY), $fråga);

    expect((int) $fråga['expires'])->toBe(now()->addMinutes(15)->getTimestamp());

    Carbon::setTestNow();
});

it('varianten följer med genom omdirigeringen', function () {
    [$user, $container] = filoriginÅtkomst();
    [, $attachment, $storedFile] = filoriginBilaga($container, filnamn: 'bild.jpg', innehåll: 'originalets byten', mime: 'image/jpeg');

    $derivative = ImageDerivative::factory()->create([
        'stored_file_id' => $storedFile->id,
        'variant' => 'thumb',
        'storage_path' => $storedFile->storage_path.'_thumb.jpg',
        'byte_size' => strlen('miniatyrens byten'),
    ]);
    Storage::disk('files')->put($derivative->storage_path, 'miniatyrens byten');

    $svar = actingAs($user)->get("/files/{$attachment->ulid}?variant=thumb");

    parse_str((string) parse_url($svar->headers->get('location'), PHP_URL_QUERY), $fråga);
    expect($fråga['variant'] ?? null)->toBe('thumb');

    // Och hela vägen fram: den signerade länken levererar miniatyren.
    $leverans = get($svar->headers->get('location'));

    $leverans->assertOk();
    expect($leverans->streamedContent())->toBe('miniatyrens byten');
});

// --- Behörigheten prövas före präglingen (Beslut 4) ------------------------

it('en användare utan åtkomst får 403 innan någon länk präglas', function () {
    [, $user] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    [, $attachment] = filoriginBilaga($container, innehåll: 'någon annans manual');

    $svar = actingAs($user)->get("/files/{$attachment->ulid}");

    $svar->assertForbidden();
    $svar->assertHeaderMissing('location');
});

it('en mjukraderad bilaga ger 404 på appdomänen', function () {
    [$user, $container] = filoriginÅtkomst();
    [, $attachment] = filoriginBilaga($container);
    $attachment->delete();

    actingAs($user)->get("/files/{$attachment->ulid}")->assertNotFound();
});

it('en bilaga vars item är mjukraderat ger 404 på appdomänen', function () {
    [$user, $container] = filoriginÅtkomst();
    [$item, $attachment] = filoriginBilaga($container);
    $item->delete();

    actingAs($user)->get("/files/{$attachment->ulid}")->assertNotFound();
});

it('en bilaga i en mjukraderad container ger 404 på appdomänen', function () {
    [$user, $container] = filoriginÅtkomst();
    [, $attachment] = filoriginBilaga($container);
    $container->delete();

    actingAs($user)->get("/files/{$attachment->ulid}")->assertNotFound();
});

it('en mjukraderad bilaga ger 404 även på filoriginet', function () {
    [, $container] = filoriginÅtkomst();
    [, $attachment] = filoriginBilaga($container);

    $länk = filoriginLänk($attachment);
    $attachment->delete();

    get($länk)->assertNotFound();
});

it('en okänd variant ger 404 på appdomänen och ingen länk', function () {
    [$user, $container] = filoriginÅtkomst();
    [, $attachment] = filoriginBilaga($container, filnamn: 'bild.jpg', innehåll: 'byten', mime: 'image/jpeg');

    $svar = actingAs($user)->get("/files/{$attachment->ulid}?variant=stor");

    $svar->assertNotFound();
    $svar->assertHeaderMissing('location');
});

it('en variant som saknas ger 404 på appdomänen och ingen länk', function () {
    [$user, $container] = filoriginÅtkomst();
    [, $attachment] = filoriginBilaga($container, filnamn: 'bild.jpg', innehåll: 'byten', mime: 'image/jpeg');

    $svar = actingAs($user)->get("/files/{$attachment->ulid}?variant=thumb");

    $svar->assertNotFound();
    $svar->assertHeaderMissing('location');
});

// --- Utan egen origin beter sig appen som förut (Beslut 2) -----------------

it('levererar bytena själv när files.url är osatt', function () {
    config(['files.url' => null]);

    [$user, $container] = filoriginÅtkomst();
    [, $attachment] = filoriginBilaga($container, innehåll: 'byten utan filorigin');

    $svar = actingAs($user)->get("/files/{$attachment->ulid}");

    $svar->assertOk();
    expect($svar->streamedContent())->toBe('byten utan filorigin');
    expect($svar->headers->get('content-disposition'))->toStartWith('attachment');
});

it('räknar ett värde som pekar på appen själv som ingen egen origin', function () {
    // En felkonfiguration ska bli en tråkigare leverans, aldrig en osäker:
    // samma värdnamn som appens är ingen annan origin, och då gäller
    // attachment utan undantag (Beslut 2).
    config(['files.url' => config('app.url')]);

    [$user, $container] = filoriginÅtkomst();
    [, $attachment] = filoriginBilaga($container, filnamn: 'foto.png', innehåll: 'bildbyten', mime: 'image/png');

    $svar = actingAs($user)->get("/files/{$attachment->ulid}");

    $svar->assertOk();
    expect($svar->streamedContent())->toBe('bildbyten');
    expect($svar->headers->get('content-disposition'))->toStartWith('attachment');
});

// --- Leveransen på filoriginet (Beslut 1, 4 och 5) -------------------------

it('den signerade länken levererar bytena utan session och utan token', function () {
    [, $container] = filoriginÅtkomst();
    [, $attachment] = filoriginBilaga($container, innehåll: 'byten genom filoriginet');

    // Inget actingAs, ingen Authorization-header: signaturen ÄR
    // autentiseringen på det här värdnamnet.
    $svar = get(filoriginLänk($attachment));

    $svar->assertOk();
    expect($svar->streamedContent())->toBe('byten genom filoriginet');
});

it('en manipulerad signatur ger 403', function () {
    [, $container] = filoriginÅtkomst();
    [, $attachment] = filoriginBilaga($container);

    $länk = filoriginLänk($attachment);
    $manipulerad = preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $länk);

    get($manipulerad)->assertForbidden();
});

it('en ändrad attachment-ulid ger 403', function () {
    [, $container] = filoriginÅtkomst();
    [, $attachment] = filoriginBilaga($container);
    [, $annan] = filoriginBilaga($container, filnamn: 'annan.pdf');

    $manipulerad = str_replace($attachment->ulid, $annan->ulid, filoriginLänk($attachment));

    get($manipulerad)->assertForbidden();
});

it('en ändrad variant ger 403', function () {
    [, $container] = filoriginÅtkomst();
    [, $attachment, $storedFile] = filoriginBilaga($container, filnamn: 'bild.jpg', innehåll: 'byten', mime: 'image/jpeg');

    $derivative = ImageDerivative::factory()->create([
        'stored_file_id' => $storedFile->id,
        'variant' => 'thumb',
        'storage_path' => $storedFile->storage_path.'_thumb.jpg',
        'byte_size' => 5,
    ]);
    Storage::disk('files')->put($derivative->storage_path, 'mini');

    $manipulerad = str_replace('variant=thumb', 'variant=medium', filoriginLänk($attachment, 'thumb'));

    get($manipulerad)->assertForbidden();
});

it('en utgången länk ger 403', function () {
    [, $container] = filoriginÅtkomst();
    [, $attachment] = filoriginBilaga($container);

    // Korrekt signerad, men med ett utgångsdatum i det förflutna: signaturen
    // håller, giltigheten gör det inte.
    get(filoriginLänk($attachment, utgår: now()->subMinute()))->assertForbidden();
});

it('en bild levereras inline på en egen origin', function (string $mime, string $filnamn) {
    [, $container] = filoriginÅtkomst();
    [, $attachment] = filoriginBilaga($container, filnamn: $filnamn, innehåll: 'byten', mime: $mime);

    $svar = get(filoriginLänk($attachment));

    $svar->assertOk();
    $svar->assertHeader('Content-Type', $mime);
    expect($svar->headers->get('content-disposition'))->toStartWith('inline');
})->with([
    'jpeg' => ['image/jpeg', 'foto.jpg'],
    'png' => ['image/png', 'foto.png'],
    'gif' => ['image/gif', 'foto.gif'],
    'webp' => ['image/webp', 'foto.webp'],
    'pdf' => ['application/pdf', 'manual.pdf'],
]);

it('en svg levereras som attachment och inte inline', function () {
    [, $container] = filoriginÅtkomst();
    [, $attachment] = filoriginBilaga(
        $container,
        filnamn: 'bild.svg',
        innehåll: '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
        mime: 'image/svg+xml',
    );

    $svar = get(filoriginLänk($attachment));

    $svar->assertOk();
    $disposition = $svar->headers->get('content-disposition');
    expect($disposition)->toStartWith('attachment');
    expect($disposition)->not->toContain('inline');
});

it('en typ utanför tillåt-listan levereras som attachment', function () {
    [, $container] = filoriginÅtkomst();
    [, $attachment] = filoriginBilaga($container, filnamn: 'logg.txt', innehåll: 'text', mime: 'text/plain');

    expect(get(filoriginLänk($attachment))->headers->get('content-disposition'))->toStartWith('attachment');
});

it('dispositionen kommer ur den lagrade typen och aldrig ur filnamnet', function () {
    // En .png-bilaga vars lagrade typ är text/plain. Hade dispositionen
    // följt filnamnet eller URL:en hade den blivit inline; den följer
    // stored_file.mime_type (Beslut 5).
    [, $container] = filoriginÅtkomst();
    [, $attachment] = filoriginBilaga($container, filnamn: 'forledande.png', innehåll: 'inte en bild', mime: 'text/plain');

    $svar = get(filoriginLänk($attachment));

    expect($svar->headers->get('content-disposition'))->toStartWith('attachment');
    // Symfoni lägger på `; charset=utf-8` för text-typer; typen är den
    // lagrade hur som helst, och den är inte bild/png.
    expect($svar->headers->get('content-type'))->toStartWith('text/plain');
});

it('varje leverans bär nosniff och en CSP med sandbox, i båda grenarna', function (bool $internalRedirect) {
    config(['files.internal_redirect' => $internalRedirect]);

    [, $container] = filoriginÅtkomst();
    [, $attachment] = filoriginBilaga($container);

    $svar = get(filoriginLänk($attachment));

    $svar->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($svar->headers->get('content-security-policy'))
        ->toContain("default-src 'none'")
        ->toContain('sandbox')
        ->toContain('frame-ancestors '.config('app.url'));
})->with([
    'strömning' => [false],
    'intern omdirigering' => [true],
]);

it('sätter X-LiteSpeed-Location på filoriginet när intern omdirigering är på', function () {
    config(['files.internal_redirect' => true]);

    [, $container] = filoriginÅtkomst();
    [, $attachment, $storedFile] = filoriginBilaga($container);

    $svar = get(filoriginLänk($attachment));

    $svar->assertOk();
    $svar->assertHeader('X-LiteSpeed-Location', '/_protected/'.$storedFile->storage_path);
    expect($svar->getContent())->toBe('');
});

it('en variant som inte finns ger 404 även med en giltig signatur', function () {
    [, $container] = filoriginÅtkomst();
    [, $attachment] = filoriginBilaga($container, filnamn: 'bild.jpg', innehåll: 'byten', mime: 'image/jpeg');

    // Signerad, men varianten finns inte — 404, aldrig originalet.
    get(filoriginLänk($attachment, 'thumb'))->assertNotFound();
});

// --- Vakten på filoriginet (Beslut 3) --------------------------------------

it('svarar 404 på varje annan rutt än leveransen', function (string $sökväg) {
    [, $user] = kontoMedMedlem();

    $svar = actingAs($user)->get(filoriginUrl($sökväg));

    $svar->assertNotFound();
})->with([
    'inloggningen' => '/login',
    'registreringen' => '/register',
    'startsidan' => '/',
    'dashboard' => '/dashboard',
    'inställningarna' => '/settings/profile',
    'api' => '/api/containers',
]);

it('svarar 404 på /api även med ett giltigt token', function () {
    [$account, , $headers] = kontoMedMedlem();
    Container::factory()->for($account, 'account')->create();

    get(filoriginUrl('/api/containers'), $headers)->assertNotFound();
});

it('files.deliver finns bara på filoriginet', function () {
    expect(Route::getRoutes()->getByName('files.deliver')->getDomain())->toBe('files.test');
    expect(Route::getRoutes()->getByName('files.download')->getDomain())->toBeNull();

    // En leveranslänk mot appdomänen når files.download och inte leveransen:
    // en gäst får 302 till inloggningen, aldrig bytena.
    [$account] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = filoriginBilaga($container);

    $motAppen = str_replace('files.test', 'localhost', filoriginLänk($attachment));
    $svar = get($motAppen);

    expect($svar->getStatusCode())->toBe(302);
    expect($svar->headers->get('location'))->toContain('/login');
});

// --- Kostnaden (Beslut 10) -------------------------------------------------

it('präglingen lägger ingen fråga', function () {
    Carbon::setTestNow(now());

    [$user, $container] = filoriginÅtkomst();
    [, $attachment] = filoriginBilaga($container);

    // Värm guarden med ett omätt anrop, precis som NedladdningTest gör:
    // App\Actions\Access\ResolveItemScope är registrerad `scoped()` och
    // memoiserar sitt svar per användare och container.
    actingAs($user)->get("/files/{$attachment->ulid}")->assertRedirect();

    $user->unsetRelation('accounts');
    app()->forgetScopedInstances();

    DB::enableQueryLog();
    actingAs($user)->get("/files/{$attachment->ulid}")->assertRedirect();
    $frågor = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Samma sju som appdomänens leverans kostar: bindningen, de tre
    // eagerladdade relationerna (stored_file, item, container) och de tre
    // omfångsupplösningen kostar. Att prägla en signerad URL är ren
    // strängmatematik — ingen fråga till, ingen kontroll av något slag.
    expect($frågor)->toBe(7);

    Carbon::setTestNow();
});

// --- Utrullningen ----------------------------------------------------------

it('ruttcachen kan byggas med den domänbundna rutten', function () {
    // `route:cache` bootar en färsk applikation ur miljön — alltså med den
    // FILES_URL beforeAll sätter — och serialiserar varje rutt. Den
    // domänbundna rutten är det nya här, och en rutt som inte går att cacha
    // faller först på servern. Cachefilen städas i finally: ligger den kvar
    // läser nästa test en frusen ruttabell.
    try {
        expect(Artisan::call('route:cache'))->toBe(0);
    } finally {
        Artisan::call('route:clear');
    }
});
