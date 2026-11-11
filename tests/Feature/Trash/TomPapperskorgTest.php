<?php

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\LegalHold;
use App\Models\StoredFile;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\from;
use function Pest\Laravel\withoutVite;

/*
 * Töm papperskorgen: rutten och behörigheten. Se
 * App\Actions\Trash\EmptyTrash, ContainerPolicy::emptyTrash och
 * App\Http\Controllers\(Api\)TrashController::destroy.
 *
 * Klart när:
 * - en medlem tömmer och allt slängt är borta, även withTrashed()
 * - levande innehåll och annan containers innehåll är orört
 * - slängd bilaga på levande item: referensräknaren minskar, purge_after sätts
 * - delegerad med nivån delete får 403
 * - utan åtkomst 403, oautentiserad 401
 * - ägaren kan tömma när kontot är read_only
 * - inboxens ägare kan tömma, andra får 403
 * - rättslig spärr ger 422 trash.legal_hold, inget raderas
 * - webben omdirigerar med status, och med fel på `trash` vid spärr
 * - en rad trash.emptied loggas med antalen
 * - en tom papperskorg ger 204 med nollor
 */

/** @return array{0: Account, 1: User, 2: array<string, string>, 3: Container} */
function tomningKontext(): array
{
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    return [$account, $user, $headers, $container];
}

/**
 * Slänger ett item, en bilaga på ett levande item, en kategori och en tagg.
 *
 * @return array{storedFile: StoredFile, levande: Item, bilaga: Attachment, item: Item, kategori: Category, tagg: Tag}
 */
function tomningSlangt(Container $container, Account $account, User $user): array
{
    $storedFile = gallringStoredFil(1);
    $levande = papperskorgsItem($container, $account, $user);
    $bilaga = papperskorgsBilaga($levande, $account, $user, ['stored_file_id' => $storedFile->id]);
    $bilaga->delete();

    $item = papperskorgsItem($container, $account, $user);
    $item->delete();
    $kategori = Category::factory()->for($container, 'container')->create();
    $kategori->delete();
    $tagg = Tag::factory()->for($container, 'container')->create();
    $tagg->delete();

    return compact('storedFile', 'levande', 'bilaga', 'item', 'kategori', 'tagg');
}

/** @param array{bilaga: Attachment, item: Item, kategori: Category, tagg: Tag} $s */
function tomningFinnsKvar(array $s): bool
{
    return Attachment::withTrashed()->whereKey($s['bilaga']->id)->exists()
        || Item::withTrashed()->whereKey($s['item']->id)->exists()
        || Category::withTrashed()->whereKey($s['kategori']->id)->exists()
        || Tag::withTrashed()->whereKey($s['tagg']->id)->exists();
}

it('tömmer det slängda innehållet och svarar 204', function () {
    [$account, $user, $headers, $container] = tomningKontext();
    $s = tomningSlangt($container, $account, $user);

    deleteJson("/api/containers/{$container->ulid}/trash", [], $headers)->assertNoContent();

    expect(tomningFinnsKvar($s))->toBeFalse();
});

it('rör varken levande innehåll eller en annan containers papperskorg', function () {
    [$account, $user, $headers, $container] = tomningKontext();
    tomningSlangt($container, $account, $user);

    $levandeItem = papperskorgsItem($container, $account, $user);
    $levandeKategori = Category::factory()->for($container, 'container')->create();
    $levandeTagg = Tag::factory()->for($container, 'container')->create();

    $annan = Container::factory()->for($account, 'account')->create();
    $annanSlangt = tomningSlangt($annan, $account, $user);

    deleteJson("/api/containers/{$container->ulid}/trash", [], $headers)->assertNoContent();

    expect(Item::find($levandeItem->id))->not->toBeNull();
    expect(Category::find($levandeKategori->id))->not->toBeNull();
    expect(Tag::find($levandeTagg->id))->not->toBeNull();
    expect(Item::onlyTrashed()->whereKey($annanSlangt['item']->id)->exists())->toBeTrue();
    expect(Attachment::onlyTrashed()->whereKey($annanSlangt['bilaga']->id)->exists())->toBeTrue();
    expect(Category::onlyTrashed()->whereKey($annanSlangt['kategori']->id)->exists())->toBeTrue();
    expect(Tag::onlyTrashed()->whereKey($annanSlangt['tagg']->id)->exists())->toBeTrue();
});

it('tar en slängd bilaga på ett levande item och minskar referensräknaren', function () {
    [$account, $user, $headers, $container] = tomningKontext();
    $s = tomningSlangt($container, $account, $user);

    deleteJson("/api/containers/{$container->ulid}/trash", [], $headers)->assertNoContent();

    expect(Item::find($s['levande']->id))->not->toBeNull();
    expect(Attachment::withTrashed()->whereKey($s['bilaga']->id)->exists())->toBeFalse();
    $s['storedFile']->refresh();
    expect($s['storedFile']->reference_count)->toBe(0);
    expect($s['storedFile']->purge_after)->not->toBeNull();
});

it('nekar en delegerad användare med nivån delete på hela containern', function () {
    [$account, $user, , $container] = tomningKontext();
    $s = tomningSlangt($container, $account, $user);

    $delegerad = User::factory()->create();
    beviljaAccess($container, $delegerad, 'delete', 'member');
    $token = $delegerad->createToken('api');

    deleteJson("/api/containers/{$container->ulid}/trash", [], ['Authorization' => "Bearer {$token->plainTextToken}"])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'auth.forbidden');

    expect(Item::onlyTrashed()->whereKey($s['item']->id)->exists())->toBeTrue();
});

it('nekar en användare utan åtkomst och en oautentiserad', function () {
    [$account, $user, , $container] = tomningKontext();
    $s = tomningSlangt($container, $account, $user);

    [, , $andraHeaders] = kontoMedMedlem();

    deleteJson("/api/containers/{$container->ulid}/trash", [], $andraHeaders)->assertForbidden();
    auth()->forgetGuards();
    deleteJson("/api/containers/{$container->ulid}/trash")->assertUnauthorized();

    expect(Item::onlyTrashed()->whereKey($s['item']->id)->exists())->toBeTrue();
});

it('låter ägaren tömma också när kontot är read_only', function () {
    [$account, $user, $headers, $container] = tomningKontext();
    $s = tomningSlangt($container, $account, $user);
    $account->update(['status' => 'read_only']);

    deleteJson("/api/containers/{$container->ulid}/trash", [], $headers)->assertNoContent();

    expect(tomningFinnsKvar($s))->toBeFalse();
});

it('låter inboxens ägare tömma sin inbox men ingen annan', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $inbox = Container::factory()->for($account, 'account')->create(['inbox_user_id' => $user->id]);
    $s = tomningSlangt($inbox, $account, $user);

    $kollega = User::factory()->create();
    $account->users()->attach($kollega, ['role' => 'owner']);
    $kollegaToken = $kollega->createToken('api');

    deleteJson("/api/containers/{$inbox->ulid}/trash", [], ['Authorization' => "Bearer {$kollegaToken->plainTextToken}"])
        ->assertForbidden();
    expect(Item::onlyTrashed()->whereKey($s['item']->id)->exists())->toBeTrue();

    auth()->forgetGuards();
    deleteJson("/api/containers/{$inbox->ulid}/trash", [], $headers)->assertNoContent();
    expect(tomningFinnsKvar($s))->toBeFalse();
});

it('svarar 422 trash.legal_hold under en rättslig spärr och raderar ingenting', function () {
    [$account, $user, $headers, $container] = tomningKontext();
    $s = tomningSlangt($container, $account, $user);
    LegalHold::factory()->create(['account_id' => $account->id]);

    deleteJson("/api/containers/{$container->ulid}/trash", [], $headers)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'trash.legal_hold');

    expect(Item::onlyTrashed()->whereKey($s['item']->id)->exists())->toBeTrue();
    expect(Attachment::onlyTrashed()->whereKey($s['bilaga']->id)->exists())->toBeTrue();
    expect(Category::onlyTrashed()->whereKey($s['kategori']->id)->exists())->toBeTrue();
    expect(Tag::onlyTrashed()->whereKey($s['tagg']->id)->exists())->toBeTrue();
});

it('omdirigerar tillbaka med status på webben', function () {
    withoutVite();
    [$account, $user, , $container] = tomningKontext();
    $s = tomningSlangt($container, $account, $user);

    actingAs($user);
    from("/containers/{$container->ulid}/trash")
        ->delete("/containers/{$container->ulid}/trash")
        ->assertRedirect("/containers/{$container->ulid}/trash")
        ->assertSessionHas('status', 'trash-emptied');

    expect(tomningFinnsKvar($s))->toBeFalse();
});

it('omdirigerar tillbaka med fel på trash vid en rättslig spärr på webben', function () {
    withoutVite();
    [$account, $user, , $container] = tomningKontext();
    $s = tomningSlangt($container, $account, $user);
    LegalHold::factory()->create(['account_id' => $account->id]);

    actingAs($user);
    from("/containers/{$container->ulid}/trash")
        ->delete("/containers/{$container->ulid}/trash")
        ->assertRedirect("/containers/{$container->ulid}/trash")
        ->assertSessionHasErrors('trash');

    expect(Item::onlyTrashed()->whereKey($s['item']->id)->exists())->toBeTrue();
});

it('loggar trash.emptied med antalen per typ', function () {
    [$account, $user, $headers, $container] = tomningKontext();
    tomningSlangt($container, $account, $user);

    $loggat = [];
    Log::shouldReceive('info')->andReturnUsing(function (string $meddelande, array $kontext = []) use (&$loggat): void {
        $loggat[] = [$meddelande, $kontext];
    });

    deleteJson("/api/containers/{$container->ulid}/trash", [], $headers)->assertNoContent();

    expect($loggat)->toBe([['trash.emptied', [
        'container_ulid' => $container->ulid,
        'user_ulid' => $user->ulid,
        'deleted' => ['attachment' => 1, 'item' => 1, 'category' => 1, 'tag' => 1],
    ]]]);
});

it('svarar 204 med alla antal noll för en redan tom papperskorg', function () {
    [, $user, $headers, $container] = tomningKontext();

    $loggat = [];
    Log::shouldReceive('info')->andReturnUsing(function (string $meddelande, array $kontext = []) use (&$loggat): void {
        $loggat[] = [$meddelande, $kontext];
    });

    deleteJson("/api/containers/{$container->ulid}/trash", [], $headers)->assertNoContent();

    expect($loggat)->toBe([['trash.emptied', [
        'container_ulid' => $container->ulid,
        'user_ulid' => $user->ulid,
        'deleted' => ['attachment' => 0, 'item' => 0, 'category' => 0, 'tag' => 0],
    ]]]);
});
