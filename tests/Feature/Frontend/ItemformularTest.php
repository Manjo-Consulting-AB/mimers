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
use function Pest\Laravel\delete;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 57b · Itemformuläret — skapa, redigera och radera, se
 * App\Http\Controllers\ItemController::create/store/edit/update/destroy,
 * resources/js/pages/Containers/Items/Create.vue och Edit.vue och
 * resources/js/components/ItemForm.vue.
 *
 * Den viktigaste gränsen i filen är DE TRE GRINDARNA (§ Beslut 2). Det är tre
 * skilda pinnar på laddern — `createItem` på CONTAINERN, `update` på itemet och
 * `delete` på itemet — och den som blandar ihop dem ger en itemgrant en rot i
 * containern, eller en `write`-mottagare rätt att radera. Varje pinne har minst
 * ett test som bevisar att den nekande grannen nekas.
 *
 * Den andra är att `category` och `tags` skickas ALLTID (§ Beslut 6). Webben
 * har ingen `has()`-gren, och det som prövas är därför att `category: null`
 * tömmer kategorin och `tags: []` tömmer taggmängden — i samma PATCH som
 * fyller dem.
 *
 * Att `/api/containers/{container}/items` svarar exakt som förut prövas av
 * tests/Feature/Item/**, som är gröna utan en enda ändrad förväntan: den här
 * issuen rör ingen rad i Api\ItemController. Det sista testet här är bara en
 * rök koll att de tre API-verben fortfarande svarar 201/200/204.
 *
 * Hjälparna har prefixet `itemform` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem i angiven roll, och en container ägd av kontot.
 *
 * @param  array<string, mixed>  $kontoAttribut
 * @return array{0: Account, 1: User, 2: Container}
 */
function itemformKontext(array $kontoAttribut = []): array
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
function itemformItem(Container $container, string $namn, ?User $skapare = null, array $attribut = []): Item
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
function itemformMottagare(Container $container, ?Item $item = null, string $nivå = 'read'): User
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
 * Ett eget konto åt $mottagare — det hon tillskriver sina egna rader.
 *
 * En mottagare UTANFÖR ägarkontot kan inte skriva i ägarkontots namn; det ger
 * 403 (§ Beslut 4). Ett item hon skapar tillskrivs därför hennes eget konto,
 * och medlemskapet i DET kontot är vad som gör skrivningen tillåten.
 */
function itemformEgetKonto(User $mottagare): Account
{
    $konto = Account::factory()->create();
    $konto->users()->attach($mottagare, ['role' => 'owner']);

    return $konto;
}

/**
 * Formulärkroppen, med de två obligatoriska fälten satta.
 *
 * @param  array<string, mixed>  $overskrid
 * @return array<string, mixed>
 */
function itemformKropp(Account $konto, array $overskrid = []): array
{
    return array_merge([
        'name' => 'Motorn',
        'account' => $konto->ulid,
    ], $overskrid);
}

/*
 * Beslut 1: alla fem rutter ligger bakom `auth`. En utloggad besökare skickas
 * till inloggningen och når aldrig en kontrollermetod.
 */
it('skickar en utloggad besökare till inloggningen från alla fem rutter', function () {
    withoutVite();

    [, , $container] = itemformKontext();
    $item = itemformItem($container, 'Motorn');

    get("/containers/{$container->ulid}/items/create")->assertRedirect('/login');
    post("/containers/{$container->ulid}/items", [])->assertRedirect('/login');
    get("/containers/{$container->ulid}/items/{$item->ulid}/edit")->assertRedirect('/login');
    patch("/containers/{$container->ulid}/items/{$item->ulid}", [])->assertRedirect('/login');
    delete("/containers/{$container->ulid}/items/{$item->ulid}")->assertRedirect('/login');
});

/*
 * Klart när: `/containers/{c}/items/create` når formuläret — rutten för
 * detaljvyn skuggar den inte.
 *
 * Det är hela skälet att skapar-rutten har en plats i routes/web.php och inte
 * bara en rutt (§ Beslut 1): utan den ligger `{item}` först och binder
 * strängen `create`, och svaret blir 404 i stället för ett formulär.
 */
it('når skapaformuläret på /items/create och inte detaljvyn', function () {
    withoutVite();

    [, $anvandare, $container] = itemformKontext();

    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Framdrivning']);
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor', 'color' => '#ff0000']);

    expect(route('containers.items.create', $container, false))
        ->toBe("/containers/{$container->ulid}/items/create");

    actingAs($anvandare)->get("/containers/{$container->ulid}/items/create")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Create')
            ->where('container.ulid', $container->ulid)
            ->where('categories.0.ulid', $kategori->ulid)
            ->where('categories.0.name', 'Framdrivning')
            ->where('tags.0.ulid', $tagg->ulid)
            ->where('tags.0.color', '#ff0000')
    );
});

/*
 * Klart när: en medlem i ägarkontot skapar ett item från
 * `/containers/{c}/items/create` och landar på dess detaljvy.
 *
 * Klart när: det skapade itemet bär vald kategori och valda taggar, och
 * `created_by_account` är det valda kontot.
 *
 * `created_by_user_id` kommer ur token och aldrig ur kroppen (§ Beslut 4) —
 * ett klientskickat `created_by_user_id` finns inte i reglerna och når aldrig
 * `validated()`.
 */
it('skapar ett item med kategori och taggar och landar på detaljvyn', function () {
    withoutVite();

    [$konto, $anvandare, $container] = itemformKontext();

    $kategori = Category::factory()->for($container, 'container')->create();
    $tagg = Tag::factory()->for($container, 'container')->create();

    $svar = actingAs($anvandare)->post("/containers/{$container->ulid}/items", itemformKropp($konto, [
        'description' => 'En diesel.',
        'manufacturer' => 'Yanmar',
        'purchased_at' => '2024-05-17',
        'category' => $kategori->ulid,
        'tags' => [$tagg->ulid],
    ]));

    $item = Item::query()->where('container_id', $container->id)->sole();

    $svar->assertRedirect("/containers/{$container->ulid}/items/{$item->ulid}")
        ->assertSessionHas('status', 'item-created');

    expect($item->name)->toBe('Motorn')
        ->and($item->description)->toBe('En diesel.')
        ->and($item->manufacturer)->toBe('Yanmar')
        ->and($item->purchased_at->toDateString())->toBe('2024-05-17')
        ->and($item->category_id)->toBe($kategori->id)
        ->and($item->container_id)->toBe($container->id)
        ->and($item->created_by_user_id)->toBe($anvandare->id)
        ->and($item->created_by_account_id)->toBe($konto->id)
        ->and($item->tags()->pluck('tag.id')->all())->toBe([$tagg->id]);
});

/*
 * Klart när: en användare som är medlem i exakt ett konto får inget kontoval,
 * och itemet tillskrivs det kontot.
 *
 * Kravet på `account` är serverns — det finns inget "aktivt konto" (§ Beslut
 * 4) — men en väljare med ett val är brus. Regeln är klientens, och den prövas
 * därför i mallen: ItemForm ritar en läsbar rad när `auth.accounts` har exakt
 * ett element, och en väljare annars. `auth.accounts` är den DELADE propen och
 * aldrig en egen sidprop (Beslut 4, samma linje som issue 54 § Beslut 5).
 *
 * Skapandet prövas servervänt i samma test: kontot är det enda hon är medlem
 * i, och itemet tillskrivs det.
 */
it('ritar inget kontoval för en medlem i exakt ett konto', function () {
    withoutVite();

    [$konto, $anvandare, $container] = itemformKontext();

    actingAs($anvandare)->get("/containers/{$container->ulid}/items/create")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Create')
            // Den delade propen bär exakt ett konto — det är hela villkoret.
            ->has('auth.accounts', 1)
            ->where('auth.accounts.0.ulid', $konto->ulid)
            // Ingen egen kontoprop: listan kommer ur `auth.accounts`.
            ->missing('accounts')
    );

    actingAs($anvandare)->post("/containers/{$container->ulid}/items", itemformKropp($konto))
        ->assertRedirect();

    expect(Item::query()->sole()->created_by_account_id)->toBe($konto->id);

    $form = File::get(resource_path('js/components/ItemForm.vue'));

    // Ett konto: läsbar rad. Flera: väljare. Ingetdera i redigeringsläget.
    expect($form)->toContain('v-if="item === null && singleAccount"')
        ->toContain('v-else-if="item === null"');

    $create = File::get(resource_path('js/pages/Containers/Items/Create.vue'));

    expect($create)->toContain('page.props.auth?.accounts')
        ->toContain(':accounts="accounts"')
        // Containerns ägarkonto förvalt när användaren är medlem i det.
        ->toContain('props.container.account');
});

/*
 * Klart när: ett konto användaren inte är medlem i ger 403, inte ett sparat
 * item.
 *
 * Medlemsprövningen är INTE en policyfråga om containern (§ Beslut 2) — att svara
 * i ett kontos namn är en annan fråga än att få skriva i containern — så den
 * ligger i kontrollern och ger 403, precis som i `Api\ItemController::store()`.
 * Att ingenting skrivs är halva kravet: ett 403 efter en sparad rad vore
 * värre än inget 403 alls.
 */
it('nekar ett konto användaren inte är medlem i med 403 och sparar ingenting', function () {
    withoutVite();

    [, $anvandare, $container] = itemformKontext();
    $frammande = Account::factory()->create();

    actingAs($anvandare)
        ->post("/containers/{$container->ulid}/items", itemformKropp($frammande))
        ->assertForbidden();

    expect(Item::query()->count())->toBe(0);
});

/*
 * Klart när: `PATCH` ändrar fälten, byter kategori, tömmer kategorin och
 * ersätter hela taggmängden — inklusive att tömma den.
 *
 * Webben skickar ALLTID `category` och `tags` (§ Beslut 6), så `category: null`
 * och `tags: []` är inte "rör inte" utan "töm". Det är skillnaden mot en
 * partiell PATCH från `/api`, och den finns bara där — kontrollern har ingen
 * `has()`-gren att skriva av.
 */
it('ändrar fälten, byter kategori och ersätter hela taggmängden', function () {
    withoutVite();

    [$konto, $anvandare, $container] = itemformKontext();

    $framdrivning = Category::factory()->for($container, 'container')->create(['name' => 'Framdrivning']);
    $el = Category::factory()->for($container, 'container')->create(['name' => 'El']);

    $motor = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $viktig = Tag::factory()->for($container, 'container')->create(['name' => 'Viktig']);

    $item = itemformItem($container, 'Motorn', $anvandare, ['category_id' => $framdrivning->id]);
    $item->tags()->attach([$motor->id]);

    $url = "/containers/{$container->ulid}/items/{$item->ulid}";

    // Fälten ändras, kategorin byts, taggmängden ersätts helt.
    actingAs($anvandare)->patch($url, itemformKropp($konto, [
        'name' => 'Nya motorn',
        'description' => 'Bytte impeller.',
        'manufacturer' => 'Yanmar',
        'model' => '3YM30',
        'serial_number' => 'SN-42',
        'purchased_at' => '2024-05-17',
        'warranty_until' => '2027-05-17',
        'position_note' => 'Bakom panelen',
        'category' => $el->ulid,
        'tags' => [$viktig->ulid],
    ]))->assertRedirect($url)->assertSessionHas('status', 'item-updated');

    $item->refresh();

    expect($item->name)->toBe('Nya motorn')
        ->and($item->description)->toBe('Bytte impeller.')
        ->and($item->serial_number)->toBe('SN-42')
        ->and($item->warranty_until->toDateString())->toBe('2027-05-17')
        ->and($item->category_id)->toBe($el->id)
        ->and($item->tags()->pluck('tag.id')->all())->toBe([$viktig->id]);

    // Kategorin töms med `null` och taggarna med `[]`.
    actingAs($anvandare)->patch($url, itemformKropp($konto, [
        'name' => 'Nya motorn',
        'category' => null,
        'tags' => [],
    ]))->assertRedirect($url);

    $item->refresh();

    expect($item->category_id)->toBeNull()
        ->and($item->tags()->count())->toBe(0);
});

/*
 * Klart när: ett `name` som saknas och ett `purchased_at` som inte är `Y-m-d`
 * visas som fel vid sitt eget fält, och formuläret behåller det användaren
 * skrev.
 *
 * Webben kör Inertia och behåller Laravels vanliga valideringsfel (§ Beslut 7,
 * AGENTS.md § Felformat: höljet gäller `/api`). `date_format:Y-m-d` och inte
 * `date` — det senare accepterar "next tuesday" (issue 13a § Beslut 5).
 */
it('lägger valideringsfelen på sina egna fält och behåller inmatningen', function () {
    withoutVite();

    [$konto, $anvandare, $container] = itemformKontext();

    actingAs($anvandare)
        ->post("/containers/{$container->ulid}/items", itemformKropp($konto, ['name' => '']))
        ->assertSessionHasErrors('name');

    expect(Item::query()->count())->toBe(0);

    actingAs($anvandare)
        ->post("/containers/{$container->ulid}/items", itemformKropp($konto, [
            'name' => 'Motorn',
            'purchased_at' => 'next tuesday',
        ]))
        ->assertSessionHasErrors('purchased_at')
        // Fältet behåller det användaren skrev — felet tömmer inte formuläret.
        ->assertSessionHasInput('name', 'Motorn');

    expect(Item::query()->count())->toBe(0);
});

/*
 * Klart när: en användare med containerbred `create` kan skapa men inte ändra
 * ett befintligt item (403 på `PATCH`).
 *
 * Det är hela `create`-pinnen ([[ADR-0028 Åtkomst på itemnivå]] § Beslut):
 * lägga till, aldrig röra det som redan står där. Nekandet gäller HELA
 * kroppen — det finns ingen fältvis grind (issue 71 § Beslut 3).
 */
it('låter en create-mottagare skapa men inte ändra', function () {
    withoutVite();

    [, , $container] = itemformKontext();
    $motorn = itemformItem($container, 'Motorn');

    $mottagare = itemformMottagare($container, null, 'create');
    $hennesKonto = itemformEgetKonto($mottagare);

    actingAs($mottagare)
        ->post("/containers/{$container->ulid}/items", itemformKropp($hennesKonto, ['name' => 'Impellern']))
        ->assertRedirect();

    expect(Item::query()->where('name', 'Impellern')->exists())->toBeTrue();

    $url = "/containers/{$container->ulid}/items/{$motorn->ulid}";

    actingAs($mottagare)->patch($url, itemformKropp($hennesKonto, ['name' => 'Ändrad']))->assertForbidden();
    actingAs($mottagare)->delete($url)->assertForbidden();

    expect($motorn->refresh()->name)->toBe('Motorn');
});

/*
 * Klart när: en användare med `write` kan ändra men inte radera (403 på
 * `DELETE`).
 *
 * `write`-pinnen ändrar det som redan står där; `delete` är en pinne högre och
 * en egen grind (§ Beslut 2). Hade de delat grind kunde en `write`-mottagare
 * radera, vilket är precis vad [[ADR-0028 Åtkomst på itemnivå]] delade upp.
 */
it('låter en write-mottagare ändra men inte radera', function () {
    withoutVite();

    [$konto, , $container] = itemformKontext();
    $motorn = itemformItem($container, 'Motorn');

    $skrivare = itemformMottagare($container, null, 'write');

    $url = "/containers/{$container->ulid}/items/{$motorn->ulid}";

    actingAs($skrivare)
        ->patch($url, itemformKropp($konto, ['name' => 'Nya motorn']))
        ->assertRedirect($url)
        ->assertSessionHas('status', 'item-updated');

    expect($motorn->refresh()->name)->toBe('Nya motorn');

    actingAs($skrivare)->delete($url)->assertForbidden();

    expect($motorn->refresh()->deleted_at)->toBeNull();
});

/*
 * Klart när: en användare med `delete` raderar itemet, som försvinner ur
 * listan men ligger kvar med `deleted_at` satt.
 *
 * Raderingen är MJUK ([[ADR-0008 Soft delete och papperskorg]]): `deleted_at`
 * sätts och ingenting annat. Papperskorgen som listar och återställer är issue
 * 62 — här prövas bara att raden finns kvar och att listan inte visar den.
 */
it('mjukraderar itemet på delete-pinnen och tar bort det ur listan', function () {
    withoutVite();

    [, $anvandare, $container] = itemformKontext();

    $motorn = itemformItem($container, 'Motorn', $anvandare);
    $masten = itemformItem($container, 'Masten', $anvandare);

    $raderare = itemformMottagare($container, null, 'delete');

    actingAs($raderare)
        ->delete("/containers/{$container->ulid}/items/{$motorn->ulid}")
        ->assertRedirect("/containers/{$container->ulid}")
        ->assertSessionHas('status', 'item-deleted');

    expect(Item::withTrashed()->find($motorn->id)->deleted_at)->not->toBeNull();
    expect(Item::query()->whereKey($motorn->id)->exists())->toBeFalse();

    actingAs($raderare)->get("/containers/{$container->ulid}")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('items', 1)
            ->where('items.0.ulid', $masten->ulid)
    );
});

/*
 * Klart när: en omfångsbegränsad mottagare får 403 på `PATCH` och `DELETE` för
 * ett item utanför sitt omfång.
 *
 * Itemet hon NÅR svarar 302 i samma test — annars hade 403:an kunnat vara en
 * trasig rutt. Itemets egen pinne är grinden, inte containerns (Beslut 2), och
 * omfånget kommer ur App\Actions\Access\ResolveItemScope.
 */
it('ger en omfångsbegränsad mottagare 403 utanför sitt omfång', function () {
    withoutVite();

    [$konto, , $container] = itemformKontext();

    $motorn = itemformItem($container, 'Motorn');
    $masten = itemformItem($container, 'Masten');

    $mottagare = itemformMottagare($container, $motorn, 'delete');

    actingAs($mottagare)
        ->patch("/containers/{$container->ulid}/items/{$motorn->ulid}", itemformKropp($konto, ['name' => 'Motor']))
        ->assertRedirect();

    actingAs($mottagare)
        ->delete("/containers/{$container->ulid}/items/{$motorn->ulid}")
        ->assertRedirect();

    $url = "/containers/{$container->ulid}/items/{$masten->ulid}";

    actingAs($mottagare)->patch($url, itemformKropp($konto, ['name' => 'Ändrad']))->assertForbidden();
    actingAs($mottagare)->delete($url)->assertForbidden();

    expect($masten->refresh()->name)->toBe('Masten')
        ->and($masten->deleted_at)->toBeNull();
});

/*
 * Klart när: ett `read_only`-ägarkonto nekar skapande, ändring och radering
 * men tillåter läsning.
 *
 * Regel 4: kontots skrivspärr, ovanpå varje pinne ([[Konton och åtkomst]]
 * § Behörighetsregler). Läsning påverkas aldrig — ItemPolicy::view() hoppar
 * över fryst-kontrollen med flit.
 */
it('nekar ett fryst ägarkonto allt skrivande men tillåter läsning', function () {
    withoutVite();

    [$konto, $anvandare, $container] = itemformKontext(['status' => 'read_only']);
    $motorn = itemformItem($container, 'Motorn', $anvandare);

    $url = "/containers/{$container->ulid}/items/{$motorn->ulid}";

    actingAs($anvandare)->get($url)->assertOk();
    actingAs($anvandare)->get("/containers/{$container->ulid}/items/create")->assertForbidden();
    actingAs($anvandare)->get("{$url}/edit")->assertForbidden();

    actingAs($anvandare)
        ->post("/containers/{$container->ulid}/items", itemformKropp($konto))
        ->assertForbidden();

    actingAs($anvandare)
        ->patch($url, itemformKropp($konto, ['name' => 'Ändrad']))
        ->assertForbidden();

    actingAs($anvandare)->delete($url)->assertForbidden();

    expect(Item::query()->count())->toBe(1)
        ->and($motorn->refresh()->name)->toBe('Motorn')
        ->and($motorn->deleted_at)->toBeNull();
});

/*
 * Klart när: ett item i en annan container går inte att ändra via den här containerns
 * rutter (404).
 *
 * `scopeBindings()` löser `{item}` genom containerns `items()`-relation
 * (Beslut 1) — samma skydd som routes/api.php sätter på sin grupp. En ULID från
 * en annan container är därför 404 och inte 403: den finns inte i DEN HÄR containern.
 */
it('ger 404 för ett item ur en annan container på alla tre skrivrutterna', function () {
    withoutVite();

    [$konto, $anvandare, $container] = itemformKontext();
    $annan = Container::factory()->for($konto, 'account')->create();

    $frammande = itemformItem($annan, 'Motorn');

    $url = "/containers/{$container->ulid}/items/{$frammande->ulid}";

    actingAs($anvandare)->get("{$url}/edit")->assertNotFound();
    actingAs($anvandare)->patch($url, itemformKropp($konto, ['name' => 'Ändrad']))->assertNotFound();
    actingAs($anvandare)->delete($url)->assertNotFound();

    expect($frammande->refresh()->name)->toBe('Motorn')
        ->and($frammande->deleted_at)->toBeNull();
});

/*
 * Klart när: en kategori eller tagg ur en annan container avvisas som
 * valideringsfel, inte som en tyst nollning.
 *
 * `StoreItemRequest`/`UpdateItemRequest` löser båda inom DEN HÄR containern
 * (issue 13a § Beslut 7, issue 13b § Beslut 5) — en främmande tagg hade läckt
 * sitt namn genom itemresursen till var och en som ser containern. Att ingenting
 * skrivs är halva kravet: "tyst nollning" är just att raden sparas utan det
 * användaren valde.
 */
it('avvisar en kategori och en tagg ur en annan container som valideringsfel', function () {
    withoutVite();

    [$konto, $anvandare, $container] = itemformKontext();
    $annan = Container::factory()->for($konto, 'account')->create();

    $frammandeKategori = Category::factory()->for($annan, 'container')->create();
    $frammandeTagg = Tag::factory()->for($annan, 'container')->create();

    actingAs($anvandare)
        ->post("/containers/{$container->ulid}/items", itemformKropp($konto, [
            'category' => $frammandeKategori->ulid,
        ]))
        ->assertSessionHasErrors('category');

    expect(Item::query()->count())->toBe(0);

    actingAs($anvandare)
        ->post("/containers/{$container->ulid}/items", itemformKropp($konto, [
            'tags' => [$frammandeTagg->ulid],
        ]))
        ->assertSessionHasErrors('tags.0');

    expect(Item::query()->count())->toBe(0);

    // Samma på PATCH, med ett item som redan finns.
    $motorn = itemformItem($container, 'Motorn', $anvandare);

    actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/items/{$motorn->ulid}", itemformKropp($konto, [
            'name' => 'Motorn',
            'category' => $frammandeKategori->ulid,
            'tags' => [],
        ]))
        ->assertSessionHasErrors('category');

    expect($motorn->refresh()->category_id)->toBeNull();
});

/*
 * Klart när: `/api/containers/{container}/items` svarar exakt som förut på
 * `POST`, `PATCH` och `DELETE`.
 *
 * Den här issuen rör ingen rad i Api\ItemController, och den djupa prövningen
 * ligger i tests/Feature/Item/**. Det här är röken: de tre verben svarar
 * 201/200/204 och skriver samma rad.
 */
it('lämnar /api-skrivningarna oförändrade', function () {
    [$konto, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    $svar = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Motorn',
        'account' => $konto->ulid,
    ], $headers)->assertCreated();

    $ulid = $svar->json('data.ulid');

    patchJson("/api/containers/{$container->ulid}/items/{$ulid}", [
        'name' => 'Nya motorn',
    ], $headers)->assertOk()->assertJsonPath('data.name', 'Nya motorn');

    deleteJson("/api/containers/{$container->ulid}/items/{$ulid}", [], $headers)
        ->assertNoContent();

    expect(Item::query()->count())->toBe(0);
});

/*
 * Klart när: ingen svensk sträng står kvar i en `.vue`-fil; varje ny nyckel
 * finns på `sv` och `en`.
 *
 * Den första halvan vaktas av SprakTest (som läser varje fil under
 * resources/js). Här prövas nyckelparen, nyckel för nyckel, för de grupper
 * 57b lägger till.
 */
it('har varje item-nyckel på båda språken', function () {
    $sv = require lang_path('sv/ui.php');
    $en = require lang_path('en/ui.php');

    foreach (['index', 'show', 'form', 'create', 'edit', 'destroy'] as $grupp) {
        expect(array_keys($en['item'][$grupp]))->toBe(array_keys($sv['item'][$grupp]));

        foreach ($sv['item'][$grupp] as $nyckel => $varde) {
            expect(trim($varde))->not->toBe('', "item.{$grupp}.{$nyckel} är tom på sv");
            expect(trim($en['item'][$grupp][$nyckel]))->not->toBe('', "item.{$grupp}.{$nyckel} är tom på en");
        }
    }

    // Flash-koderna sätts i kontrollern och formuleras i FlashMessage.
    foreach (['item-created', 'item-updated', 'item-deleted'] as $kod) {
        expect($sv['flash'][$kod])->not->toBe('');
        expect($en['flash'][$kod])->not->toBe('');
    }

    // Raderingen lovar inte mer än den håller (Beslut 8): papperskorgen och de
    // 30 dagarna, aldrig "permanent".
    expect($sv['item']['destroy']['confirm'])->toContain('30');

    foreach ([
        'pages/Containers/Items/Create.vue',
        'pages/Containers/Items/Edit.vue',
        'pages/Containers/Items/Index.vue',
        'pages/Containers/Items/Show.vue',
        'components/ItemForm.vue',
    ] as $fil) {
        $kod = File::get(resource_path("js/{$fil}"));
        $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
        $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

        expect($kod)->not->toMatch('/[åäöÅÄÖ]/u', "svensk text utanför kommentar i {$fil}");
    }
});
