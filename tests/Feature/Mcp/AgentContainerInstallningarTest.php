<?php

use App\Actions\Agent\ResolveAgentAccess;
use App\Actions\Invitation\AcceptInvitation;
use App\Models\Agent;
use App\Models\AgentAccountGrant;
use App\Models\AgentContainerGrant;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Agent\AgentGroup;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;
use function Pest\Laravel\put;
use function Pest\Laravel\withoutVite;

/*
 * Issue 408 · Agenter i containerns inställningar och ägarens tak i
 * inbjudan, se [[ADR-0087 MCP-server för agenter]] Riktning 4 och 6.
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    withoutVite();
});

function instAgent(User $anvandare, array $kontonivaer = []): Agent
{
    $konto = $anvandare->accounts()->where('account.type', 'personal')->firstOrFail();

    $agent = new Agent;
    $agent->user_id = $anvandare->id;
    $agent->account_id = $konto->id;
    $agent->oauth_client_id = (string) Str::uuid();
    $agent->name = 'Claude';
    $agent->save();

    foreach ($kontonivaer as $grupp => $nivå) {
        $rad = new AgentAccountGrant;
        $rad->agent_id = $agent->id;
        $rad->group = $grupp;
        $rad->level = $nivå;
        $rad->save();
    }

    return $agent->refresh();
}

function instNiva(Agent $agent, Container $container, AgentGroup $grupp = AgentGroup::Items): ?string
{
    return app(ResolveAgentAccess::class)->handle($agent->refresh(), $grupp, $container);
}

function instContainerRader(Agent $agent, Container $container): array
{
    $rader = AgentContainerGrant::query()
        ->where('agent_id', $agent->id)
        ->where('container_id', $container->id)
        ->pluck('level', 'group')
        ->all();
    ksort($rader);

    return $rader;
}

/** @return array{0: User, 1: Agent, 2: Container} */
function instEgen(string $plan = 'pro', array $nivaer = ['items' => 'write', 'costs' => 'read']): array
{
    [$konto, $anvandare] = mcpKonto($plan);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$anvandare, instAgent($anvandare, $nivaer), $container];
}

/** @return array{0: User, 1: Agent, 2: Container, 3: User, 4: ContainerAccess} */
function instAnnans(?string $tak, string $agarplan = 'pro', string $niva = 'delete'): array
{
    [, $anvandare] = mcpKonto('pro');
    [$agarkonto, $agare] = mcpKonto($agarplan);
    $container = Container::factory()->for($agarkonto, 'account')->create();
    $rad = beviljaAccess($container, $anvandare, $niva, 'member');
    $rad->agent_level = $tak;
    $rad->save();

    return [$anvandare, instAgent($anvandare, ['items' => 'delete', 'costs' => 'delete']), $container, $agare, $rad];
}

it('har en agent avslagen i varje container tills användaren slår på den', function () {
    [$anvandare, $agent, $container] = instEgen();
    actingAs($anvandare);

    get("/containers/{$container->ulid}/agents")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $sida) => $sida
            ->component('Containers/Agents')
            ->where('section.agents.0.ulid', $agent->ulid)
            ->where('section.agents.0.enabled', false)
            ->where('section.agents.0.all', false));

    expect(instContainerRader($agent, $container))->toBe([])
        ->and(instNiva($agent, $container))->toBeNull();
});

it('sparar kontots nivåer som de är med switchen för allt, och följer inte en höjning', function () {
    [$anvandare, $agent, $container] = instEgen();
    actingAs($anvandare);

    put("/containers/{$container->ulid}/agents/{$agent->ulid}", ['all' => true])->assertRedirect();

    expect(instContainerRader($agent, $container))->toBe(['costs' => 'read', 'items' => 'write'])
        ->and(instNiva($agent, $container))->toBe('write');

    AgentAccountGrant::query()->where('agent_id', $agent->id)->where('group', 'items')->update(['level' => 'delete']);

    expect(instContainerRader($agent, $container))->toBe(['costs' => 'read', 'items' => 'write']);

    get("/containers/{$container->ulid}/agents")
        ->assertInertia(fn (AssertableInertia $sida) => $sida->where('section.agents.0.enabled', true));
});

it('sparar en nivå per grupp direkt och kapar den till kontots nivå', function () {
    [$anvandare, $agent, $container] = instEgen();
    actingAs($anvandare);

    put("/containers/{$container->ulid}/agents/{$agent->ulid}", ['levels' => ['items' => 'read', 'costs' => 'delete', 'loans' => 'read']])
        ->assertRedirect();

    expect(instContainerRader($agent, $container))->toBe(['costs' => 'read', 'items' => 'read'])
        ->and(instNiva($agent, $container, AgentGroup::Costs))->toBe('read');
});

it('slår av agenten med DELETE och tar bort raderna', function () {
    [$anvandare, $agent, $container] = instEgen();
    actingAs($anvandare);

    put("/containers/{$container->ulid}/agents/{$agent->ulid}", ['all' => true]);
    delete("/containers/{$container->ulid}/agents/{$agent->ulid}")->assertRedirect();

    expect(instContainerRader($agent, $container))->toBe([]);
});

it('visar menyns agentrad bara när sidan finns', function () {
    [$anvandare, , $container] = instEgen();
    actingAs($anvandare);

    get("/containers/{$container->ulid}/edit")
        ->assertInertia(fn (AssertableInertia $sida) => $sida->where('containerAgentsAvailable', true));

    config(['mcp.enabled' => false]);

    // Proppen styr raden i ContainerSettingsLayout; klienten räknar aldrig
    // fram tillgången själv (issue 1483).
    get("/containers/{$container->ulid}/edit")
        ->assertInertia(fn (AssertableInertia $sida) => $sida
            ->where('containerAgentsAvailable', false)
            ->missing('agents'));
});

it('svarar 404 på agenternas sida när MCP är avslaget', function () {
    [$anvandare, , $container] = instEgen();
    actingAs($anvandare);

    config(['mcp.enabled' => false]);

    get("/containers/{$container->ulid}/agents")->assertNotFound();
});

it('kapar i någon annans container till ägarens tak', function () {
    [$anvandare, $agent, $container] = instAnnans('read');
    actingAs($anvandare);

    get("/containers/{$container->ulid}/agents")
        ->assertInertia(fn (AssertableInertia $sida) => $sida
            ->where('section.own', false)
            ->where('section.ceiling', 'read')
            ->where('section.agents.0.limits.items', 'read'));

    put("/containers/{$container->ulid}/agents/{$agent->ulid}", ['all' => true])->assertRedirect();
    expect(instContainerRader($agent, $container))->toBe(['costs' => 'read', 'items' => 'read']);

    put("/containers/{$container->ulid}/agents/{$agent->ulid}", ['levels' => ['items' => 'delete']])->assertRedirect();
    expect(instContainerRader($agent, $container))->toBe(['items' => 'read']);
});

it('visar att ägaren inte tillåter agenter när taket är NULL, och sparar inget', function () {
    [$anvandare, $agent, $container] = instAnnans(null);
    actingAs($anvandare);

    get("/containers/{$container->ulid}/agents")
        ->assertInertia(fn (AssertableInertia $sida) => $sida->where('section.ownerAllows', false));

    put("/containers/{$container->ulid}/agents/{$agent->ulid}", ['all' => true])->assertSessionHasErrors('agent');

    expect(instContainerRader($agent, $container))->toBe([]);
});

it('nekar en annans agent med 404', function () {
    [$anvandare, , $container] = instEgen();
    [, $annan] = mcpKonto('pro');
    $annansAgent = instAgent($annan, ['items' => 'read']);
    actingAs($anvandare);

    put("/containers/{$container->ulid}/agents/{$annansAgent->ulid}", ['all' => true])->assertNotFound();
});

it('ger inbjudan agent_level som följer med vid accept, och NULL utan val', function () {
    [$konto, $agare] = mcpKonto('pro');
    $container = Container::factory()->for($konto, 'account')->create();
    actingAs($agare);

    post("/containers/{$container->ulid}/invitations", ['email' => 'a@example.test', 'level' => 'delete', 'agent_level' => 'read'])
        ->assertSessionHasNoErrors();
    post("/containers/{$container->ulid}/invitations", ['email' => 'b@example.test', 'level' => 'delete'])
        ->assertSessionHasNoErrors();

    $a = Invitation::query()->where('email', 'a@example.test')->sole();
    $b = Invitation::query()->where('email', 'b@example.test')->sole();

    expect($a->agent_level)->toBe('read')->and($b->agent_level)->toBeNull();

    $ny = User::factory()->create(['email' => 'a@example.test']);
    app(AcceptInvitation::class)->handle($a, $ny);

    expect($container->accesses()->where('grantee_id', $ny->id)->sole()->agent_level)->toBe('read');
});

it('erbjuder och tar inte emot någon agent_level över inbjudans egen nivå', function () {
    [$konto, $agare] = mcpKonto('pro');
    $container = Container::factory()->for($konto, 'account')->create();
    actingAs($agare);

    post("/containers/{$container->ulid}/invitations", ['email' => 'a@example.test', 'level' => 'read', 'agent_level' => 'write'])
        ->assertSessionHasErrors('agent_level');

    expect(Invitation::query()->count())->toBe(0);

    $form = file_get_contents(base_path('resources/js/components/InvitationForm.vue'));
    expect($form)->toContain('props.levels.slice(0, props.levels.indexOf(form.level) + 1)');
});

it('ändrar agent_level för en befintlig medlem och syns i ResolveAgentAccess direkt', function () {
    [$anvandare, $agent, $container, $agare, $rad] = instAnnans('delete');
    foreach (['items', 'costs'] as $grupp) {
        $g = new AgentContainerGrant;
        $g->agent_id = $agent->id;
        $g->container_id = $container->id;
        $g->group = $grupp;
        $g->level = 'delete';
        $g->save();
    }
    expect(instNiva($agent, $container))->toBe('delete');

    actingAs($agare);
    patch("/containers/{$container->ulid}/accesses/{$rad->ulid}/agent-level", ['agent_level' => 'read'])->assertSessionHasNoErrors();
    expect(instNiva($agent, $container))->toBe('read');

    patch("/containers/{$container->ulid}/accesses/{$rad->ulid}/agent-level", ['agent_level' => null])->assertSessionHasNoErrors();
    expect(instNiva($agent, $container))->toBeNull();
});

it('nekar agent_level över medlemmens egen nivå och en medlem som inte äger', function () {
    [$anvandare, , $container, $agare, $rad] = instAnnans('read', niva: 'read');

    actingAs($agare);
    patch("/containers/{$container->ulid}/accesses/{$rad->ulid}/agent-level", ['agent_level' => 'write'])
        ->assertSessionHasErrors('agent_level');
    expect($rad->refresh()->agent_level)->toBe('read');

    actingAs($anvandare);
    patch("/containers/{$container->ulid}/accesses/{$rad->ulid}/agent-level", ['agent_level' => 'read'])->assertForbidden();
});

it('visar fälten i delningen bara när båda har Pro och MCP är på', function () {
    [$anvandare, , $container, $agare, $rad] = instAnnans('read');
    actingAs($agare);

    get("/containers/{$container->ulid}/sharing")
        ->assertInertia(fn (AssertableInertia $sida) => $sida
            ->where('agentAccess', true)
            ->where("agentLevels.{$rad->ulid}", 'read'));

    config(['mcp.enabled' => false]);
    get("/containers/{$container->ulid}/sharing")
        ->assertInertia(fn (AssertableInertia $sida) => $sida->where('agentAccess', false));
});

it('visar varken avsnitt eller fält när användaren har Gratis', function () {
    [$anvandare, $agent, $container] = instEgen('free');
    actingAs($anvandare);

    get("/containers/{$container->ulid}/agents")->assertNotFound();
    get("/containers/{$container->ulid}/edit")->assertInertia(fn (AssertableInertia $sida) => $sida
        ->missing('agents')
        ->where('containerAgentsAvailable', false));
    put("/containers/{$container->ulid}/agents/{$agent->ulid}", ['all' => true])->assertNotFound();
    get("/containers/{$container->ulid}/sharing")->assertInertia(fn (AssertableInertia $sida) => $sida->where('agentAccess', false));
    expect(instContainerRader($agent, $container))->toBe([]);
});

it('visar varken avsnitt eller fält när ägaren har Gratis, och agent_level förblir NULL', function () {
    [$anvandare, $agent, $container, $agare, $rad] = instAnnans('read', 'free');

    actingAs($anvandare);
    get("/containers/{$container->ulid}/agents")->assertNotFound();
    get("/containers/{$container->ulid}/edit")->assertInertia(fn (AssertableInertia $sida) => $sida
        ->missing('agents')
        ->where('containerAgentsAvailable', false));
    put("/containers/{$container->ulid}/agents/{$agent->ulid}", ['all' => true])->assertNotFound();

    actingAs($agare);
    get("/containers/{$container->ulid}/sharing")->assertInertia(fn (AssertableInertia $sida) => $sida->where('agentAccess', false));
    patch("/containers/{$container->ulid}/accesses/{$rad->ulid}/agent-level", ['agent_level' => 'read'])->assertNotFound();

    // Ett eget gratiskonto utan medlemmar, så delningstaket inte ligger i vägen.
    [$gratisKonto, $gratisAgare] = mcpKonto('free');
    $tom = Container::factory()->for($gratisKonto, 'account')->create();
    actingAs($gratisAgare);
    post("/containers/{$tom->ulid}/invitations", ['email' => 'a@example.test', 'level' => 'delete', 'agent_level' => 'read'])
        ->assertSessionHasNoErrors();
    expect(Invitation::query()->where('email', 'a@example.test')->sole()->agent_level)->toBeNull();
});
