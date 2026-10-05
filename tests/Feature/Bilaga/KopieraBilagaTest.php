<?php

use App\Actions\Usage\AdjustUsage;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Number;

use function Pest\Laravel\from;
use function Pest\Laravel\withoutVite;

/*
 * M27 · issue 242 — kopian av en bilaga. Se
 * App\Actions\Attachment\CopyAttachment,
 * App\Support\Account\ActingAccount och
 * App\Http\Controllers\AttachmentController::copy(),
 * samt [[ADR-0053 Flytt och kopiering]] § 2, 3, 4, 5 och 7.
 *
 * Skillnaderna mot flytten (tests/Feature/Bilaga/FlyttaBilagaTest.php), och
 * det filen här prövar:
 *
 * 1. **Kopian är en NY bilaga mot SAMMA `stored_file`** — ny ULID, samma
 *    `stored_file_id`, `reference_count` upp med ett (§ 5).
 * 2. **Kvoten prövas ALLTID och bytena läggs PÅ** — också inom samma konto,
 *    fast filen bara finns en gång på disken (§ 4).
 * 3. **Grindarna är `view` på källan och `create` på målet** (§ 2) — kopian
 *    rör inte originalet, så `delete` krävs inte.
 * 4. **Loggen får EN rad, i målcontainern** (§ 7).
 *
 * Hjälparna har prefixet `kopiera`, av samma skäl som `flytta`-hjälparna:
 * Pest lägger alla testfiler i samma namnrymd när hela sviten körs.
 */

function kopieraKonto(string $typ = 'personal'): array
{
    $konto = Account::factory()->create(['type' => $typ]);
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare];
}

function kopieraItem(Container $container, Account $konto, User $anvandare): Item
{
    return Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);
}

/**
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function kopieraKontoMedItem(string $typ = 'personal'): array
{
    [$konto, $anvandare] = kopieraKonto($typ);
    $container = Container::factory()->for($konto, 'account')->create();
    $item = kopieraItem($container, $konto, $anvandare);

    return [$konto, $anvandare, $container, $item];
}

/**
 * En bilaga på itemet med känd storlek. Räknaren rörs INTE här — testet
 * sätter den själv.
 */
function kopieraBilaga(Item $item, Account $konto, User $uppladdare, int $byteSize): Attachment
{
    $stored = StoredFile::factory()->create(['byte_size' => $byteSize]);

    return Attachment::factory()->create([
        'item_id' => $item->id,
        'stored_file_id' => $stored->id,
        'filename' => 'manual.pdf',
        'kind' => 'document',
        'uploaded_by_user_id' => $uppladdare->id,
        'billed_account_id' => $konto->id,
    ]);
}

function kopieraForbrukning(Account $konto): int
{
    return (int) UsageCounter::query()->where('account_id', $konto->id)->value('storage_bytes');
}

function kopieraUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}";
}

function kopieraPost(Container $container, Item $item, Attachment $bilaga, Item $mål, User $anvandare)
{
    return from(kopieraUrl($container, $item))
        ->actingAs($anvandare)
        ->post(
            kopieraUrl($container, $item)."/attachments/{$bilaga->ulid}/copy",
            ['target' => $mål->ulid],
        );
}

it('kopian är en ny bilaga mot samma fil', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = kopieraKontoMedItem();
    $mål = kopieraItem($container, $konto, $anvandare);
    $bilaga = kopieraBilaga($item, $konto, $anvandare, 1500);

    kopieraPost($container, $item, $bilaga, $mål, $anvandare)
        ->assertSessionHas('status', 'attachment-copied');

    expect(Attachment::query()->count())->toBe(2);

    $kopia = Attachment::query()->where('id', '!=', $bilaga->id)->firstOrFail();

    // Ny rad, ny ULID — men SAMMA `stored_file`: bytena skrivs inte igen
    // ([[ADR-0006 Innehållsadresserad lagring]], § 5).
    expect($kopia->ulid)->not->toBe($bilaga->ulid);
    expect($kopia->stored_file_id)->toBe($bilaga->stored_file_id);
    expect($kopia->item_id)->toBe($mål->id);
    expect($kopia->filename)->toBe('manual.pdf');
    expect($kopia->kind)->toBe('document');
    expect($kopia->billed_account_id)->toBe($konto->id);

    // `uploaded_by_user_id` blir den som KOPIERADE — det är hon som skapade
    // den här bilagan (§ 5).
    expect($kopia->uploaded_by_user_id)->toBe($anvandare->id);

    // Originalet står kvar orört, och referensräkningen har ökat med ett.
    expect($bilaga->fresh()->item_id)->toBe($item->id);
    expect(StoredFile::query()->whereKey($bilaga->stored_file_id)->value('reference_count'))->toBe(2);
});

it('kopian belastar kvoten även inom samma konto', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = kopieraKontoMedItem();
    $mål = kopieraItem($container, $konto, $anvandare);
    $bilaga = kopieraBilaga($item, $konto, $anvandare, 1500);
    (new AdjustUsage)->handle($konto->id, bytesDelta: 1500);

    kopieraPost($container, $item, $bilaga, $mål, $anvandare)
        ->assertSessionHas('status', 'attachment-copied');

    // Kvoten mäter den LOGISKA storleken och inte vår disk: kopian belastar
    // ägaren fullt ut, även inom samma konto och fast filen bara finns en
    // gång på disken (§ 4). Dedupen är vår besparing, inte kundens rabatt.
    expect(kopieraForbrukning($konto))->toBe(3000);
    expect(StoredFile::query()->count())->toBe(1);
});

it('kräver view på källan och create på målet', function () {
    withoutVite();

    [$konto, $agare, $container, $item] = kopieraKontoMedItem();
    $bilaga = kopieraBilaga($item, $konto, $agare, 1500);

    // a) Utan `view` på källan. Den utomstående når sitt eget mål (hon äger
    //    containern) men har ingen åtkomst till det här itemet.
    [$utomKonto, $utomAgare] = kopieraKonto('organisation');
    $utomContainer = Container::factory()->for($utomKonto, 'account')->create();
    $utomMål = kopieraItem($utomContainer, $utomKonto, $utomAgare);

    kopieraPost($container, $item, $bilaga, $utomMål, $utomAgare)->assertForbidden();

    // b) Utan `create` på målet. Läsaren är medlem i källans konto (view
    //    räcker) men har bara `read` på det främmande målet.
    $lasare = User::factory()->create();
    $konto->users()->attach($lasare, ['role' => 'member']);

    [$frammandeKonto, $frammandeAgare] = kopieraKonto('organisation');
    $frammande = Container::factory()->for($frammandeKonto, 'account')->create();
    $frammandeMål = kopieraItem($frammande, $frammandeKonto, $frammandeAgare);
    beviljaAccess($frammande, $lasare, 'read', 'guest');

    kopieraPost($container, $item, $bilaga, $frammandeMål, $lasare)->assertForbidden();

    // Ingen av de två nekade gångerna skapade något.
    expect(Attachment::query()->count())->toBe(1);
    expect(StoredFile::query()->whereKey($bilaga->stored_file_id)->value('reference_count'))->toBe(1);
});

it('nekas när kvoten är full', function () {
    withoutVite();

    sättPlangräns('free', 'storage_bytes', 2000);

    [$konto, $anvandare, $container, $item] = kopieraKontoMedItem();
    $mål = kopieraItem($container, $konto, $anvandare);
    $bilaga = kopieraBilaga($item, $konto, $anvandare, 1500);
    (new AdjustUsage)->handle($konto->id, bytesDelta: 1000); // 1000 + 1500 > 2000

    $svar = kopieraPost($container, $item, $bilaga, $mål, $anvandare);

    // Formulärvägen: ett fältfel på `attachment` med meningen ur
    // `ui.error.quota.storage_exceeded` — aldrig en JSON-kropp mitt i sidan.
    $svar->assertRedirect(kopieraUrl($container, $item));
    $svar->assertSessionHasErrors('attachment');
    expect($svar->getContent())->not->toContain('"error"');

    expect(session('errors')->get('attachment')[0])->toBe(Lang::get('ui.error.quota.storage_exceeded', [
        'limit_bytes' => Number::fileSize(2000),
        'used_bytes' => Number::fileSize(1000),
        'file_bytes' => Number::fileSize(1500),
    ], 'en'));

    // Ingenting skrivs: ingen ny rad, ingen räknarökning och
    // referensräkningen står kvar.
    expect(Attachment::query()->count())->toBe(1);
    expect(kopieraForbrukning($konto))->toBe(1000);
    expect(StoredFile::query()->whereKey($bilaga->stored_file_id)->value('reference_count'))->toBe(1);
});
