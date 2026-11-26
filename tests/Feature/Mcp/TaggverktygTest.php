<?php

use App\Actions\Inbox\ResolveInbox;
use App\Models\AuditLog;
use App\Models\Tag;
use App\Models\User;

/*
 * Issue 1726 · `tags_create`, se [[ADR-0087 MCP-server för agenter]]
 * § Tillägg 2026-11-26.
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
});

it('skapar en tagg genom CreateTag med agenten som aktör', function () {
    [$agent, $token] = mcpAgentMedToken();
    $container = mcpEgenContainer($agent);

    $svar = mcpResultat(mcpVerktyg($token, 'tags_create', [
        'container' => $container->ulid, 'name' => '  Vinter ', 'color' => '#AABBCC',
    ]));

    $tagg = Tag::query()->where('container_id', $container->id)->sole();

    expect($svar)->toBe(['ulid' => $tagg->ulid, 'name' => 'Vinter', 'color' => '#aabbcc', 'created' => true])
        ->and(AuditLog::query()->where('action', AuditLog::ACTION_TAG_CREATED)->where('agent_id', $agent->id)->count())->toBe(1);
});

it('returnerar en aktiv tagg med samma namn oförändrad och utan loggrad', function () {
    [$agent, $token] = mcpAgentMedToken();
    $container = mcpEgenContainer($agent);
    $tagg = Tag::factory()->create(['container_id' => $container->id, 'name' => 'Vinter', 'color' => '#112233']);
    $saldo = (int) AuditLog::query()->max('id');

    $svar = mcpResultat(mcpVerktyg($token, 'tags_create', [
        'container' => $container->ulid, 'name' => 'Vinter', 'color' => '#ffffff',
    ]));

    expect($svar)->toBe(['ulid' => $tagg->ulid, 'name' => 'Vinter', 'color' => '#112233', 'created' => false])
        ->and($tagg->refresh()->color)->toBe('#112233')
        ->and(AuditLog::query()->where('id', '>', $saldo)->exists())->toBeFalse();
});

it('återupplivar en mjukraderad tagg med samma ULID', function () {
    [$agent, $token] = mcpAgentMedToken();
    $container = mcpEgenContainer($agent);
    $tagg = Tag::factory()->create(['container_id' => $container->id, 'name' => 'Vinter']);
    $tagg->delete();

    $svar = mcpResultat(mcpVerktyg($token, 'tags_create', ['container' => $container->ulid, 'name' => 'Vinter']));

    expect($svar['ulid'])->toBe($tagg->ulid)
        ->and($svar['created'])->toBeTrue()
        ->and($tagg->refresh()->trashed())->toBeFalse();
});

it('avvisar utan skrivnivå och i en inbox med agent.forbidden', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    $container = mcpEgenContainer($agent);

    // Inboxen prövas först, med full skrivnivå: skrivNiva() sänker också
    // kontonivån, och då skulle nivåkontrollen svara i stället för inbox-kontrollen.
    $inkorg = app(ResolveInbox::class)->handle($anvandare)->container;

    expect($inkorg->isInbox())->toBeTrue();

    mcpSlaPaI($agent, $inkorg);

    expect(mcpFel(mcpVerktyg($token, 'tags_create', ['container' => $inkorg->ulid, 'name' => 'Vinter']))['code'])
        ->toBe('agent.forbidden');

    skrivNiva($agent, $container, 'items', 'read');

    expect(mcpFel(mcpVerktyg($token, 'tags_create', ['container' => $container->ulid, 'name' => 'Vinter']))['code'])
        ->toBe('agent.forbidden')
        ->and(Tag::query()->exists())->toBeFalse();
});

it('svarar resource.not_found för en okänd container', function () {
    [, $token] = mcpAgentMedToken();

    expect(mcpFel(mcpVerktyg($token, 'tags_create', ['container' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'name' => 'Vinter']))['code'])
        ->toBe('resource.not_found');
});

it('ger validation.failed för ogiltig färg och för ett namn över 100 tecken', function () {
    [$agent, $token] = mcpAgentMedToken();
    $container = mcpEgenContainer($agent);

    foreach ([
        ['name' => 'Vinter', 'color' => 'röd'],
        ['name' => 'Vinter', 'color' => '#abc'],
        ['name' => str_repeat('a', 101)],
        ['name' => '   '],
    ] as $args) {
        expect(mcpFel(mcpVerktyg($token, 'tags_create', ['container' => $container->ulid] + $args))['code'])
            ->toBe('validation.failed');
    }

    expect(mcpResultat(mcpVerktyg($token, 'tags_create', ['container' => $container->ulid, 'name' => str_repeat('a', 100)]))['created'])
        ->toBeTrue();
});

it('avvisar med agent.copy_widens_access när skrivningen når någon utanför snittet', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    $privat = mcpEgenContainer($agent, 'Privat');
    $delad = mcpEgenContainer($agent, 'Delad');
    beviljaAccess($delad, User::factory()->create(), 'read', 'guest');

    Tag::factory()->create(['container_id' => $privat->id, 'name' => 'Hemlig']);
    mcpResultat(mcpVerktyg($token, 'tags_list', ['container' => $privat->ulid]));

    expect(mcpFel(mcpVerktyg($token, 'tags_create', ['container' => $delad->ulid, 'name' => 'Vinter']))['code'])
        ->toBe('agent.copy_widens_access')
        ->and(Tag::query()->where('container_id', $delad->id)->exists())->toBeFalse();
});
