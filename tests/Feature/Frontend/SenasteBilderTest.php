<?php

use App\Actions\Attachment\ListRecentImages;
use App\Http\Controllers\ContainerController;
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
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 173 · Senaste bilder — panelen på containerns översikt, se
 * app/Actions/Attachment/ListRecentImages.php,
 * app/Http/Controllers/ContainerController::show(),
 * resources/js/components/RecentImagesPanel.vue,
 * resources/js/pages/Containers/Overview.vue och
 * [[ADR-0050 Desktopdesignen]] § 7.
 *
 * Filen bevisar de sex regler panelen lyder under:
 *
 * 1. **Urvalet är bilder och ingenting annat**: `kind = 'image'`, nyast
 *    först, högst fem ([[Filer och lagring]] § attachment).
 * 2. **Omfånget gäller som för varje annan listning**
 *    ([[ADR-0028 Åtkomst på itemnivå]]): en bild på ett item användaren inte
 *    når försvinner ur listan — och papperskorgen syns inte i något av leden.
 * 3. **Containerns EGEN bild räknas aldrig** ([[ADR-0047 Containerns bild]]):
 *    den sitter på containern och inte på ett item.
 * 4. **Miniatyren ritas bara när `thumb`-varianten finns** — samma regel som
 *    `variants` i App\Http\Controllers\ItemController::show (issue 61b
 *    § Beslut 1). En `<img>` mot en bilaga utan derivat är en trasig bild.
 * 5. **Panelen ritas bara när det finns bilder**, som kostnadspanelen: en
 *    rubrik över ingenting påstår att det finns något att visa.
 * 6. **Frågan kostar ett konstant antal frågor** (issue 9a § Att se upp med).
 *
 * Hjälparna har prefixet `bild` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::today()->setTime(12, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett ägarkonto med en medlem i, och en container under kontot.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function bildKontext(): array
{
    $konto = Account::factory()->create();
    $agare = User::factory()->create();
    $konto->users()->attach($agare, ['role' => 'owner']);

    return [$konto, $agare, Container::factory()->for($konto, 'account')->create(['name' => 'Havsörnen'])];
}

/**
 * Ett item i containern, med känt namn.
 */
function bildItem(Container $container, string $namn = 'Motorn'): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * En bildbilaga på itemet, med egen `stored_file` och — när `$variant` är
 * satt — ett derivat. `created_at` går att välja, så "nyast först" kan
 * bevisas utan att räkna sekunder.
 */
function bildBilaga(Item $item, string $filnamn = 'foto.jpg', ?string $variant = null, ?Carbon $skapad = null): Attachment
{
    $storedFile = StoredFile::factory()->create(['mime_type' => 'image/jpeg', 'byte_size' => 4]);

    $bilaga = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'filename' => $filnamn,
        'kind' => 'image',
        'created_at' => $skapad ?? now(),
    ]);

    if ($variant !== null) {
        ImageDerivative::factory()->create([
            'stored_file_id' => $storedFile->id,
            'variant' => $variant,
        ]);
    }

    return $bilaga;
}

/**
 * En mottagare UTANFÖR ägarkontot med en grant på ETT item — den smala
 * granten är vad som gör omfånget begränsat.
 */
function bildGast(Container $container, Item $item, string $niva = 'read'): User
{
    $gast = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $gast->id,
        'level' => $niva,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $gast;
}

/**
 * Översiktens proppar ur svaret.
 *
 * @return array<string, mixed>
 */
function bildProps(TestResponse $svar): array
{
    return $svar->inertiaProps();
}

/**
 * Källkoden med kommentarer borta — samma tre slag som genomgangUtanKommentarer
 * rensar, och av samma skäl: ett prov som letar efter en klass eller en markup
 * ska inte kunna nöjas av en mening i en docblock.
 */
function bildUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * Klart när: `panelen visar de fem senaste bilderna nyast först`.
 *
 * Sex bilder och taket fem: den ÄLDSTA faller utanför, och dess ULID får inte
 * finnas någonstans i svaret — en panel som klippte före sorteringen hade
 * visat fem bilder och ändå fel fem. Provet fäster också radens form och att
 * varje bild är en länk till SITT item: adressen kräver båda ULID:na.
 */
it('panelen visar de fem senaste bilderna nyast först', function () {
    withoutVite();

    expect(ContainerController::IMAGE_LIMIT)->toBe(5)
        ->and(ListRecentImages::LIMIT)->toBe(5);

    [$konto, $agare, $container] = bildKontext();
    $motorn = bildItem($container, 'Motorn');

    // I stigande ålder: den första är äldst och ska falla utanför.
    $bilder = [];

    foreach (range(1, 6) as $i) {
        $bilder[] = bildBilaga(
            $motorn,
            "bild-{$i}.jpg",
            'thumb',
            Carbon::today()->setTime(12, 0)->subMinutes(6 - $i),
        );
    }

    $svar = actingAs($agare)->get("/containers/{$container->ulid}")->assertOk();
    $rader = bildProps($svar)['recentImages'];

    $förväntade = array_reverse(array_slice($bilder, 1));

    expect($rader)->toHaveCount(5)
        ->and(array_column($rader, 'ulid'))->toBe(array_map(fn (Attachment $b): string => $b->ulid, $förväntade));

    // Den äldsta bilden finns inte i svaret alls.
    expect($svar->getContent())->not->toContain($bilder[0]->ulid);

    // Radens fyra fält: filnamnet, variantsvaret och itemets ULID och namn.
    expect($rader[0])->toBe([
        'ulid' => $förväntade[0]->ulid,
        'filename' => 'bild-6.jpg',
        'hasThumb' => true,
        'item' => ['ulid' => $motorn->ulid, 'name' => 'Motorn'],
    ]);

    // Varje bild är en länk till sitt item — panelen bygger adressen ur
    // containerns och itemets ULID:er, och den öppnar ingen bildvisare.
    $panel = bildUtanKommentarer(File::get(resource_path('js/components/RecentImagesPanel.vue')));

    expect($panel)->toContain(':href="itemUrl(image)"')
        ->toContain('/containers/${props.containerUlid}/items/${image.item.ulid}');
});

/*
 * Klart när: `ett dokument visas inte`.
 *
 * Panelen är bildens yta ([[ADR-0050 Desktopdesignen]] § 7). Dokumentet
 * ligger på SAMMA item och är nyare än bilden, så ett prov som bara räknade
 * rader hade kunnat se rätt ut av fel skäl.
 */
it('ett dokument visas inte', function () {
    withoutVite();

    [, $agare, $container] = bildKontext();
    $motorn = bildItem($container);

    $bild = bildBilaga($motorn, 'foto.jpg', 'thumb', Carbon::today()->setTime(12, 0)->subMinutes(5));

    Attachment::factory()->for($motorn, 'item')->create([
        'filename' => 'manual.pdf',
        'kind' => 'document',
        'created_at' => now(),
    ]);

    $svar = actingAs($agare)->get("/containers/{$container->ulid}")->assertOk();
    $rader = bildProps($svar)['recentImages'];

    expect($rader)->toHaveCount(1)
        ->and($rader[0]['ulid'])->toBe($bild->ulid);

    expect($svar->getContent())->not->toContain('manual.pdf');
});

/*
 * Klart när: `en bild på ett item utanför omfånget visas inte`.
 *
 * Gästen når containern men bara genom sin grant på motorn
 * ([[ADR-0028 Åtkomst på itemnivå]]). Bilden på det hemliga itemet försvinner
 * ur listan — och varken dess ULID eller filnamn finns i svarets råa innehåll,
 * för ingenting får berätta hur många som filtrerats bort (issue 73 § Beslut
 * 6).
 */
it('en bild på ett item utanför omfånget visas inte', function () {
    withoutVite();

    [, $agare, $container] = bildKontext();
    $motorn = bildItem($container, 'Motorn');
    $hemlig = bildItem($container, 'Hemlig motor');

    $motorns = bildBilaga($motorn, 'motorn.jpg', 'thumb');
    $hemligBild = bildBilaga($hemlig, 'hemlig.jpg', 'thumb');

    $gast = bildGast($container, $motorn);

    $svar = actingAs($gast)->get("/containers/{$container->ulid}")->assertOk();
    $rader = bildProps($svar)['recentImages'];

    expect($rader)->toHaveCount(1)
        ->and($rader[0]['ulid'])->toBe($motorns->ulid)
        ->and($rader[0]['item']['name'])->toBe('Motorn');

    foreach (['hemlig.jpg', $hemligBild->ulid, $hemlig->ulid] as $dold) {
        expect($svar->getContent())->not->toContain($dold);
    }

    // Ägaren ser båda — filtret är omfånget och inte urvalet.
    expect(bildProps(actingAs($agare)->get("/containers/{$container->ulid}")->assertOk())['recentImages'])
        ->toHaveCount(2);
});

/*
 * Klart när: `en bild i papperskorgen eller på ett item i papperskorgen visas
 * inte`.
 *
 * Två led, två globala scope: bilagans SoftDeletes och itemets. Raden står
 * kvar i databasen i båda fallen — det är läsningen som filtrerar, och den
 * filtrerar när den läses ([[Filer och lagring]] § attachment).
 */
it('en bild i papperskorgen eller på ett item i papperskorgen visas inte', function () {
    withoutVite();

    [, $agare, $container] = bildKontext();
    $motorn = bildItem($container, 'Motorn');
    $riggen = bildItem($container, 'Riggen');

    $kvar = bildBilaga($motorn, 'kvar.jpg', 'thumb');
    $kastad = bildBilaga($motorn, 'kastad.jpg', 'thumb');
    $påKastatItem = bildBilaga($riggen, 'pa-kastat-item.jpg', 'thumb');

    $kastad->delete();
    $riggen->delete();

    $svar = actingAs($agare)->get("/containers/{$container->ulid}")->assertOk();
    $rader = bildProps($svar)['recentImages'];

    expect($rader)->toHaveCount(1)
        ->and($rader[0]['ulid'])->toBe($kvar->ulid);

    // Raderna ligger kvar — mjukraderingen är ingen gallring.
    expect(DB::table('attachment')->where('id', $kastad->id)->exists())->toBeTrue()
        ->and(DB::table('item')->where('id', $riggen->id)->exists())->toBeTrue();

    $svar->assertOk();
    expect($svar->getContent())->not->toContain($påKastatItem->ulid);
});

/*
 * Klart när: `containerns egen bild visas inte`.
 *
 * Containerns bild sitter på `container.cover_attachment_id` och har
 * `item_id = NULL` ([[ADR-0047 Containerns bild]]): den hör till containern
 * och inte till något item, och den ritar redan skalets topprad. Raden är
 * nyast av alla, så ett prov som bara jämförde taket hade kunnat se rätt ut av
 * fel skäl.
 */
it('containerns egen bild visas inte', function () {
    withoutVite();

    [, $agare, $container] = bildKontext();
    $motorn = bildItem($container);
    $motorns = bildBilaga($motorn, 'motorn.jpg', 'thumb');

    $storedFile = StoredFile::factory()->create(['mime_type' => 'image/jpeg', 'byte_size' => 4]);

    $containerns = Attachment::factory()->create([
        'item_id' => null,
        'container_id' => $container->id,
        'stored_file_id' => $storedFile->id,
        'filename' => 'hjalte.jpg',
        'kind' => 'image',
        'created_at' => now()->addMinute(),
    ]);

    // Kolumnen är inte massdelbar — samma väg som App\Actions\Container\
    // SetContainerCover går.
    $container->cover_attachment_id = $containerns->id;
    $container->save();

    $svar = actingAs($agare)->get("/containers/{$container->ulid}")->assertOk();
    $rader = bildProps($svar)['recentImages'];

    expect($rader)->toHaveCount(1)
        ->and($rader[0]['ulid'])->toBe($motorns->ulid);

    // Skalets bild är kvar där den ska vara — den är bara inte en rad i
    // panelen.
    expect($container->fresh()->cover_attachment_id)->toBe($containerns->id);
});

/*
 * Klart när: `en bild utan miniatyr får hasThumb falsk`.
 *
 * Servern svarar på frågan vyn annars hade gissat: `?variant=thumb` mot en
 * bilaga utan derivat är 404 (issue 61b § Beslut 1), så en `<img>` mot en
 * sådan adress är en trasig bild. Panelen ritar därför miniatyren bara när
 * flaggan är sann och den neutrala ytan annars.
 */
it('en bild utan miniatyr får hasThumb falsk', function () {
    withoutVite();

    [, $agare, $container] = bildKontext();
    $motorn = bildItem($container);

    $utan = bildBilaga($motorn, 'nyss-uppladdad.jpg', null, Carbon::today()->setTime(12, 0)->subMinute());
    $med = bildBilaga($motorn, 'med-miniatyr.jpg', 'thumb');

    $rader = collect(bildProps(actingAs($agare)->get("/containers/{$container->ulid}")->assertOk())['recentImages'])
        ->keyBy('ulid');

    expect($rader[$med->ulid]['hasThumb'])->toBeTrue()
        ->and($rader[$utan->ulid]['hasThumb'])->toBeFalse();

    // Vyn ritar miniatyren på flaggan och aldrig på måfå.
    $panel = bildUtanKommentarer(File::get(resource_path('js/components/RecentImagesPanel.vue')));

    expect($panel)->toContain('v-if="image.hasThumb"')
        ->toContain('/files/${image.ulid}?variant=thumb');
});

/*
 * Klart när: `panelen saknas när det inte finns några bilder`.
 *
 * Panelen är tom propp och ingen rubrik, som kostnadspanelen: en rubrik över
 * ingenting påstår att det finns något att visa. Vyn avgör det ur
 * `recentImages.length`, så provet fäster båda ändarna — svaret och regeln i
 * filen.
 */
it('panelen saknas när det inte finns några bilder', function () {
    withoutVite();

    [, $agare, $container] = bildKontext();
    bildItem($container, 'Motorn');

    $props = bildProps(actingAs($agare)->get("/containers/{$container->ulid}")->assertOk());

    expect($props['recentImages'])->toBe([]);

    $vy = bildUtanKommentarer(File::get(resource_path('js/pages/Containers/Overview.vue')));

    expect($vy)->toContain('v-if="props.recentImages.length"')
        ->toContain('import RecentImagesPanel from')
        ->toContain('<RecentImagesPanel');

    // Panelen har inget tomt läge — den ritas bara när listan har något i sig,
    // så en tom ruta och en tom-rubrik finns inte i den.
    $panel = bildUtanKommentarer(File::get(resource_path('js/components/RecentImagesPanel.vue')));

    expect($panel)->toContain("t('container.overview.images')");

    expect($panel)->not->toContain('UiEmptyState');
});

/*
 * Klart när: `frågan kostar lika många frågor för en bild som för fem`.
 *
 * Mätningen tas över SIDAN och inte över actionen. Actionen hämtar bilagorna
 * med sina relationer, men det är kontrollerns proppbygge som LÄSER dem — och
 * en lazy load där hade gett en fråga per bild utan att en mätning som
 * stannade vid actionen såg det. Ett prov som bara räknade actionens egna
 * frågor hade alltså varit grönt för en panel som ändå frågade fem gånger.
 *
 * Mätningen värms upp med en förfrågan först, som BilagelistaTest: användarens
 * konton och omfånget memoiseras, och den andra mätningen hade annars varit
 * billigare än den första utan att något blivit bättre.
 */
it('frågan kostar lika många frågor för en bild som för fem', function () {
    withoutVite();

    [, $agare, $container] = bildKontext();
    $motorn = bildItem($container);

    bildBilaga($motorn, 'första.jpg', 'thumb');

    $url = "/containers/{$container->ulid}";

    actingAs($agare)->get($url)->assertOk();

    $mät = function () use ($agare, $url): int {
        DB::enableQueryLog();
        DB::flushQueryLog();

        actingAs($agare)->get($url)->assertOk();

        $antal = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $antal;
    };

    $medEn = $mät();

    foreach (range(2, 5) as $i) {
        bildBilaga($motorn, "bild-{$i}.jpg", 'thumb');
    }

    $medFem = $mät();

    expect($medFem)->toBe($medEn)
        // Provet är inte tomt: frågan räknas, och den hämtar raderna.
        ->and($medEn)->toBeGreaterThan(0)
        ->and(bildProps(actingAs($agare)->get($url)->assertOk())['recentImages'])->toHaveCount(5);
});
