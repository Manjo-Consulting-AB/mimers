<?php

use App\Actions\Inbox\ResolveInbox;
use App\Models\Agent;
use App\Models\AgentContainerGrant;
use App\Models\AgentReadAudience;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
 * Issue 1686 · `inbox_get` och inboxens undantag från kopieringsspärren, se
 * [[ADR-0054 Inboxen]] och [[ADR-0087 MCP-server för agenter]] Riktning 10.
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
    Storage::fake('files');
});

/**
 * Agentens rader i användarens inbox, bara för de grupper som anges.
 *
 * @param  array<string, string>  $nivaer  grupp => nivå
 */
function inboxRaderFor(Agent $agent, array $nivaer): Item
{
    $item = app(ResolveInbox::class)->handle($agent->user);

    foreach ($nivaer as $grupp => $niva) {
        $rad = new AgentContainerGrant;
        $rad->agent_id = $agent->id;
        $rad->container_id = $item->container_id;
        $rad->group = $grupp;
        $rad->level = $niva;
        $rad->save();
    }

    return $item;
}

function inboxBilaga(Item $item, string $filnamn = 'kvitto.pdf'): Attachment
{
    $fil = StoredFile::factory()->create(['mime_type' => 'application/pdf', 'byte_size' => 1234]);
    Storage::disk('files')->put($fil->storage_path, '%PDF');

    return Attachment::factory()->create([
        'item_id' => $item->id,
        'stored_file_id' => $fil->id,
        'filename' => $filnamn,
        'kind' => 'document',
    ]);
}

function inboxIngenLasning(Agent $agent): bool
{
    return AgentReadAudience::query()->where('agent_id', $agent->id)->doesntExist()
        && $agent->fresh()->read_audience_until === null;
}

it('ger inbox.not_enabled med hint utan rader i inboxen och skapar ingen inbox', function () {
    [$agent, $token] = mcpAgentMedToken();

    $fel = mcpFel(mcpVerktyg($token, 'inbox_get'));

    expect($fel['code'])->toBe('inbox.not_enabled')
        ->and($fel['hint'])->toContain('/settings/agents')
        ->and(Container::query()->whereNotNull('inbox_user_id')->exists())->toBeFalse();
});

it('ger inbox.not_enabled när inboxen finns men agenten saknar rader där', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    app(ResolveInbox::class)->handle($anvandare);

    expect(mcpFel(mcpVerktyg($token, 'inbox_get'))['code'])->toBe('inbox.not_enabled');
});

it('ger item och levels utan attachments med schedules write, och schedules_create ger gtd_list null', function () {
    [$agent, $token] = mcpAgentMedToken();
    $item = inboxRaderFor($agent, ['schedules' => 'write']);

    $svar = mcpResultat(mcpVerktyg($token, 'inbox_get'));

    expect($svar['item'])->toBe($item->ulid)
        ->and($svar['levels'])->toBe(['schedules' => 'write', 'files' => null])
        ->and($svar)->not->toHaveKey('attachments');

    mcpResultat(mcpVerktyg($token, 'schedules_create', [
        'item' => $svar['item'], 'title' => 'Ring tandläkaren', 'recurrence_type' => 'none',
    ]));

    $schema = Schedule::query()->where('item_id', $item->id)->firstOrFail();

    expect(ScheduleOccurrence::query()->where('schedule_id', $schema->id)->firstOrFail()->gtd_list)->toBeNull();
});

it('ger attachments i samma form som items_get med files read', function () {
    [$agent, $token] = mcpAgentMedToken();
    $item = inboxRaderFor($agent, ['files' => 'read']);
    $bilaga = inboxBilaga($item);

    $svar = mcpResultat(mcpVerktyg($token, 'inbox_get'));

    expect($svar['levels'])->toBe(['schedules' => null, 'files' => 'read'])
        ->and($svar['attachments'])->toBe([[
            'ulid' => $bilaga->ulid,
            'filename' => 'kvitto.pdf',
            'kind' => 'document',
            'mime_type' => 'application/pdf',
            'byte_size' => 1234,
        ]]);
});

it('lämnar läsningsunderlaget oförändrat efter inbox_get, attachments_get och occurrences_list, och en delad skrivning går igenom', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    $item = inboxRaderFor($agent, ['schedules' => 'read', 'files' => 'read']);
    $bilaga = inboxBilaga($item);

    $delad = mcpEgenContainer($agent, 'Delad');
    beviljaAccess($delad, User::factory()->create(), 'read', 'guest');

    mcpResultat(mcpVerktyg($token, 'inbox_get'));
    expect(mcpVerktyg($token, 'attachments_get', ['attachment' => $bilaga->ulid])->assertOk()->json('result.isError'))->not->toBeTrue();
    mcpResultat(mcpVerktyg($token, 'occurrences_list', ['item' => $item->ulid]));
    mcpResultat(mcpVerktyg($token, 'schedules_list', ['item' => $item->ulid]));

    expect(inboxIngenLasning($agent))->toBeTrue();

    mcpResultat(mcpVerktyg($token, 'items_create', ['container' => $delad->ulid, 'name' => 'Efter inboxen']));

    expect(Item::query()->where('name', 'Efter inboxen')->exists())->toBeTrue();
});

it('avvisar fortfarande en delad skrivning när agenten också läst i en privat container', function () {
    [$agent, $token] = mcpAgentMedToken();
    $item = inboxRaderFor($agent, ['schedules' => 'read']);

    $privat = mcpEgenContainer($agent, 'Privat');
    $delad = mcpEgenContainer($agent, 'Delad');
    beviljaAccess($delad, User::factory()->create(), 'read', 'guest');
    $privatItem = Item::factory()->create(['container_id' => $privat->id, 'name' => 'Privat']);

    mcpResultat(mcpVerktyg($token, 'occurrences_list', ['item' => $item->ulid]));
    mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $privatItem->ulid]));

    expect(mcpFel(mcpVerktyg($token, 'items_create', [
        'container' => $delad->ulid, 'name' => 'Kopierat',
    ]))['code'])->toBe('agent.copy_widens_access');
});

it('når inte items i inboxen med bara files', function () {
    [$agent, $token] = mcpAgentMedToken();
    $item = inboxRaderFor($agent, ['files' => 'read']);

    expect(mcpFel(mcpVerktyg($token, 'items_get', ['item' => $item->ulid]))['code'])->toBe('resource.not_found')
        ->and(mcpFel(mcpVerktyg($token, 'containers_tree', ['container' => $item->container->ulid]))['code'])->toBe('resource.not_found');
});

it('når aldrig en annan användares inbox', function () {
    [$agent, $token] = mcpAgentMedToken();
    $egen = inboxRaderFor($agent, ['schedules' => 'write']);

    [, $annan] = mcpKonto('pro');
    $annansItem = app(ResolveInbox::class)->handle($annan);

    expect(mcpResultat(mcpVerktyg($token, 'inbox_get'))['item'])->toBe($egen->ulid)
        ->and(mcpFel(mcpVerktyg($token, 'schedules_list', ['item' => $annansItem->ulid]))['code'])->toBe('resource.not_found');
});

it('tar inte med inboxen i containers_list, även med rader där', function () {
    [$agent, $token] = mcpAgentMedToken();
    inboxRaderFor($agent, ['schedules' => 'write']);
    mcpEgenContainer($agent, 'Verkstan');

    $rader = mcpResultat(mcpVerktyg($token, 'containers_list'))['containers'];

    expect(array_column($rader, 'name'))->toBe(['Verkstan']);
});

it('avvisar en pausad agent och ett konto utan Pro i inbox_get', function () {
    [$agent, $token] = mcpAgentMedToken();
    inboxRaderFor($agent, ['schedules' => 'write']);

    $agent->forceFill(['paused_at' => now()])->save();

    expect(mcpFel(mcpVerktyg($token, 'inbox_get'))['code'])->toBe('agent.paused');
});
