<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * Issue 56a · Tagglistan i webben: listningen med träffräknaren, skapandet
 * med återupplivningen, ändringen och raderingen. Se
 * App\Http\Controllers\TagController, App\Actions\Tag\ListTags/CreateTag,
 * resources/js/pages/Containers/Tags.vue och
 * resources/js/components/TagRow.vue/TagColorField.vue.
 *
 * Den viktigaste gränsen i filen är RÄKNAREN (Beslut 6): talet är per OMFÅNG,
 * inte per container. En mottagare som når fyra items ska se att taggen sitter på
 * två av dem, inte att den sitter på nittio — och en tagg hon inte når något
 * item genom syns inte alls, för namnet är avslöjandet.
 *
 * Den andra är ÅTERUPPLIVNINGEN (Beslut 7): ett namn som finns på en
 * mjukraderad rad skapar inget fel utan återställer DEN raden med sin gamla
 * ULID. Det är hela avvikelsen från ren CRUD i issue 12.
 *
 * Att `/api/containers/{container}/tags` svarar exakt som förut prövas av
 * tests/Feature/Tag/** och tests/Feature/Omfang/ListningsfilterTest.php, som
 * är gröna utan en enda ändrad förväntan efter utbrytningen — här prövas bara
 * att antalet frågor är konstant.
 *
 * Hjälparna har prefixet `taggvy` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * @param  array<string, mixed>  $kontoAttribut
 * @return array{0: Account, 1: User, 2: Container}
 */
function taggvyKontext(array $kontoAttribut = []): array
{
    $konto = Account::factory()->create($kontoAttribut);
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

function taggvyTagg(Container $container, string $namn, ?string $color = null): Tag
{
    return Tag::factory()->for($container, 'container')->create([
        'name' => $namn,
        'color' => $color,
    ]);
}

function taggvyItem(Container $container, string $namn): Item
{
    return Item::factory()->create(['container_id' => $container->id, 'name' => $namn]);
}

function taggvyMottagare(Container $container, Item $item, string $level = 'read'): User
{
    $mottagare = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $level,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/**
 * En containerbred åtkomst på $level.
 */
function taggvyContainerbred(Container $container, User $user, string $level): ContainerAccess
{
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => null,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $level,
        'kind' => $level === 'write' ? 'member' : 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * Antalet frågor $anrop ställer — mönstret från
 * ListningsfilterTest::listningsFrågor(). `ResolveItemScope` är `scoped` och
 * memoiserar per request i drift, men i testsviten överlever den mellan
 * HTTP-anropen, så den glöms inför varje mätning.
 */
function taggvyFrågor(Closure $anrop): int
{
    app()->forgetScopedInstances();

    $frågor = 0;
    DB::listen(function () use (&$frågor) {
        $frågor++;
    });

    $anrop();

    return $frågor;
}

/*
 * Beslut 1: fyra rutter, alla bakom `auth`.
 */
it('skickar en utloggad besökare till inloggningen från taggrutterna', function () {
    withoutVite();

    [, , $container] = taggvyKontext();
    $tagg = taggvyTagg($container, 'Motor');

    get("/containers/{$container->ulid}/tags")->assertRedirect('/login');
    post("/containers/{$container->ulid}/tags", ['name' => 'Motor'])->assertRedirect('/login');
    patch("/containers/{$container->ulid}/tags/{$tagg->ulid}", ['name' => 'Motor'])->assertRedirect('/login');
    delete("/containers/{$container->ulid}/tags/{$tagg->ulid}")->assertRedirect('/login');
});

/*
 * Klart när: /containers/{container}/tags listar taggarna sorterade på namn,
 * med färgprick där färg finns.
 */
it('listar taggarna sorterade på namn och skickar färgen med', function () {
    withoutVite();

    [, $anvandare, $container] = taggvyKontext();

    taggvyTagg($container, 'Vinter');
    taggvyTagg($container, 'Motor', '#3b82f6');
    taggvyTagg($container, 'Försäkringar');

    $mått = taggvyItem($container, 'Motorn');
    Tag::query()->where('name', 'Motor')->first()->items()->attach([$mått->id]);

    actingAs($anvandare)->get("/containers/{$container->ulid}/tags")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Tags')
            ->where('container.ulid', $container->ulid)
            ->has('tags', 3)
            ->where('tags.0.name', 'Försäkringar')
            ->where('tags.1.name', 'Motor')
            ->where('tags.2.name', 'Vinter')
            // Färgen är kolumnens värde: `#rrggbb` eller `null`. Ingen
            // standardfärg hittas på (Beslut 8).
            ->where('tags.1.color', '#3b82f6')
            ->where('tags.0.color', null)
            ->where('can.manage', true)
    );

    // Pricken ritas på färgen som finns, och är omålad när den saknas.
    $fält = File::get(resource_path('js/components/TagColorField.vue'));

    expect($fält)->toContain('modelValue ? { backgroundColor: modelValue } : null')
        ->toContain('modelValue === null')
        ->toContain("t('container.tags.no_color')");

    $rad = File::get(resource_path('js/components/TagRow.vue'));

    expect($rad)->toContain('tag.color ? { backgroundColor: tag.color } : null')
        ->toContain("t('container.tags.item_count'");
});

/*
 * Klart när: en användare utan åtkomst till containern får 403 på båda sidorna.
 */
it('nekar en främling både taggsidan och varje taggskrivning', function () {
    withoutVite();

    [, , $container] = taggvyKontext();
    $tagg = taggvyTagg($container, 'Motor');
    $frammande = User::factory()->create();

    actingAs($frammande)->get("/containers/{$container->ulid}/tags")->assertForbidden();
    actingAs($frammande)->post("/containers/{$container->ulid}/tags", ['name' => 'Ny'])->assertForbidden();
    actingAs($frammande)
        ->patch("/containers/{$container->ulid}/tags/{$tagg->ulid}", ['name' => 'Ändrad'])
        ->assertForbidden();
    actingAs($frammande)->delete("/containers/{$container->ulid}/tags/{$tagg->ulid}")->assertForbidden();

    expect($tagg->refresh()->name)->toBe('Motor');
    expect($tagg->trashed())->toBeFalse();
});

/*
 * Klart när: en omfångsbegränsad mottagare ser bara taggar som sitter på minst
 * ett item hon når, och räknaren visar antalet items HON når.
 *
 * "Försäkringar" sitter på två items, men bara ett av dem är hennes — hon ska
 * se 1, inte 2. "Skilsmässa" sitter bara på en båt hon inte når, och syns
 * därför inte alls: namnet är avslöjandet.
 */
it('visar bara taggar inom omfånget, och räknar bara hennes items', function () {
    withoutVite();

    [, , $container] = taggvyKontext();

    $motor = taggvyTagg($container, 'Motor');
    $försäkringar = taggvyTagg($container, 'Försäkringar');
    $skilsmässa = taggvyTagg($container, 'Skilsmässa');

    $motorn = taggvyItem($container, 'Motorn');
    $båten = taggvyItem($container, 'Båten');

    $motorn->tags()->attach([$motor->id, $försäkringar->id]);
    $båten->tags()->attach([$skilsmässa->id, $försäkringar->id]);

    $mottagare = taggvyMottagare($container, $motorn);

    $svar = actingAs($mottagare)->get("/containers/{$container->ulid}/tags");

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('tags', 2)
        ->where('tags.0.name', 'Försäkringar')
        ->where('tags.1.name', 'Motor')
        // Talet är hennes items, inte containerns.
        ->where('counts', [
            $försäkringar->ulid => 1,
            $motor->ulid => 1,
        ])
        ->where('can.manage', false)
    );

    expect($svar->getContent())->not->toContain('Skilsmässa');
    expect($svar->getContent())->not->toContain($skilsmässa->ulid);
});

/*
 * En containerbred innehavare är OMFATTANDE: hon ser hela listan, också en
 * tagg ingen använt, och räknaren är 0 där — inte frånvarande.
 */
it('visar hela listan för en containerbred innehavare, med noll för en oanvänd tagg', function () {
    withoutVite();

    [, , $container] = taggvyKontext();

    $oanvänd = taggvyTagg($container, 'Försäkringar');
    $använd = taggvyTagg($container, 'Motor');

    $motorn = taggvyItem($container, 'Motorn');
    $motorn->tags()->attach([$använd->id]);

    $innehavare = User::factory()->create();
    taggvyContainerbred($container, $innehavare, 'read');

    actingAs($innehavare)->get("/containers/{$container->ulid}/tags")->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('tags', 2)
            ->where('counts', [
                $använd->ulid => 1,
                $oanvänd->ulid => 0,
            ])
    );
});

/*
 * Klart när: POST skapar ett taggnamn.
 */
it('skapar en tagg med färg', function () {
    withoutVite();

    [, $anvandare, $container] = taggvyKontext();

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/tags")
        ->post("/containers/{$container->ulid}/tags", ['name' => 'Motor', 'color' => '#3B82F6'])
        ->assertRedirect("/containers/{$container->ulid}/tags")
        ->assertSessionHas('status', 'tag-created');

    // `color` normaliseras till gemener av StoreTagRequest.
    expect(Tag::query()->where('name', 'Motor')->firstOrFail()->color)->toBe('#3b82f6');
});

/*
 * Klart när: POST av ett taggnamn som redan finns aktivt ger ett
 * valideringsfel på `name`.
 */
it('ger ett valideringsfel på name för ett namn som redan finns aktivt', function () {
    withoutVite();

    [, $anvandare, $container] = taggvyKontext();
    taggvyTagg($container, 'Motor');

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/tags")
        ->post("/containers/{$container->ulid}/tags", ['name' => 'Motor'])
        ->assertSessionHasErrors('name');

    expect(Tag::query()->where('name', 'Motor')->count())->toBe(1);
});

/*
 * Klart när: POST av ett namn som finns som MJUKRADERAD tagg återupplivar den
 * raden — samma ULID som före raderingen.
 *
 * Hela avvikelsen från ren CRUD (issue 12 § Beslut 4), och den bor i
 * App\Actions\Tag\CreateTag. Utan den kan en användare inte skapa om en tagg
 * hon nyss raderade.
 */
it('återupplivar en mjukraderad tagg med samma ULID', function () {
    withoutVite();

    [, $anvandare, $container] = taggvyKontext();

    $tagg = taggvyTagg($container, 'Motor', '#3b82f6');
    $ulid = $tagg->ulid;

    $tagg->delete();
    expect($tagg->refresh()->trashed())->toBeTrue();

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/tags")
        ->post("/containers/{$container->ulid}/tags", ['name' => 'Motor', 'color' => '#ef4444'])
        ->assertRedirect("/containers/{$container->ulid}/tags")
        ->assertSessionHas('status', 'tag-created');

    $svar = actingAs($anvandare)->get("/containers/{$container->ulid}/tags");

    $svar->assertInertia(fn (AssertableInertia $page) => $page
        ->has('tags', 1)
        ->where('tags.0.ulid', $ulid)
        ->where('tags.0.color', '#ef4444')
    );

    $tagg->refresh();
    expect($tagg->trashed())->toBeFalse();
    expect($tagg->ulid)->toBe($ulid);
    // Ingen ny rad har skapats — samma löpnummer som förut.
    expect(Tag::query()->where('name', 'Motor')->count())->toBe(1);
});

/*
 * Klart när: en färg som inte är `#rrggbb` avvisas på fältet `color`; `null`
 * sparas som ingen färg.
 */
it('avvisar en färg som inte är #rrggbb och sparar null som ingen färg', function () {
    withoutVite();

    [, $anvandare, $container] = taggvyKontext();

    foreach (['3b82f6', '#3b8', '#zzzzzz', 'röd'] as $ogiltig) {
        actingAs($anvandare)
            ->from("/containers/{$container->ulid}/tags")
            ->post("/containers/{$container->ulid}/tags", ['name' => 'Motor', 'color' => $ogiltig])
            ->assertSessionHasErrors('color');
    }

    expect(Tag::query()->count())->toBe(0);

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/tags")
        ->post("/containers/{$container->ulid}/tags", ['name' => 'Motor', 'color' => null])
        ->assertSessionHasNoErrors()
        ->assertRedirect("/containers/{$container->ulid}/tags");

    expect(Tag::query()->where('name', 'Motor')->firstOrFail()->color)->toBeNull();
});

/*
 * Klart när: PATCH byter namn och färg, DELETE mjukraderar, och en tagg kan
 * spara sitt eget namn oförändrat.
 */
it('byter namn och färg, och mjukraderar', function () {
    withoutVite();

    [, $anvandare, $container] = taggvyKontext();
    $tagg = taggvyTagg($container, 'Motor', '#3b82f6');

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/tags")
        ->patch("/containers/{$container->ulid}/tags/{$tagg->ulid}", ['name' => 'Motor'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'tag-updated');

    expect($tagg->refresh()->name)->toBe('Motor');

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/tags")
        ->patch("/containers/{$container->ulid}/tags/{$tagg->ulid}", ['name' => 'Framdrivning', 'color' => null])
        ->assertSessionHasNoErrors();

    $tagg->refresh();
    expect($tagg->name)->toBe('Framdrivning');
    expect($tagg->color)->toBeNull();

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/tags")
        ->delete("/containers/{$container->ulid}/tags/{$tagg->ulid}")
        ->assertRedirect("/containers/{$container->ulid}/tags")
        ->assertSessionHas('status', 'tag-deleted');

    expect($tagg->refresh()->trashed())->toBeTrue();

    actingAs($anvandare)->get("/containers/{$container->ulid}/tags")->assertInertia(
        fn (AssertableInertia $page) => $page->has('tags', 0)
    );
});

/*
 * Klart när: en användare med containerbred `write` kan skapa, ändra och radera
 * både kategorier och taggar; en med `read` får 403 på varje skrivning.
 *
 * Grinden är `ContainerPolicy::update()`, ALDRIG `delete()` — den senare
 * betyder "får radera containern" och skulle låsa ute en write-deltagare från att
 * städa bland taggarna (regel 3, [[Konton och åtkomst]]).
 */
it('låter en write-innehavare skriva och nekar en read-innehavare varje skrivning', function () {
    withoutVite();

    [, , $container] = taggvyKontext();

    $skrivare = User::factory()->create();
    $läsare = User::factory()->create();

    taggvyContainerbred($container, $skrivare, 'write');
    taggvyContainerbred($container, $läsare, 'read');

    $tagg = taggvyTagg($container, 'Motor');

    actingAs($läsare)->get("/containers/{$container->ulid}/tags")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('can.manage', false)
    );

    actingAs($läsare)->post("/containers/{$container->ulid}/tags", ['name' => 'Ny'])->assertForbidden();
    actingAs($läsare)
        ->patch("/containers/{$container->ulid}/tags/{$tagg->ulid}", ['name' => 'Ändrad'])
        ->assertForbidden();
    actingAs($läsare)->delete("/containers/{$container->ulid}/tags/{$tagg->ulid}")->assertForbidden();

    expect($tagg->refresh()->name)->toBe('Motor');
    expect($tagg->trashed())->toBeFalse();

    actingAs($skrivare)
        ->from("/containers/{$container->ulid}/tags")
        ->post("/containers/{$container->ulid}/tags", ['name' => 'Rigg'])
        ->assertSessionHas('status', 'tag-created');

    $ny = Tag::query()->where('name', 'Rigg')->firstOrFail();

    actingAs($skrivare)
        ->from("/containers/{$container->ulid}/tags")
        ->patch("/containers/{$container->ulid}/tags/{$ny->ulid}", ['name' => 'Stormasten'])
        ->assertSessionHas('status', 'tag-updated');

    expect($ny->refresh()->name)->toBe('Stormasten');

    actingAs($skrivare)
        ->from("/containers/{$container->ulid}/tags")
        ->delete("/containers/{$container->ulid}/tags/{$ny->ulid}")
        ->assertSessionHas('status', 'tag-deleted');

    expect($ny->refresh()->trashed())->toBeTrue();
});

/*
 * Klart när: ett `read_only`-ägarkonto nekar varje skrivning men tillåter
 * listning.
 */
it('låter ett fryst ägarkonto lista men inte skriva', function () {
    withoutVite();

    [, $anvandare, $container] = taggvyKontext(['status' => 'read_only']);
    $tagg = taggvyTagg($container, 'Motor');

    actingAs($anvandare)->get("/containers/{$container->ulid}/tags")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('tags', 1)
            ->where('can.manage', false)
    );

    actingAs($anvandare)->post("/containers/{$container->ulid}/tags", ['name' => 'Ny'])->assertForbidden();
    actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/tags/{$tagg->ulid}", ['name' => 'Ändrad'])
        ->assertForbidden();
    actingAs($anvandare)->delete("/containers/{$container->ulid}/tags/{$tagg->ulid}")->assertForbidden();

    expect($tagg->refresh()->name)->toBe('Motor');
});

/*
 * Klart när: en tagg i en annan container går inte att nå via den här containerns rutter
 * (404). `scopeBindings()` på de två nästlade skrivningarna.
 */
it('når inte en tagg i en annan container via den här containerns rutt', function () {
    withoutVite();

    [$konto, $anvandare, $container] = taggvyKontext();
    $annan = Container::factory()->for($konto, 'account')->create();

    $främmande = taggvyTagg($annan, 'Motor');

    actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/tags/{$främmande->ulid}", ['name' => 'Ändrad'])
        ->assertNotFound();

    actingAs($anvandare)
        ->delete("/containers/{$container->ulid}/tags/{$främmande->ulid}")
        ->assertNotFound();

    expect($främmande->refresh()->name)->toBe('Motor');
    expect($främmande->trashed())->toBeFalse();
});

/*
 * Klart när: taggsidans räknare kostar ett konstant antal frågor oavsett antal
 * taggar, mätt med DB::listen.
 *
 * Talet räknas i App\Actions\Tag\ListTags::itemsPerTag() — EN fråga, delad med
 * det svep som avgör vilka taggar som syns. Utan memon hade `handle()` och
 * `counts()` ställt samma fråga två gånger, och ett antal som växer med
 * träfflistan vore precis den N+1 räknaren är byggd för att undvika.
 */
it('räknar träffarna med ett konstant antal frågor, oavsett antal taggar', function () {
    withoutVite();

    [, $anvandare, $container] = taggvyKontext();

    $motorn = taggvyItem($container, 'Motorn');
    $första = taggvyTagg($container, 'Motor');
    $motorn->tags()->attach([$första->id]);

    actingAs($anvandare);

    // En uppvärmningsrequest först: den inloggade användaren ligger kvar i
    // minnet mellan anropen i samma test, så den första mätningen hade annars
    // betalat för laddningar den andra får gratis.
    get("/containers/{$container->ulid}/tags")->assertOk();

    DB::flushQueryLog();

    $faTaggar = taggvyFrågor(function () use ($container) {
        get("/containers/{$container->ulid}/tags")->assertOk();
    });

    foreach (range(1, 6) as $i) {
        $tagg = taggvyTagg($container, "Tagg {$i}");
        $motorn->tags()->attach([$tagg->id]);
    }

    $flerTaggar = taggvyFrågor(function () use ($container) {
        $svar = get("/containers/{$container->ulid}/tags");
        $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('tags', 7));
    });

    expect($flerTaggar)->toBe($faTaggar);
});

/*
 * Klart när: `/api/containers/{container}/tags` svarar exakt som förut, med
 * samma antal frågor.
 *
 * Formen prövas av tests/Feature/Tag/**; här prövas kostnaden. Räknarfrågan
 * får inte ha följt med in i API-vägen — `/api` har inte bett om talet, och
 * `handle()` rör den inte.
 */
it('ställer ett konstant antal frågor på api-listan, oavsett antal taggar', function () {
    [, $anvandare, $container] = taggvyKontext();

    $motorn = taggvyItem($container, 'Motorn');
    $motorn->tags()->attach([taggvyTagg($container, 'Motor')->id]);

    $token = $anvandare->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];
    $url = "/api/containers/{$container->ulid}/tags";

    getJson($url, $headers)->assertOk();

    $faTaggar = taggvyFrågor(function () use ($url, $headers) {
        getJson($url, $headers)->assertOk();
    });

    foreach (range(1, 6) as $i) {
        $motorn->tags()->attach([taggvyTagg($container, "Tagg {$i}")->id]);
    }

    $flerTaggar = taggvyFrågor(function () use ($url, $headers) {
        $svar = getJson($url, $headers);
        $svar->assertOk();
        expect($svar->json('data'))->toHaveCount(7);
    });

    expect($flerTaggar)->toBe($faTaggar);
});

/*
 * Beslut 8: skillnaden mellan tagg och kategori står i gränssnittet — två
 * rubriker och en mening under var sin,.
 */
it('skiljer taggen från kategorin med en mening', function () {
    $sv = require lang_path('en/ui.php');
    $en = require lang_path('en/ui.php');

    expect($sv['container']['tags']['description'])->not->toBe('');
    expect($en['container']['tags']['description'])->not->toBe('');
    expect($sv['container']['categories']['description'])->not->toBe('');
    expect($en['container']['categories']['description'])->not->toBe('');

    // De säger inte samma sak — det är hela poängen med ADR-0004.
    expect($sv['container']['tags']['description'])
        ->not->toBe($sv['container']['categories']['description']);

    // Räknarens mening bär talet.
    expect($sv['container']['tags']['item_count'])->toContain(':count');
    expect($en['container']['tags']['item_count'])->toContain(':count');

    // Navigationen har en nyckel per rad i containerSections.
    foreach (['categories', 'tags'] as $nyckel) {
        expect($sv['container']['nav'][$nyckel])->not->toBe('');
        expect($en['container']['nav'][$nyckel])->not->toBe('');
    }

    $sida = File::get(resource_path('js/pages/Containers/Tags.vue'));

    expect($sida)->toContain("t('container.tags.description')")
        ->toContain('counts[tag.ulid]');
});
