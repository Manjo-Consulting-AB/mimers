<?php

use App\Actions\Container\SetContainerCover;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\ImageDerivative;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 292 · Trädets kolumner ur servern. Se
 * App\Actions\Item\ResolveItemTreeRows, App\Http\Controllers\ItemController::
 * index() och [[ADR-0058 Trädet som tabell]] § 1, 3 och 4.
 *
 * Filen prövar svaret, inte upplösningens inre: `treeRows` är item-ULID →
 * `{category, tags, documents, tasks, updated_at, icon, icon_color, cover}` för
 * exakt de items som står i `structure`, och `null` i kartläget.
 *
 * De två talen följer ADR-0028:s omfång: en mottagare med en itemgrant får
 * bara poster för de items trädet bär. Den regeln — och att räkningen aldrig
 * når containern i stort — prövas i
 * tests/Feature/Omfang/StrukturomfangTest.php; här prövas formen och
 * innehållet.
 *
 * Frågekostnaden mäts på SAMMA container före och efter att trädet växer, så
 * att "konstant" betyder konstant och inte "två containrar råkade kosta lika".
 *
 * Hjälparna har prefixet `tradrad` — Pest delar global namnrymd mellan
 * testfilerna.
 */

/**
 * En container med en ägare som når hela containern utan grants.
 *
 * @return array{0: Container, 1: User}
 */
function tradradKontext(): array
{
    $container = Container::factory()->create();
    $anvandare = User::factory()->create();

    $container->account->users()->attach($anvandare, ['role' => 'owner']);

    return [$container, $anvandare];
}

/**
 * @param  array<string, mixed>  $attribut
 */
function tradradItem(Container $container, string $namn, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ], $attribut));
}

/**
 * En `parent`-kant skriven direkt i tabellen, förbi LinkItems — samma skäl som
 * i ItemtradTest: garanten i Actionen ska inte kunna maskera ett fel i
 * räkningen.
 */
function tradradKant(Item $foralder, Item $barn): void
{
    ItemLink::query()->insert([
        'from_item_id' => $foralder->id,
        'to_item_id' => $barn->id,
        'relation' => 'parent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * En bild på itemet, med ett `thumb`-derivat när $thumb är sant — samma
 * handgrepp som itemlistaBild(): derivatet byggs direkt så att `hasThumb` är
 * känt utan att köa ett jobb.
 */
function tradradBild(Item $item, string $namn = 'foto.jpg', bool $thumb = true, string $kind = 'image'): Attachment
{
    $bilaga = Attachment::factory()->for($item, 'item')->create([
        'filename' => $namn,
        'kind' => $kind,
    ]);

    if ($thumb) {
        ImageDerivative::factory()->create([
            'stored_file_id' => $bilaga->stored_file_id,
            'variant' => 'thumb',
            'storage_path' => $bilaga->storedFile->storage_path.'_thumb.jpg',
        ]);
    }

    return $bilaga;
}

function tradradUrl(Container $container, string $query = ''): string
{
    return "/containers/{$container->ulid}/items".($query === '' ? '' : "?{$query}");
}

/**
 * En förekomst på schemat med given status.
 */
function tradradForekomst(Schedule $schema, string $status): ScheduleOccurrence
{
    $attribut = ['schedule_id' => $schema->id, 'status' => $status];

    if ($status === ScheduleOccurrence::STATUS_COMPLETED || $status === ScheduleOccurrence::STATUS_SKIPPED) {
        $attribut['completed_at'] = now();
        $attribut['completed_by_user_id'] = User::factory()->create()->id;
        $attribut['completed_by_account_id'] = $schema->item->container->account_id;
    }

    return ScheduleOccurrence::factory()->create($attribut);
}

/**
 * Antalet frågor $anrop ställer, mätt efter ett omätt anrop — samma mönster
 * som itemtradFragor() och strukturFrågor().
 */
function tradradFragor(Closure $varm, Closure $anrop): int
{
    app()->forgetScopedInstances();

    $varm();

    $antal = 0;

    DB::listen(function () use (&$antal): void {
        $antal++;
    });

    $anrop();

    return $antal;
}

/**
 * Källkoden med kommentarer borta — samma tre slag som GenomgangTest rensar.
 */
function tradradKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * Klart när: i trädläget har `treeRows` exakt en post per item som står i
 * `structure` och inga andra.
 */
it('svarar med exakt en post per item i structure och inga andra', function () {
    withoutVite();

    [$container, $anvandare] = tradradKontext();

    $baten = tradradItem($container, 'Båten');
    $motorn = tradradItem($container, 'Motorn');
    $masten = tradradItem($container, 'Masten');

    tradradKant($baten, $motorn);
    tradradKant($baten, $masten);

    // Varken ett mjukraderat item eller en granne i en annan container hör
    // till strukturen — och får därför ingen rad.
    $raderad = tradradItem($container, 'Raderad');
    $raderad->delete();

    $annan = Container::factory()->create();
    $gramen = tradradItem($annan, 'Grannen');

    actingAs($anvandare)->get(tradradUrl($container, 'view=tree'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Index')
            ->has('structure', 1)
            ->has('treeRows', 3)
            ->has('treeRows.'.$baten->ulid)
            ->has('treeRows.'.$motorn->ulid)
            ->has('treeRows.'.$masten->ulid)
            ->missing('treeRows.'.$raderad->ulid)
            ->missing('treeRows.'.$gramen->ulid)
    );
});

/*
 * Klart när (issue 326 · [[ADR-0067 Itemfliken utan listläge]] § 8): trädet är
 * förvalet, och bara kartläget svarar `null`.
 *
 * Samma form och samma skäl som `structure`: en yta ingen ser ska inte kosta
 * en fråga, och `null` betyder "inte hämtad" medan en tom tabell hade varit
 * ett svar servern hade gett. Sedan issue 326 finns inget listläge kvar —
 * `?view=list` landar i trädet.
 */
it('svarar trädet som förval och null bara i kartläget', function () {
    withoutVite();

    [$container, $anvandare] = tradradKontext();

    $item = tradradItem($container, 'Motorn');

    foreach (['', 'view=list', 'view=tree', 'view=okant'] as $fraga) {
        actingAs($anvandare)->get(tradradUrl($container, $fraga))->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('view', 'tree')
                ->has('treeRows.'.$item->ulid)
        );
    }

    actingAs($anvandare)->get(tradradUrl($container, 'view=map'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('view', 'map')
            ->where('treeRows', null)
    );
});

/*
 * Klart när: `category` är kategorins namn eller `null`.
 */
it('ger kategorins namn eller null', function () {
    withoutVite();

    [$container, $anvandare] = tradradKontext();

    $kategorin = Category::factory()->for($container, 'container')->create(['name' => 'El']);

    $medKategori = tradradItem($container, 'Motorn', ['category_id' => $kategorin->id]);
    $utanKategori = tradradItem($container, 'Masten');

    actingAs($anvandare)->get(tradradUrl($container, 'view=tree'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('treeRows.'.$medKategori->ulid.'.category', 'El')
            ->where('treeRows.'.$utanKategori->ulid.'.category', null)
    );
});

/*
 * Klart när: `tags` är `{ulid, name, color}` sorterade på namnet.
 */
it('ger taggarna som ulid, namn och färg i namnordning', function () {
    withoutVite();

    [$container, $anvandare] = tradradKontext();

    $item = tradradItem($container, 'Motorn');

    $beta = Tag::factory()->for($container, 'container')->create(['name' => 'Beta', 'color' => null]);
    $alfa = Tag::factory()->for($container, 'container')->create(['name' => 'Alfa', 'color' => '#1f6feb']);

    // Kopplas i omvänd ordning: sorteringen ska komma ur upplösningen och
    // inte ur pivottabellens ordning.
    $item->tags()->attach([$beta->id, $alfa->id]);

    actingAs($anvandare)->get(tradradUrl($container, 'view=tree'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('treeRows.'.$item->ulid.'.tags', [
            ['ulid' => $alfa->ulid, 'name' => 'Alfa', 'color' => '#1f6feb'],
            ['ulid' => $beta->ulid, 'name' => 'Beta', 'color' => null],
        ])
    );
});

/*
 * Klart när: `documents` räknar itemets egna levande bilagor, inte barnens;
 * en mjukraderad bilaga räknas inte.
 */
it('räknar itemets egna levande bilagor och inte barnens', function () {
    withoutVite();

    [$container, $anvandare] = tradradKontext();

    $foraldern = tradradItem($container, 'Motorn');
    $barnet = tradradItem($container, 'Impellern');
    tradradKant($foraldern, $barnet);

    Attachment::factory()->for($foraldern, 'item')->create();
    $borttagen = Attachment::factory()->for($foraldern, 'item')->create();
    $borttagen->delete();

    Attachment::factory()->count(2)->for($barnet, 'item')->create();

    actingAs($anvandare)->get(tradradUrl($container, 'view=tree'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('treeRows.'.$foraldern->ulid.'.documents', 1)
            ->where('treeRows.'.$barnet->ulid.'.documents', 2)
    );
});

/*
 * Klart när: `tasks` räknar förekomster i `open` och `in_progress` på itemets
 * egna levande scheman; `completed`, `skipped` och mjukraderade scheman räknas
 * inte.
 */
it('räknar aktiva förekomster på itemets egna levande scheman', function () {
    withoutVite();

    [$container, $anvandare] = tradradKontext();

    $item = tradradItem($container, 'Motorn');
    $barnet = tradradItem($container, 'Impellern');
    tradradKant($item, $barnet);

    $oppet = Schedule::factory()->for($item, 'item')->create();
    tradradForekomst($oppet, ScheduleOccurrence::STATUS_OPEN);

    $pagaende = Schedule::factory()->for($item, 'item')->create();
    tradradForekomst($pagaende, ScheduleOccurrence::STATUS_IN_PROGRESS);

    $klart = Schedule::factory()->for($item, 'item')->create();
    tradradForekomst($klart, ScheduleOccurrence::STATUS_COMPLETED);

    $overhoppat = Schedule::factory()->for($item, 'item')->create();
    tradradForekomst($overhoppat, ScheduleOccurrence::STATUS_SKIPPED);

    $raderat = Schedule::factory()->for($item, 'item')->create();
    tradradForekomst($raderat, ScheduleOccurrence::STATUS_OPEN);
    $raderat->delete();

    // Barnets uppgift hör till barnet: siffran är itemets egen.
    $barnetsSchema = Schedule::factory()->for($barnet, 'item')->create();
    tradradForekomst($barnetsSchema, ScheduleOccurrence::STATUS_OPEN);

    actingAs($anvandare)->get(tradradUrl($container, 'view=tree'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('treeRows.'.$item->ulid.'.tasks', 2)
            ->where('treeRows.'.$barnet->ulid.'.tasks', 1)
    );
});

/*
 * Klart när (issue 304 · [[ADR-0060 Itemets ikon]] § 6 och 10): `icon` och
 * `icon_color` står i radens celler, med `null` när itemet inte valt någon.
 *
 * Fälten hämtas i samma fråga som de övriga kolumnerna, så trädets
 * frågekostnad är oförändrad — det prövas av provet nedanför.
 */
it('ger itemets ikon och färg i radens celler', function () {
    withoutVite();

    [$container, $anvandare] = tradradKontext();

    $medIkon = tradradItem($container, 'Motorn', [
        'icon' => 'droplet',
        'icon_color' => 'blue',
    ]);
    $utanIkon = tradradItem($container, 'Masten');

    actingAs($anvandare)->get(tradradUrl($container, 'view=tree'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('treeRows.'.$medIkon->ulid.'.icon', 'droplet')
            ->where('treeRows.'.$medIkon->ulid.'.icon_color', 'blue')
            ->where('treeRows.'.$utanIkon->ulid.'.icon', null)
            ->where('treeRows.'.$utanIkon->ulid.'.icon_color', null)
    );
});

/*
 * Klart när: `updated_at` är itemets `updated_at` i ISO 8601, UTC.
 */
it('formaterar updated_at som ISO 8601 i UTC', function () {
    withoutVite();

    [$container, $anvandare] = tradradKontext();

    $item = tradradItem($container, 'Motorn');

    // Förbi modellens tidsstämpel: värdet ska vara känt, inte "strax före nu".
    DB::table('item')->where('id', $item->id)->update(['updated_at' => '2026-05-04 10:11:12']);

    actingAs($anvandare)->get(tradradUrl($container, 'view=tree'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('treeRows.'.$item->ulid.'.updated_at', '2026-05-04T10:11:12+00:00')
    );
});

/*
 * Klart när: ett item med två föräldrar har en post.
 *
 * Noderna är förekomster — itemet ritas två gånger — men siffrorna hör till
 * itemet, så kartan nycklas på ULID och bär en enda rad (ADR-0058 § 3).
 */
it('ger ett item med två föräldrar en enda rad', function () {
    withoutVite();

    [$container, $anvandare] = tradradKontext();

    $baten = tradradItem($container, 'Båten');
    $masten = tradradItem($container, 'Masten');
    $impellern = tradradItem($container, 'Impellern');

    tradradKant($baten, $impellern);
    tradradKant($masten, $impellern);

    Attachment::factory()->for($impellern, 'item')->create();

    actingAs($anvandare)->get(tradradUrl($container, 'view=tree'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            // Trädet ritar samma item under båda föräldrarna.
            ->has('structure', 2)
            ->where('structure.0.children.0.ulid', $impellern->ulid)
            ->where('structure.1.children.0.ulid', $impellern->ulid)
            // Men raden finns en gång, med itemets egna siffror.
            ->has('treeRows', 3)
            ->where('treeRows.'.$impellern->ulid.'.documents', 1)
    );
});

/*
 * Klart när (issue 326 · [[ADR-0067 Itemfliken utan listläge]] § 4): raden bär
 * itemets omslag, med samma form som listan hade.
 *
 * `cover` är `{ulid, hasThumb, chosen}` eller `null`, ur
 * App\Actions\Item\ResolveItemCover::thumbnails(). Den valda bilden är den
 * NYASTE av två, så att skillnaden mellan "vald" och "äldst" syns: `chosen`
 * är sann för den valda och falsk för den automatiska. Ett item utan bild
 * svarar null i stället för att saknas.
 */
it('ger varje rad sitt omslag med chosen och null för ett item utan bild', function () {
    withoutVite();

    [$container, $anvandare] = tradradKontext();

    $vald = tradradItem($container, 'Motorn');
    tradradBild($vald, 'gammal.jpg', false);
    $ny = tradradBild($vald, 'ny.jpg', true);
    $vald->cover_attachment_id = $ny->id;
    $vald->save();

    $automatisk = tradradItem($container, 'Masten');
    $aldst = tradradBild($automatisk, 'foto.jpg', false);

    $utanBild = tradradItem($container, 'Seglet');

    actingAs($anvandare)->get(tradradUrl($container, 'view=tree'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('treeRows.'.$vald->ulid.'.cover', [
                'ulid' => $ny->ulid,
                'hasThumb' => true,
                'chosen' => true,
            ])
            ->where('treeRows.'.$automatisk->ulid.'.cover', [
                'ulid' => $aldst->ulid,
                'hasThumb' => false,
                'chosen' => false,
            ])
            ->where('treeRows.'.$utanBild->ulid.'.cover', null)
    );
});

/*
 * Klart när (issue 326 · [[ADR-0067 Itemfliken utan listläge]] § 4): radens
 * omslag är itemets eget, aldrig containerns.
 *
 * Containern har en egen bild satt genom samma action som rutten använder,
 * men itemet har inga bilder — och då är radens `cover` null, inte containerns
 * foto. Utan `expect`-raden hade provet varit grönt även om bilden aldrig
 * satts och bevisat noll.
 */
it('tar aldrig containerns omslag till en rad', function () {
    withoutVite();

    [$container, $anvandare] = tradradKontext();

    actingAs($anvandare);

    $containerbild = app(SetContainerCover::class)->handle(
        $container,
        UploadedFile::fake()->image('container.png'),
        $anvandare,
        $container->account,
    );

    expect($container->refresh()->cover_attachment_id)->toBe($containerbild->id);

    $item = tradradItem($container, 'Motorn');

    actingAs($anvandare)->get(tradradUrl($container, 'view=tree'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('treeRows.'.$item->ulid.'.cover', null)
    );
});

/*
 * Klart när (issue 326 · [[ADR-0067 Itemfliken utan listläge]] § 4): antalet
 * frågor i trädläget är detsamma för 3 och 30 items, också när raderna har
 * bilder.
 *
 * Omslaget hämtas en gång för hela trädet i stället för en gång per rad
 * (App\Actions\Item\ResolveItemCover::forItems()), så bilderna läggs på alla
 * items och mätningen jämför samma frågor före och efter att skogen växer.
 */
it('kostar samma antal frågor för tre och trettio items, också med bilder', function () {
    withoutVite();

    [$container, $anvandare] = tradradKontext();

    $kategorin = Category::factory()->for($container, 'container')->create(['name' => 'El']);

    $baten = tradradItem($container, 'Båten', ['category_id' => $kategorin->id]);
    $motorn = tradradItem($container, 'Motorn');
    $masten = tradradItem($container, 'Masten');

    tradradKant($baten, $motorn);
    tradradKant($baten, $masten);

    // Två bilder var, med ett derivat på den ena: en upplösning som vandrade
    // per rad — eller frågade efter varje bilds derivat — hade vuxit.
    foreach ([$baten, $motorn, $masten] as $item) {
        tradradBild($item, 'ett.jpg', false);
        tradradBild($item, 'tva.jpg', true);
    }

    // En tagg på ett item gör att tagguppslaget alltid har något att hämta —
    // mätningen ska jämföra samma frågor, inte råka tappa en tom sådan.
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'El']);
    $baten->tags()->attach($tagg);

    // Frys tiden runt mätningarna så uppdateringen av senast aktiv skriver
    // deterministiskt (issue 80 och 477).
    Carbon::setTestNow(now());

    $medTre = tradradFragor(
        fn () => actingAs($anvandare)->get(tradradUrl($container, 'view=tree')),
        fn () => actingAs($anvandare)->get(tradradUrl($container, 'view=tree')),
    );

    foreach (range(1, 30) as $i) {
        $barn = tradradItem($container, sprintf('Del %02d', $i));
        tradradKant($baten, $barn);
        tradradBild($barn, 'ett.jpg', false);
        tradradBild($barn, 'tva.jpg', true);
    }

    $medTrettiotre = tradradFragor(
        fn () => actingAs($anvandare)->get(tradradUrl($container, 'view=tree')),
        fn () => actingAs($anvandare)->get(tradradUrl($container, 'view=tree')),
    );

    Carbon::setTestNow();

    expect($medTrettiotre)->toBe(
        $medTre,
        'trädraderna kostar fler frågor när trädet växer — räkningen går per item',
    );
});

/*
 * Klart när: Index.vue tar emot `treeRows` som propp med `null` som standard.
 *
 * Sedan issue 294 ritar trädläget tabellen och mallen läser `treeRows`:
 * kolumnerna kommer därifrån och räknas inte i klienten
 * ([[ADR-0058 Trädet som tabell]] § 1). Proppen finns fortfarande BARA i
 * trädläget — `null` betyder "inte hämtad" — så den som öppnar listan slipper
 * betala för kolumner hon inte ser.
 */
it('tar emot treeRows som propp med null som standard och skickar den till tabellen', function () {
    $vy = tradradKod('pages/Containers/Items/Index.vue');

    expect($vy)->toContain('treeRows: { type: Object, default: null }');

    // Trädläget ritar trädet ur `structure` och kolumnerna ur `treeRows`
    // (issue 294): tabellen får båda, och vyn räknar ingenting själv.
    $mal = substr($vy, (int) strpos($vy, '<template>'));

    expect($mal)->toContain(':nodes="structure ?? []"')
        ->toContain(':tree-rows="treeRows ?? {}"');
});
