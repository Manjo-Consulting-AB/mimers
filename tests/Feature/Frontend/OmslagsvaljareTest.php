<?php

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\ImageDerivative;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 342 · Omslaget bland miniatyrer — se [[ADR-0068 Itemets sida och
 * skapandet]] § 12 och [[M45 Itemets sida]] § 342.
 *
 * Två delar: redigeringens rutnät (ItemCoverPicker.vue och `images` med
 * `hasThumb` i ItemController::edit()) och *Use as cover* i fliken Documents
 * (ItemAttachmentSection.vue), som skickar bara `cover` mot itemets befintliga
 * PATCH. Den sista förutsätter att `update()` rör `category` och `tags` bara
 * när nyckeln finns i requesten.
 *
 * Klientkoden är en Vue-komponent utan webbläsartest här; provet läser
 * källan, som resten av tests/Feature/Frontend gör. Hjälparna har prefixet
 * `omslagsvaljare` — Pest delar namnrymd mellan filerna.
 */

/**
 * @return array{0: Account, 1: User, 2: Container}
 */
function omslagsvaljareKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare, Container::factory()->for($konto, 'account')->create()];
}

function omslagsvaljareItem(Container $container): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'description' => 'Den gamla',
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

function omslagsvaljareBild(Item $item, string $filnamn, bool $miniatyr): Attachment
{
    $bild = Attachment::factory()->for($item, 'item')->create([
        'filename' => $filnamn,
        'kind' => 'image',
    ]);

    if ($miniatyr) {
        ImageDerivative::factory()->create([
            'stored_file_id' => $bild->stored_file_id,
            'variant' => 'thumb',
        ]);
    }

    return $bild;
}

/**
 * Komponentens källa utan kommentarer.
 */
function omslagsvaljareKalla(string $sokvag): string
{
    $kod = File::get(resource_path($sokvag));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);

    return (string) preg_replace('#<!--.*?-->#s', '', $kod);
}

it('ger redigeringsvyn hasThumb per bild', function () {
    withoutVite();

    [, $anvandare, $container] = omslagsvaljareKontext();
    $item = omslagsvaljareItem($container);
    $med = omslagsvaljareBild($item, 'med.jpg', true);
    $utan = omslagsvaljareBild($item, 'utan.jpg', false);

    actingAs($anvandare)->get("/containers/{$container->ulid}/items/{$item->ulid}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Edit')
            ->has('images', 2)
            ->where('images.0.ulid', $med->ulid)
            ->where('images.0.hasThumb', true)
            ->where('images.1.ulid', $utan->ulid)
            ->where('images.1.hasThumb', false)
        );
});

it('ritar redigeringens omslag som en radiogrupp med No cover först', function () {
    $valjare = omslagsvaljareKalla('js/components/ItemCoverPicker.vue');
    $formular = omslagsvaljareKalla('js/components/ItemForm.vue');

    expect($formular)->toContain('<ItemCoverPicker')
        ->toContain('v-model="form.cover"');
    expect($formular)->not->toContain('name="cover"');

    expect($valjare)->toContain('role="radiogroup"')
        ->toContain('role="radio"')
        ->toContain(':aria-checked=');

    // Rutan No cover ligger före bildernas `v-for`.
    expect(strpos($valjare, "t('item.form.cover_none')"))
        ->toBeLessThan(strpos($valjare, 'v-for="(image, index) in images"'));
});

it('skickar samma cover som förut från rutnätet', function () {
    $valjare = omslagsvaljareKalla('js/components/ItemCoverPicker.vue');

    // ULID för en bild och null för No cover.
    expect($valjare)->toContain('@click="select(null)"')
        ->toContain('@click="select(image.ulid)"')
        ->toContain("emit('update:modelValue', value)");
});

it('går att använda rutnätet med tangentbordet', function () {
    $valjare = omslagsvaljareKalla('js/components/ItemCoverPicker.vue');

    // Rörlig tabindex: bara den valda rutan står i tabbordningen.
    expect($valjare)->toContain(':tabindex="isChecked(image.ulid) ? 0 : -1"')
        ->toContain(':tabindex="isChecked(null) ? 0 : -1"');
    expect($valjare)->toMatch('/ArrowRight/')
        ->toMatch('/ArrowLeft/')
        ->toMatch('/ArrowDown/')
        ->toMatch('/ArrowUp/');

    // Valet syns med mer än färg: en bockmarkering ritas på den valda rutan.
    expect($valjare)->toContain('<UiIcon v-if="isChecked(image.ulid)" name="check"');
});

it('ritar en bild utan miniatyr utan img', function () {
    $valjare = omslagsvaljareKalla('js/components/ItemCoverPicker.vue');

    expect($valjare)->toContain('<img')
        ->toContain('v-if="image.hasThumb"')
        ->toContain('<span v-else class="break-all px-1">{{ image.filename }}</span>');
    expect(substr_count($valjare, '<img'))->toBe(1);
});

it('ritar Use as cover på bilder utom omslaget och bara med can.update', function () {
    $sektion = omslagsvaljareKalla('js/components/ItemAttachmentSection.vue');
    $vy = omslagsvaljareKalla('js/pages/Containers/Items/Show.vue');

    expect($sektion)->toContain("v-if=\"can.update && attachment.kind === 'image' && attachment.ulid !== coverUlid\"")
        ->toContain("t('item.attachment.use_as_cover')");
    expect($vy)->toContain(':cover-ulid="cover?.ulid ?? null"');
});

it('skickar bara cover med preserveScroll mot itemets befintliga uppdatering', function () {
    $sektion = omslagsvaljareKalla('js/components/ItemAttachmentSection.vue');

    expect($sektion)->toContain('router.patch(itemUrl(), { cover: attachment.ulid }, {');

    $start = (int) strpos($sektion, 'function useAsCover');
    $anrop = substr($sektion, $start, 700);

    expect($anrop)->toContain('preserveScroll: true')
        ->toContain('onStart:')
        ->toContain('onFinish:');

    // Adressen är den befintliga uppdateringsrutten.
    [, , $container] = omslagsvaljareKontext();
    $item = omslagsvaljareItem($container);

    expect(route('containers.items.update', [$container, $item], false))
        ->toBe("/containers/{$container->ulid}/items/{$item->ulid}");

    // Ingen ny rutt: ingen adress i appen nämner omslaget.
    $rutter = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($rutt) => preg_match('#items/\{[a-z_]+\}.*cover#', $rutt->uri()) === 1);

    expect($rutter)->toHaveCount(0);
});

it('gör bilden till omslag och lämnar kategori, taggar och övriga fält orörda', function () {
    withoutVite();

    [, $anvandare, $container] = omslagsvaljareKontext();
    $item = omslagsvaljareItem($container);
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Verktyg']);
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);
    $item->category_id = $kategori->id;
    $item->save();
    $item->tags()->attach($tagg->id);

    omslagsvaljareBild($item, 'gammal.jpg', true);
    $ny = omslagsvaljareBild($item, 'ny.jpg', true);

    actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/items/{$item->ulid}", ['cover' => $ny->ulid])
        ->assertRedirect();

    $item->refresh()->load('tags');

    expect($item->cover_attachment_id)->toBe($ny->id)
        ->and($item->category_id)->toBe($kategori->id)
        ->and($item->name)->toBe('Motorn')
        ->and($item->description)->toBe('Den gamla')
        ->and($item->tags->pluck('id')->all())->toBe([$tagg->id]);
});

it('rör kategori och taggar bara när nyckeln finns i requesten', function () {
    withoutVite();

    [, $anvandare, $container] = omslagsvaljareKontext();
    $item = omslagsvaljareItem($container);
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Verktyg']);
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);
    $item->category_id = $kategori->id;
    $item->save();
    $item->tags()->attach($tagg->id);

    $url = "/containers/{$container->ulid}/items/{$item->ulid}";

    // Utelämnade nycklar rör ingenting.
    actingAs($anvandare)->patch($url, ['name' => 'Motorn 2'])->assertRedirect();

    expect($item->refresh()->category_id)->toBe($kategori->id)
        ->and($item->tags()->count())->toBe(1);

    // Redigeringsformuläret skickar `tags: []` och `category: null`: det tömmer.
    actingAs($anvandare)->patch($url, ['name' => 'Motorn 2', 'category' => null, 'tags' => []])->assertRedirect();

    expect($item->refresh()->category_id)->toBeNull()
        ->and($item->tags()->count())->toBe(0);
});
