<?php

use App\Models\Agent;
use App\Models\AgentReadAudience;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\travel;

/*
 * Issue 404 § 5 · Kopieringsspärren, se [[ADR-0087 MCP-server för agenter]]
 * Riktning 10, *Kopiering*.
 *
 * Servern minns vilka personer som når allt agenten har läst — snittet av
 * läsningarnas mängder — och en skrivning som når någon utanför snittet
 * avvisas med `agent.copy_widens_access`. Användaren finns alltid i snittet,
 * så den privata containern är alltid öppen, och containernamn räknas inte
 * (`containers_list`).
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
});

/**
 * En agent med två containrar: en privat som bara användaren når, och en
 * delad som också en annan person når. Båda ägs av agentens konto, så
 * behörigheten kapar ingenting — det är kopieringsspärren som prövas.
 *
 * @return array{0: Agent, 1: string, 2: User, 3: Container, 4: Container, 5: User}
 */
function kopieringsMiljo(): array
{
    [$agent, $token, $anvandare] = mcpAgentMedToken();

    $privat = mcpEgenContainer($agent, 'Privat');
    $delad = mcpEgenContainer($agent, 'Delad');

    $medlem = User::factory()->create();
    beviljaAccess($delad, $medlem, 'read', 'guest');

    return [$agent, $token, $anvandare, $privat, $delad, $medlem];
}

function kopieringsItem(Container $container, string $namn): Item
{
    return Item::factory()->create(['container_id' => $container->id, 'name' => $namn]);
}

it('avvisar items_create, items_update och items_notes_update efter en läsning i den privata containern', function () {
    [$agent, $token, $anvandare, $privat, $delad, $medlem] = kopieringsMiljo();

    $privatItem = kopieringsItem($privat, 'Privat batteri');
    $delatItem = kopieringsItem($delad, 'Delat batteri');

    // Läsningen i den privata containern: snittet blir {användaren}.
    mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $privatItem->ulid]));

    $fel = mcpFel(mcpVerktyg($token, 'items_create', [
        'container' => $delad->ulid,
        'name' => 'Kopierat batteri',
    ]));

    expect($fel['code'])->toBe('agent.copy_widens_access')
        ->and($fel['hint'])->toContain('search with the container filter before writing here')
        ->and($fel['data'])->toBe([]);

    expect(mcpFel(mcpVerktyg($token, 'items_update', [
        'item' => $delatItem->ulid,
        'name' => 'Delat batteri II',
    ]))['code'])->toBe('agent.copy_widens_access');

    expect(mcpFel(mcpVerktyg($token, 'items_notes_update', [
        'item' => $delatItem->ulid,
        'notes' => 'Kopierat',
    ]))['code'])->toBe('agent.copy_widens_access');

    // Ingenting skrevs.
    expect(Item::query()->where('name', 'Kopierat batteri')->exists())->toBeFalse()
        ->and($delatItem->refresh()->name)->toBe('Delat batteri')
        ->and($delatItem->notes)->toBeNull();
});

it('släpper igenom samma skrivning när agenten bara har läst i den delade containern', function () {
    [$agent, $token, $anvandare, $privat, $delad, $medlem] = kopieringsMiljo();

    $delatItem = kopieringsItem($delad, 'Delat batteri');

    mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $delatItem->ulid]));

    mcpResultat(mcpVerktyg($token, 'items_update', [
        'item' => $delatItem->ulid,
        'name' => 'Delat batteri II',
    ]));

    mcpResultat(mcpVerktyg($token, 'items_create', [
        'container' => $delad->ulid,
        'name' => 'Nytt i det delade',
    ]));

    expect($delatItem->refresh()->name)->toBe('Delat batteri II');
});

it('släpper alltid igenom en skrivning i användarens privata container', function () {
    [$agent, $token, $anvandare, $privat, $delad, $medlem] = kopieringsMiljo();

    $privatItem = kopieringsItem($privat, 'Privat batteri');
    $annatPrivat = kopieringsItem($privat, 'Privat mast');

    mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $privatItem->ulid]));

    mcpResultat(mcpVerktyg($token, 'items_update', [
        'item' => $annatPrivat->ulid,
        'notes' => 'Bara jag ser det här',
    ]));

    expect($annatPrivat->refresh()->notes)->toBe('Bara jag ser det här');
});

it('noterar ingenting ur containers_list — varken snitt eller fönster', function () {
    [$agent, $token, $anvandare, $privat, $delad, $medlem] = kopieringsMiljo();

    $delatItem = kopieringsItem($delad, 'Batteri delat');

    // Containernamnen räknas inte som läst innehåll: listan lämnar inte ut
    // något ur containrarna. Hade den noterat båda hade snittet blivit
    // {användaren} och skrivningen nedan avvisats.
    mcpResultat(mcpVerktyg($token, 'containers_list'));

    expect(AgentReadAudience::query()->where('agent_id', $agent->id)->exists())->toBeFalse()
        ->and($agent->refresh()->read_audience_until)->toBeNull();

    mcpResultat(mcpVerktyg($token, 'items_update', [
        'item' => $delatItem->ulid,
        'name' => 'Tillåten',
    ]));

    expect($delatItem->refresh()->name)->toBe('Tillåten');
});

it('snittar flera läsningar — en senare läsning kan inte vidga snittet', function () {
    [$agent, $token, $anvandare, $privat, $delad, $medlem] = kopieringsMiljo();

    $delatItem = kopieringsItem($delad, 'Delat batteri');
    $privatItem = kopieringsItem($privat, 'Privat batteri');

    // Den delade läsningen ger {användaren, medlemmen}, den privata ger
    // {användaren}. Snittet är det minsta av dem.
    mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $delatItem->ulid]));
    mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $privatItem->ulid]));

    expect(AgentReadAudience::query()->where('agent_id', $agent->id)->pluck('user_id')->map(fn ($id): int => (int) $id)->sort()->values()->all())
        ->toBe([(int) $anvandare->id]);

    expect(mcpFel(mcpVerktyg($token, 'items_update', [
        'item' => $delatItem->ulid,
        'name' => 'Avvisad',
    ]))['code'])->toBe('agent.copy_widens_access');
});

it('räknar kategorilistan som läst innehåll', function () {
    [$agent, $token, $anvandare, $privat, $delad, $medlem] = kopieringsMiljo();

    Category::factory()->create(['container_id' => $privat->id, 'name' => 'Privat kategori']);
    $delatItem = kopieringsItem($delad, 'Delat batteri');

    mcpResultat(mcpVerktyg($token, 'categories_list', ['container' => $privat->ulid]));

    expect(mcpFel(mcpVerktyg($token, 'items_update', [
        'item' => $delatItem->ulid,
        'name' => 'Avvisad',
    ]))['code'])->toBe('agent.copy_widens_access');
});

it('räknar tagglistan som läst innehåll', function () {
    [$agent, $token, $anvandare, $privat, $delad, $medlem] = kopieringsMiljo();

    Tag::factory()->create(['container_id' => $privat->id, 'name' => 'Privat tagg']);
    $delatItem = kopieringsItem($delad, 'Delat batteri');

    mcpResultat(mcpVerktyg($token, 'tags_list', ['container' => $privat->ulid]));

    expect(mcpFel(mcpVerktyg($token, 'items_update', [
        'item' => $delatItem->ulid,
        'name' => 'Avvisad',
    ]))['code'])->toBe('agent.copy_widens_access');
});

it('räknar förälderns publik när items_get lämnar ut vägen', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    $container = mcpEgenContainer($agent, 'Delad');

    $foralder = kopieringsItem($container, 'Förälder');
    $barnet = kopieringsItem($container, 'Barn');

    DB::table('item_link')->insert([
        'from_item_id' => $foralder->id,
        'to_item_id' => $barnet->id,
        'relation' => 'parent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Medlemmen når barnet genom en itemgrant, aldrig föräldern. Vägen
    // "Förälder › Barn" nämner då någon som bara användaren når.
    $medlem = User::factory()->create();
    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $barnet->id,
        'grantee_type' => 'user',
        'grantee_id' => $medlem->id,
        'level' => 'read',
        'kind' => 'member',
    ]);

    mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $barnet->ulid]));

    expect(mcpFel(mcpVerktyg($token, 'items_update', [
        'item' => $barnet->ulid,
        'name' => 'Avvisad',
    ]))['code'])->toBe('agent.copy_widens_access');
});

it('räknar de relaterade itemens publik när relations_list lämnar ut dem', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    $container = mcpEgenContainer($agent, 'Delad');

    $delatItem = kopieringsItem($container, 'Delat');
    $baraAnvandaren = kopieringsItem($container, 'Bara användaren');

    DB::table('item_link')->insert([
        'from_item_id' => $delatItem->id,
        'to_item_id' => $baraAnvandaren->id,
        'relation' => 'related',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $medlem = User::factory()->create();
    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $delatItem->id,
        'grantee_type' => 'user',
        'grantee_id' => $medlem->id,
        'level' => 'read',
        'kind' => 'member',
    ]);

    mcpResultat(mcpVerktyg($token, 'relations_list', ['item' => $delatItem->ulid]));

    expect(mcpFel(mcpVerktyg($token, 'items_update', [
        'item' => $delatItem->ulid,
        'name' => 'Avvisad',
    ]))['code'])->toBe('agent.copy_widens_access');
});

it('räknar sökningen med de items den lämnade ut — utan filter blir snittet det gemensamma', function () {
    [$agent, $token, $anvandare, $privat, $delad, $medlem] = kopieringsMiljo();

    kopieringsItem($privat, 'Batteri privat');
    $delatItem = kopieringsItem($delad, 'Batteri delat');

    $svar = mcpResultat(mcpVerktyg($token, 'items_search', ['q' => 'Batteri']));

    expect(collect($svar['items'])->pluck('name')->sort()->values()->all())
        ->toBe(['Batteri delat', 'Batteri privat']);

    expect(mcpFel(mcpVerktyg($token, 'items_update', [
        'item' => $delatItem->ulid,
        'name' => 'Avvisad',
    ]))['code'])->toBe('agent.copy_widens_access');
});

it('räknar sökningen med de items den lämnade ut — med containerfiltret räcker snittet', function () {
    [$agent, $token, $anvandare, $privat, $delad, $medlem] = kopieringsMiljo();

    $delatItem = kopieringsItem($delad, 'Batteri delat');

    mcpResultat(mcpVerktyg($token, 'items_search', ['q' => 'Batteri', 'container' => $delad->ulid]));

    mcpResultat(mcpVerktyg($token, 'items_update', [
        'item' => $delatItem->ulid,
        'name' => 'Tillåten',
    ]));

    expect($delatItem->refresh()->name)->toBe('Tillåten');
});

it('släpper igenom skrivningen när fönstret har passerat', function () {
    [$agent, $token, $anvandare, $privat, $delad, $medlem] = kopieringsMiljo();

    $privatItem = kopieringsItem($privat, 'Privat batteri');
    $delatItem = kopieringsItem($delad, 'Delat batteri');

    mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $privatItem->ulid]));

    $skriv = fn () => mcpVerktyg($token, 'items_update', [
        'item' => $delatItem->ulid,
        'name' => 'Efter fönstret',
    ]);

    expect(mcpFel($skriv())['code'])->toBe('agent.copy_widens_access');

    // 24 timmar efter SENASTE läsningen tar fönstret slut.
    travel(25)->hours();

    mcpResultat($skriv());

    expect($delatItem->refresh()->name)->toBe('Efter fönstret');
});

it('behåller snittet efter cache:clear', function () {
    [$agent, $token, $anvandare, $privat, $delad, $medlem] = kopieringsMiljo();

    $privatItem = kopieringsItem($privat, 'Privat batteri');
    $delatItem = kopieringsItem($delad, 'Delat batteri');

    mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $privatItem->ulid]));

    Artisan::call('cache:clear');

    expect(mcpFel(mcpVerktyg($token, 'items_update', [
        'item' => $delatItem->ulid,
        'name' => 'Avvisad ändå',
    ]))['code'])->toBe('agent.copy_widens_access');
});
