<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 57a · Itemets detaljvy. Se App\Http\Controllers\ItemController::show(),
 * App\Http\Resources\ItemResource och
 * resources/js/pages/Containers/Items/Show.vue.
 *
 * Den viktigaste gränsen i filen är AUKTORISERINGEN (Beslut 5): grinden är
 * ITEMETS egen, inte containerns, och ett item i containern men utanför
 * mottagarens omfång ger 403 — inte 404. "Känd men utanför omfånget" har en
 * kod över tio kontrollrar (issue 73 § Beslut 3), och webben uppfinner inte en
 * elfte regel.
 *
 * Den andra är att flaggorna är PRESENTATION (Beslut 6 och 7). Sedan issue 57b
 * ritar vyn skrivytor — redigera-länken och radera-knappen — och de sitter
 * bakom `can.update` respektive `can.delete`: en yta användaren inte får
 * använda ritas inte alls ([[M10 Webbfrontend]] § 57). Det som prövas här är
 * därför `can`-värdena, och att mallen faktiskt villkorar ytorna med dem.
 * Skrivningarnas egna grindar prövas i ItemformularTest.
 *
 * Hjälparna har prefixet `itemdetalj` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem i angiven roll, och en container ägd av kontot.
 *
 * @param  array<string, mixed>  $kontoAttribut
 * @return array{0: Account, 1: User, 2: Container}
 */
function itemdetaljKontext(array $kontoAttribut = []): array
{
    $konto = Account::factory()->create($kontoAttribut);
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

/**
 * Ett item i containern med sammanhängande `created_by_*`.
 *
 * @param  array<string, mixed>  $attribut
 */
function itemdetaljItem(Container $container, string $namn, ?User $skapare = null, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'name' => $namn,
        'created_by_user_id' => ($skapare ?? User::factory()->create())->id,
        'created_by_account_id' => $container->account_id,
    ], $attribut));
}

/**
 * En mottagare UTANFÖR ägarkontot: en itemgrant när $item ges, en
 * container-bred grant annars.
 */
function itemdetaljMottagare(Container $container, ?Item $item = null, string $nivå = 'read'): User
{
    $mottagare = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $nivå,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/**
 * Kör en snutt mot resources/js/components/itemPresentation.js i node och
 * returnerar det som skrivs på stdout.
 *
 * `$tz` sätts i miljön för processen: tidszonsregeln i formatDateOnly() går
 * bara att pröva i en zon med negativ offset, där den naiva vägen visar dagen
 * före.
 */
function itemdetaljKör(string $skript, string $tz = 'UTC'): string
{
    $kod = implode("\n", [
        "const { pathToFileURL } = await import('node:url');",
        'const m = await import(pathToFileURL('
            .json_encode(resource_path('js/components/itemPresentation.js'), JSON_UNESCAPED_SLASHES).').href);',
        $skript,
    ]);

    $rader = [];
    $status = 0;

    exec('TZ='.escapeshellarg($tz).' node --input-type=module -e '.escapeshellarg($kod).' 2>&1', $rader, $status);

    expect($status)->toBe(0, implode("\n", $rader));

    return implode("\n", $rader);
}

/*
 * Klart när: `/containers/{c}/items/{item}` visar itemets fält, kategorin och
 * taggarna för den som når itemet.
 */
it('visar itemets fält, kategorin och taggarna för den som når itemet', function () {
    withoutVite();

    [, $anvandare, $container] = itemdetaljKontext();

    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Framdrivning']);

    $motorn = itemdetaljItem($container, 'Motorn', $anvandare, [
        'description' => 'En diesel.',
        'manufacturer' => 'Yanmar',
        'model' => '3YM30',
        'serial_number' => 'SN-42',
        'purchased_at' => '2024-05-17',
        'warranty_until' => '2027-05-17',
        'position_note' => 'Bakom panelen',
        'category_id' => $kategori->id,
    ]);

    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor', 'color' => '#ff0000']);
    $motorn->tags()->attach([$tagg->id]);

    actingAs($anvandare)->get("/containers/{$container->ulid}/items/{$motorn->ulid}")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Show')
            ->where('container.ulid', $container->ulid)
            ->where('item.ulid', $motorn->ulid)
            ->where('item.name', 'Motorn')
            ->where('item.description', 'En diesel.')
            ->where('item.manufacturer', 'Yanmar')
            ->where('item.model', '3YM30')
            ->where('item.serial_number', 'SN-42')
            // DATE-kolumnerna bär `Y-m-d` rakt igenom — ingen ISO-tidsstämpel.
            ->where('item.purchased_at', '2024-05-17')
            ->where('item.warranty_until', '2027-05-17')
            ->where('item.position_note', 'Bakom panelen')
            ->where('item.category', $kategori->ulid)
            ->where('item.tags.0.name', 'Motor')
            ->where('item.tags.0.color', '#ff0000')
            // Kategorinamnet kommer BREDVID resursen, aldrig inuti den: varken
            // löpnummer eller främmande nycklar någonsin.
            ->where("categories.{$kategori->ulid}", 'Framdrivning')
            ->missing('item.container_id')
            ->missing('item.category_id')
    );
});

/*
 * Klart när: ett item i containern men utanför mottagarens omfång ger 403 på
 * detaljvyn, inte 404.
 *
 * Samma item som mottagaren NÅR svarar 200 i samma test — annars hade 403:an
 * kunnat vara en trasig rutt.
 */
it('ger 403 och inte 404 för ett item i containern men utanför omfånget', function () {
    withoutVite();

    [, , $container] = itemdetaljKontext();

    $motorn = itemdetaljItem($container, 'Motorn');
    $masten = itemdetaljItem($container, 'Masten');

    $mottagare = itemdetaljMottagare($container, $motorn);

    actingAs($mottagare)
        ->get("/containers/{$container->ulid}/items/{$motorn->ulid}")
        ->assertOk();

    actingAs($mottagare)
        ->get("/containers/{$container->ulid}/items/{$masten->ulid}")
        ->assertForbidden();
});

/*
 * Klart när: en item-ULID från en annan container ger 404 på den här containerns rutt.
 *
 * `scopeBindings()` löser `{item}` genom containerns `items()`-relation
 * (Beslut 1) — samma skydd som routes/api.php sätter på sin grupp.
 */
it('ger 404 för en item-ULID från en annan container', function () {
    withoutVite();

    [$konto, $anvandare, $container] = itemdetaljKontext();
    $annan = Container::factory()->for($konto, 'account')->create();

    $främmande = itemdetaljItem($annan, 'Motorn');

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}/items/{$främmande->ulid}")
        ->assertNotFound();
});

/*
 * Klart när: en användare med bara `read` ser detaljvyn utan redigerings-,
 * raderings- och skapandeytor.
 *
 * Flaggan är presentation och grinden är policyn (Beslut 6): `ItemPolicy::
 * update()`, `delete()` och `create()` ligger alla över `read` i laddern.
 * Vyn ritar ingen av ytorna alls — 57b är den som ritar dem, och en knapp
 * till en rutt som inte finns är precis den knapp Beslut 7 förbjuder.
 */
it('ger en read-innehavare en detaljvy utan skrivytor', function () {
    withoutVite();

    [, , $container] = itemdetaljKontext();
    $motorn = itemdetaljItem($container, 'Motorn');

    $läsare = itemdetaljMottagare($container, null, 'read');

    actingAs($läsare)->get("/containers/{$container->ulid}/items/{$motorn->ulid}")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('item.ulid', $motorn->ulid)
            ->where('can.update', false)
            ->where('can.delete', false)
            ->where('can.create', false)
    );

    // Skrivytorna ritas BARA bakom sina flaggor. Issue 57b lade dem i mallen
    // (redigera-länken och radera-knappen), och regeln är att en yta
    // användaren inte får använda inte ritas — [[M10 Webbfrontend]] § 57: "Ett
    // item användaren bara har `read` på visas utan redigeringsytor, inte med
    // knappar som ger felkod." Flaggorna är alltså hela villkoret.
    $vy = File::get(resource_path('js/pages/Containers/Items/Show.vue'));

    expect($vy)->toContain('v-if="can.update"');
    expect($vy)->toContain('v-if="can.delete"');
});

/*
 * Klart när: en användare med `write` ser redigeringsytan; `can.delete` är
 * falskt för henne och sant för en `delete`-innehavare.
 *
 * `write` ligger över `create` i laddern, så `can.create` följer med — det är
 * flaggan 57b ritar barn-itemets skapayta efter.
 */
it('skiljer can.update från can.delete i laddern', function () {
    withoutVite();

    [, , $container] = itemdetaljKontext();
    $motorn = itemdetaljItem($container, 'Motorn');

    $skrivare = itemdetaljMottagare($container, null, 'write');
    $raderare = itemdetaljMottagare($container, null, 'delete');

    $url = "/containers/{$container->ulid}/items/{$motorn->ulid}";

    actingAs($skrivare)->get($url)->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('can.update', true)
            ->where('can.delete', false)
            ->where('can.create', true)
    );

    actingAs($raderare)->get($url)->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('can.update', true)
            ->where('can.delete', true)
    );
});

/*
 * Klart när: ett `read_only`-ägarkonto tillåter listning och detaljvy men ger
 * `can.update`/`can.create`/`can.delete` falskt.
 *
 * Regel 4: kontots skrivspärr. Läsning påverkas aldrig (ItemPolicy::view()
 * hoppar över fryst-kontrollen med flit), och varje skrivande grind nekar
 * oavsett nivå.
 */
it('låter ett fryst ägarkonto se itemet men nekar varje skrivflagga', function () {
    withoutVite();

    [, $anvandare, $container] = itemdetaljKontext(['status' => 'read_only']);
    $motorn = itemdetaljItem($container, 'Motorn', $anvandare);

    actingAs($anvandare)->get("/containers/{$container->ulid}")->assertOk();

    actingAs($anvandare)->get("/containers/{$container->ulid}/items/{$motorn->ulid}")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('item.ulid', $motorn->ulid)
            ->where('can.update', false)
            ->where('can.delete', false)
            ->where('can.create', false)
    );
});

/*
 * Klart när: ett tomt fält visas som tomt eller utelämnas — men vyn hittar
 * inte på ett värde.
 *
 * Regeln bor i itemFields() i resources/js/components/itemPresentation.js, och
 * prövas därför i node på exakt den modul klienten importerar. Ett item med
 * bara `manufacturer` satt ska ge EN rad.
 *
 * Sedan issue 102 är listan fältraderna på översiktsfliken, och `description`
 * och `notes` står i indata men inte i svaret: de är vyns ledande stycken,
 * över listan, och aldrig en rad bland tillverkare och modell. Att de har
 * värden här är hela beviset — hade de varit kvar i listan hade de synts.
 */
it('utelämnar tomma fält i stället för att hitta på ett värde', function () {
    $item = json_encode([
        'description' => 'En diesel.',
        'notes' => 'Bytte impeller 2024.',
        'manufacturer' => 'Yanmar',
        'model' => null,
        'serial_number' => null,
        'purchased_at' => null,
        'warranty_until' => null,
        'position_note' => '',
    ], JSON_UNESCAPED_UNICODE);

    $rader = itemdetaljKör("process.stdout.write(JSON.stringify(m.itemFields({$item}, 'sv-SE')));");

    expect($rader)->toBe('[{"key":"manufacturer","value":"Yanmar"}]');
});

/*
 * Klart när: datumen formateras för användaren utan att räknas om till en
 * annan tidszon.
 *
 * Prövas i en zon med NEGATIV offset (Beslut 8): den naiva vägen — `new
 * Date("2024-05-17")` tolkas som UTC midnatt och `toLocaleDateString()` — hade
 * visat 2024-05-16 för en användare där. Kolumnen är ett DATE utan tid, och
 * datumet får inte flytta sig för att användaren bor någon annanstans.
 */
it('formaterar datumet utan att flytta det över en tidszon', function () {
    expect(itemdetaljKör("process.stdout.write(m.formatDateOnly('2024-05-17', 'sv-SE'));"))
        ->toBe('2024-05-17');

    expect(itemdetaljKör("process.stdout.write(m.formatDateOnly('2024-05-17', 'sv-SE'));", 'America/Los_Angeles'))
        ->toBe('2024-05-17');

    expect(itemdetaljKör("process.stdout.write(String(m.formatDateOnly(null, 'sv-SE')));"))
        ->toBe('null');
});
