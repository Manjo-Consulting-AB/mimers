<?php

use App\Actions\Agent\SetContainerAgentRules;
use App\Actions\Agent\SetUserAgentRules;
use App\Models\AgentRulesRevision;
use App\Models\Container;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\put;
use function Pest\Laravel\withoutVite;

// rott-pa-basen: issue 1732 (M95), ny yta — ingen befintlig test rörs.

/*
 * Issue 1732 · M95 Agentregler, ADR-0100. Sidorna där en människa skriver
 * reglerna och ser revisionerna.
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
    withoutVite();
});

/** @return array{0: User, 1: Container, 2: User} ägare (Pro), containern, mottagare (Pro) */
function reglerContainer(string $niva = 'read'): array
{
    [$konto, $ägare] = mcpKonto('pro');
    [, $mottagare] = mcpKonto('pro');
    $container = Container::factory()->for($konto, 'account')->create();
    beviljaAccess($container, $mottagare, $niva, 'member');

    return [$ägare, $container, $mottagare];
}

it('PUT /settings/agents/rules sparar användarens regler och en revision', function () {
    [, $användare] = mcpKonto('pro');
    actingAs($användare);

    put('/settings/agents/rules', ['rules' => 'Fråga först.'])->assertRedirect();

    expect($användare->fresh()->agent_rules)->toBe('Fråga först.')
        ->and(AgentRulesRevision::query()->where('user_id', $användare->id)->pluck('rules')->all())->toBe(['Fråga först.']);

    get('/settings/agents')->assertInertia(fn (AssertableInertia $sida) => $sida
        ->component('Settings/Agents')
        ->where('rules.text', 'Fråga först.')
        ->where('rules.revisions.0.rules', 'Fråga först.')
        ->where('rules.revisions.0.author', $användare->name));
});

it('PUT /settings/agents/rules ger 403 utan Pro eller Pro+', function () {
    [, $användare] = mcpKonto('free');
    actingAs($användare);

    put('/settings/agents/rules', ['rules' => 'Något.'])->assertForbidden();

    expect($användare->fresh()->agent_rules)->toBeNull()
        ->and(AgentRulesRevision::query()->count())->toBe(0);
});

it('PUT /containers/{container}/agent-rules sparar för ägarkontots medlem', function () {
    [$ägare, $container] = reglerContainer();
    actingAs($ägare);

    put("/containers/{$container->ulid}/agent-rules", ['rules' => 'Radera aldrig.'])->assertRedirect();

    expect($container->fresh()->agent_rules)->toBe('Radera aldrig.')
        ->and(AgentRulesRevision::query()->where('container_id', $container->id)->count())->toBe(1);
});

it('PUT /containers/{container}/agent-rules ger 403 för en mottagare på varje nivå', function () {
    foreach (['read', 'create', 'write', 'delete'] as $niva) {
        [, $container, $mottagare] = reglerContainer($niva);
        actingAs($mottagare);

        put("/containers/{$container->ulid}/agent-rules", ['rules' => 'Hej.'])->assertForbidden();
        // Även en text över gränsen får 403 och inte ett fältfel.
        put("/containers/{$container->ulid}/agent-rules", ['rules' => str_repeat('a', 2001)])->assertForbidden();

        expect($container->fresh()->agent_rules)->toBeNull();
    }

    expect(AgentRulesRevision::query()->count())->toBe(0);
});

it('PUT /containers/{container}/agent-rules ger 403 för inboxen', function () {
    [$konto, $ägare] = mcpKonto('pro');
    $inbox = Container::factory()->for($konto, 'account')->create(['inbox_user_id' => $ägare->id]);
    actingAs($ägare);

    put("/containers/{$inbox->ulid}/agent-rules", ['rules' => 'Hej.'])->assertForbidden();

    expect($inbox->fresh()->agent_rules)->toBeNull();
});

it('ger mottagaren reglerna som skrivskyddad text utan revisioner, och ägaren textarean med revisioner', function () {
    [$ägare, $container, $mottagare] = reglerContainer();
    app(SetContainerAgentRules::class)->handle($container, 'Version ett.', $ägare);
    app(SetContainerAgentRules::class)->handle($container->fresh(), 'Version två.', $ägare);

    actingAs($mottagare);
    get("/containers/{$container->ulid}/agents")->assertOk()->assertInertia(fn (AssertableInertia $sida) => $sida
        ->component('Containers/Agents')
        ->where('rules.text', 'Version två.')
        ->where('rules.can_edit', false)
        ->where('rules.revisions', []));

    actingAs($ägare);
    get("/containers/{$container->ulid}/agents")->assertInertia(fn (AssertableInertia $sida) => $sida
        ->where('rules.can_edit', true)
        ->where('rules.text', 'Version två.')
        ->has('rules.revisions', 2)
        ->where('rules.revisions.0.rules', 'Version två.')
        ->where('rules.revisions.1.rules', 'Version ett.'));
});

it('ritar ingen ruta åt en mottagare när containern saknar regler', function () {
    [$ägare, $container, $mottagare] = reglerContainer();

    actingAs($mottagare);
    get("/containers/{$container->ulid}/agents")->assertInertia(fn (AssertableInertia $sida) => $sida->where('rules', null));

    actingAs($ägare);
    get("/containers/{$container->ulid}/agents")->assertInertia(fn (AssertableInertia $sida) => $sida
        ->where('rules.can_edit', true)
        ->where('rules.text', null));
});

it('visar författarens namn och null när författaren är borttagen', function () {
    [, $användare] = mcpKonto('pro');
    [, $annan] = mcpKonto('pro');
    app(SetUserAgentRules::class)->handle($användare, 'Från annan.', $annan);
    app(SetUserAgentRules::class)->handle($användare, 'Från mig.', $användare);
    // En borttagen användare: FK:n sätter `author_user_id` till NULL.
    AgentRulesRevision::query()->where('author_user_id', $annan->id)->update(['author_user_id' => null]);
    actingAs($användare);

    get('/settings/agents')->assertInertia(fn (AssertableInertia $sida) => $sida
        ->where('rules.revisions.0.author', $användare->name)
        ->where('rules.revisions.1.author', null));

    expect(file_get_contents(resource_path('js/components/AgentRulesEditor.vue')))
        ->toContain("revision.author ?? t('agents.rules.deleted_user')");
    expect(__('ui.agents.rules.deleted_user'))->toBe('Deleted user');
});

it('Use this version fyller textarean utan att spara', function () {
    $källa = file_get_contents(resource_path('js/components/AgentRulesEditor.vue'));

    expect($källa)->toContain("form.rules = revision.rules ?? ''")
        ->and($källa)->toContain('@click="useVersion(revision)"')
        ->and(preg_match('/function useVersion\(revision\) \{[^}]*\}/', $källa, $träff))->toBe(1)
        ->and($träff[0])->not->toContain('form.put')
        ->and(__('ui.agents.rules.use_version'))->toBe('Use this version');
});

it('2 001 tecken ger ett fältfel på rules på båda sidorna', function () {
    [$ägare, $container] = reglerContainer();
    actingAs($ägare);

    put("/containers/{$container->ulid}/agent-rules", ['rules' => str_repeat('a', 2001)])->assertSessionHasErrors('rules');
    put('/settings/agents/rules', ['rules' => str_repeat('a', 2001)])->assertSessionHasErrors('rules');
    put('/settings/agents/rules', ['rules' => str_repeat('a', 2000)])->assertSessionDoesntHaveErrors();

    expect($container->fresh()->agent_rules)->toBeNull();
});

it('visar container.agent_rules_updated i containerns historik', function () {
    [$ägare, $container] = reglerContainer();
    actingAs($ägare);
    put("/containers/{$container->ulid}/agent-rules", ['rules' => 'Regel.']);

    get("/containers/{$container->ulid}/history")->assertOk()->assertInertia(fn (AssertableInertia $sida) => $sida
        ->where('days.0.rows.0.action', 'container.agent_rules_updated')
        ->where('days.0.rows.0.user', $ägare->name));

    expect(__('ui.audit.action.container.agent_rules_updated', ['user' => 'X']))->toContain('changed the agent rules');
});
