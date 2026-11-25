<?php

use App\Actions\Agent\ApplyAgentContainerGrants;
use App\Actions\Agent\ResolveAgentAccess;
use App\Actions\Inbox\ResolveInbox;
use App\Models\Agent;
use App\Models\AgentAccountGrant;
use App\Models\AgentContainerGrant;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\User;
use App\Support\Agent\AgentGroup;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\put;
use function Pest\Laravel\withoutVite;

/*
 * Issue 1685 · agentens rader i användarens inbox, se [[ADR-0054 Inboxen]]
 * och App\Http\Controllers\Settings\AgentController::inbox().
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
    withoutVite();
});

/** @return array{0: Agent, 1: User} */
function inboxAgent(array $kontonivaer = [], string $plan = 'pro'): array
{
    [, $anvandare] = mcpKonto($plan);
    $agent = mcpAnslut($anvandare)['agent'];

    foreach ($kontonivaer as $grupp => $niva) {
        $rad = new AgentAccountGrant;
        $rad->agent_id = $agent->id;
        $rad->group = $grupp;
        $rad->level = $niva;
        $rad->save();
    }

    return [$agent, $anvandare];
}

function inboxContainer(User $anvandare): Container
{
    return app(ResolveInbox::class)->handle($anvandare)->container;
}

function inboxRader(Agent $agent, Container $container): array
{
    $rader = AgentContainerGrant::query()
        ->where('agent_id', $agent->id)
        ->where('container_id', $container->id)
        ->pluck('level', 'group')
        ->all();
    ksort($rader);

    return $rader;
}

it('visar avsnittet Inbox med båda väljarna avslagna för en ny agent', function () {
    [$agent, $anvandare] = inboxAgent(['schedules' => 'delete']);
    actingAs($anvandare);

    get("/settings/agents/{$agent->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $sida) => $sida
            ->where('inbox.levels', ['schedules' => null, 'files' => null])
            ->where('inbox.limits', ['schedules' => 'delete', 'files' => null]));

    expect(Container::query()->whereNotNull('inbox_user_id')->exists())->toBeFalse();
});

it('skapar inboxen och ger en rad i schedules, och raderar den med båda null', function () {
    [$agent, $anvandare] = inboxAgent(['schedules' => 'delete', 'items' => 'delete']);
    actingAs($anvandare);

    put("/settings/agents/{$agent->ulid}/inbox", ['levels' => ['schedules' => 'write', 'files' => null]])->assertRedirect();

    $inbox = Container::query()->where('inbox_user_id', $anvandare->id)->firstOrFail();

    expect(inboxRader($agent, $inbox))->toBe(['schedules' => 'write']);

    $item = app(ResolveInbox::class)->handle($anvandare);

    expect(app(ResolveAgentAccess::class)->handle($agent->fresh(), AgentGroup::Schedules, $inbox))->toBe('write')
        ->and(app(ResolveAgentAccess::class)->handle($agent->fresh(), AgentGroup::Items, $inbox))->toBeNull()
        ->and($item->container_id)->toBe($inbox->id);

    put("/settings/agents/{$agent->ulid}/inbox", ['levels' => ['schedules' => null, 'files' => null]])->assertRedirect();

    expect(inboxRader($agent, $inbox))->toBe([])
        ->and(AuditLog::query()->where('action', AuditLog::ACTION_AGENT_DISABLED_IN_CONTAINER)->exists())->toBeTrue();
});

it('kapar en nivå över kontonivån och ger ingen rad för en grupp utan kontonivå', function () {
    [$agent, $anvandare] = inboxAgent(['schedules' => 'read']);
    actingAs($anvandare);

    put("/settings/agents/{$agent->ulid}/inbox", ['levels' => ['schedules' => 'delete', 'files' => 'write']])->assertRedirect();

    expect(inboxRader($agent, inboxContainer($anvandare)))->toBe(['schedules' => 'read']);
});

it('ger 422 för en annan grupp än schedules och files och skriver ingen rad', function () {
    [$agent, $anvandare] = inboxAgent(['schedules' => 'delete', 'items' => 'delete']);
    actingAs($anvandare);

    put("/settings/agents/{$agent->ulid}/inbox", ['levels' => ['schedules' => 'write', 'items' => 'read']])->assertStatus(422);
    put("/settings/agents/{$agent->ulid}/inbox", ['levels' => ['nonsens' => 'read']])->assertStatus(422);

    expect(AgentContainerGrant::query()->where('agent_id', $agent->id)->exists())->toBeFalse();
});

it('skriver bara schedules och files i läget allt upp till kontots nivå', function () {
    $alla = array_fill_keys(AgentGroup::values(), 'delete');
    [$agent, $anvandare] = inboxAgent($alla);
    $inbox = inboxContainer($anvandare);

    $sparat = app(ApplyAgentContainerGrants::class)->handle($agent, $inbox, null);

    expect($sparat)->toBe(['schedules' => 'delete', 'files' => 'delete'])
        ->and(inboxRader($agent, $inbox))->toBe(['files' => 'delete', 'schedules' => 'delete']);

    // Även med uttryckliga nivåer för andra grupper.
    app(ApplyAgentContainerGrants::class)->handle($agent, $inbox, ['items' => 'read', 'costs' => 'read', 'files' => 'read']);

    expect(inboxRader($agent, $inbox))->toBe(['files' => 'read']);
});

it('svarar 404 på containerns agentsida och dess skrivrutter för en inbox, även för ägaren', function () {
    [$agent, $anvandare] = inboxAgent(['schedules' => 'delete']);
    $inbox = inboxContainer($anvandare);
    actingAs($anvandare);

    get("/containers/{$inbox->ulid}/agents")->assertNotFound();
    put("/containers/{$inbox->ulid}/agents/{$agent->ulid}", ['levels' => ['schedules' => 'read']])->assertNotFound();
    delete("/containers/{$inbox->ulid}/agents/{$agent->ulid}")->assertNotFound();

    expect(AgentContainerGrant::query()->exists())->toBeFalse();
});

it('ger 404 för någon annans agent', function () {
    [$agent] = inboxAgent(['schedules' => 'delete']);
    [, $annan] = mcpKonto('pro');
    actingAs($annan);

    put("/settings/agents/{$agent->ulid}/inbox", ['levels' => ['schedules' => 'read']])->assertNotFound();

    expect(AgentContainerGrant::query()->exists())->toBeFalse();
});

it('tar inte med inboxen i containerlistan eller containerCount', function () {
    [$agent, $anvandare] = inboxAgent(['schedules' => 'delete']);
    actingAs($anvandare);

    put("/settings/agents/{$agent->ulid}/inbox", ['levels' => ['schedules' => 'write']])->assertRedirect();

    get("/settings/agents/{$agent->ulid}")
        ->assertInertia(fn (AssertableInertia $sida) => $sida
            ->where('containers', [])
            ->where('inbox.levels.schedules', 'write'));

    get('/settings/agents')
        ->assertInertia(fn (AssertableInertia $sida) => $sida->where('agents.0.containerCount', 0));
});

it('ger 404 när MCP är av eller planen är Free', function () {
    [$agent, $anvandare] = inboxAgent(['schedules' => 'delete']);
    actingAs($anvandare);

    config(['mcp.enabled' => false]);
    put("/settings/agents/{$agent->ulid}/inbox", ['levels' => ['schedules' => 'read']])->assertNotFound();

    [, $gratis] = mcpKonto('free');
    $fri = new Agent;
    $fri->user_id = $gratis->id;
    $fri->account_id = $gratis->accounts()->firstOrFail()->id;
    $fri->oauth_client_id = (string) Str::uuid();
    $fri->name = 'Fri';
    $fri->save();

    config(['mcp.enabled' => true]);
    actingAs($gratis);
    put("/settings/agents/{$fri->ulid}/inbox", ['levels' => ['schedules' => 'read']])->assertNotFound();
});
