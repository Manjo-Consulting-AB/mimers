<?php

use App\Models\Agent;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

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

it('påverkas inte av containers_list, men av en sökning utan containerfilter', function () {
    [$agent, $token, $anvandare, $privat, $delad, $medlem] = kopieringsMiljo();

    $privatItem = kopieringsItem($privat, 'Batteri privat');
    $delatItem = kopieringsItem($delad, 'Batteri delat');

    mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $privatItem->ulid]));

    // Containernamnen räknas inte — listan lämnar inte ut det delade itemet.
    mcpResultat(mcpVerktyg($token, 'containers_list'));

    expect(mcpFel(mcpVerktyg($token, 'items_update', [
        'item' => $delatItem->ulid,
        'name' => 'Fortfarande avvisad',
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
