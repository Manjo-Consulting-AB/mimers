<?php

use App\Actions\Agent\ResolveAgentAccess;
use App\Models\Agent;
use App\Models\AgentAccountGrant;
use App\Models\AgentContainerGrant;
use App\Models\AuditLog;
use App\Models\SecurityLog;
use App\Models\User;
use App\Support\Agent\AgentGroup;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\put;
use function Pest\Laravel\withoutVite;

/*
 * Issue 407 · /settings/agents, se [[ADR-0087 MCP-server för agenter]]
 * Riktning 6 och 12 och App\Http\Controllers\Settings\AgentController.
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
    withoutVite();
});

/**
 * En agent utan några rättigheter, ansluten av en användare på `$plan`.
 *
 * @return array{0: Agent, 1: User}
 */
function sidaAgent(string $plan = 'pro'): array
{
    [, $anvandare] = mcpKonto($plan);
    $flode = mcpAnslut($anvandare);

    return [$flode['agent'], $anvandare];
}

it('ger ett gratiskonto 404 på sidan och ingen länk i menyn', function () {
    [, $anvandare] = mcpKonto('free');
    actingAs($anvandare);

    get('/settings/agents')->assertNotFound();

    get('/settings/profile')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $sida) => $sida->where('mcpAvailable', false));
});

it('ger inte 404 på andra sidor för en användare utan konto', function () {
    [, $anvandare] = mcpKonto('pro');
    $anvandare->accounts()->detach();
    actingAs($anvandare);

    get('/settings/profile')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $sida) => $sida->where('mcpAvailable', false));
});

it('ger 404 när MCP_ENABLED är falsk, även för Pro', function () {
    [$agent, $anvandare] = sidaAgent();
    config(['mcp.enabled' => false]);
    actingAs($anvandare);

    get('/settings/agents')->assertNotFound();
    get("/settings/agents/{$agent->ulid}")->assertNotFound();
    get('/settings/profile')
        ->assertInertia(fn (AssertableInertia $sida) => $sida->where('mcpAvailable', false));
});

it('visar länken Agents efter Webhooks med Pro', function () {
    [, $anvandare] = mcpKonto('pro');
    actingAs($anvandare);

    get('/settings/profile')
        ->assertInertia(fn (AssertableInertia $sida) => $sida->where('mcpAvailable', true));

    $rader = file_get_contents(base_path('resources/js/layouts/settingsSections.js'));

    expect(strpos($rader, "key: 'agents'"))->toBeGreaterThan(strpos($rader, "key: 'webhooks'"))
        ->and($rader)->toContain("requires: 'mcpAvailable'");
});

it('visar en mening och adressen när det inte finns några agenter', function () {
    [, $anvandare] = mcpKonto('pro');
    actingAs($anvandare);

    get('/settings/agents')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $sida) => $sida
            ->component('Settings/Agents')
            ->has('agents', 0)
            ->where('mcpUrl', 'mimers.app/mcp'));
});

it('listar namn, anslutningsdatum, senast använd, antal containrar och paus', function () {
    [$agent, $anvandare] = sidaAgent();
    $agent->forceFill(['last_used_at' => now()->subHour(), 'paused_at' => now()])->saveQuietly();
    mcpEgenContainer($agent);
    mcpEgenContainer($agent);
    actingAs($anvandare);

    get('/settings/agents')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $sida) => $sida
            ->has('agents', 1)
            ->where('agents.0.ulid', $agent->ulid)
            ->where('agents.0.name', $agent->name)
            ->where('agents.0.connectedAt', fn ($v) => is_string($v))
            ->where('agents.0.lastUsedAt', fn ($v) => is_string($v))
            ->where('agents.0.containerCount', 2)
            ->where('agents.0.paused', true));
});

it('visar en ny agent utan någon nivå', function () {
    [$agent, $anvandare] = sidaAgent();
    actingAs($anvandare);

    get("/settings/agents/{$agent->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $sida) => $sida
            ->component('Settings/AgentShow')
            ->has('groups', count(AgentGroup::cases()))
            ->where('groups.0.level', null)
            ->where('agent.createContainers', false)
            ->has('containers', 0));
});

it('ger 404 på någon annans agent', function () {
    [$agent] = sidaAgent();
    [, $annan] = mcpKonto('pro');
    actingAs($annan);

    get("/settings/agents/{$agent->ulid}")->assertNotFound();
    delete("/settings/agents/{$agent->ulid}")->assertNotFound();
    expect(Agent::query()->whereKey($agent->id)->exists())->toBeTrue();
});

it('sparar en nivå direkt och ResolveAgentAccess ser den', function () {
    [$agent, , $anvandare] = mcpAgentMedToken();
    $container = mcpEgenContainer($agent);
    actingAs($anvandare);

    put("/settings/agents/{$agent->ulid}/grants", ['levels' => ['items' => 'read']])->assertRedirect();

    expect(AgentAccountGrant::query()->where('agent_id', $agent->id)->where('group', 'items')->value('level'))->toBe('read')
        ->and(app(ResolveAgentAccess::class)->handle($agent->fresh(), AgentGroup::Items, $container))->toBe('read');

    put("/settings/agents/{$agent->ulid}/grants", ['levels' => ['items' => null], 'create_containers' => true])->assertRedirect();

    expect(AgentAccountGrant::query()->where('agent_id', $agent->id)->where('group', 'items')->exists())->toBeFalse()
        ->and($agent->fresh()->create_containers)->toBeTrue()
        ->and(app(ResolveAgentAccess::class)->handle($agent->fresh(), AgentGroup::Items, $container))->toBeNull();
});

it('avvisar en okänd grupp eller nivå', function () {
    [$agent, $anvandare] = sidaAgent();
    actingAs($anvandare);

    put("/settings/agents/{$agent->ulid}/grants", ['levels' => ['nonsens' => 'read']])->assertStatus(422);
    put("/settings/agents/{$agent->ulid}/grants", ['levels' => ['items' => 'admin']])->assertSessionHasErrors('levels.items');
});

it('sänker containrarnas rader när gruppens nivå sänks', function () {
    [$agent, , $anvandare] = mcpAgentMedToken();
    $container = mcpEgenContainer($agent);
    actingAs($anvandare);

    put("/settings/agents/{$agent->ulid}/grants", ['levels' => ['items' => 'create']])->assertRedirect();

    expect(AgentContainerGrant::query()
        ->where('agent_id', $agent->id)->where('container_id', $container->id)->where('group', 'items')
        ->value('level'))->toBe('create')
        ->and(AgentContainerGrant::query()
            ->where('agent_id', $agent->id)->where('container_id', $container->id)->where('group', 'costs')
            ->value('level'))->toBe('delete');
});

it('byter namn med PATCH och kräver ett namn', function () {
    [$agent, $anvandare] = sidaAgent();
    actingAs($anvandare);

    patch("/settings/agents/{$agent->ulid}", ['name' => 'Min Claude'])->assertRedirect();
    expect($agent->fresh()->name)->toBe('Min Claude');

    patch("/settings/agents/{$agent->ulid}", ['name' => ''])->assertSessionHasErrors('name');
    expect($agent->fresh()->name)->toBe('Min Claude');
});

it('pausar och återupptar, loggar båda och avvisar MCP-anropet under pausen', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    actingAs($anvandare);

    put("/settings/agents/{$agent->ulid}/pause", ['paused' => true])->assertRedirect();

    expect($agent->fresh()->isPaused())->toBeTrue()
        ->and(SecurityLog::query()->where('action', SecurityLog::ACTION_AGENT_PAUSED)->count())->toBe(1);

    // Samma tillstånd igen är ingen händelse.
    put("/settings/agents/{$agent->ulid}/pause", ['paused' => true])->assertRedirect();
    expect(SecurityLog::query()->where('action', SecurityLog::ACTION_AGENT_PAUSED)->count())->toBe(1);

    auth()->forgetGuards();
    expect(mcpFel(mcpVerktyg($token, 'containers_list'))['code'])->toBe('agent.paused');

    // MCP-anropet lämnade `mcp` som standardguard; en ny begäran börjar om.
    auth()->shouldUse('web');
    actingAs($anvandare);
    put("/settings/agents/{$agent->ulid}/pause", ['paused' => false])->assertRedirect();

    $rad = SecurityLog::query()->where('action', SecurityLog::ACTION_AGENT_RESUMED)->sole();

    expect($agent->fresh()->isPaused())->toBeFalse()
        ->and($rad->meta['agent_ulid'])->toBe($agent->ulid)
        ->and($rad->user_id)->toBe($anvandare->id);
});

it('kopplar bort agenten, raderar rättigheterna, återkallar tokens och loggar', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    mcpEgenContainer($agent);
    actingAs($anvandare);

    delete("/settings/agents/{$agent->ulid}")->assertRedirect('/settings/agents');

    expect(Agent::query()->whereKey($agent->id)->exists())->toBeFalse()
        ->and(AgentAccountGrant::query()->where('agent_id', $agent->id)->exists())->toBeFalse()
        ->and(AgentContainerGrant::query()->where('agent_id', $agent->id)->exists())->toBeFalse()
        ->and(SecurityLog::query()->where('action', SecurityLog::ACTION_AGENT_DISCONNECTED)->count())->toBe(1);

    auth()->forgetGuards();
    mcpAnropa($token, 'tools/call', ['name' => 'containers_list', 'arguments' => (object) []])
        ->assertUnauthorized();
});

it('visar containrarna med länk och bara händelser med agentens agent_id', function () {
    [$agent, $anvandare] = sidaAgent();
    [$annanAgent] = sidaAgent();
    $container = mcpEgenContainer($agent, 'Bilen');

    foreach ([[$agent, 'item.updated'], [$annanAgent, 'item.created'], [null, 'item.deleted']] as [$vem, $handling]) {
        $rad = new AuditLog;
        $rad->action = $handling;
        $rad->account_id = $agent->account_id;
        $rad->agent_id = $vem?->id;
        $rad->meta = [];
        $rad->save();
    }

    actingAs($anvandare);

    get("/settings/agents/{$agent->ulid}")
        ->assertInertia(fn (AssertableInertia $sida) => $sida
            ->has('containers', 1)
            ->where('containers.0.url', "/containers/{$container->ulid}/edit")
            ->has('events', 1)
            ->where('events.0.action', 'item.updated'));
});

it('visar högst tjugo händelser', function () {
    [$agent, $anvandare] = sidaAgent();

    foreach (range(1, 25) as $i) {
        $rad = new AuditLog;
        $rad->action = 'item.updated';
        $rad->account_id = $agent->account_id;
        $rad->agent_id = $agent->id;
        $rad->meta = [];
        $rad->save();
    }

    actingAs($anvandare);

    get("/settings/agents/{$agent->ulid}")
        ->assertInertia(fn (AssertableInertia $sida) => $sida->has('events', 20));
});
