<?php

use App\Actions\Access\ResolveItemScope;
use App\Actions\Container\ListShellContainers;
use App\Actions\Inbox\ResolveInbox;
use App\Actions\Item\SearchAccessibleItems;
use App\Actions\User\DeleteUser;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\User;
use App\Support\Access\AccessLevel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 243 · Inboxen, servern. Se [[ADR-0054 Inboxen]] § 1, 2, 3, 9 och
 * App\Actions\Inbox\ResolveInbox.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här. Inga
 * vyer ingår — det här är containern, itemet, åtkomsten, listorna och
 * raderingen.
 *
 * Hjälparna kontoMedMedlem(), beviljaAccess(), papperskorgsBilaga() och
 * oppnaForekomst() är globala i tests/Support/Testhjalpare.php. De tre
 * inbox* nedan är filens egna.
 */

/**
 * En person och hennes personkonto — kontot inboxen ägs av.
 *
 * @return array{0: User, 1: Account}
 */
function inboxPerson(): array
{
    $person = User::factory()->create();
    $konto = Account::factory()->create(['type' => 'personal']);
    $konto->users()->attach($person, ['role' => 'owner']);

    return [$person, $konto];
}

/**
 * Personens inbox-item, skapat om det saknas.
 */
function inboxItem(User $person): Item
{
    return app(ResolveInbox::class)->handle($person);
}

/**
 * Personens inbox-container. Skapar inboxen om den inte finns.
 */
function inboxBehallare(User $person): Container
{
    inboxItem($person);

    return Container::query()->where('inbox_user_id', $person->id)->firstOrFail();
}

it('skapar inboxen första gången och återanvänder den sedan', function () {
    [$person] = inboxPerson();

    $första = inboxItem($person);
    $andra = inboxItem($person);

    expect($andra->id)->toBe($första->id)
        ->and($andra->name)->toBe('Inbox')
        ->and(Container::query()->where('inbox_user_id', $person->id)->count())->toBe(1);

    // Schemat bakom (ADR-0054 § 1): kolumnen är nullbar, unik och bär en
    // RESTRICT-nyckel mot personen. Det unika indexet är skyddet mot två
    // samtidiga inboxar.
    $kolumn = collect(Schema::getColumns('container'))->firstWhere('name', 'inbox_user_id');
    expect($kolumn['nullable'])->toBeTrue();

    $unikt = collect(Schema::getIndexes('container'))
        ->first(fn (array $index): bool => $index['unique'] === true && $index['columns'] === ['inbox_user_id']);
    expect($unikt)->not->toBeNull();

    $nyckel = collect(Schema::getForeignKeys('container'))->firstWhere('columns', ['inbox_user_id']);
    expect($nyckel)->not->toBeNull()
        ->and($nyckel['foreign_table'])->toBe('user')
        ->and($nyckel['on_delete'])->toBe('restrict');
});

it('inboxen räknas inte mot containertaket', function () {
    [$konto, $person, $headers] = kontoMedMedlem();

    inboxItem($person);

    // Gratisplanens enda container är användarens egen — inboxen tog inte
    // dess plats (ADR-0054 § 3).
    $svar = postJson('/api/containers', [
        'name' => 'Vindil',
        'kind' => 'boat',
        'account' => $konto->ulid,
    ], $headers);

    $svar->assertCreated();

    expect(DB::table('usage_counter')->where('account_id', $konto->id)->value('container_count'))->toBe(1);
});

it('en annan medlem i personkontot når inte inboxen', function () {
    [$ägare, $konto] = inboxPerson();
    $medlem = User::factory()->create();
    $konto->users()->attach($medlem, ['role' => 'member']);

    $item = inboxItem($ägare);

    expect(Gate::forUser($medlem)->allows('view', $item))->toBeFalse();

    withoutVite();

    actingAs($medlem)
        ->get("/containers/{$item->container->ulid}")
        ->assertForbidden();
});

it('en grant mot en inbox ger ingenting', function () {
    [$ägare, $konto] = inboxPerson();
    $medlem = User::factory()->create();
    $konto->users()->attach($medlem, ['role' => 'member']);

    $item = inboxItem($ägare);
    $inbox = inboxBehallare($ägare);

    beviljaAccess($inbox, $medlem, 'delete', 'member');

    expect(app(ResolveItemScope::class)->handle($medlem, $inbox)->allows($item->id, AccessLevel::READ))->toBeFalse()
        ->and(Gate::forUser($medlem)->allows('view', $item))->toBeFalse()
        ->and(Gate::forUser($medlem)->allows('view', $inbox))->toBeFalse();
});

it('ägaren får view, create, update och delete på inbox-itemet', function () {
    [$person] = inboxPerson();
    $item = inboxItem($person);

    $grind = Gate::forUser($person);

    expect($grind->allows('view', $item))->toBeTrue()
        ->and($grind->allows('create', $item))->toBeTrue()
        ->and($grind->allows('update', $item))->toBeTrue()
        ->and($grind->allows('delete', $item))->toBeTrue();
});

it('inboxen kan inte döpas om, raderas, delas, överlåtas eller få nya items', function () {
    [$person] = inboxPerson();

    $inbox = inboxItem($person)->container;

    $grind = Gate::forUser($person);

    foreach (['update', 'delete', 'createItem', 'manageAccess', 'transfer', 'viewAccesses', 'viewTransfers', 'viewAuditLog'] as $förmåga) {
        expect($grind->allows($förmåga, $inbox))->toBeFalse("Grinden [{$förmåga}] släppte igenom en inbox för ägaren.");
    }
});

it('inboxen syns inte i containerlistan, sidopanelen, dashboarden, /api/containers eller sökningen', function () {
    [$konto, $person, $headers] = kontoMedMedlem();

    $vanlig = Container::factory()->for($konto, 'account')->create(['name' => 'Båten']);
    $vanligtItem = Item::factory()->for($vanlig, 'container')->create([
        'name' => 'Inbox',
        'created_by_user_id' => $person->id,
        'created_by_account_id' => $konto->id,
    ]);

    $item = inboxItem($person);
    $inbox = $item->container;

    // /api/containers
    $api = getJson('/api/containers', $headers)->assertOk();
    $apiUlider = collect($api->json('data'))->pluck('ulid')->all();

    expect($apiUlider)->toContain($vanlig->ulid)
        ->and($apiUlider)->not->toContain($inbox->ulid);

    // Sökningen — samma action som /search och /api/items. Ett item med
    // samma namn i en vanlig container hittas; inboxens gör det inte.
    $träffar = app(SearchAccessibleItems::class)->handle($person, 'Inbox');

    expect($träffar->pluck('id')->all())->toContain($vanligtItem->id)
        ->and($träffar->pluck('id')->all())->not->toContain($item->id);

    // Sidopanelen
    $grupper = app(ListShellContainers::class)->handle($person);
    $skalUlider = collect($grupper)->flatMap(fn (array $grupp): array => $grupp['containers'])->pluck('ulid')->all();

    expect($skalUlider)->toContain($vanlig->ulid)
        ->and($skalUlider)->not->toContain($inbox->ulid);

    withoutVite();

    // Containerlistan
    $lista = actingAs($person)->get('/containers')->assertOk()->inertiaProps();
    $listaUlider = collect($lista['containers'])->pluck('ulid')->all();

    expect($listaUlider)->toContain($vanlig->ulid)
        ->and($listaUlider)->not->toContain($inbox->ulid);

    // Dashboarden
    $dashboard = actingAs($person)->get('/dashboard')->assertOk()->inertiaProps();
    $kortUlider = collect($dashboard['containerGroups'])
        ->flatMap(fn (array $grupp): array => $grupp['containers'])
        ->pluck('ulid')
        ->all();

    expect($kortUlider)->toContain($vanlig->ulid)
        ->and($kortUlider)->not->toContain($inbox->ulid);
});

it('inboxens uppgifter syns på /tasks', function () {
    [$konto, $person] = kontoMedMedlem();

    $item = inboxItem($person);

    [, $förekomst] = oppnaForekomst($item, [
        'title' => 'Fångad uppgift',
        'anchor_date' => Carbon::now($konto->timezone)->toDateString(),
    ]);

    withoutVite();

    $svar = actingAs($person)->get('/tasks')->assertOk();

    $rader = collect($svar->inertiaProps()['groups'])
        ->flatMap(fn (array $grupp): array => $grupp)
        ->pluck('ulid')
        ->all();

    expect($rader)->toContain($förekomst->ulid);
});

it('inboxen raderas med personen även när kontot står kvar', function () {
    [$person, $konto] = inboxPerson();

    // En andra medlem, så att kontot inte är personens eget och står kvar.
    $konto->users()->attach(User::factory()->create(), ['role' => 'owner']);

    $item = inboxItem($person);
    $inbox = $item->container;

    $bilaga = papperskorgsBilaga($item, $konto, $person);
    [$schema] = oppnaForekomst($item, ['title' => 'Fångad uppgift']);

    app(DeleteUser::class)->handle($person);

    expect(Account::query()->whereKey($konto->id)->exists())->toBeTrue()
        ->and(Container::withTrashed()->whereKey($inbox->id)->exists())->toBeFalse()
        ->and(Item::withTrashed()->whereKey($item->id)->exists())->toBeFalse()
        ->and(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeFalse()
        ->and(Schedule::withTrashed()->whereKey($schema->id)->exists())->toBeFalse();
});
