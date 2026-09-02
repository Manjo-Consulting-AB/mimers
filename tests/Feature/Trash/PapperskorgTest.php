<?php

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 20a · Papperskorgen: se vad som raderats i en container och ta
 * tillbaka det. Se App\Http\Controllers\Api\TrashController,
 * App\Http\Requests\Trash\RestoreRequest, App\Actions\Trash\RestoreContent,
 * App\Http\Resources\TrashEntryResource, routes/api.php och
 * config/files.php § trash_retention_days.
 *
 * kontoMedMedlem() och beviljaAccess() återanvänds via Pests globala
 * namnrymd — deklareras i tests/Feature/Container/ContainerCrudTest.php
 * respektive ContainerAtkomstTest.php, samma mönster som DeltagarlistaTest.
 *
 * Testerna sätter mestadels papperskorgstillståndet direkt med modellerna
 * (mjukraderaPapperskorg()) i stället för via raderingsrutterna — det är
 * själva papperskorgen som är ytan under test, och raderingens egna regler
 * (kategorin får inte ha barn eller items, etc.) hör hemma i sina issues.
 *
 * "Klart när" (PapperskorgTest):
 * - listar raderade items, bilagor, kategorier och taggar
 * - listan bär label, context, deleted_at och expires_at
 * - expires_at är deleted_at plus retentionen
 * - listan sorteras med det senast raderade först
 * - innehåll ur en annan container kommer aldrig med
 * - utgånget innehåll listas inte
 * - utgånget innehåll kan inte återställas
 * - ett item återställs
 * - en tagg som återställs kommer tillbaka på sina items
 * - ett item som återställs tar inte med sig en separat raderad bilaga
 * - en bilaga kan inte återställas medan itemet ligger i papperskorgen
 * - en underkategori kan inte återställas medan föräldern ligger i papperskorgen
 * - en ulid ur en annan container avvisas
 * - en rad som inte är raderad kan inte återställas
 * - en read-deltagare får se papperskorgen men inte återställa
 * - en användare utan åtkomst nekas
 * - oautentiserad begäran ger 401
 * - svaret bär aldrig ett löpnummer
 * - listningen gör ett konstant antal frågor
 */

/**
 * Skapar ett item direkt i containern, med $user/$account som skapare —
 * fabrikens egna default-skapare hade annars skapat två ovidkommande
 * användare/konton per item.
 */
function papperskorgsItem(Container $container, Account $account, User $user, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ], $attribut));
}

/**
 * Skapar en bilaga direkt på itemet.
 */
function papperskorgsBilaga(Item $item, Account $account, User $user, array $attribut = []): Attachment
{
    return Attachment::factory()->for($item, 'item')->create(array_merge([
        'uploaded_by_user_id' => $user->id,
        'billed_account_id' => $account->id,
    ], $attribut));
}

/**
 * Mjukraderar en rad genom att sätta deleted_at — samma sluttillstånd som
 * raderingsrutterna (SoftDeletes) men med kontrollerad tidpunkt.
 */
function mjukraderaPapperskorg(Item|Attachment|Category|Tag $modell, ?Carbon $deletedAt = null): void
{
    $modell->deleted_at = $deletedAt ?? now();
    $modell->save();
}

it('listar raderade items, bilagor, kategorier och taggar', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $raderatItem = papperskorgsItem($container, $account, $user, ['name' => 'Motor']);
    mjukraderaPapperskorg($raderatItem);

    $levandeItem = papperskorgsItem($container, $account, $user, ['name' => 'Växellåda']);
    $raderadBilaga = papperskorgsBilaga($levandeItem, $account, $user, ['filename' => 'faktura.pdf']);
    mjukraderaPapperskorg($raderadBilaga);

    $raderadKategori = Category::factory()->for($container, 'container')->create(['name' => 'El']);
    mjukraderaPapperskorg($raderadKategori);

    $raderadTagg = Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);
    mjukraderaPapperskorg($raderadTagg);

    $response = getJson("/api/containers/{$container->ulid}/trash", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(4);

    $typPerUlid = collect($response->json('data'))->pluck('type', 'ulid');
    expect($typPerUlid[$raderatItem->ulid])->toBe('item');
    expect($typPerUlid[$raderadBilaga->ulid])->toBe('attachment');
    expect($typPerUlid[$raderadKategori->ulid])->toBe('category');
    expect($typPerUlid[$raderadTagg->ulid])->toBe('tag');
});

it('listan bär label, context, deleted_at och expires_at', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $raderatItem = papperskorgsItem($container, $account, $user, ['name' => 'Motor']);
    mjukraderaPapperskorg($raderatItem);

    $levandeItem = papperskorgsItem($container, $account, $user, ['name' => 'Växellåda']);
    $raderadBilaga = papperskorgsBilaga($levandeItem, $account, $user, ['filename' => 'faktura.pdf']);
    mjukraderaPapperskorg($raderadBilaga);

    $rotKategori = Category::factory()->for($container, 'container')->create(['name' => 'El']);
    mjukraderaPapperskorg($rotKategori);

    $förälder = Category::factory()->for($container, 'container')->create(['name' => 'Elsystem']);
    $underKategori = Category::factory()->for($container, 'container')->create(['name' => 'Startmotor', 'parent_id' => $förälder->id]);
    mjukraderaPapperskorg($underKategori);

    $raderadTagg = Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);
    mjukraderaPapperskorg($raderadTagg);

    $response = getJson("/api/containers/{$container->ulid}/trash", $headers);

    $response->assertOk();
    $rader = collect($response->json('data'))->keyBy('ulid');

    foreach ($rader as $post) {
        expect(array_keys($post))->toBe(['type', 'ulid', 'label', 'context', 'deleted_at', 'expires_at']);
        expect($post['deleted_at'])->toBeString();
        expect($post['expires_at'])->toBeString();
    }

    expect($rader[$raderatItem->ulid])->toMatchArray(['label' => 'Motor', 'context' => null]);
    expect($rader[$raderadBilaga->ulid])->toMatchArray(['label' => 'faktura.pdf', 'context' => 'Växellåda']);
    expect($rader[$rotKategori->ulid])->toMatchArray(['label' => 'El', 'context' => null]);
    expect($rader[$underKategori->ulid])->toMatchArray(['label' => 'Startmotor', 'context' => 'Elsystem']);
    expect($rader[$raderadTagg->ulid])->toMatchArray(['label' => 'Vinter', 'context' => null]);
});

it('expires_at är deleted_at plus retentionen', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');

    try {
        [$account, $user, $headers] = kontoMedMedlem();
        $container = Container::factory()->for($account, 'account')->create();

        $raderat = papperskorgsItem($container, $account, $user, ['name' => 'Motor']);
        // Inom retentionen (30 dagar) — en fast tidpunkt som gäller så länge
        // testet fryser klockan ovan.
        $deletedAt = Carbon::parse('2026-08-20 10:00:00', 'UTC');
        mjukraderaPapperskorg($raderat, $deletedAt);

        $response = getJson("/api/containers/{$container->ulid}/trash", $headers);

        $response->assertOk();
        $rad = collect($response->json('data'))->firstWhere('ulid', $raderat->ulid);

        $retention = (int) config('files.trash_retention_days');
        expect($rad['deleted_at'])->toBe($deletedAt->toIso8601String());
        expect($rad['expires_at'])->toBe($deletedAt->copy()->addDays($retention)->toIso8601String());
    } finally {
        Carbon::setTestNow();
    }
});

it('listan sorteras med det senast raderade först', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');

    try {
        [$account, $user, $headers] = kontoMedMedlem();
        $container = Container::factory()->for($account, 'account')->create();

        $äldst = papperskorgsItem($container, $account, $user, ['name' => 'Äldst']);
        mjukraderaPapperskorg($äldst, now()->subDays(3));

        $mellan = papperskorgsItem($container, $account, $user, ['name' => 'Mellan']);
        mjukraderaPapperskorg($mellan, now()->subDays(2));

        $nyast = papperskorgsItem($container, $account, $user, ['name' => 'Nyast']);
        mjukraderaPapperskorg($nyast, now()->subDay());

        $response = getJson("/api/containers/{$container->ulid}/trash", $headers);

        $response->assertOk();
        expect($response->json('data.*.ulid'))->toBe([
            $nyast->ulid,
            $mellan->ulid,
            $äldst->ulid,
        ]);
    } finally {
        Carbon::setTestNow();
    }
});

it('innehåll ur en annan container kommer aldrig med', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $mitt = papperskorgsItem($container, $account, User::factory()->create(), ['name' => 'Mitt']);
    mjukraderaPapperskorg($mitt);

    $annatKonto = Account::factory()->create();
    $annanContainer = Container::factory()->for($annatKonto, 'account')->create();
    $deras = papperskorgsItem($annanContainer, $annatKonto, User::factory()->create(), ['name' => 'Deras']);
    mjukraderaPapperskorg($deras);

    $response = getJson("/api/containers/{$container->ulid}/trash", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.ulid'))->toBe($mitt->ulid);
});

it('utgånget innehåll listas inte', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');

    try {
        [$account, $user, $headers] = kontoMedMedlem();
        $container = Container::factory()->for($account, 'account')->create();

        $retention = (int) config('files.trash_retention_days');

        $utgånget = papperskorgsItem($container, $account, $user, ['name' => 'Utgånget']);
        mjukraderaPapperskorg($utgånget, now()->subDays($retention + 1));

        $kvar = papperskorgsItem($container, $account, $user, ['name' => 'Kvar']);
        mjukraderaPapperskorg($kvar, now()->subDays($retention - 1));

        $response = getJson("/api/containers/{$container->ulid}/trash", $headers);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.ulid'))->toBe($kvar->ulid);
    } finally {
        Carbon::setTestNow();
    }
});

it('utgånget innehåll kan inte återställas', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');

    try {
        [$account, $user, $headers] = kontoMedMedlem();
        $container = Container::factory()->for($account, 'account')->create();

        $retention = (int) config('files.trash_retention_days');
        $utgånget = papperskorgsItem($container, $account, $user, ['name' => 'Utgånget']);
        mjukraderaPapperskorg($utgånget, now()->subDays($retention + 1));

        $response = postJson("/api/containers/{$container->ulid}/trash/restore", [
            'type' => 'item',
            'ulid' => $utgånget->ulid,
        ], $headers);

        $response->assertStatus(404);
        expect($response->json('error.code'))->toBe('resource.not_found');
    } finally {
        Carbon::setTestNow();
    }
});

it('ett item återställs', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $raderat = papperskorgsItem($container, $account, $user, ['name' => 'Motor']);
    mjukraderaPapperskorg($raderat);

    $response = postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'item',
        'ulid' => $raderat->ulid,
    ], $headers);

    $response->assertOk();
    expect($response->json('data'))->toMatchArray([
        'type' => 'item',
        'ulid' => $raderat->ulid,
        'label' => 'Motor',
        'context' => null,
        'deleted_at' => null,
        'expires_at' => null,
    ]);

    expect(DB::table('item')->where('id', $raderat->id)->value('deleted_at'))->toBeNull();

    $listning = getJson("/api/containers/{$container->ulid}/items", $headers);
    $listning->assertOk();
    expect($listning->json('data.*.ulid'))->toContain($raderat->ulid);
});

it('en tagg som återställs kommer tillbaka på sina items', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $item = papperskorgsItem($container, $account, $user, ['name' => 'Motor']);
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);
    $item->tags()->attach($tagg);
    mjukraderaPapperskorg($tagg);

    $response = postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'tag',
        'ulid' => $tagg->ulid,
    ], $headers);

    $response->assertOk();
    expect($response->json('data.ulid'))->toBe($tagg->ulid);

    $listning = getJson("/api/containers/{$container->ulid}/items", $headers);
    $listning->assertOk();
    $itemRad = collect($listning->json('data'))->firstWhere('ulid', $item->ulid);
    expect(collect($itemRad['tags'])->pluck('ulid'))->toContain($tagg->ulid);
});

it('ett item som återställs tar inte med sig en separat raderad bilaga', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $item = papperskorgsItem($container, $account, $user, ['name' => 'Motor']);
    $separatRaderad = papperskorgsBilaga($item, $account, $user, ['filename' => 'gammal.pdf']);
    mjukraderaPapperskorg($separatRaderad);
    $kvar = papperskorgsBilaga($item, $account, $user, ['filename' => 'aktuell.pdf']);

    mjukraderaPapperskorg($item);

    $response = postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'item',
        'ulid' => $item->ulid,
    ], $headers);

    $response->assertOk();

    // Den separat raderade bilagan ligger KVAR i papperskorgen med sin egen post.
    $papperskorg = getJson("/api/containers/{$container->ulid}/trash", $headers);
    $papperskorg->assertOk();
    expect($papperskorg->json('data.*.ulid'))->toContain($separatRaderad->ulid);

    // Itemets bilagelista visar bara den som aldrig raderades för sig.
    $bilagor = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", $headers);
    $bilagor->assertOk();
    expect($bilagor->json('data'))->toHaveCount(1);
    expect($bilagor->json('data.0.ulid'))->toBe($kvar->ulid);
});

it('en bilaga kan inte återställas medan itemet ligger i papperskorgen', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $item = papperskorgsItem($container, $account, $user, ['name' => 'Motor']);
    $bilaga = papperskorgsBilaga($item, $account, $user, ['filename' => 'faktura.pdf']);

    mjukraderaPapperskorg($item);
    mjukraderaPapperskorg($bilaga);

    $response = postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'attachment',
        'ulid' => $bilaga->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('trash.parent_deleted');
    expect($response->json('error.data'))->toMatchArray([
        'type' => 'item',
        'ulid' => $item->ulid,
    ]);
});

it('en underkategori kan inte återställas medan föräldern ligger i papperskorgen', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $förälder = Category::factory()->for($container, 'container')->create(['name' => 'Elsystem']);
    $barn = Category::factory()->for($container, 'container')->create(['name' => 'Startmotor', 'parent_id' => $förälder->id]);

    mjukraderaPapperskorg($barn);
    mjukraderaPapperskorg($förälder);

    $response = postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'category',
        'ulid' => $barn->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('trash.parent_deleted');
    expect($response->json('error.data'))->toMatchArray([
        'type' => 'category',
        'ulid' => $förälder->ulid,
    ]);
});

it('en ulid ur en annan container avvisas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $annatKonto = Account::factory()->create();
    $annanContainer = Container::factory()->for($annatKonto, 'account')->create();
    $deras = papperskorgsItem($annanContainer, $annatKonto, User::factory()->create(), ['name' => 'Deras']);
    mjukraderaPapperskorg($deras);

    $response = postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'item',
        'ulid' => $deras->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.ulid.0.code'))->toBe('validation.exists');
});

it('en rad som inte är raderad kan inte återställas', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $levande = papperskorgsItem($container, $account, $user, ['name' => 'Motor']);

    $response = postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'item',
        'ulid' => $levande->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.ulid.0.code'))->toBe('validation.exists');
});

it('en read-deltagare får se papperskorgen men inte återställa', function () {
    [$ägarkonto, $ägare, $ägareHeaders] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create();

    $raderat = papperskorgsItem($container, $ägarkonto, $ägare, ['name' => 'Motor']);
    mjukraderaPapperskorg($raderat);

    [, $gäst, $gästHeaders] = kontoMedMedlem();
    beviljaAccess($container, $gäst, 'read', 'member');

    $listning = getJson("/api/containers/{$container->ulid}/trash", $gästHeaders);
    $listning->assertOk();
    expect($listning->json('data.0.ulid'))->toBe($raderat->ulid);

    $återställning = postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'item',
        'ulid' => $raderat->ulid,
    ], $gästHeaders);

    $återställning->assertStatus(403);
    expect($återställning->json('error.code'))->toBe('auth.forbidden');
});

it('en användare utan åtkomst nekas', function () {
    [, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();

    $response = getJson("/api/containers/{$container->ulid}/trash", $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('oautentiserad begäran ger 401', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();

    $response = getJson("/api/containers/{$container->ulid}/trash");

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
});

it('svaret bär aldrig ett löpnummer', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $raderatItem = papperskorgsItem($container, $account, $user, ['name' => 'Motor']);
    mjukraderaPapperskorg($raderatItem);

    $levandeItem = papperskorgsItem($container, $account, $user, ['name' => 'Växellåda']);
    $raderadBilaga = papperskorgsBilaga($levandeItem, $account, $user, ['filename' => 'faktura.pdf']);
    mjukraderaPapperskorg($raderadBilaga);

    $response = getJson("/api/containers/{$container->ulid}/trash", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);

    foreach ($response->json('data') as $post) {
        expect(array_keys($post))->toBe(['type', 'ulid', 'label', 'context', 'deleted_at', 'expires_at']);
    }

    // Inget "id" någonstans i kroppen — varken posternas eget eller en
    // främmande nyckel som smyger in (samma helkroppssök som DeltagarlistaTest).
    expect($response->getContent())->not->toContain('"id"');
});

it('listningen gör ett konstant antal frågor', function () {
    Carbon::setTestNow(now());

    try {
        [$account, $user, $headers] = kontoMedMedlem();
        $container = Container::factory()->for($account, 'account')->create();

        foreach (range(1, 3) as $i) {
            $item = papperskorgsItem($container, $account, $user, ['name' => "Första $i"]);
            mjukraderaPapperskorg($item);
        }

        $url = "/api/containers/{$container->ulid}/trash";

        // Frys tiden runt mätningarna så UpdateLastActiveAt skriver
        // deterministiskt (issue 80), samma teknik som DeltagarlistaTest.
        getJson($url, $headers)->assertOk();

        DB::enableQueryLog();
        $förstaSvaret = getJson($url, $headers);
        $frågorMedTrePoster = count(DB::getQueryLog());
        DB::flushQueryLog();

        $förstaSvaret->assertOk();
        expect($förstaSvaret->json('data'))->toHaveCount(3);

        foreach (range(1, 10) as $i) {
            $item = papperskorgsItem($container, $account, $user, ['name' => "Fler $i"]);
            mjukraderaPapperskorg($item);
        }
        DB::flushQueryLog(); // fabrikernas egna INSERT-frågor ska inte mätas

        $andraSvaret = getJson($url, $headers);
        $frågorMedTrettonPoster = count(DB::getQueryLog());
        DB::disableQueryLog();

        $andraSvaret->assertOk();
        expect($andraSvaret->json('data'))->toHaveCount(13);

        expect($frågorMedTrettonPoster)->toBe($frågorMedTrePoster);
    } finally {
        Carbon::setTestNow();
    }
});
