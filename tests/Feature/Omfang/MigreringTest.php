<?php

use App\Actions\Trash\PurgeContainer;
use App\Actions\Trash\PurgeContent;
use App\Models\Account;
use App\Models\Container;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 69 · Laddern och migreringen — schemat, datamigreringen, rundturen
 * och gallringen. Se [[ADR-0028 Åtkomst på itemnivå]] § Beslut,
 * migrationen 2026_09_10_030000_add_item_id_to_container_access_and_invitation
 * och [[Konton och åtkomst]] § container_access och § invitation.
 *
 * Tyngdpunkten är att efter migrationen ska systemet fungera EXAKT som
 * före den: `item_id` finns men är alltid NULL, `write` har blivit `delete`
 * utan att någon förlorat en rättighet, och gallringen tar med sig de nya
 * raderna i stället för att falla på ett främmandenyckelfel varje natt.
 *
 * Hjälparna kontoMedMedlem(), beviljaAccess(), papperskorgsItem(),
 * papperskorgsBilaga(), gallringContainer() och gallringItem() är globala i
 * tests/Support/Testhjalpare.php.
 */

/**
 * En färsk instans av den här issuens migration, så ett test kan köra
 * `down()` och `up()` om varandra. `require` (inte `require_once`) gör att
 * filen evalueras på nytt varje gång och ger en ny anonym klass.
 *
 * Migrationen är inte ett paket man normalt kör för hand i ett test — den
 * här vägen finns för att rundturen ("fram och sedan tillbaka") bara går
 * att pröva genom att faktiskt vända schemat.
 */
function ladderMigreringen(): object
{
    return require database_path('migrations/2026_09_10_030000_add_item_id_to_container_access_and_invitation.php');
}

it('lägger item_id på båda tabellerna, nullbar med främmande nyckel mot item', function () {
    foreach (['container_access', 'invitation'] as $tabell) {
        expect(Schema::hasColumn($tabell, 'item_id'))->toBeTrue();

        $kolumn = collect(Schema::getColumns($tabell))->firstWhere('name', 'item_id');
        expect($kolumn['nullable'])->toBeTrue();

        $nyckel = collect(Schema::getForeignKeys($tabell))->firstWhere('columns', ['item_id']);

        expect($nyckel)->not->toBeNull();
        expect($nyckel['foreign_table'])->toBe('item');
        expect($nyckel['on_delete'])->toBe('restrict');
    }
});

it('lägger indexet (item_id, revoked_at) på container_access men inte på invitation', function () {
    expect(collect(Schema::getIndexes('container_access'))->firstWhere('columns', ['item_id', 'revoked_at']))
        ->not->toBeNull();

    // invitation slås upp på token_hash, (container_id, status) och
    // (email, status) — aldrig på itemet.
    expect(collect(Schema::getIndexes('invitation'))->firstWhere('columns', ['item_id', 'revoked_at']))
        ->toBeNull();
});

it('lämnar item_id NULL på varje rad', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    beviljaAccess($container, $user, 'read', 'member');
    beviljaAccess($container, $user, 'write', 'member', revokedAt: now());

    Invitation::factory()->create([
        'container_id' => $container->id,
        'invited_by_user_id' => $user->id,
    ]);

    expect(DB::table('container_access')->whereNotNull('item_id')->count())->toBe(0);
    expect(DB::table('invitation')->whereNotNull('item_id')->count())->toBe(0);
});

/*
 * Avbildningen: `write` blir `delete`, `read` står kvar, och `create` och
 * `write` börjar utan innehavare. En `write`-innehavare kan i dag radera
 * items och bilagor, så det är den nivå hon ska behålla.
 *
 * Raderna skapas medan schemat är fullt migrerat och sätts sedan tillbaka
 * till nivåerna som fanns FÖRE: `down()` tar bort kolumnen och `up()` är
 * den avbildning som prövas. `invitation` följer med, prövad på en
 * `pending`-rad — en inbjudan som skickades i går ska ge samma behörighet
 * vid accept i morgon som den utlovade när den skickades.
 */
it('avbildar write till delete och lämnar read orörd, i båda tabellerna', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $read = beviljaAccess($container, $user, 'read', 'member');
    $write = beviljaAccess($container, $user, 'write', 'member', revokedAt: now());
    $inbjudan = Invitation::factory()->create([
        'container_id' => $container->id,
        'level' => 'write',
        'status' => 'pending',
        'invited_by_user_id' => $user->id,
    ]);

    $migration = ladderMigreringen();
    $migration->down();
    $migration->up();

    expect(DB::table('container_access')->where('id', $read->id)->value('level'))->toBe('read');
    expect(DB::table('container_access')->where('id', $write->id)->value('level'))->toBe('delete');
    expect(DB::table('invitation')->where('id', $inbjudan->id)->value('level'))->toBe('delete');

    expect(DB::table('container_access')->whereIn('level', ['create', 'write'])->exists())->toBeFalse();
    expect(DB::table('invitation')->whereIn('level', ['create', 'write'])->exists())->toBeFalse();
});

/*
 * Rundturen: en migrering fram och sedan tillbaka lämnar `level` intakt på
 * rader som fanns FÖRE den — `read` är `read` och `write` är `write`
 * efteråt. Det är hela beviset för att `down()`:s avbildning (delete →
 * write, create → read) är den omvända av `up()`:s.
 */
it('en migrering fram och tillbaka lämnar level intakt', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $read = beviljaAccess($container, $user, 'read', 'member');
    $write = beviljaAccess($container, $user, 'write', 'member', revokedAt: now());

    $migration = ladderMigreringen();

    // Fram. write -> delete.
    $migration->down();
    $migration->up();

    expect(DB::table('container_access')->where('id', $read->id)->value('level'))->toBe('read');
    expect(DB::table('container_access')->where('id', $write->id)->value('level'))->toBe('delete');

    // och tillbaka. delete -> write.
    $migration->down();

    expect(DB::table('container_access')->where('id', $read->id)->value('level'))->toBe('read');
    expect(DB::table('container_access')->where('id', $write->id)->value('level'))->toBe('write');

    // Schemat tillbaka till måltillståndet, så testet inte lämnar
    // kolumnerna borttagna åt nästa test i samma process.
    $migration->up();
});

/*
 * Den migrerade `write`-innehavaren — nu `delete` — ska fortfarande kunna
 * radera ett item och en bilaga, med samma anrop och samma statuskod som
 * före migrationen. Det är issuens starkaste kvitto på att ingen grind
 * bytte mening: `ItemController::destroy()` och
 * `AttachmentController::destroy()` står kvar på `update`-grinden, och
 * `delete` passerar den eftersom minimum är `write`.
 */
it('en migrerad write-innehavare kan fortfarande radera ett item och en bilaga', function () {
    [, $user, $headers] = kontoMedMedlem();
    $ägarkonto = Account::factory()->create();
    $container = Container::factory()->for($ägarkonto, 'account')->create();

    beviljaAccess($container, $user, 'delete', 'member');

    $item = papperskorgsItem($container, $ägarkonto, $user);
    $bilaga = papperskorgsBilaga($item, $ägarkonto, $user);

    deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$bilaga->ulid}", [], $headers)
        ->assertNoContent();

    deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [], $headers)
        ->assertNoContent();

    expect(DB::table('item')->where('id', $item->id)->value('deleted_at'))->not->toBeNull();
    expect(DB::table('attachment')->where('id', $bilaga->id)->value('deleted_at'))->not->toBeNull();
});

it('en read-innehavare nekas fortfarande PATCH av ett item', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member');

    $item = Item::factory()->for($container, 'container')->create();

    $svar = patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", ['name' => 'Ändrad'], $headers);

    $svar->assertStatus(403);
    expect($svar->json('error.code'))->toBe('auth.forbidden');
});

it('ett fryst ägarkonto nekas fortfarande allt skrivande', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = papperskorgsItem($container, $account, $user);

    $account->update(['status' => 'read_only']);

    $ändra = patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", ['name' => 'Ändrad'], $headers);
    $ändra->assertStatus(403);
    expect($ändra->json('error.code'))->toBe('auth.forbidden');

    $radera = deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [], $headers);
    $radera->assertStatus(403);
    expect($radera->json('error.code'))->toBe('auth.forbidden');
});

/*
 * Gallringen. `container_access.item_id` och `invitation.item_id` är
 * ON DELETE RESTRICT (AGENTS.md § Databaskonventioner), och
 * PurgeContainer::handle() gallrar containerns items FÖRE
 * container_access-raderna. Utan städningen i PurgeContent::item() faller
 * den nattliga gallringen på ett främmandenyckelfel varje natt utan att
 * någon ser det.
 *
 * Raden tas hårt, inte med `revoked_at` — historiken om vem som haft
 * åtkomst till ett item som fysiskt inte längre finns är inte historik värd
 * att bevara. Därför prövas både en levande och en återkallad rad.
 */
it('gallrar ett item som bär en itemåtkomst, även en återkallad', function () {
    [$account, $user, $container] = gallringContainer();
    $item = gallringItem($container, $account, $user);

    $levande = beviljaAccess($container, $user, 'delete', 'member');
    $återkallad = beviljaAccess($container, $user, 'read', 'member', revokedAt: now());

    DB::table('container_access')->whereIn('id', [$levande->id, $återkallad->id])
        ->update(['item_id' => $item->id]);

    app(PurgeContent::class)->item($item);

    expect(DB::table('item')->where('id', $item->id)->exists())->toBeFalse();
    expect(DB::table('container_access')->whereIn('id', [$levande->id, $återkallad->id])->exists())->toBeFalse();
});

it('gallrar ett item som bär en inbjudan med item_id satt', function () {
    [$account, $user, $container] = gallringContainer();
    $item = gallringItem($container, $account, $user);

    $inbjudan = Invitation::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'invited_by_user_id' => $user->id,
    ]);

    app(PurgeContent::class)->item($item);

    expect(DB::table('item')->where('id', $item->id)->exists())->toBeFalse();
    expect(DB::table('invitation')->where('id', $inbjudan->id)->exists())->toBeFalse();
});

it('gallrar en container vars items bär itemåtkomster, hela vägen', function () {
    [$account, $user, $container] = gallringContainer();
    $item = gallringItem($container, $account, $user);

    $access = beviljaAccess($container, $user, 'delete', 'member');
    DB::table('container_access')->where('id', $access->id)->update(['item_id' => $item->id]);

    $inbjudan = Invitation::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'invited_by_user_id' => $user->id,
    ]);

    $container->deleted_at = now();
    $container->save();

    app(PurgeContainer::class)->handle($container);

    expect(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse();
    expect(Item::withTrashed()->whereKey($item->id)->exists())->toBeFalse();
    expect(DB::table('container_access')->where('container_id', $container->id)->exists())->toBeFalse();
    expect(DB::table('invitation')->where('id', $inbjudan->id)->exists())->toBeFalse();
});

/*
 * Ytan är oförändrad i den här issuen: databasen kan bära `create` och
 * `delete`, men API:et ska ännu inte kunna SKAPA dem. Att öppna
 * StoreContainerAccessRequest och StoreInvitationRequest är issue 72 — en
 * valideringsregel som släpper in dem här vore att flytta grinden i
 * samma PR som schemat, och göra felsökningen omöjlig om något går sönder.
 */
it('avvisar fortfarande create och delete som level på en åtkomst', function (string $nivå) {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $mottagare = User::factory()->create();

    $svar = postJson("/api/containers/{$container->ulid}/accesses", [
        'grantee_type' => 'user',
        'grantee' => $mottagare->ulid,
        'level' => $nivå,
        'kind' => 'member',
    ], $headers);

    $svar->assertStatus(422);
    expect($svar->json('error.code'))->toBe('validation.failed');
    expect($svar->json('error.data.fields.level'))->not->toBeNull();
})->with(['create', 'delete']);

it('avvisar fortfarande create och delete som level på en inbjudan', function (string $nivå) {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $svar = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => $nivå,
    ], $headers);

    $svar->assertStatus(422);
    expect($svar->json('error.code'))->toBe('validation.failed');
    expect($svar->json('error.data.fields.level'))->not->toBeNull();
})->with(['create', 'delete']);
