<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\ImageDerivative;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 61b · Ytorna för filvisning: miniatyren i bilagelistan, bildvisaren
 * och PDF-ramen på itemets detaljvy. Se
 * App\Http\Controllers\ItemController::show(), resources/js/components/
 * ItemAttachmentSection.vue, resources/js/components/attachmentPresentation.js
 * och [[Filer och lagring]] § image_derivative och § Säkerhet vid leverans.
 *
 * Filen bevisar de fyra gränserna issuen är byggd kring:
 *
 * 1. **Varianterna är ett uppslag och aldrig en gissning** (Beslut 1).
 *    `variants`-propen speglar exakt de `image_derivative`-rader som finns,
 *    och detaljvyn kostar ett konstant antal frågor oavsett antal bilagor och
 *    derivat — relationen är eager-laddad, precis som `categoryNames()`.
 * 2. **Ytorna ritas bara när det finns ett filorigin** (Beslut 2).
 *    `inlineEnabled` räknas på servern ur `files.url` och appens värdnamn,
 *    och är den falsk ser sektionen ut som i 60a.
 * 3. **Bilden och PDF:en avgörs av `attachmentPreview()`** (Beslut 1, 3 och
 *    4): `thumb` ger en miniatyr, `medium` bildvisarens källa med originalet
 *    som fallback, en PDF en ram, och allt annat filikonen — aldrig en
 *    `<img>` mot en 404.
 * 4. **Nedladdningslänken finns kvar på varje rad** (Beslut 5), också de som
 *    ritas inline.
 *
 * Modulen körs i node, som itemdetaljKör() i ItemdetaljTest.php och
 * bilagevyKör() i BilagevyTest.php: formateringen och uppslaget är rena
 * funktioner i en egen fil just för att gå att pröva — en mall går inte att
 * pröva. Att ingen svensk sträng står kvar i en Vue-komponent och att varje ny
 * nyckel finns prövas av tests/Feature/Frontend/SprakTest.php,
 * som läser varenda fil under resources/js; nycklarna den här issuen la till
 * prövas dessutom uttryckligen sist i den här filen.
 *
 * Hjälparna har prefixet `bilagevisning` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

beforeEach(function () {
    Storage::fake('files');

    // Miljön utan handpåläggning: filoriginet är osatt, precis som i varje
    // annan fil i sviten (tests/Feature/Filleverans/FiloriginTest.php äger
    // den satta miljön och ställer tillbaka den efter sig).
    config(['files.url' => null]);
});

/**
 * Ett konto med en medlem, och en container med ett item under kontot. Båda på
 * svenska, så meningarna nedan kan jämföras mot `Lang::get(…, 'en')`.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function bilagevisningKontext(): array
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
 * En mottagare UTANFÖR ägarkontot med en itemgrant på angiven nivå — samma
 * uppställning som bilagevyMottagare i BilagevyTest, med eget namn.
 *
 * @return array{0: User, 1: Account}
 */
function bilagevisningMottagare(Container $container, Item $item, string $niva): array
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
 * En bilaga med angiven MIME-typ. Bytena ligger bara som en rad — inget test
 * här läser dem ur disken.
 */
function bilagevisningBilaga(
    Item $item,
    Account $konto,
    User $uppladdare,
    string $namn,
    string $mime = 'application/pdf',
    string $kind = 'document',
): Attachment {
    $stored = StoredFile::factory()->create(['mime_type' => $mime]);

    return Attachment::factory()->create([
        'item_id' => $item->id,
        'stored_file_id' => $stored->id,
        'filename' => $namn,
        'kind' => $kind,
        'uploaded_by_user_id' => $uppladdare->id,
        'billed_account_id' => $konto->id,
    ]);
}

/**
 * Derivaten som faktiskt finns för bilagans stored_file. Rader och ingenting
 * annat — vad GenerateImageDerivatives skriver till disken är issue 18:s sak.
 */
function bilagevisningDerivat(Attachment $bilaga, string ...$varianter): void
{
    foreach ($varianter as $variant) {
        ImageDerivative::factory()->create([
            'stored_file_id' => $bilaga->stored_file_id,
            'variant' => $variant,
        ]);
    }
}

function bilagevisningUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}";
}

/**
 * Kör ett uttryck mot resources/js/components/attachmentPresentation.js i
 * node och returnerar det som skrivs på stdout — samma teknik som
 * bilagevyKör() i BilagevyTest.php och itemdetaljKör() i ItemdetaljTest.php.
 */
function bilagevisningKör(string $anrop): string
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
 * Svaret ur `attachmentPreview()` för en bilaga, som en array.
 *
 * @param  array<string, mixed>  $bilaga
 * @param  array<string, list<string>>  $varianter
 * @return array<string, mixed>
 */
function bilagevisningPreview(array $bilaga, array $varianter, bool $inline): array
{
    $svar = bilagevisningKör(sprintf(
        'JSON.stringify(m.attachmentPreview(%s, %s, %s))',
        json_encode($bilaga, JSON_UNESCAPED_SLASHES),
        json_encode($varianter, JSON_UNESCAPED_SLASHES),
        $inline ? 'true' : 'false',
    ));

    return json_decode($svar, true, flags: JSON_THROW_ON_ERROR);
}

/** Bilagesektionen som text — den bär mallen som ingen PHP-test kan rendera. */
function bilagevisningVy(): string
{
    return File::get(resource_path('js/components/ItemAttachmentSection.vue'));
}

// --- variants: uppslaget, inte en gissning (Beslut 1) ---------------------

it('speglar precis de derivat som finns i databasen', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = bilagevisningKontext();

    $bada = bilagevisningBilaga($item, $konto, $anvandare, 'bada.jpg', 'image/jpeg', 'image');
    bilagevisningDerivat($bada, 'thumb', 'medium');

    $baraMedium = bilagevisningBilaga($item, $konto, $anvandare, 'medium.jpg', 'image/jpeg', 'image');
    bilagevisningDerivat($baraMedium, 'medium');

    $inget = bilagevisningBilaga($item, $konto, $anvandare, 'nyss-uppladdad.jpg', 'image/jpeg', 'image');

    actingAs($anvandare)->get(bilagevisningUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Show')
            ->has('attachments', 3)
            ->where("variants.{$bada->ulid}", ['medium', 'thumb'])
            ->where("variants.{$baraMedium->ulid}", ['medium'])
            // En bilaga utan derivat får en tom lista och inte en utelämnad
            // nyckel: vyns uppslag är detsamma för varje rad.
            ->where("variants.{$inget->ulid}", [])
    );
});

it('kostar ett konstant antal frågor oavsett antal bilagor och derivat', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = bilagevisningKontext();

    $url = bilagevisningUrl($container, $item);

    // Jämförelsepunkten är EN bilaga och inte noll: en tom relation hoppar
    // Eloquent över sina eager load-frågor helt, så noll rader kostar mindre
    // av ett skäl som inte har med per-rad-arbete att göra. Det som prövas är
    // att rad TIO inte kostar mer än rad ETT.
    bilagevisningDerivat(
        bilagevisningBilaga($item, $konto, $anvandare, 'forsta.jpg', 'image/jpeg', 'image'),
        'thumb',
        'medium',
    );

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80, 477).
    Carbon::setTestNow(now());

    // Värm sessionen så att den första frågan för `last_active_at` inte
    // räknas med, samma resonemang som BilagevyTest och ItemrelationvyTest.
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
        bilagevisningDerivat(
            bilagevisningBilaga($item, $konto, $anvandare, "bild-{$i}.jpg", 'image/jpeg', 'image'),
            'thumb',
            'medium',
        );
    }

    $antal = 0;
    actingAs($anvandare)->get($url)->assertOk();
    $medTio = $antal;

    // Bilagorna, deras stored_file och deras derivat hämtas i ett konstant
    // antal frågor: `storedFile.derivatives` är eager-laddad, precis som
    // `categoryNames()` bygger sitt uppslag ur en laddad relation.
    expect($medTio)->toBe($medEn);

    Carbon::setTestNow();
});

// --- inlineEnabled: flaggan räknas på servern (Beslut 2) -----------------

it('räknar inlineEnabled ur files.url och appens värdnamn', function () {
    withoutVite();

    [, $anvandare, $container, $item] = bilagevisningKontext();

    $url = bilagevisningUrl($container, $item);

    // Osatt: leveransen ligger på appdomänen och allt är `attachment`.
    config(['files.url' => null]);

    actingAs($anvandare)->get($url)->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('inlineEnabled', false)
    );

    // Satt, men pekar på appen själv: ingen egen origin att rendera i.
    config(['files.url' => config('app.url')]);

    actingAs($anvandare)->get($url)->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('inlineEnabled', false)
    );

    // Egen origin: `inline` är möjligt och PDF:en får ramas in av appen.
    config(['files.url' => 'https://files.test']);

    actingAs($anvandare)->get($url)->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('inlineEnabled', true)
    );
});

// --- attachmentPreview: miniatyren, bildvisaren och ramen ----------------

it('ritar miniatyren när thumb finns och medium som bildvisarens källa', function () {
    $bilaga = ['ulid' => '01JBILAGA', 'filename' => 'motor.jpg', 'mime_type' => 'image/jpeg'];

    $svar = bilagevisningPreview($bilaga, ['01JBILAGA' => ['thumb', 'medium']], true);

    expect($svar['display'])->toBe('thumb');
    expect($svar['thumbnail'])->toBe('/files/01JBILAGA?variant=thumb');
    expect($svar['image'])->toBe('/files/01JBILAGA?variant=medium');
    expect($svar['frame'])->toBeNull();
});

it('faller tillbaka på originalet när medium saknas', function () {
    $bilaga = ['ulid' => '01JBILAGA', 'filename' => 'liten.jpg', 'mime_type' => 'image/jpeg'];

    // Bara `thumb`: bilden är mindre än 1024 px och fick aldrig någon medium
    // (issue 18 § Beslut 2). Originalet är alltid en giltig URL (Beslut 3).
    $svar = bilagevisningPreview($bilaga, ['01JBILAGA' => ['thumb']], true);

    expect($svar['display'])->toBe('thumb');
    expect($svar['image'])->toBe('/files/01JBILAGA');
});

it('ger filikon i stället för en trasig bild när thumb saknas', function () {
    // En bild som just laddats upp: derivaten genereras i ett köat jobb och
    // finns ännu inte. `?variant=thumb` hade varit 404 (Beslut 1).
    $bild = ['ulid' => '01JNYSS', 'filename' => 'nyss.jpg', 'mime_type' => 'image/jpeg'];

    $svar = bilagevisningPreview($bild, ['01JNYSS' => []], true);

    expect($svar['display'])->toBe('file');
    expect($svar['thumbnail'])->toBeNull();
    expect($svar['image'])->toBeNull();
    expect($svar['frame'])->toBeNull();
});

it('ritar PDF:en i en ram och aldrig som miniatyr', function () {
    $pdf = ['ulid' => '01JPDF', 'filename' => 'faktura.pdf', 'mime_type' => 'application/pdf'];

    $svar = bilagevisningPreview($pdf, ['01JPDF' => []], true);

    expect($svar['display'])->toBe('frame');
    expect($svar['frame'])->toBe('/files/01JPDF');
    expect($svar['thumbnail'])->toBeNull();
});

it('ritar varken bildvisare eller PDF-ram utan ett filorigin', function () {
    $bild = ['ulid' => '01JBILD', 'filename' => 'motor.jpg', 'mime_type' => 'image/jpeg'];
    $pdf = ['ulid' => '01JPDF', 'filename' => 'faktura.pdf', 'mime_type' => 'application/pdf'];

    // Utan egen origin levereras allt som `attachment` (61a § Beslut 2) — en
    // <img> eller <iframe> mot en sådan URL är i bästa fall tom. Ingen
    // miniatyr, ingen ram och ingen filikon: listan ser ut som i 60a, med
    // filnamn, storlek och nedladdningslänk. Flaggan kommer ur detaljvyns
    // props, aldrig ur `window.location`.
    foreach ([$bild, $pdf] as $bilaga) {
        $svar = bilagevisningPreview($bilaga, [$bilaga['ulid'] => ['thumb', 'medium']], false);

        expect($svar['display'])->toBe('none');
        expect($svar['thumbnail'])->toBeNull();
        expect($svar['image'])->toBeNull();
        expect($svar['frame'])->toBeNull();
    }
});

it('ritar ingen filikon i mallen när visningsytan är avstängd', function () {
    // `none` är den fjärde grenen och den enda som inte har en egen tagg:
    // mallen ritar ingenting, och 60a:s utseende står kvar orört.
    $vy = bilagevisningVy();

    expect($vy)->not->toContain("display === 'none'");
    expect($vy)->toContain("attachment.preview.display === 'file'");
});

it('ritar ingen miniatyr för en bilaga vars ULID saknas i uppslaget', function () {
    $bild = ['ulid' => '01JSAKNAS', 'filename' => 'motor.jpg', 'mime_type' => 'image/jpeg'];

    // Uppslaget är serverns och kan bara innehålla bilagor som finns; en
    // nyckel som saknas får aldrig bli ett antagande om att derivatet finns.
    $svar = bilagevisningPreview($bild, [], true);

    expect($svar['display'])->toBe('file');
    expect($svar['thumbnail'])->toBeNull();
});

// --- mallen: uppladdningen först, visaren och nedladdningslänken ---------

it('öppnar bilden i en dialog som stängs med Esc och lämnar fokus tillbaka', function () {
    $vy = bilagevisningVy();

    // Webbläsarens egen <dialog> (Beslut 3): Esc stänger utan en rad kod för
    // tangentbordet, och `showModal` gör den modal.
    expect($vy)->toContain('<dialog');
    expect($vy)->toContain('showModal');
    expect($vy)->toContain('@close="onViewerClosed"');

    // Fokus tillbaka till miniatyren. <dialog> gör det av sig själv, men bara
    // till ett element som finns kvar — vyn håller reda på vilket.
    expect($vy)->toContain('viewerTrigger.value?.focus()');

    // Källan är `medium` med originalet som fallback, och `alt` är filnamnet
    // ur datan — ingen sträng ur lang/ (Beslut 7).
    expect($vy)->toContain(':src="viewer.preview.image"');
    expect($vy)->toContain(':alt="viewer.filename"');
});

it('ritar uppladdningen före listan', function () {
    $vy = bilagevisningVy();

    // M24 (testarfynd 2026-10-02): uppladdningen stod efter listan, och med
    // några bilagor fick användaren skrolla förbi allt för att ladda upp
    // nästa fil. Innehållet i ytan är oförändrat — bara var det står
    // (Beslut 1).
    $uppladdning = strpos($vy, '<template v-if="can.create">');
    $lista = strpos($vy, 'v-for="attachment in');

    expect($uppladdning)->not->toBeFalse();
    expect($lista)->not->toBeFalse();
    expect($uppladdning)->toBeLessThan($lista);
});

it('ritar ingen PDF-ram i raden utan filikonen', function () {
    $vy = bilagevisningVy();

    // Raden ritar filikonen för både `file` och `frame` (Beslut 2): samma
    // <span role="img"> som en bilaga utan derivat ritar i dag.
    expect($vy)->toContain("attachment.preview.display === 'file'");
    expect($vy)->toContain("attachment.preview.display === 'frame'");

    // Raden bygger ingen ram längre — det var den som gjorde listan svår att
    // överblicka.
    expect($vy)->not->toContain(':src="attachment.preview.frame"');

    // Den ENDA ramen i filen är visarens, och den står efter <dialog>.
    $dialog = strrpos($vy, '<dialog');
    $iframe = strpos($vy, '<iframe');

    expect($dialog)->not->toBeFalse();
    expect($iframe)->not->toBeFalse();
    expect(substr_count($vy, '<iframe'))->toBe(1);
    expect($iframe)->toBeGreaterThan($dialog);

    // Ingen JavaScript-läsare och inget paket (Beslut 4): ramen pekar på
    // samma väg som nedladdningen, och webbservern sätter typen.
    expect($vy)->not->toContain('pdfjs');
    expect($vy)->not->toContain('srcdoc');
});

it('ger varje rad som kan visas en förhandsvisningsknapp', function () {
    $vy = bilagevisningVy();

    // Knappen ritas bara när bilagan har något att visa: `image` för en bild,
    // `frame` för en PDF (Beslut 3). Utan ett filorigin är båda null och
    // knappen ritas inte — samma regel som gör att miniatyren uteblir.
    expect($vy)->toContain('attachment.preview.image !== null');
    expect($vy)->toContain('attachment.preview.frame !== null');
    expect($vy)->toContain("t('item.attachment.preview')");

    // Knappen går samma väg in i visaren som miniatyren: exakt två anrop, och
    // miniatyrens klick finns kvar.
    preg_match_all('/@click="openViewer\(attachment, \$event\)"/', $vy, $träffar);

    expect($träffar[0])->toHaveCount(2);
});

it('visar PDF:en i samma dialog som bilden', function () {
    $vy = bilagevisningVy();

    $dialog = strrpos($vy, '<dialog');

    expect($dialog)->not->toBeFalse();

    // Ramen, filnamnet, rubriken och fallback-meningen bor alla i visaren
    // (Beslut 4) — efter <dialog> och inte i raden.
    foreach ([
        ':src="viewer.preview.frame"',
        ':title="viewer.filename"',
        'item.attachment.pdf_viewer_heading',
        'item.attachment.pdf_fallback',
    ] as $markor) {
        expect(strpos($vy, $markor))->toBeGreaterThan($dialog, "{$markor} står inte i visaren");
    }

    // Ingen isolering på ramen och ingen egen läsare: CSP:n stänger av
    // skriptet i dokumentet, och webbläsarens egen läsare är den enda som
    // behövs.
    expect($vy)->not->toContain('sandbox');
    expect($vy)->not->toContain('pdfjs');
    expect($vy)->not->toContain('srcdoc');
});

it('har en nedladdningslänk på varje bilagerad, också de som ritas inline', function () {
    $vy = bilagevisningVy();

    preg_match_all('/<a\b[^>]*>/s', $vy, $träffar);

    $nedladdning = array_values(array_filter(
        $träffar[0],
        fn (string $tagg): bool => str_contains($tagg, '/files/${attachment.ulid}'),
    ));

    // Exakt EN sådan länk i raden, och den är ovillkorlig: att se en faktura
    // är inte att ha den (Beslut 5), och den är samtidigt vägen vidare när
    // webbläsaren inte kan visa PDF:en.
    expect($nedladdning)->toHaveCount(1);
    expect($nedladdning[0])->not->toContain('v-if');
});

it('reparerar aldrig en trasig bild i komponenten', function () {
    // Uppslaget på servern är det som gör en <img> ärlig (Beslut 1). En
    // `onerror` i vyn hade varit en andra sanning om vilka derivat som finns.
    expect(bilagevisningVy())->not->toContain('onerror');
});

// --- läsaren: visningen är ingen skrivyta --------------------------------

it('ger en read-mottagare miniatyrerna och visningen men ingen skrivyta', function () {
    withoutVite();

    [, , $container, $item] = bilagevisningKontext();

    $uppladdare = User::factory()->create();
    $agarkonto = Account::factory()->create();

    $bild = bilagevisningBilaga($item, $agarkonto, $uppladdare, 'motor.jpg', 'image/jpeg', 'image');
    bilagevisningDerivat($bild, 'thumb', 'medium');

    [$mottagare] = bilagevisningMottagare($container, $item, 'read');

    actingAs($mottagare)->get(bilagevisningUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('attachments', 1)
            ->where("variants.{$bild->ulid}", ['medium', 'thumb'])
            ->where('can.create', false)
            ->where('can.delete', false)
            ->where('can.update', false)
    );
});

// --- strängarna (Beslut 7) -----------------------------------------------

it('har visningens nycklar och läser dem ur lang/', function () {
    // `preview` och `pdf_viewer_heading` kom med M24 (Beslut 3 och 4).
    foreach (['viewer_heading', 'pdf_viewer_heading', 'viewer_close', 'preview', 'file_icon', 'pdf_fallback'] as $nyckel) {
        expect(trim((string) Lang::get("ui.item.attachment.{$nyckel}", [], 'en')))
            ->not->toBe('', "ui.item.attachment.{$nyckel} saknas");
    }
});

it('lämnar 60a:s och 60b:s ytor orörda', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = bilagevisningKontext();

    bilagevisningBilaga($item, $konto, $anvandare, 'manual.pdf', 'application/pdf');

    actingAs($anvandare)->get(bilagevisningUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            // Uppladdningen och kön finns kvar bakom samma grind som förut.
            ->where('maxUploadBytes', (int) config('files.max_upload_bytes'))
            ->where('can.create', true)
            ->where('can.delete', true)
    );

    // Fliken bär fortfarande uppladdningsytan och raderingen, oförändrade.
    $vy = bilagevisningVy();

    expect($vy)->toContain('item.attachment.dropzone');
    expect($vy)->toContain('item.attachment.upload_heading');
    expect($vy)->toContain('item.attachment.destroy_confirm');
});

it('bygger varje visnings-URL mot appdomänens rutt, aldrig mot filoriginet', function () {
    // Klienten bygger aldrig en URL mot filoriginet: `/files/{ulid}` svarar
    // 302 till den signerade länken, och signaturen präglas där behörigheten
    // prövas (61a § Beslut 1 och 4). En egen väg dit hade varit en andra väg
    // till samma byten — och en osignerad sådan. Modulen läses, för det är
    // där URL:en byggs.
    $modul = File::get(resource_path('js/components/attachmentPresentation.js'));

    expect($modul)->toContain('/files/${attachment.ulid}');
    expect($modul)->not->toContain('files.mimers.app');

    // Och vyn läser flaggan ur detaljvyns props, aldrig ur webbläsarens
    // adressfält: vilket origin sidan råkade laddas från är inte ett svar på
    // var filerna levereras ifrån.
    expect(bilagevisningVy())->not->toContain('window.location');
});
