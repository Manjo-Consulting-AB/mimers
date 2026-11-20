<?php

use App\Actions\Item\UpdateItem;
use App\Mcp\Tools\ItemsVersionsList;
use App\Mcp\Tools\ItemsVersionsRestore;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemVersion;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
 * Issue 415 · MCP-verktygen för versioner, se [[ADR-0088 Versionshistorik
 * för items]] Riktning 5 och [[ADR-0087 MCP-server för agenter]] Riktning 3,
 * 9, 10 och 15.
 *
 * Proven täcker de fem Klart när-punkterna: agentens skrivning sparar
 * `replaced_by_agent_id`, användarens skrivning direkt efter ger en ny
 * version, listan avvisas för en agent som inte når containern,
 * återställningen kräver samma rättighet som `items_update`, och
 * annotationerna står i kontraktsprovet (tests/Feature/Mcp/
 * VerktygslistaTest.php).
 *
 * Hjälparna mcpAgentMedToken(), mcpVerktyg(), mcpFel(), mcpResultat(),
 * mcpEgenContainer() och skrivNiva() bor i McpEndpointTest.php och
 * SkrivverktygTest.php.
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * En agent med token, en egen container och ett item i den. Kontots tak är
 * förvalet tre versioner.
 *
 * @return array{0: Agent, 1: string, 2: User, 3: Container, 4: Item}
 */
function versionMcpMiljo(): array
{
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    $container = mcpEgenContainer($agent);

    $item = Item::factory()->create([
        'container_id' => $container->id,
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $agent->account_id,
        'billed_account_id' => $agent->account_id,
    ]);

    return [$agent, $token, $anvandare, $container, $item];
}

it('sparar agenten som replaced_by_agent_id när items_update skriver', function () {
    [$agent, $token, $anvandare, , $item] = versionMcpMiljo();

    mcpResultat(mcpVerktyg($token, 'items_update', [
        'item' => $item->ulid,
        'name' => 'Motorn II',
    ]));

    $version = ItemVersion::query()->where('item_id', $item->id)->sole();

    expect($version->name)->toBe('Motorn')
        ->and((int) $version->replaced_by_user_id)->toBe((int) $anvandare->id)
        ->and((int) $version->replaced_by_agent_id)->toBe((int) $agent->id);
});

it('sparar agenten som aktör när items_notes_update skriver', function () {
    [$agent, $token, , , $item] = versionMcpMiljo();

    mcpResultat(mcpVerktyg($token, 'items_notes_update', [
        'item' => $item->ulid,
        'notes' => 'Servad i höstas',
    ]));

    $version = ItemVersion::query()->where('item_id', $item->id)->sole();

    expect($version->notes)->toBeNull()
        ->and((int) $version->replaced_by_agent_id)->toBe((int) $agent->id);
});

it('ger användarens skrivning direkt efter agentens en ny version', function () {
    [$agent, $token, $anvandare, , $item] = versionMcpMiljo();

    mcpResultat(mcpVerktyg($token, 'items_update', [
        'item' => $item->ulid,
        'name' => 'Motorn II',
    ]));

    // Samma användare, noll minuter senare, men utan agenten: en annan aktör.
    $item->refresh();
    $item->name = 'Motorn III';
    app(UpdateItem::class)->handle($item, $anvandare, false, collect());

    $versioner = ItemVersion::query()
        ->where('item_id', $item->id)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();

    expect($versioner)->toHaveCount(2)
        ->and($versioner[0]->name)->toBe('Motorn')
        ->and((int) $versioner[0]->replaced_by_agent_id)->toBe((int) $agent->id)
        ->and($versioner[1]->name)->toBe('Motorn II')
        ->and($versioner[1]->replaced_by_agent_id)->toBeNull();
});

it('listar versionerna nyast först med vem som ersatte och vilka fält som skiljer', function () {
    [$agent, $token, $anvandare, , $item] = versionMcpMiljo();

    // Agentens skrivning sparar läget före: namnet utan beskrivning.
    mcpResultat(mcpVerktyg($token, 'items_update', [
        'item' => $item->ulid,
        'name' => 'Mellan',
        'description' => 'Gammal text',
    ]));

    // Användarens skrivning bryter skurven och sparar läget dessförinnan.
    $item->refresh();
    $item->name = 'Ny';
    app(UpdateItem::class)->handle($item, $anvandare, false, collect());

    $svar = mcpResultat(mcpVerktyg($token, 'items_versions_list', [
        'item' => $item->ulid,
    ]));

    expect($svar['versions'])->toHaveCount(2)
        ->and($svar['next_cursor'])->toBeNull();

    [$nyast, $aldst] = $svar['versions'];

    expect($nyast['name'])->toBe('Mellan')
        ->and($nyast['replaced_by']['name'])->toBe($anvandare->name)
        ->and($nyast['replaced_by']['agent'])->toBeFalse()
        ->and($nyast['changed'])->toContain('name')
        ->and($nyast['changed'])->not->toContain('description')
        // Fritexten ligger i sitt eget fält, åtskild från verktygets.
        ->and($nyast['content']['description'])->toBe('Gammal text')
        ->and($aldst['name'])->toBe('Motorn')
        ->and($aldst['replaced_by']['name'])->toBe('Claude')
        ->and($aldst['replaced_by']['agent'])->toBeTrue()
        ->and($aldst['changed'])->toContain('name', 'description');
});

it('sidindelar versionerna med markör', function () {
    [$agent, $token, , , $item] = versionMcpMiljo();

    foreach (range(1, 3) as $nummer) {
        // Klockan flyttas så att raderna får olika `created_at`; ordningen
        // är nyast först.
        Carbon::setTestNow(now()->addMinute());

        ItemVersion::factory()->for($item, 'item')->create([
            'billed_account_id' => $agent->account_id,
            'name' => 'Version '.$nummer,
            'replaced_by_user_id' => $agent->user_id,
        ]);
    }

    $första = mcpResultat(mcpVerktyg($token, 'items_versions_list', [
        'item' => $item->ulid,
        'limit' => 2,
    ]));

    expect($första['versions'])->toHaveCount(2)
        ->and($första['versions'][0]['name'])->toBe('Version 3')
        ->and($första['next_cursor'])->not->toBeNull();

    $andra = mcpResultat(mcpVerktyg($token, 'items_versions_list', [
        'item' => $item->ulid,
        'limit' => 2,
        'cursor' => $första['next_cursor'],
    ]));

    expect($andra['versions'])->toHaveCount(1)
        ->and($andra['versions'][0]['name'])->toBe('Version 1')
        ->and($andra['next_cursor'])->toBeNull();
});

it('visar inga versioner när historiken är avstängd', function () {
    [$agent, $token, , $container, $item] = versionMcpMiljo();

    ItemVersion::factory()->for($item, 'item')->create([
        'billed_account_id' => $agent->account_id,
        'name' => 'Gammal',
        'replaced_by_user_id' => $agent->user_id,
    ]);

    $container->versioning_max_versions = 0;
    $container->save();

    expect(mcpResultat(mcpVerktyg($token, 'items_versions_list', [
        'item' => $item->ulid,
    ]))['versions'])->toBe([]);
});

it('avvisar en agent som inte når containern', function () {
    [, $token] = mcpAgentMedToken();

    $annans = Container::factory()->create(['name' => 'Annans']);
    $item = Item::factory()->create(['container_id' => $annans->id, 'name' => 'Hemlig']);

    expect(mcpFel(mcpVerktyg($token, 'items_versions_list', [
        'item' => $item->ulid,
    ]))['code'])->toBe('resource.not_found');

    expect(mcpFel(mcpVerktyg($token, 'items_versions_restore', [
        'item' => $item->ulid,
        'version' => 'oavgjord',
    ]))['code'])->toBe('resource.not_found');
});

it('återställer en version och kräver samma rättighet som items_update', function () {
    [$agent, $token, $anvandare, $container, $item] = versionMcpMiljo();

    mcpResultat(mcpVerktyg($token, 'items_update', [
        'item' => $item->ulid,
        'name' => 'Motorn II',
    ]));

    $version = ItemVersion::query()->where('item_id', $item->id)->sole();

    // Läsning räcker för listan men inte för återställningen.
    skrivNiva($agent, $container, 'items', 'read');

    mcpResultat(mcpVerktyg($token, 'items_versions_list', ['item' => $item->ulid]));

    expect(mcpFel(mcpVerktyg($token, 'items_versions_restore', [
        'item' => $item->ulid,
        'version' => $version->ulid,
    ]))['code'])->toBe('agent.forbidden');

    skrivNiva($agent, $container, 'items', 'write');

    $svar = mcpResultat(mcpVerktyg($token, 'items_versions_restore', [
        'item' => $item->ulid,
        'version' => $version->ulid,
    ]));

    expect($svar['ulid'])->toBe($item->ulid)
        ->and($svar['skipped'])->toBe([])
        ->and(Item::query()->findOrFail($item->id)->name)->toBe('Motorn');

    // Nuläget FÖRE återställningen sparades som en version med agenten som
    // aktör, så att återställningen går att ångra.
    $förra = ItemVersion::query()
        ->where('item_id', $item->id)
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->firstOrFail();

    expect($förra->name)->toBe('Motorn II')
        ->and((int) $förra->replaced_by_agent_id)->toBe((int) $agent->id);

    // Och historiken visar återställningen som agentens handling.
    expect(AuditLog::query()
        ->where('agent_id', $agent->id)
        ->where('item_id', $item->id)
        ->where('action', AuditLog::ACTION_ITEM_UPDATED)
        ->exists())->toBeTrue();
});

it('avvisar en version som hör till ett annat item', function () {
    [$agent, $token, $anvandare, $container, $item] = versionMcpMiljo();

    $annat = Item::factory()->create([
        'container_id' => $container->id,
        'name' => 'Masten',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $agent->account_id,
        'billed_account_id' => $agent->account_id,
    ]);

    $version = ItemVersion::factory()->for($annat, 'item')->create([
        'billed_account_id' => $agent->account_id,
        'name' => 'Gammal mast',
        'replaced_by_user_id' => $anvandare->id,
    ]);

    expect(mcpFel(mcpVerktyg($token, 'items_versions_restore', [
        'item' => $item->ulid,
        'version' => $version->ulid,
    ]))['code'])->toBe('resource.not_found');
});

it('märker verktygen som kontraktet kräver', function () {
    expect(app(ItemsVersionsList::class)->annotations())
        ->toMatchArray(['readOnlyHint' => true]);

    expect(app(ItemsVersionsRestore::class)->annotations())
        ->toMatchArray([
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => false,
        ]);
});
