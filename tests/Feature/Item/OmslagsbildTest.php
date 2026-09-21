<?php

use App\Actions\Item\ResolveItemCover;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patch;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 93 · Itemet får en omslagsbild — se [[ADR-0041 Itemets vy]]
 * § Beslut och § Konsekvenser, och [[M16 Itemets vy]] § 93.
 *
 * Filen prövar den här sidan av itemet: kolumnen, upplösningen och valet i
 * redigeringsvyn. Att en bilaga som raderas HÅRT nollställer pekaren i stället
 * för att blockera raderingen ligger i tests/Feature/Attachment/
 * OmslagspekareTest.php, där bilagan är huvudpersonen.
 *
 * Tre saker är värda att se efter, för de är lätta att få fel:
 *
 * 1. **Upplösningen bor i App\Actions\Item\ResolveItemCover och inte i vyn.**
 *    Varje steg i ordningen har ett eget test, och `images()` — urvalet och
 *    ordningen — prövas för sig: en andra formulering av "itemets bilder" i en
 *    kontroller eller en mall glider ifrån den första.
 * 2. **`cover` i PATCH är pekaren och inte den upplösta bilden.** Rensar
 *    användaren valet ska upplösningens steg 2 gälla, och det är först då
 *    valet går att ta tillbaka.
 * 3. **`ItemResource` och `attachment` rörs inte.** Två test längst ned
 *    vaktar det: inget nytt fält i `/api`s svar och ingen ny kolumn på
 *    bilagan. Vilken bild som är itemets är ett faktum om itemet.
 *
 * Hjälparna har prefixet `omslag` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem, och en container ägd av kontot.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function omslagKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare, Container::factory()->for($konto, 'account')->create()];
}

/**
 * Ett item i containern med sammanhängande `created_by_*`.
 */
function omslagItem(Container $container, string $namn = 'Motorn'): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En bilaga på itemet, med sin egen `stored_file` ur fabriken.
 *
 * `kind` är `image` som standard: det är den sortens bilaga hela issuen
 * handlar om, och den enda upplösningen väljer.
 */
function omslagBilaga(Item $item, string $filnamn, string $kind = 'image'): Attachment
{
    return Attachment::factory()->for($item, 'item')->create([
        'filename' => $filnamn,
        'kind' => $kind,
    ]);
}

/**
 * Redigeringsformulärets kropp. `category` och `tags` är med därför att webben
 * ALLTID skickar båda (issue 57b § Beslut 6) — ett item utan kategori och
 * taggar tömmer dem, och det är samma PATCH.
 *
 * @param  array<string, mixed>  $overskrid
 * @return array<string, mixed>
 */
function omslagKropp(array $overskrid = []): array
{
    return array_merge([
        'name' => 'Motorn',
        'category' => null,
        'tags' => [],
    ], $overskrid);
}

/**
 * Sätter pekaren direkt på raden — utgångsläget ett test behöver, utan att gå
 * genom formuläret. Kolumnen är inte massilskrivbar (App\Models\Item), så den
 * sätts för hand.
 */
function omslagVal(Item $item, ?Attachment $bilaga): Item
{
    $item->cover_attachment_id = $bilaga?->id;
    $item->save();

    return $item;
}

/*
 * Klart när: `item` har en nullbar pekare till en bilaga.
 *
 * Kolumnens FORM prövas och inte bara dess namn: nullable, mot `attachment`,
 * och ON DELETE SET NULL. Den sista är hela avvikelsen från husets RESTRICT
 * och bevisas i sitt sammanhang i tests/Feature/Attachment/OmslagspekareTest.
 */
it('har en nullbar pekare till en bilaga på item', function () {
    $kolumn = collect(Schema::getColumns('item'))->firstWhere('name', 'cover_attachment_id');

    expect($kolumn)->not->toBeNull();
    expect($kolumn['nullable'])->toBeTrue();

    $nyckel = collect(Schema::getForeignKeys('item'))->firstWhere('columns', ['cover_attachment_id']);

    expect($nyckel)->not->toBeNull();
    expect($nyckel['foreign_table'])->toBe('attachment');
    expect($nyckel['on_delete'])->toBe('set null');
});

/*
 * Klart när: upplösningen väljer den valda bilagan, annars den äldsta bilden,
 * annars ingen.
 *
 * Första steget. Valet är den NYASTE bilden med flit: hade upplösningen tagit
 * den första i listan hade testet varit grönt ändå, och skillnaden mellan
 * "vald" och "äldst" osynlig.
 */
it('väljer den valda bilagan framför den äldsta', function () {
    $item = omslagItem(omslagKontext()[2]);
    omslagBilaga($item, 'gammal.jpg');
    $ny = omslagBilaga($item, 'ny.jpg');

    omslagVal($item, $ny);

    expect((new ResolveItemCover)->handle($item)?->id)->toBe($ny->id);
});

/*
 * Andra steget: inget val gjort. Den ÄLDSTA bilden — och inte den nyaste.
 *
 * Att itemets ansikte inte byter skepnad varje gång någon laddar upp ett foto
 * är halva skälet till att steg 2 finns ([[ADR-0041 Itemets vy]] § Beslut),
 * så testet laddar upp en ny bild EFTER valet och förväntar sig den gamla.
 * Två bilagor i samma sekund skiljs av `id`, samma par som bilagelistan
 * sorterar på (issue 60 § Beslut 10).
 */
it('väljer den äldsta bilden när inget val är gjort', function () {
    $item = omslagItem(omslagKontext()[2]);
    $aldsta = omslagBilaga($item, 'gammal.jpg');
    omslagBilaga($item, 'ny.jpg');

    expect((new ResolveItemCover)->handle($item)?->id)->toBe($aldsta->id);
});

/*
 * Tredje steget: ingen bild alls. Null och inte en gissning — vyn utelämnar
 * fotot i stället för att rita ett trasigt.
 */
it('svarar ingen bild när itemet inte har någon', function () {
    $item = omslagItem(omslagKontext()[2]);

    expect((new ResolveItemCover)->handle($item))->toBeNull();
});

/*
 * Urvalet: bara `kind = 'image'`. Ett dokument är en bilaga men inte en bild,
 * och en PDF som omslag är en trasig bild i vyn.
 *
 * Dokumentet skapas FÖRST, så det ligger först i tiden: hade urvalet varit
 * "den äldsta bilagan" i stället för "den äldsta BILDEN" hade testet fallit.
 */
it('hoppar över dokument och andra bilagor', function () {
    $container = omslagKontext()[2];

    $medDokument = omslagItem($container, 'Med dokument');
    omslagBilaga($medDokument, 'manual.pdf', 'document');
    $bild = omslagBilaga($medDokument, 'foto.jpg');

    expect((new ResolveItemCover)->handle($medDokument)?->id)->toBe($bild->id);

    // Bara dokument: ingen bild, alltså inget omslag.
    $baraDokument = omslagItem($container, 'Bara dokument');
    omslagBilaga($baraDokument, 'manual.pdf', 'document');
    omslagBilaga($baraDokument, 'kvitto.pdf', 'other');

    expect((new ResolveItemCover)->handle($baraDokument))->toBeNull();
});

/*
 * Steg 1 kräver att den valda bilagan är en BILD. En pekare som pekar på ett
 * dokument — den kan inte sättas genom formuläret, men kolumnen kan bära den
 * ändå — får inte bli itemets ansikte: då gäller steg 2.
 */
it('väljer inte en vald bilaga som inte är en bild', function () {
    $item = omslagItem(omslagKontext()[2]);
    $dokument = omslagBilaga($item, 'manual.pdf', 'document');
    $bild = omslagBilaga($item, 'foto.jpg');

    omslagVal($item, $dokument);

    expect((new ResolveItemCover)->handle($item)?->id)->toBe($bild->id);
});

/*
 * Klart när: en vald bilaga som mjukraderats faller tillbaka på regeln i
 * stället för att ge en trasig bild.
 *
 * Pekaren står KVAR på den mjukraderade bilagan — kolumnen rörs inte av en
 * mjuk radering ([[ADR-0008 Soft delete och papperskorg]]) — men
 * SoftDeletes' globala scope gör att steg 1 inte hittar henne, och steg 2
 * gäller. Varken en trasig bild eller ett tomrum.
 */
it('faller tillbaka på den äldsta bilden när den valda bilagan mjukraderats', function () {
    $item = omslagItem(omslagKontext()[2]);
    $aldsta = omslagBilaga($item, 'gammal.jpg');
    $vald = omslagBilaga($item, 'ny.jpg');

    omslagVal($item, $vald);
    $vald->delete();

    expect($item->refresh()->cover_attachment_id)->toBe($vald->id);
    expect((new ResolveItemCover)->handle($item)?->id)->toBe($aldsta->id);
});

/*
 * Klart när: valet går att sätta, ändra och rensa i itemets redigeravy.
 *
 * Tre skrivningar i samma test, för det är samma fält och samma PATCH:
 * sätta, byta, och rensa med `null`. Rensningen är den som är lätt att tappa
 * bort — `cover: null` ska ge en NULL kolumn och inte en tidigare bild, och
 * först då kan användaren ångra ett val.
 */
it('sätter, byter och rensar omslagsbilden', function () {
    [$konto, $anvandare, $container] = omslagKontext();

    $item = omslagItem($container);
    $forsta = omslagBilaga($item, 'gammal.jpg');
    $andra = omslagBilaga($item, 'ny.jpg');

    $url = "/containers/{$container->ulid}/items/{$item->ulid}";

    actingAs($anvandare)->patch($url, omslagKropp(['cover' => $forsta->ulid]))
        ->assertRedirect($url)
        ->assertSessionHas('status', 'item-updated');

    expect($item->refresh()->cover_attachment_id)->toBe($forsta->id);

    actingAs($anvandare)->patch($url, omslagKropp(['cover' => $andra->ulid]))
        ->assertRedirect($url);

    expect($item->refresh()->cover_attachment_id)->toBe($andra->id);

    actingAs($anvandare)->patch($url, omslagKropp(['cover' => null]))
        ->assertRedirect($url);

    expect($item->refresh()->cover_attachment_id)->toBeNull();
});

/*
 * Klart när: en bilaga som inte är en bild kan inte väljas.
 *
 * 422 på fältet `cover` — webben kör Inertia och behåller Laravels vanliga
 * valideringsfel (AGENTS.md § Felformat). Ingen skrivning sker.
 */
it('avvisar en bilaga som inte är en bild', function () {
    [, $anvandare, $container] = omslagKontext();

    $item = omslagItem($container);
    $dokument = omslagBilaga($item, 'manual.pdf', 'document');

    actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/items/{$item->ulid}", omslagKropp(['cover' => $dokument->ulid]))
        ->assertSessionHasErrors('cover');

    expect($item->refresh()->cover_attachment_id)->toBeNull();
});

/*
 * Klart när: en bilaga som hör till ett annat item kan inte väljas.
 *
 * Grannen ligger i SAMMA container, så det är inte containern som skiljer
 * dem: regeln prövar bilagan mot DET HÄR itemet. Utan den hade ett item
 * kunnat visa ett annat items foto.
 */
it('avvisar en bilaga som hör till ett annat item', function () {
    [, $anvandare, $container] = omslagKontext();

    $item = omslagItem($container);
    $annat = omslagItem($container, 'Grannen');
    $grannens = omslagBilaga($annat, 'grannen.jpg');

    actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/items/{$item->ulid}", omslagKropp(['cover' => $grannens->ulid]))
        ->assertSessionHasErrors('cover');

    expect($item->refresh()->cover_attachment_id)->toBeNull();
});

/*
 * En mjukraderad bilaga kan inte väljas. Väljaren ritar henne inte — hon
 * kommer ur App\Actions\Item\ResolveItemCover::images(), som filtrerar bort
 * mjukraderade — och regeln stänger samma dörr på servern: en ULID ur en
 * gammal flik ska inte kunna peka omslaget på en bild som ligger i
 * papperskorgen.
 */
it('avvisar en bilaga som ligger i papperskorgen', function () {
    [, $anvandare, $container] = omslagKontext();

    $item = omslagItem($container);
    $bild = omslagBilaga($item, 'foto.jpg');
    $bild->delete();

    actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/items/{$item->ulid}", omslagKropp(['cover' => $bild->ulid]))
        ->assertSessionHasErrors('cover');

    expect($item->refresh()->cover_attachment_id)->toBeNull();
});

/*
 * Redigeringsvyns två propar: `images` och `cover`.
 *
 * `images` är itemets bilder i upplösningens ordning — äldst först — och
 * `cover` är den VALDA bildens ULID. Den upplösta bilden är med flit INTE
 * vald: är pekaren null ska väljaren visa "inget val" och inte det foto regeln
 * råkade peka ut, annars gick valet aldrig att ta tillbaka.
 *
 * Ett dokument är inte med i listan: väljaren erbjuder bara det som går att
 * välja.
 */
it('ger redigeringsvyn bilderna i ordning och det valda', function () {
    withoutVite();

    [, $anvandare, $container] = omslagKontext();

    $item = omslagItem($container);
    omslagBilaga($item, 'manual.pdf', 'document');
    $aldsta = omslagBilaga($item, 'gammal.jpg');
    $nyaste = omslagBilaga($item, 'ny.jpg');

    actingAs($anvandare)->get("/containers/{$container->ulid}/items/{$item->ulid}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Edit')
            ->where('images', [
                ['ulid' => $aldsta->ulid, 'filename' => 'gammal.jpg'],
                ['ulid' => $nyaste->ulid, 'filename' => 'ny.jpg'],
            ])
            ->where('cover', null)
        );

    omslagVal($item, $nyaste);

    actingAs($anvandare)->get("/containers/{$container->ulid}/items/{$item->ulid}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('cover', $nyaste->ulid)
        );
});

/*
 * En pekare som står kvar på en mjukraderad bilaga syns inte i väljaren: hon
 * finns inte i `images`. `cover` blir null — valet är borta — och det är rätt
 * svar, för upplösningens steg 2 gäller. Vyn får aldrig en ULID den inte kan
 * rita en rad för.
 */
it('utelämnar en mjukraderad bild ur redigeringsvyns val', function () {
    withoutVite();

    [, $anvandare, $container] = omslagKontext();

    $item = omslagItem($container);
    $vald = omslagBilaga($item, 'foto.jpg');

    omslagVal($item, $vald);
    $vald->delete();

    actingAs($anvandare)->get("/containers/{$container->ulid}/items/{$item->ulid}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('images', [])
            ->where('cover', null)
        );
});

/*
 * Klart när: `ItemResource` har inget nytt fält.
 *
 * `app/Http/Resources/**` är `/api`:s format, och den upplösta bilden ligger
 * BREDVID resursen och inte i den — samma linje som `variants` i issue 61b
 * § Beslut 1 och kategorinamnet i issue 57a § Beslut 6. Varken pekaren eller
 * den upplösta bilden läcker ut i svaret.
 */
it('har inget omslagsfält i ItemResource', function () {
    [$konto, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    $item = omslagItem($container);
    omslagVal($item, omslagBilaga($item, 'foto.jpg'));

    $svar = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}", $headers)->assertOk();

    foreach (['cover', 'cover_attachment_id', 'cover_attachment', 'cover_image'] as $falt) {
        expect($svar->json('data'))->not->toHaveKey($falt);
    }
});

/*
 * Omslagsbilden är WEBBENS fält. `UpdateItemRequest` är delad med `/api`, och
 * `cover` finns därför bara i reglerna för ruttnamnet
 * `containers.items.update` — `/api`s PATCH är onamngiven och känner inte
 * igen fältet alls.
 *
 * Det är skillnaden mellan "fältet finns inte här" och "fältet tas emot och
 * kastas": en regel hade gett 422 för ett värde `/api` ändå inte skriver,
 * alltså en validering som lovar en skrivning ingen gör. Testet prövar båda
 * sidorna — en giltig bild-ULID sätter ingen pekare, och en påhittad ULID
 * avvisas inte.
 */
it('känner inte igen omslagsbilden i /api', function () {
    [$konto, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    $item = omslagItem($container);
    $bild = omslagBilaga($item, 'foto.jpg');

    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}";

    patchJson($url, ['cover' => $bild->ulid], $headers)->assertOk();

    expect($item->refresh()->cover_attachment_id)->toBeNull();

    patchJson($url, ['cover' => 'inte-en-ulid'], $headers)
        ->assertOk()
        ->assertJsonMissingValidationErrors('cover');
});

/*
 * Klart när: `attachment` har ingen ny kolumn.
 *
 * Ingen `is_cover`, ingen sorteringsnyckel, ingen flagga. Listan är exakt
 * dokumentets ([[Filer och lagring]] § attachment), och testet faller om en
 * kolumn läggs till — det är hela punkten: vilken bild som är itemets är ett
 * faktum om ITEMET.
 */
it('har ingen ny kolumn på attachment', function () {
    expect(Schema::getColumnListing('attachment'))->toBe([
        'id',
        'ulid',
        'item_id',
        'stored_file_id',
        'filename',
        'kind',
        'uploaded_by_user_id',
        'billed_account_id',
        'deleted_at',
        'created_at',
        'updated_at',
    ]);
});
