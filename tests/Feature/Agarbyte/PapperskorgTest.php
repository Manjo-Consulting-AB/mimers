<?php

// rott-pa-basen: issue 1048, ny yta — ingen befintlig test rörs.

use App\Actions\Usage\AdjustUsage;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\LegalHold;
use App\Models\OwnershipTransfer;
use App\Models\StoredFile;
use App\Models\Tag;
use App\Models\UsageCounter;
use App\Models\User;

use function Pest\Laravel\postJson;

/*
 * Issue 1048 · Ägarbyte: papperskorgen töms, och en rättslig spärr på
 * avsändarkontot nekar bytet. Se
 * App\Actions\OwnershipTransfer\AcceptOwnershipTransfer.
 *
 * Webbsidan av spärren (POST /transfers/{transfer}/accept) testas i
 * tests/Feature/Frontend/AgarbytesvyTest.php.
 *
 * "Klart när" (PapperskorgTest):
 * - spärr på avsändaren: 422 transfer.legal_hold, pending, ingenting ändrat
 * - hävd spärr: samma överlåtelse går att acceptera
 * - spärr på mottagaren hindrar inte bytet
 * - inga rader kvar (withTrashed) för slängda items, bilagor, kategorier, taggar
 * - slängd bilaga på levande item är borta, levande item och bilagor kvar
 * - slängt item med levande bilaga: båda borta, avsändarens storage_bytes ned
 * - reference_count minskar och purge_after sätts vid noll
 * - mottagarens storage_bytes ökar med exakt de kvarvarande levande bilagorna
 * - slängt innehåll i en annan container rörs inte
 * - faller bytet efter tömningen rullas den tillbaka
 */

/**
 * @return array{0: Account, 1: User, 2: Container, 3: Account, 4: array<string, string>, 5: OwnershipTransfer}
 */
function papperskorgBas(): array
{
    [$säljare, $användare] = kontoMedMedlem();
    $container = Container::factory()->for($säljare, 'account')->create();
    UsageCounter::factory()->create(['account_id' => $säljare->id, 'container_count' => 1, 'storage_bytes' => 0]);

    [$köpare, , $köparHeaders] = kontoMedMedlem();
    UsageCounter::factory()->create(['account_id' => $köpare->id, 'container_count' => 0, 'storage_bytes' => 0]);

    $överföring = skapaÄgarbyteRad($container, ['to_account_id' => $köpare->id, 'to_email' => null]);

    return [$säljare, $användare, $container, $köpare, $köparHeaders, $överföring];
}

function papperskorgItem(Container $container, Account $konto, User $användare): Item
{
    return Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $användare->id,
        'created_by_account_id' => $konto->id,
    ]);
}

/**
 * En bilaga på $item, bokförd på $konto, med räknaren uppräknad.
 *
 * @return array{0: Attachment, 1: StoredFile}
 */
function papperskorgBilaga(Item $item, Account $konto, User $användare, int $bytes): array
{
    $fil = StoredFile::factory()->create(['byte_size' => $bytes, 'reference_count' => 1]);
    $bilaga = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $fil->id,
        'uploaded_by_user_id' => $användare->id,
        'billed_account_id' => $konto->id,
    ]);
    (new AdjustUsage)->handle($konto->id, bytesDelta: $bytes);

    return [$bilaga, $fil];
}

/**
 * Slänger en bilaga som TrashAttachment gör: mjukradera och dra av bytena ur
 * räknaren — redan där lämnar de förbrukningen (issue 26a § Beslut 6).
 */
function papperskorgSläng(Attachment $bilaga, Account $konto, int $bytes): void
{
    $bilaga->delete();
    (new AdjustUsage)->handle($konto->id, bytesDelta: -$bytes);
}

function papperskorgBytes(Account $konto): int
{
    return (int) UsageCounter::query()->where('account_id', $konto->id)->value('storage_bytes');
}

it('nekar accept med transfer.legal_hold när avsändarkontot är spärrat och ändrar ingenting', function () {
    [$säljare, $användare, $container, , $köparHeaders, $överföring] = papperskorgBas();
    $slängtItem = papperskorgItem($container, $säljare, $användare);
    [$slängdBilaga] = papperskorgBilaga($slängtItem, $säljare, $användare, 100);
    papperskorgSläng($slängdBilaga, $säljare, 100);
    $slängtItem->delete();
    $levande = papperskorgItem($container, $säljare, $användare);
    [$levandeBilaga] = papperskorgBilaga($levande, $säljare, $användare, 50);
    $kategori = Category::factory()->for($container, 'container')->create();
    $kategori->delete();
    $tagg = Tag::factory()->for($container, 'container')->create();
    $tagg->delete();
    LegalHold::factory()->create(['account_id' => $säljare->id]);

    $svar = postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders);

    $svar->assertStatus(422);
    expect($svar->json('error.code'))->toBe('transfer.legal_hold');
    expect($överföring->fresh()->status)->toBe('pending');
    expect($container->fresh()->account_id)->toBe($säljare->id);
    expect(Item::withTrashed()->whereKey($slängtItem->id)->exists())->toBeTrue();
    expect(Attachment::withTrashed()->whereKey($slängdBilaga->id)->exists())->toBeTrue();
    expect(Attachment::query()->whereKey($levandeBilaga->id)->exists())->toBeTrue();
    expect(Category::withTrashed()->whereKey($kategori->id)->exists())->toBeTrue();
    expect(Tag::withTrashed()->whereKey($tagg->id)->exists())->toBeTrue();
    expect(papperskorgBytes($säljare))->toBe(50);
});

it('låter samma överlåtelse accepteras när spärren hävts', function () {
    [$säljare, , $container, $köpare, $köparHeaders, $överföring] = papperskorgBas();
    $spärr = LegalHold::factory()->create(['account_id' => $säljare->id]);

    postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders)->assertStatus(422);

    $spärr->lifted_at = now();
    $spärr->save();

    postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders)->assertOk();
    expect($container->fresh()->account_id)->toBe($köpare->id);
});

it('hindras inte av en spärr på mottagarkontot', function () {
    [, , $container, $köpare, $köparHeaders, $överföring] = papperskorgBas();
    LegalHold::factory()->create(['account_id' => $köpare->id]);

    postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders)->assertOk();

    expect($container->fresh()->account_id)->toBe($köpare->id);
    expect($överföring->fresh()->status)->toBe('accepted');
});

it('tömmer containerns papperskorg fysiskt men lämnar levande innehåll och andra containers', function () {
    [$säljare, $användare, $container, $köpare, $köparHeaders, $överföring] = papperskorgBas();

    // Slängd bilaga på ett levande item.
    $levandeItem = papperskorgItem($container, $säljare, $användare);
    [$slängdBilaga, $slängdFil] = papperskorgBilaga($levandeItem, $säljare, $användare, 100);
    papperskorgSläng($slängdBilaga, $säljare, 100);
    [$levandeBilaga] = papperskorgBilaga($levandeItem, $säljare, $användare, 40);

    // Slängt item med en levande bilaga, och en slängd bilaga på det.
    $slängtItem = papperskorgItem($container, $säljare, $användare);
    [$bilagaPåSlängt, $filPåSlängt] = papperskorgBilaga($slängtItem, $säljare, $användare, 70);
    [$slängdPåSlängt] = papperskorgBilaga($slängtItem, $säljare, $användare, 30);
    papperskorgSläng($slängdPåSlängt, $säljare, 30);
    $slängtItem->delete();

    $kategori = Category::factory()->for($container, 'container')->create();
    $kategori->delete();
    $tagg = Tag::factory()->for($container, 'container')->create();
    $tagg->delete();

    // Slängt innehåll i en ANNAN container hos avsändaren.
    $annan = Container::factory()->for($säljare, 'account')->create();
    $annatItem = papperskorgItem($annan, $säljare, $användare);
    $annatItem->delete();
    $annanTagg = Tag::factory()->for($annan, 'container')->create();
    $annanTagg->delete();

    expect(papperskorgBytes($säljare))->toBe(110);

    postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders)->assertOk();

    expect(Item::withTrashed()->whereKey($slängtItem->id)->exists())->toBeFalse();
    foreach ([$slängdBilaga, $bilagaPåSlängt, $slängdPåSlängt] as $bilaga) {
        expect(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeFalse();
    }
    expect(Category::withTrashed()->whereKey($kategori->id)->exists())->toBeFalse();
    expect(Tag::withTrashed()->whereKey($tagg->id)->exists())->toBeFalse();

    // Levande innehåll är kvar.
    expect(Item::query()->whereKey($levandeItem->id)->exists())->toBeTrue();
    expect(Attachment::query()->whereKey($levandeBilaga->id)->exists())->toBeTrue();

    // Referensräknaren och purge_after.
    expect($slängdFil->fresh()->reference_count)->toBe(0);
    expect($slängdFil->fresh()->purge_after)->not->toBeNull();
    expect($filPåSlängt->fresh()->reference_count)->toBe(0);
    expect($filPåSlängt->fresh()->purge_after)->not->toBeNull();

    // Räknarna: bara den kvarvarande levande bilagan (40) flyttades.
    expect(papperskorgBytes($säljare))->toBe(0);
    expect(papperskorgBytes($köpare))->toBe(40);
    expect($levandeBilaga->fresh()->billed_account_id)->toBe($köpare->id);

    // Den andra containern är orörd.
    expect(Item::withTrashed()->whereKey($annatItem->id)->exists())->toBeTrue();
    expect(Tag::withTrashed()->whereKey($annanTagg->id)->exists())->toBeTrue();
});

it('räknar ned avsändarens förbrukning för en levande bilaga på ett slängt item', function () {
    [$säljare, $användare, $container, $köpare, $köparHeaders, $överföring] = papperskorgBas();
    $slängtItem = papperskorgItem($container, $säljare, $användare);
    [$bilaga] = papperskorgBilaga($slängtItem, $säljare, $användare, 500);
    $slängtItem->delete();

    postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders)->assertOk();

    expect(Item::withTrashed()->whereKey($slängtItem->id)->exists())->toBeFalse();
    expect(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeFalse();
    expect(papperskorgBytes($säljare))->toBe(0);
    expect(papperskorgBytes($köpare))->toBe(0);
});

it('rullar tillbaka tömningen när ägarbytet faller på mottagarens kvot', function () {
    [$säljare, $användare, $container, $köpare, $köparHeaders, $överföring] = papperskorgBas();
    $slängtItem = papperskorgItem($container, $säljare, $användare);
    $slängtItem->delete();
    $tagg = Tag::factory()->for($container, 'container')->create();
    $tagg->delete();

    // Köparen har redan en container; gratisplanen rymmer en.
    Container::factory()->for($köpare, 'account')->create();
    UsageCounter::query()->where('account_id', $köpare->id)->update(['container_count' => 1]);

    $svar = postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders);

    $svar->assertStatus(403);
    expect($svar->json('error.code'))->toBe('quota.containers_exceeded');
    expect(Item::withTrashed()->whereKey($slängtItem->id)->exists())->toBeTrue();
    expect(Tag::withTrashed()->whereKey($tagg->id)->exists())->toBeTrue();
    expect($överföring->fresh()->status)->toBe('pending');
});
