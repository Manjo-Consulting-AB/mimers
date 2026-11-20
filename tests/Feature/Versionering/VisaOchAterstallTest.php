<?php

use App\Actions\Container\CreateContainer;
use App\Actions\Item\CreateItem;
use App\Actions\Item\UpdateItem;
use App\Actions\Tag\CreateTag;
use App\Models\Account;
use App\Models\Agent;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemVersion;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 413 · Visa och återställa ett items versioner, se [[ADR-0088
 * Versionshistorik för items]] Riktning 5.
 *
 * Filen prövar webbens halva: *History*-fliken bär versionerna, dialogen
 * läser dem, och den enda skrivningen är återställningen. Reglerna som
 * prövas är de som skiljer en behörighet från en annan — `view` ser men får
 * inte skriva, `write` får — och de som gör återställningen ärlig: det som
 * inte längre finns hoppas över, nuläget sparas först, och loggen namnger
 * versionen utan att bära fritext.
 *
 * Hjälparna har prefixet `aters` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en medlem och ett valt tak för versionshistoriken.
 *
 * @return array{0: Account, 1: User}
 */
function atersKonto(int $tak = 3): array
{
    $konto = Account::factory()->create(['versioning_max_versions' => $tak]);
    $användare = User::factory()->create();
    $konto->users()->attach($användare, ['role' => 'owner']);

    return [$konto, $användare];
}

/**
 * En container (skapad genom CreateContainer) i kontot.
 */
function atersContainer(Account $konto, User $användare, ?int $tak = null): Container
{
    $container = app(CreateContainer::class)->handle($användare, $konto, 'Båten', 'boat', null);

    if ($tak !== null) {
        $container->versioning_max_versions = $tak;
        $container->save();
    }

    return $container;
}

/**
 * Ett item skapat genom CreateItem, så att betalaren och taggarna följer med.
 *
 * @param  array<string, mixed>  $attribut
 * @param  array<int, Tag>  $taggar
 */
function atersItem(
    Container $container,
    Account $konto,
    User $användare,
    array $attribut = ['name' => 'Motor'],
    array $taggar = [],
    ?Category $kategori = null,
): Item {
    return app(CreateItem::class)->handle(
        $container,
        $konto,
        $användare,
        new Item($attribut),
        $kategori,
        collect($taggar),
        null,
    );
}

/**
 * En vanlig ändring av itemets kolumner, genom UpdateItem.
 *
 * @param  array<string, mixed>  $attribut
 */
function atersSkriv(Item $item, User $aktör, array $attribut): void
{
    $item->fill($attribut);
    app(UpdateItem::class)->handle($item, $aktör, false, collect());
}

/**
 * Adressen till itemets *History*-flik.
 */
function atersHistoryUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}?tab=history";
}

/**
 * Adressen till webbens återställning.
 */
function atersRestoreUrl(Container $container, Item $item, ItemVersion $version): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}/versions/{$version->ulid}/restore";
}

it('låter en inbjuden med view se versionerna men inte återställa', function () {
    withoutVite();

    [$ägare, $ägarUser] = atersKonto();
    $container = atersContainer($ägare, $ägarUser);
    $item = atersItem($container, $ägare, $ägarUser);

    atersSkriv($item, $ägarUser, ['name' => 'Motorn']);

    $version = ItemVersion::query()->where('item_id', $item->id)->sole();

    $gäst = User::factory()->create();
    beviljaAccess($container, $gäst, 'read', 'member');

    actingAs($gäst)->get(atersHistoryUrl($container, $item))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Show')
            ->has('versions', 1)
            ->where('versions.0.ulid', $version->ulid)
            ->where('versions.0.name', 'Motor')
            ->where('can.update', false));

    actingAs($gäst)->post(atersRestoreUrl($container, $item, $version))
        ->assertForbidden();

    // Restore ritas bara bakom samma grind som rutten prövar.
    expect(File::get(resource_path('js/components/ItemVersionSection.vue')))
        ->toContain(':can-update="can.update"')
        ->and(File::get(resource_path('js/components/ItemVersionDialog.vue')))
        ->toContain('v-if="canUpdate"');
});

it('låter en inbjuden med write återställa fälten och taggarna', function () {
    [$ägare, $ägarUser] = atersKonto();
    $container = atersContainer($ägare, $ägarUser);

    $röd = app(CreateTag::class)->handle($container, 'Röd', null, $ägarUser);
    $blå = app(CreateTag::class)->handle($container, 'Blå', null, $ägarUser);

    $kategoriA = Category::factory()->for($container, 'container')->create(['name' => 'A']);
    $kategoriB = Category::factory()->for($container, 'container')->create(['name' => 'B']);

    $item = atersItem(
        $container,
        $ägare,
        $ägarUser,
        ['name' => 'Motor', 'description' => 'Gammal'],
        [$röd],
        $kategoriA,
    );

    // Skriv över allt: versionen bär läget före.
    $item = Item::query()->findOrFail($item->id);
    $item->description = 'Ny';
    $item->category_id = $kategoriB->id;
    app(UpdateItem::class)->handle($item, $ägarUser, true, collect([$blå]));

    $version = ItemVersion::query()->where('item_id', $item->id)->sole();

    expect($version->name)->toBe('Motor')
        ->and($version->tag_ids)->toBe([$röd->id]);

    $skrivare = User::factory()->create();
    beviljaAccess($container, $skrivare, 'write', 'member');

    $svar = actingAs($skrivare)->post(atersRestoreUrl($container, $item, $version));

    $svar->assertRedirect(route('containers.items.show', [
        'container' => $container,
        'item' => $item,
        'tab' => 'history',
    ]));
    $svar->assertSessionHas('status', 'item-version-restored');
    $svar->assertSessionHas('restored_skipped', []);

    $item = Item::query()->findOrFail($item->id);

    expect($item->name)->toBe('Motor')
        ->and($item->description)->toBe('Gammal')
        ->and($item->category_id)->toBe($kategoriA->id)
        ->and(array_map('intval', DB::table('item_tag')->where('item_id', $item->id)->pluck('tag_id')->all()))
        ->toBe([$röd->id]);
});

it('sparar det förra läget som en ny version så att återställningen går att ångra', function () {
    [$ägare, $ägarUser] = atersKonto();
    $container = atersContainer($ägare, $ägarUser);
    $item = atersItem($container, $ägare, $ägarUser);

    atersSkriv($item, $ägarUser, ['name' => 'Överskriven']);

    $version = ItemVersion::query()->where('item_id', $item->id)->sole();

    actingAs($ägarUser)->post(atersRestoreUrl($container, $item, $version))->assertRedirect();

    expect(Item::query()->findOrFail($item->id)->name)->toBe('Motor');

    // Nuläget FÖRE återställningen är nu en egen version, nyast först.
    $förra = ItemVersion::query()
        ->where('item_id', $item->id)
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->firstOrFail();

    expect($förra->name)->toBe('Överskriven')
        ->and($förra->replaced_at->greaterThanOrEqualTo($version->replaced_at))->toBeTrue();

    // Och en återställning av den ger tillbaka det överskrivna läget.
    actingAs($ägarUser)->post(atersRestoreUrl($container, $item, $förra))->assertRedirect();

    expect(Item::query()->findOrFail($item->id)->name)->toBe('Överskriven');
});

it('hoppar över en kategori, tagg och omslagsbild som inte längre finns och räknar upp dem', function () {
    [$ägare, $ägarUser] = atersKonto();
    $container = atersContainer($ägare, $ägarUser);

    $kategoriA = Category::factory()->for($container, 'container')->create(['name' => 'A']);
    $kategoriB = Category::factory()->for($container, 'container')->create(['name' => 'B']);
    $röd = app(CreateTag::class)->handle($container, 'Röd', null, $ägarUser);
    $blå = app(CreateTag::class)->handle($container, 'Blå', null, $ägarUser);

    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Nuvarande',
        'category_id' => $kategoriB->id,
        'created_by_user_id' => $ägarUser->id,
        'created_by_account_id' => $ägare->id,
        'billed_account_id' => $ägare->id,
    ]);

    $gamlaOmslaget = Attachment::factory()->for($item, 'item')->create(['filename' => 'gammal.jpg', 'kind' => 'image']);
    $nyaOmslaget = Attachment::factory()->for($item, 'item')->create(['filename' => 'ny.jpg', 'kind' => 'image']);

    $item->cover_attachment_id = $nyaOmslaget->id;
    $item->save();
    $item->tags()->attach($blå->id);

    // Versionen pekar på det som snart är borta: kategori A, taggen Röd och
    // det gamla omslaget.
    $version = ItemVersion::factory()->for($item, 'item')->create([
        'billed_account_id' => $ägare->id,
        'name' => 'Motor',
        'category_id' => $kategoriA->id,
        'cover_attachment_id' => $gamlaOmslaget->id,
        'tag_ids' => [$röd->id],
        'replaced_by_user_id' => $ägarUser->id,
    ]);

    // Allt tre raderas mjukt sedan versionen spelades in.
    $kategoriA->delete();
    $röd->delete();
    $gamlaOmslaget->delete();

    $svar = actingAs($ägarUser)->post(atersRestoreUrl($container, $item, $version));

    $svar->assertRedirect();
    $svar->assertSessionHas('restored_skipped', ['category', 'tags', 'cover']);

    $item = Item::query()->findOrFail($item->id);

    // Namnet är versionens; de tre fälten som inte längre finns står kvar.
    expect($item->name)->toBe('Motor')
        ->and($item->category_id)->toBe($kategoriB->id)
        ->and($item->cover_attachment_id)->toBe($nyaOmslaget->id)
        ->and(array_map('intval', DB::table('item_tag')->where('item_id', $item->id)->pluck('tag_id')->all()))
        ->toBe([$blå->id]);
});

it('ger 404 för en version som hör till ett annat item', function () {
    [$ägare, $ägarUser] = atersKonto();
    $container = atersContainer($ägare, $ägarUser);

    $mitt = atersItem($container, $ägare, $ägarUser, ['name' => 'Motor']);
    $annat = atersItem($container, $ägare, $ägarUser, ['name' => 'Mast']);

    $version = ItemVersion::factory()->for($annat, 'item')->create([
        'billed_account_id' => $ägare->id,
        'name' => 'Gammal mast',
        'replaced_by_user_id' => $ägarUser->id,
    ]);

    actingAs($ägarUser)->post(atersRestoreUrl($container, $mitt, $version))
        ->assertNotFound();

    expect(Item::query()->findOrFail($mitt->id)->name)->toBe('Motor');
});

it('loggar item.updated med versionens ULID och ingen fritext', function () {
    [$ägare, $ägarUser] = atersKonto();
    $container = atersContainer($ägare, $ägarUser);

    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Nuvarande',
        'description' => 'Ny beskrivning',
        'notes' => 'Ny anteckning',
        'created_by_user_id' => $ägarUser->id,
        'created_by_account_id' => $ägare->id,
        'billed_account_id' => $ägare->id,
    ]);

    $version = ItemVersion::factory()->for($item, 'item')->create([
        'billed_account_id' => $ägare->id,
        'name' => 'Motor',
        'description' => 'Gammal beskrivning',
        'notes' => 'Gammal anteckning',
        'replaced_by_user_id' => $ägarUser->id,
    ]);

    actingAs($ägarUser)->post(atersRestoreUrl($container, $item, $version))->assertRedirect();

    $logg = AuditLog::query()
        ->where('action', AuditLog::ACTION_ITEM_UPDATED)
        ->orderByDesc('id')
        ->firstOrFail();

    $meta = $logg->meta;

    expect($meta['restored_from'])->toBe($version->ulid)
        ->and($meta['changed'])->toContain('name', 'description', 'notes')
        // Fritextens innehåll följer aldrig med, inte ens som gammalt värde.
        ->and($meta)->not->toHaveKey('values')
        ->and(json_encode($meta))->not->toContain('Gammal beskrivning')
        ->and(json_encode($meta))->not->toContain('Gammal anteckning');
});

it('visar agentens namn när en agent ersatte versionen', function () {
    withoutVite();

    [$ägare, $ägarUser] = atersKonto();
    $container = atersContainer($ägare, $ägarUser);
    $item = atersItem($container, $ägare, $ägarUser);

    $agent = new Agent;
    $agent->user_id = $ägarUser->id;
    $agent->account_id = $ägare->id;
    $agent->oauth_client_id = (string) Str::uuid();
    $agent->name = 'Claude';
    $agent->save();

    ItemVersion::factory()->for($item, 'item')->create([
        'billed_account_id' => $ägare->id,
        'name' => 'Motor',
        'replaced_by_user_id' => $ägarUser->id,
        'replaced_by_agent_id' => $agent->id,
    ]);

    actingAs($ägarUser)->get(atersHistoryUrl($container, $item))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('versions', 1)
            ->where('versions.0.replaced_by.name', 'Claude')
            ->where('versions.0.replaced_by.agent', true));
});

it('ritar inget versionsavsnitt när historiken är avstängd eller tom', function () {
    withoutVite();

    [$ägare, $ägarUser] = atersKonto();
    $container = atersContainer($ägare, $ägarUser, tak: 0);
    $item = atersItem($container, $ägare, $ägarUser);

    // En version kan finnas kvar innan issue 414 har tagit bort överskottet;
    // den ska ändå inte synas när taket är noll.
    ItemVersion::factory()->for($item, 'item')->create([
        'billed_account_id' => $ägare->id,
        'name' => 'Motor',
        'replaced_by_user_id' => $ägarUser->id,
    ]);

    actingAs($ägarUser)->get(atersHistoryUrl($container, $item))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('versions', 0));

    // Och ett item utan versioner bär en tom lista, inte en saknad nyckel.
    [$ägare2, $ägarUser2] = atersKonto();
    $tom = atersContainer($ägare2, $ägarUser2);
    $item2 = atersItem($tom, $ägare2, $ägarUser2);

    actingAs($ägarUser2)->get(atersHistoryUrl($tom, $item2))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('versions', 0));
});
