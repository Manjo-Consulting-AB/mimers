<?php

use App\Actions\Inbox\ResolveInbox;
use App\Actions\Schedule\OpenNextOccurrence;
use App\Models\Agent;
use App\Models\AgentContainerGrant;
use App\Models\Attachment;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
 * Issue 1688 · `inbox_tasks_process` och `inbox_attachments_process`, se
 * [[ADR-0054 Inboxen]] § 6 och [[ADR-0087 MCP-server för agenter]] Riktning 10.
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
    Storage::fake('files');
});

/**
 * @param  array<string, string>  $nivaer  grupp => nivå
 */
function bearbRader(Agent $agent, array $nivaer): Item
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

function bearbSchema(Item $item, string $typ = 'none'): Schedule
{
    $schema = Schedule::factory()->create(['item_id' => $item->id, 'recurrence_type' => $typ, 'title' => 'Ring']);
    app(OpenNextOccurrence::class)->handle($schema, now());

    return $schema;
}

function bearbBilaga(Item $item): Attachment
{
    $fil = StoredFile::factory()->create(['mime_type' => 'application/pdf', 'byte_size' => 100]);
    Storage::disk('files')->put($fil->storage_path, '%PDF');

    return Attachment::factory()->create(['item_id' => $item->id, 'stored_file_id' => $fil->id, 'filename' => 'a.pdf', 'kind' => 'document']);
}

function bearbMal(Agent $agent, bool $delad = true): Item
{
    $container = mcpEgenContainer($agent, 'Mål');

    if ($delad) {
        beviljaAccess($container, User::factory()->create(), 'read', 'guest');
    }

    return Item::factory()->create(['container_id' => $container->id, 'name' => 'Målet']);
}

it('flyttar ett schema till ett item i en delad container med lista next som förval', function () {
    [$agent, $token] = mcpAgentMedToken();
    $inbox = bearbRader($agent, ['schedules' => 'delete']);
    $schema = bearbSchema($inbox);
    $mal = bearbMal($agent);

    $svar = mcpResultat(mcpVerktyg($token, 'inbox_tasks_process', ['schedule' => $schema->ulid, 'target' => $mal->ulid]));

    expect($svar)->toBe(['ulid' => $schema->ulid, 'title' => 'Ring', 'item' => $mal->ulid])
        ->and($schema->fresh()->item_id)->toBe($mal->id)
        ->and(Schedule::query()->where('item_id', $inbox->id)->exists())->toBeFalse()
        ->and(ScheduleOccurrence::query()->where('schedule_id', $schema->id)->first()->gtd_list)->toBe('next');
});

it('ger förekomsten vald lista och datum på ett none-schema', function () {
    [$agent, $token] = mcpAgentMedToken();
    $inbox = bearbRader($agent, ['schedules' => 'delete']);
    $schema = bearbSchema($inbox);
    $mal = bearbMal($agent);

    mcpResultat(mcpVerktyg($token, 'inbox_tasks_process', [
        'schedule' => $schema->ulid, 'target' => $mal->ulid, 'gtd_list' => 'someday', 'due_at' => '2027-03-01',
    ]));

    $schema->refresh();

    expect($schema->anchor_date->toDateString())->toBe('2027-03-01')
        ->and(ScheduleOccurrence::query()->where('schedule_id', $schema->id)->first()->gtd_list)->toBe('someday');
});

it('nekar uppgiften med bara write i inboxen eller utan create på målet, och inget flyttas', function () {
    [$agent, $token] = mcpAgentMedToken();
    $inbox = bearbRader($agent, ['schedules' => 'write']);
    $schema = bearbSchema($inbox);
    $mal = bearbMal($agent);

    expect(mcpFel(mcpVerktyg($token, 'inbox_tasks_process', ['schedule' => $schema->ulid, 'target' => $mal->ulid]))['code'])->toBe('agent.forbidden');

    AgentContainerGrant::query()->where('agent_id', $agent->id)->where('container_id', $inbox->container_id)->update(['level' => 'delete']);
    AgentContainerGrant::query()->where('agent_id', $agent->id)->where('container_id', $mal->container_id)->where('group', 'schedules')->update(['level' => 'read']);

    expect(mcpFel(mcpVerktyg($token, 'inbox_tasks_process', ['schedule' => $schema->ulid, 'target' => $mal->ulid]))['code'])->toBe('agent.forbidden');

    $okand = Item::factory()->create();

    expect(mcpFel(mcpVerktyg($token, 'inbox_tasks_process', ['schedule' => $schema->ulid, 'target' => $okand->ulid]))['code'])->toBe('resource.not_found')
        ->and($schema->fresh()->item_id)->toBe($inbox->id);
});

it('ger schedule.not_in_inbox för ett schema i någon annans inbox eller på ett riktigt item', function () {
    [$agent, $token] = mcpAgentMedToken();
    bearbRader($agent, ['schedules' => 'delete']);
    $mal = bearbMal($agent);

    [, $annan] = mcpKonto('pro');
    $annansInbox = app(ResolveInbox::class)->handle($annan);
    $annansSchema = bearbSchema($annansInbox);
    $riktigt = bearbSchema($mal);

    foreach ([$annansSchema, $riktigt] as $schema) {
        $fel = mcpFel(mcpVerktyg($token, 'inbox_tasks_process', ['schedule' => $schema->ulid, 'target' => $mal->ulid]));

        expect($fel['code'])->toBe('schedule.not_in_inbox')->and($fel['hint'])->toContain('inbox_get');
    }

    expect($annansSchema->fresh()->item_id)->toBe($annansInbox->id);
});

it('ger schedule.not_a_valid_target när målet är en inbox', function () {
    [$agent, $token] = mcpAgentMedToken();
    $inbox = bearbRader($agent, ['schedules' => 'delete']);
    $schema = bearbSchema($inbox);

    expect(mcpFel(mcpVerktyg($token, 'inbox_tasks_process', ['schedule' => $schema->ulid, 'target' => $inbox->ulid]))['code'])->toBe('schedule.not_a_valid_target')
        ->and($schema->fresh()->item_id)->toBe($inbox->id);
});

it('prövar inte flyttspärren efter en läsning i en privat container', function () {
    [$agent, $token] = mcpAgentMedToken();
    $inbox = bearbRader($agent, ['schedules' => 'delete']);
    $schema = bearbSchema($inbox);
    $mal = bearbMal($agent);

    $privat = mcpEgenContainer($agent, 'Privat');
    $privatItem = Item::factory()->create(['container_id' => $privat->id, 'name' => 'Privat']);
    mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $privatItem->ulid]));

    mcpResultat(mcpVerktyg($token, 'inbox_tasks_process', ['schedule' => $schema->ulid, 'target' => $mal->ulid]));

    expect($schema->fresh()->item_id)->toBe($mal->id);
});

it('flyttar alla bilagor till målet med antal i svaret', function () {
    [$agent, $token] = mcpAgentMedToken();
    $inbox = bearbRader($agent, ['files' => 'delete']);
    $a = bearbBilaga($inbox);
    $b = bearbBilaga($inbox);
    $mal = bearbMal($agent);

    $svar = mcpResultat(mcpVerktyg($token, 'inbox_attachments_process', ['attachments' => [$a->ulid, $b->ulid], 'target' => $mal->ulid]));

    expect($svar)->toBe(['moved' => 2, 'target' => $mal->ulid])
        ->and($a->fresh()->item_id)->toBe($mal->id)
        ->and($b->fresh()->item_id)->toBe($mal->id);
});

it('flyttar ingen bilaga när en ligger utanför inboxen eller i någon annans inbox', function () {
    [$agent, $token] = mcpAgentMedToken();
    $inbox = bearbRader($agent, ['files' => 'delete']);
    $egen = bearbBilaga($inbox);
    $mal = bearbMal($agent);

    [, $annan] = mcpKonto('pro');
    $annansBilaga = bearbBilaga(app(ResolveInbox::class)->handle($annan));
    $paItem = bearbBilaga($mal);

    foreach ([$annansBilaga, $paItem] as $utanfor) {
        $fel = mcpFel(mcpVerktyg($token, 'inbox_attachments_process', ['attachments' => [$egen->ulid, $utanfor->ulid], 'target' => $mal->ulid]));

        expect($fel['code'])->toBe('attachment.not_in_inbox')->and($fel['hint'])->toContain('nothing was moved');
    }

    expect($egen->fresh()->item_id)->toBe($inbox->id);
});

it('nekar bilagor med bara write i inboxen eller utan create på målet', function () {
    [$agent, $token] = mcpAgentMedToken();
    $inbox = bearbRader($agent, ['files' => 'write']);
    $bilaga = bearbBilaga($inbox);
    $mal = bearbMal($agent);

    expect(mcpFel(mcpVerktyg($token, 'inbox_attachments_process', ['attachments' => [$bilaga->ulid], 'target' => $mal->ulid]))['code'])->toBe('agent.forbidden');

    AgentContainerGrant::query()->where('agent_id', $agent->id)->where('container_id', $inbox->container_id)->update(['level' => 'delete']);
    AgentContainerGrant::query()->where('agent_id', $agent->id)->where('container_id', $mal->container_id)->where('group', 'files')->update(['level' => 'read']);

    expect(mcpFel(mcpVerktyg($token, 'inbox_attachments_process', ['attachments' => [$bilaga->ulid], 'target' => $mal->ulid]))['code'])->toBe('agent.forbidden')
        ->and($bilaga->fresh()->item_id)->toBe($inbox->id);
});

it('ger validation.failed för tom lista och dubbletter', function () {
    [$agent, $token] = mcpAgentMedToken();
    $inbox = bearbRader($agent, ['files' => 'delete']);
    $bilaga = bearbBilaga($inbox);
    $mal = bearbMal($agent);

    expect(mcpFel(mcpVerktyg($token, 'inbox_attachments_process', ['attachments' => [], 'target' => $mal->ulid]))['code'])->toBe('validation.failed')
        ->and(mcpFel(mcpVerktyg($token, 'inbox_attachments_process', ['attachments' => [$bilaga->ulid, $bilaga->ulid], 'target' => $mal->ulid]))['code'])->toBe('validation.failed');
});

it('ger auth.forbidden i read_only och agent.paused för en pausad agent', function () {
    [$agent, $token] = mcpAgentMedToken();
    $inbox = bearbRader($agent, ['schedules' => 'delete', 'files' => 'delete']);
    $schema = bearbSchema($inbox);
    $bilaga = bearbBilaga($inbox);
    $mal = bearbMal($agent);

    $agent->account->forceFill(['status' => 'read_only'])->save();

    expect(mcpFel(mcpVerktyg($token, 'inbox_tasks_process', ['schedule' => $schema->ulid, 'target' => $mal->ulid]))['code'])->toBe('auth.forbidden')
        ->and(mcpFel(mcpVerktyg($token, 'inbox_attachments_process', ['attachments' => [$bilaga->ulid], 'target' => $mal->ulid]))['code'])->toBe('auth.forbidden');

    $agent->account->forceFill(['status' => 'active'])->save();
    $agent->forceFill(['paused_at' => now()])->save();

    expect(mcpFel(mcpVerktyg($token, 'inbox_tasks_process', ['schedule' => $schema->ulid, 'target' => $mal->ulid]))['code'])->toBe('agent.paused')
        ->and(mcpFel(mcpVerktyg($token, 'inbox_attachments_process', ['attachments' => [$bilaga->ulid], 'target' => $mal->ulid]))['code'])->toBe('agent.paused');
});
