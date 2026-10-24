<?php

use App\Actions\Item\ResolveItemCover;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 341 · Bilden vid skapandet, se [[ADR-0068 Itemets sida och skapandet]]
 * § 11, resources/js/components/ItemForm.vue och resources/js/pages/Containers/Items/Show.vue.
 *
 * Bilden laddas upp EFTER att itemet skapats, mot den BEFINTLIGA ändpunkten
 * `containers.items.attachments.store`. Klienten är källkodsprov (Vue körs inte
 * i Pest), och servern prövas med de två anrop klienten gör: skapandets POST och
 * uppladdningens POST. Varje prov har därför en källkodsnål som bara finns efter
 * ändringen, så att det faller på basen — och en servernål som visar att den
 * befintliga vägen räcker: ingen ny rutt, ingen ny grind, ingen hash från
 * klienten, kvoten på kontot.
 *
 * Hjälparna har prefixet `bildvidskapandet` — Pest lägger alla testfiler i samma
 * namnrymd.
 */

beforeEach(function () {
    Storage::fake('files');
});

function bildvidskapandetSkript(): string
{
    return vueSkript('js/components/ItemForm.vue');
}

/**
 * Ett konto med en ägarmedlem och en container ägd av kontot.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function bildvidskapandetKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare, Container::factory()->for($konto, 'account')->create()];
}

function bildvidskapandetBild(string $namn = 'bat.png'): UploadedFile
{
    $bild = imagecreatetruecolor(20, 20);
    imagefilledrectangle($bild, 0, 0, 19, 19, imagecolorallocate($bild, 10, 20, 30));

    ob_start();
    imagepng($bild);
    $byten = (string) ob_get_clean();
    imagedestroy($bild);

    return UploadedFile::fake()->createWithContent($namn, $byten);
}

/**
 * Skapandets POST, som formuläret skickar den (utan bilden), och sedan itemet.
 */
function bildvidskapandetSkapa(User $anvandare, Container $container, Account $konto): Item
{
    actingAs($anvandare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Båtmotorn',
        'account' => $konto->ulid,
    ])->assertRedirect();

    return Item::query()->where('container_id', $container->id)->sole();
}

it('ger skapandet fältet Image som bara tar bilder', function () {
    withoutVite();

    $mall = vueMall('js/components/ItemForm.vue');
    $skript = bildvidskapandetSkript();

    expect($mall)->toContain("t('item.form.image')");
    expect($mall)->toContain('accept="image/*"');
    expect($mall)->toContain('type="file"');
    preg_match('/<input[^>]*type="file"[^>]*>/s', $mall, $falt);
    expect($falt)->toHaveCount(1);
    expect($falt[0])->not->toContain('multiple');
    expect($skript)->toContain("file.type.startsWith('image/')");
    expect(File::get(base_path('lang/en/ui.php')))->toContain("'image_only' => 'Choose an image file.'");
});

it('gör ett item skapat med bild till bilden som bilaga och som omslag', function () {
    withoutVite();

    [$konto, $anvandare, $container] = bildvidskapandetKontext();

    // Klienten: uppladdningen startas ur `success` på skapandets svar, och
    // skickar bara file och account — aldrig cover eller hash.
    $skript = bildvidskapandetSkript();
    expect($skript)->toContain("router.on('success'");
    expect($skript)->toContain('{ file, account }');
    expect($skript)->not->toContain('{ file, account, cover');
    expect($skript)->not->toContain('hash');

    // Servern: skapandet, sedan uppladdningen mot den befintliga ändpunkten.
    $item = bildvidskapandetSkapa($anvandare, $container, $konto);

    actingAs($anvandare)
        ->post("/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
            'file' => bildvidskapandetBild(),
            'account' => $konto->ulid,
        ])
        ->assertSessionHasNoErrors();

    $bilaga = Attachment::query()->where('item_id', $item->id)->sole();

    expect($bilaga->kind)->toBe('image');
    expect(app(ResolveItemCover::class)->handle($item)?->is($bilaga))->toBeTrue();
});

it('debiterar uppladdningen på kontot itemet skapades med och går mot den befintliga ändpunkten', function () {
    withoutVite();

    [$konto, $anvandare, $container] = bildvidskapandetKontext();
    $annat = Account::factory()->create();
    $annat->users()->attach($anvandare, ['role' => 'owner']);

    $skript = bildvidskapandetSkript();
    expect($skript)->toContain('const account = form.account;');
    expect($skript)->toContain('`${itemUrl}/attachments`');
    expect($skript)->toContain('router.post(`${itemUrl}/attachments`, { file, account }');

    // Itemet skapas med det andra kontot, och bilden laddas upp med samma.
    actingAs($anvandare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Båtmotorn',
        'account' => $annat->ulid,
    ])->assertRedirect();

    $item = Item::query()->where('container_id', $container->id)->sole();

    actingAs($anvandare)->post("/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => bildvidskapandetBild(),
        'account' => $annat->ulid,
    ])->assertSessionHasNoErrors();

    expect((int) DB::table('usage_counter')->where('account_id', $annat->id)->value('storage_bytes'))->toBeGreaterThan(0);
    expect((int) DB::table('usage_counter')->where('account_id', $konto->id)->value('storage_bytes'))->toBe(0);
    expect(Route::has('containers.items.attachments.store'))->toBeTrue();
});

it('lämnar itemet skapat, öppnar Documents och visar felet när kvoten nekar uppladdningen', function () {
    withoutVite();

    sättPlangräns('free', 'storage_bytes', 10);
    [$konto, $anvandare, $container] = bildvidskapandetKontext();

    // Klienten: felet bärs till itemets Documents-flik, som ritar det.
    $skript = bildvidskapandetSkript();
    expect($skript)->toContain('?tab=attachments');
    expect($skript)->toContain('errors.file');
    expect($skript)->toContain('creationFailure.value = { item: itemUlid, message };');
    expect(vueMall('js/pages/Containers/Items/Show.vue'))->toContain("t('item.create.image_failed')");
    expect(vueMall('js/pages/Containers/Items/Show.vue'))->toContain('creationFailure.message');

    // Servern: itemet finns, bilagan finns inte och felet ligger på `file`.
    $item = bildvidskapandetSkapa($anvandare, $container, $konto);

    actingAs($anvandare)
        ->post("/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
            'file' => bildvidskapandetBild(),
            'account' => $konto->ulid,
        ])
        ->assertSessionHasErrors('file');

    expect(Item::query()->where('container_id', $container->id)->count())->toBe(1);
    expect(Attachment::query()->count())->toBe(0);
});

it('skapar ett item utan bild som förut och laddar inte upp något', function () {
    withoutVite();

    [$konto, $anvandare, $container] = bildvidskapandetKontext();

    // Klienten: utan vald bild sätts ingen lyssnare och ingen uppladdning görs.
    expect(bildvidskapandetSkript())->toMatch('/if \(file === null\) \{\s+return;\s+\}/');

    $item = bildvidskapandetSkapa($anvandare, $container, $konto);

    expect($item->name)->toBe('Båtmotorn');
    expect(Attachment::query()->count())->toBe(0);
});

it('lägger inga rutter till för bilden vid skapandet', function () {
    withoutVite();

    $bilagerutter = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($rutt) => str_contains($rutt->uri(), 'attachments') && $rutt->methods()[0] === 'POST')
        ->map(fn ($rutt) => $rutt->getName())
        ->filter()
        ->sort()
        ->values()
        ->all();

    // Uppladdningen återanvänder `containers.items.attachments.store`; ingen
    // egen rutt för bilden finns, och klienten bygger ingen annan adress.
    expect($bilagerutter)->toContain('containers.items.attachments.store');
    expect(Route::has('containers.items.image.store'))->toBeFalse();
    expect(bildvidskapandetSkript())->toContain('router.post(`${itemUrl}/attachments`');
    expect(bildvidskapandetSkript())->not->toContain('/image');
});
