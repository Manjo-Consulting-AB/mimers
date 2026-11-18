<?php

use App\Actions\Access\ResolveItemScope;
use App\Actions\Access\UpdateContainerAccess;
use App\Actions\Agent\AgentCallerRefusedException;
use App\Actions\Agent\CreateContainerForAgent;
use App\Actions\Agent\DisableAgentInContainer;
use App\Actions\Agent\EnableAgentInContainer;
use App\Actions\Agent\ResolveAgentAccess;
use App\Actions\Agent\SetMemberAgentLevel;
use App\Actions\Agent\UpdateAgentAccountGrants;
use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Container\CreateContainer;
use App\Actions\Invitation\AcceptInvitation;
use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\Agent;
use App\Models\AgentAccountGrant;
use App\Models\AgentContainerGrant;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use App\Support\Agent\AgentGroup;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/*
 * Issue 401 · Rättighetsmodellen för agenter, se [[ADR-0087 MCP-server för
 * agenter]] Riktning 3, 6 och 7. Frågan är "vad får agent X göra i container
 * Y?", och svaret är den lägsta av fyra lager.
 */

/**
 * En agent för användaren, utan rättigheter — som en ny anslutning.
 */
function rattighetsAgent(User $anvandare, Account $konto): Agent
{
    $agent = new Agent;
    $agent->user_id = $anvandare->id;
    $agent->account_id = $konto->id;
    $agent->oauth_client_id = (string) Str::uuid();
    $agent->name = 'Claude';
    $agent->save();

    return $agent->refresh();
}

function sattKontonivaer(Agent $agent, array $nivaer): void
{
    foreach ($nivaer as $grupp => $nivå) {
        $rad = new AgentAccountGrant;
        $rad->agent_id = $agent->id;
        $rad->group = $grupp;
        $rad->level = $nivå;
        $rad->save();
    }
}

function sattContainernivaer(Agent $agent, Container $container, array $nivaer): void
{
    foreach ($nivaer as $grupp => $nivå) {
        $rad = new AgentContainerGrant;
        $rad->agent_id = $agent->id;
        $rad->container_id = $container->id;
        $rad->group = $grupp;
        $rad->level = $nivå;
        $rad->save();
    }
}

/**
 * Agentens nivå i `items` för en container, med en färsk upplösare.
 */
function agentNiva(Agent $agent, Container $container, AgentGroup $grupp = AgentGroup::Items): ?string
{
    return (new ResolveAgentAccess(new ResolveItemScope))->handle($agent->refresh(), $grupp, $container);
}

/**
 * Användarens egen container på Pro, med en agent som har `delete` överallt
 * (konto och container) om inget annat sägs.
 *
 * @return array{0: Account, 1: User, 2: Agent, 3: Container}
 */
function egenContainerMedAgent(string $nivå = 'delete'): array
{
    [$konto, $anvandare] = mcpKonto('pro');
    $container = Container::factory()->for($konto, 'account')->create();
    $agent = rattighetsAgent($anvandare, $konto);
    sattKontonivaer($agent, array_fill_keys(AgentGroup::values(), $nivå));
    sattContainernivaer($agent, $container, array_fill_keys(AgentGroup::values(), $nivå));

    return [$konto, $anvandare, $agent, $container];
}

/**
 * Någon annans container på Pro där användaren är medlem med `$nivå` och
 * ägarens tak för agenter är `$agentNivå`.
 *
 * @return array{0: User, 1: Agent, 2: Container, 3: ContainerAccess}
 */
function annansContainerMedAgent(string $nivå, ?string $agentNivå, string $agentens = 'delete'): array
{
    [$konto, $anvandare] = mcpKonto('pro');
    [$agarkonto] = mcpKonto('pro');
    $container = Container::factory()->for($agarkonto, 'account')->create();
    $rad = beviljaAccess($container, $anvandare, $nivå, 'member');
    $rad->agent_level = $agentNivå;
    $rad->save();

    $agent = rattighetsAgent($anvandare, $konto);
    sattKontonivaer($agent, array_fill_keys(AgentGroup::values(), $agentens));
    sattContainernivaer($agent, $container, array_fill_keys(AgentGroup::values(), $agentens));

    return [$anvandare, $agent, $container, $rad];
}

it('ger en ny agent ingen nivå i någon grupp och ingen rätt att skapa containrar', function () {
    [$konto, $anvandare] = mcpKonto('pro');
    $container = Container::factory()->for($konto, 'account')->create();
    $agent = rattighetsAgent($anvandare, $konto);

    expect($agent->create_containers)->toBeFalse()
        ->and($agent->accountGrants()->count())->toBe(0)
        ->and($agent->containerGrants()->count())->toBe(0);

    foreach (AgentGroup::cases() as $grupp) {
        expect(agentNiva($agent, $container, $grupp))->toBeNull();
    }
});

it('är den lägsta av lagren: kontots nivå kapar', function () {
    [, , $agent, $container] = egenContainerMedAgent('delete');
    AgentAccountGrant::query()->where('agent_id', $agent->id)->where('group', 'items')->update(['level' => 'read']);

    expect(agentNiva($agent, $container))->toBe('read');
});

it('är den lägsta av lagren: containerns nivå kapar', function () {
    [, , $agent, $container] = egenContainerMedAgent('delete');
    AgentContainerGrant::query()->where('agent_id', $agent->id)->where('group', 'items')->update(['level' => 'create']);

    expect(agentNiva($agent, $container))->toBe('create');
});

it('är den lägsta av lagren: ägarens tak kapar', function () {
    [, $agent, $container] = annansContainerMedAgent('delete', 'write');

    expect(agentNiva($agent, $container))->toBe('write');
});

it('är den lägsta av lagren: användarens egen nivå kapar', function () {
    [, $agent, $container] = annansContainerMedAgent('read', 'delete');

    expect(agentNiva($agent, $container))->toBe('read');
});

it('kapar per item med användarens nivå för itemet', function () {
    [$konto, $anvandare] = mcpKonto('pro');
    [$agarkonto] = mcpKonto('pro');
    $container = Container::factory()->for($agarkonto, 'account')->create();
    $item = Item::factory()->create(['container_id' => $container->id]);
    $rad = beviljaAccess($container, $anvandare, 'read', 'member');
    $rad->item_id = $item->id;
    $rad->agent_level = 'delete';
    $rad->save();

    $agent = rattighetsAgent($anvandare, $konto);
    sattKontonivaer($agent, ['items' => 'delete']);
    sattContainernivaer($agent, $container, ['items' => 'delete']);

    expect((new ResolveAgentAccess(new ResolveItemScope))->handle($agent, AgentGroup::Items, $container, $item))->toBe('read');
});

it('låter en item-rad med högt tak inte lyfta en container-bred rad med agent_level NULL', function () {
    [$konto, $anvandare] = mcpKonto('pro');
    [$agarkonto] = mcpKonto('pro');
    $container = Container::factory()->for($agarkonto, 'account')->create();
    $item = Item::factory()->create(['container_id' => $container->id]);
    $annat = Item::factory()->create(['container_id' => $container->id]);

    $bred = beviljaAccess($container, $anvandare, 'delete', 'member');
    $bred->agent_level = null;
    $bred->save();

    $itemRad = beviljaAccess($container, $anvandare, 'delete', 'member');
    $itemRad->item_id = $item->id;
    $itemRad->agent_level = 'delete';
    $itemRad->save();

    $agent = rattighetsAgent($anvandare, $konto);
    sattKontonivaer($agent, ['items' => 'delete']);
    sattContainernivaer($agent, $container, ['items' => 'delete']);

    $resolver = new ResolveAgentAccess(new ResolveItemScope);

    expect($resolver->handle($agent, AgentGroup::Items, $container))->toBeNull()
        ->and($resolver->handle($agent, AgentGroup::Items, $container, $item))->toBeNull()
        ->and($resolver->handle($agent, AgentGroup::Items, $container, $annat))->toBeNull();
});

it('ger ingen åtkomst när itemet hör till en annan container än den agenten har rader i', function () {
    [, , $agent, $container] = egenContainerMedAgent('delete');
    $annan = Container::factory()->for($container->account, 'account')->create();
    $item = Item::factory()->create(['container_id' => $annan->id]);

    expect((new ResolveAgentAccess(new ResolveItemScope))->handle($agent, AgentGroup::Items, $container, $item))->toBeNull();
});

it('ger ingen åtkomst utan rad i agent_container_grant, oavsett kontots nivå', function () {
    [, , $agent, $container] = egenContainerMedAgent('delete');
    AgentContainerGrant::query()->where('agent_id', $agent->id)->delete();

    expect(agentNiva($agent, $container))->toBeNull();
});

it('ger ingen åtkomst utan rad på kontot, även med rad i containern', function () {
    [, , $agent, $container] = egenContainerMedAgent('delete');
    AgentAccountGrant::query()->where('agent_id', $agent->id)->delete();

    expect(agentNiva($agent, $container))->toBeNull();
});

it('ger ingen åtkomst i någon annans container när agent_level är NULL', function () {
    [, $agent, $container] = annansContainerMedAgent('delete', null);

    expect(agentNiva($agent, $container))->toBeNull();
});

it('ger högst read med agent_level read och användarens delete', function () {
    [, $agent, $container] = annansContainerMedAgent('delete', 'read');

    expect(agentNiva($agent, $container))->toBe('read');
});

it('ger ingen åtkomst i en container användaren inte når alls', function () {
    [, $agent, $container, $rad] = annansContainerMedAgent('delete', 'delete');
    $rad->revoked_at = now();
    $rad->save();

    expect(agentNiva($agent, $container))->toBeNull();
});

it('ger ingen åtkomst när agenten är pausad', function () {
    [, , $agent, $container] = egenContainerMedAgent('delete');
    $agent->paused_at = now();
    $agent->save();

    expect(agentNiva($agent, $container))->toBeNull();
});

it('ger ingen åtkomst när agentens användare har Gratis', function () {
    [$konto, $anvandare] = mcpKonto('free');
    $container = Container::factory()->for($konto, 'account')->create();
    $agent = rattighetsAgent($anvandare, $konto);
    sattKontonivaer($agent, ['items' => 'delete']);
    sattContainernivaer($agent, $container, ['items' => 'delete']);

    expect(agentNiva($agent, $container))->toBeNull();
});

it('ger ingen åtkomst när containerns ägare har Gratis', function () {
    [$konto, $anvandare] = mcpKonto('pro');
    [$agarkonto] = mcpKonto('free');
    $container = Container::factory()->for($agarkonto, 'account')->create();
    $rad = beviljaAccess($container, $anvandare, 'delete', 'member');
    $rad->agent_level = 'delete';
    $rad->save();
    $agent = rattighetsAgent($anvandare, $konto);
    sattKontonivaer($agent, ['items' => 'delete']);
    sattContainernivaer($agent, $container, ['items' => 'delete']);

    expect(agentNiva($agent, $container))->toBeNull();
});

it('släpper igenom Pro+', function () {
    [$konto, $anvandare] = mcpKonto('pro_plus');
    $container = Container::factory()->for($konto, 'account')->create();
    $agent = rattighetsAgent($anvandare, $konto);
    sattKontonivaer($agent, ['items' => 'create']);
    sattContainernivaer($agent, $container, ['items' => 'create']);

    expect(agentNiva($agent, $container))->toBe('create');
});

it('ändrar inte containerns rader när kontots nivå höjs', function () {
    [, $anvandare, $agent, $container] = egenContainerMedAgent('read');

    app(UpdateAgentAccountGrants::class)->handle($agent, $anvandare, ['items' => 'delete']);

    expect(AgentAccountGrant::query()->where('agent_id', $agent->id)->where('group', 'items')->value('level'))->toBe('delete')
        ->and(AgentContainerGrant::query()->where('agent_id', $agent->id)->where('group', 'items')->value('level'))->toBe('read')
        ->and(agentNiva($agent, $container))->toBe('read');
});

it('sänker containerns rader som ligger över när kontots nivå sänks', function () {
    [, $anvandare, $agent, $container] = egenContainerMedAgent('delete');
    $annan = Container::factory()->for(Account::query()->findOrFail($agent->account_id), 'account')->create();
    sattContainernivaer($agent, $annan, ['items' => 'read', 'costs' => 'delete']);

    app(UpdateAgentAccountGrants::class)->handle($agent, $anvandare, ['items' => 'write']);

    $nivå = fn (Container $c, string $grupp) => AgentContainerGrant::query()
        ->where('agent_id', $agent->id)->where('container_id', $c->id)->where('group', $grupp)->value('level');

    expect($nivå($container, 'items'))->toBe('write')
        ->and($nivå($annan, 'items'))->toBe('read')
        ->and($nivå($container, 'costs'))->toBe('delete')
        ->and($nivå($annan, 'costs'))->toBe('delete');
});

it('tar bort containerns rader när kontots grupp tas bort, och rör inte andra agenter', function () {
    [$konto, $anvandare, $agent, $container] = egenContainerMedAgent('delete');
    $andra = rattighetsAgent($anvandare, $konto);
    sattKontonivaer($andra, ['items' => 'delete']);
    sattContainernivaer($andra, $container, ['items' => 'delete']);

    app(UpdateAgentAccountGrants::class)->handle($agent, $anvandare, ['items' => null]);

    expect(AgentContainerGrant::query()->where('agent_id', $agent->id)->where('group', 'items')->exists())->toBeFalse()
        ->and(AgentContainerGrant::query()->where('agent_id', $andra->id)->where('group', 'items')->value('level'))->toBe('delete');
});

it('sparar kontots nivåer som de är med EnableAgentInContainer i läget allt', function () {
    [$konto, $anvandare] = mcpKonto('pro');
    $container = Container::factory()->for($konto, 'account')->create();
    $agent = rattighetsAgent($anvandare, $konto);
    app(UpdateAgentAccountGrants::class)->handle($agent, $anvandare, ['items' => 'write', 'costs' => 'read']);

    app(EnableAgentInContainer::class)->handle($agent, $container, $anvandare);

    expect(AgentContainerGrant::query()->where('agent_id', $agent->id)->pluck('level', 'group')->all())
        ->toEqualCanonicalizing(['items' => 'write', 'costs' => 'read']);

    // En senare höjning på kontot syns inte i containern.
    app(UpdateAgentAccountGrants::class)->handle($agent, $anvandare, ['items' => 'delete']);

    expect(agentNiva($agent, $container))->toBe('write');
});

it('kapar valda nivåer till kontots med EnableAgentInContainer', function () {
    [$konto, $anvandare] = mcpKonto('pro');
    $container = Container::factory()->for($konto, 'account')->create();
    $agent = rattighetsAgent($anvandare, $konto);
    app(UpdateAgentAccountGrants::class)->handle($agent, $anvandare, ['items' => 'write', 'costs' => 'read']);

    app(EnableAgentInContainer::class)->handle($agent, $container, $anvandare, [
        'items' => 'delete',
        'costs' => 'read',
        'loans' => 'read',
    ]);

    // `loans` saknar kontonivå och får ingen rad; `schedules` valdes inte.
    expect(AgentContainerGrant::query()->where('agent_id', $agent->id)->pluck('level', 'group')->all())
        ->toEqualCanonicalizing(['items' => 'write', 'costs' => 'read']);
});

it('stänger av agenten i containern med DisableAgentInContainer', function () {
    [, $anvandare, $agent, $container] = egenContainerMedAgent('delete');

    app(DisableAgentInContainer::class)->handle($agent, $container, $anvandare);

    expect(agentNiva($agent, $container))->toBeNull()
        ->and($agent->accountGrants()->count())->toBe(count(AgentGroup::cases()));
});

it('för över agent_level från inbjudan till container_access vid accept', function () {
    [$konto] = mcpKonto('pro');
    $container = Container::factory()->for($konto, 'account')->create();
    $invitation = bjudInRad($container, 'ny@example.test');
    $invitation->level = 'delete';
    $invitation->agent_level = 'read';
    $invitation->save();
    $ny = User::factory()->create(['email' => 'ny@example.test']);

    app(AcceptInvitation::class)->handle($invitation, $ny);

    expect(ContainerAccess::query()->where('container_id', $container->id)->where('grantee_id', $ny->id)->sole()->agent_level)->toBe('read');
});

it('låter en inbjudan utan agent_level ge NULL', function () {
    [$konto] = mcpKonto('pro');
    $container = Container::factory()->for($konto, 'account')->create();
    $invitation = bjudInRad($container, 'ny@example.test');
    $ny = User::factory()->create(['email' => 'ny@example.test']);

    app(AcceptInvitation::class)->handle($invitation, $ny);

    expect(ContainerAccess::query()->where('container_id', $container->id)->where('grantee_id', $ny->id)->sole()->agent_level)->toBeNull();
});

it('sänker agent_level när ägaren sänker level under det', function () {
    [, , $container, $rad] = annansContainerMedAgent('delete', 'write');
    [$agarkonto, $agare] = [$container->account, $container->account->users()->first() ?? User::factory()->create()];

    app(UpdateContainerAccess::class)->handle($container, $rad, ['level' => 'create'], $agare);

    expect($rad->refresh()->level)->toBe('create')
        ->and($rad->agent_level)->toBe('create');
});

it('låter agent_level vara kvar när level sänks men inte under det', function () {
    [, , $container, $rad] = annansContainerMedAgent('delete', 'read');
    $agare = User::factory()->create();

    app(UpdateContainerAccess::class)->handle($container, $rad, ['level' => 'write'], $agare);

    expect($rad->refresh()->agent_level)->toBe('read');
});

it('sätter taket för en befintlig medlem med SetMemberAgentLevel men aldrig över level', function () {
    [, , $container, $rad] = annansContainerMedAgent('write', null);
    $agare = User::factory()->create();

    app(SetMemberAgentLevel::class)->handle($container, $rad, 'create', $agare);

    expect($rad->refresh()->agent_level)->toBe('create');

    expect(fn () => app(SetMemberAgentLevel::class)->handle($container, $rad, 'delete', $agare))
        ->toThrow(ApiException::class);
    expect($rad->refresh()->agent_level)->toBe('create');

    app(SetMemberAgentLevel::class)->handle($container, $rad, null, $agare);
    expect($rad->refresh()->agent_level)->toBeNull();
});

it('ger en container som agenten skapar kontots nivåer', function () {
    [$konto, $anvandare] = mcpKonto('pro');
    $agent = rattighetsAgent($anvandare, $konto);
    app(UpdateAgentAccountGrants::class)->handle($agent, $anvandare, ['items' => 'write', 'files' => 'read'], true);

    $container = app(CreateContainerForAgent::class)->handle($agent->refresh(), 'Verkstaden');

    expect($container->account_id)->toBe($konto->id)
        ->and(AgentContainerGrant::query()->where('agent_id', $agent->id)->where('container_id', $container->id)->pluck('level', 'group')->all())
        ->toEqualCanonicalizing(['items' => 'write', 'files' => 'read'])
        ->and(AuditLog::query()->where('action', AuditLog::ACTION_CONTAINER_CREATED)->where('container_id', $container->id)->sole()->agent_id)
        ->toBe($agent->id);
});

it('nekar en agent att skapa en container utan create_containers', function () {
    [$konto, $anvandare] = mcpKonto('pro');
    $agent = rattighetsAgent($anvandare, $konto);

    expect(fn () => app(CreateContainerForAgent::class)->handle($agent, 'Verkstaden'))
        ->toThrow(ApiException::class);
    expect(Container::query()->where('account_id', $konto->id)->count())->toBe(0);
});

it('ger en container som användaren skapar inga rader', function () {
    [$konto, $anvandare] = mcpKonto('pro');
    $agent = rattighetsAgent($anvandare, $konto);
    app(UpdateAgentAccountGrants::class)->handle($agent, $anvandare, ['items' => 'delete']);

    $container = app(CreateContainer::class)->handle($anvandare, $konto, 'Egen', null);

    expect(AgentContainerGrant::query()->where('container_id', $container->id)->count())->toBe(0)
        ->and(agentNiva($agent, $container))->toBeNull();
});

it('skriver agent_id i RecordAuditEvent med en agent', function () {
    [$konto, $anvandare] = mcpKonto('pro');
    $agent = rattighetsAgent($anvandare, $konto);

    $med = app(RecordAuditEvent::class)->handle(action: 'test.agent', account: $konto, user: $anvandare, agent: $agent);
    $utan = app(RecordAuditEvent::class)->handle(action: 'test.human', account: $konto, user: $anvandare);

    expect($med->refresh()->agent_id)->toBe($agent->id)
        ->and($utan->refresh()->agent_id)->toBeNull();
});

it('nekar actionerna när anroparen är en agent eller inte agentens användare', function () {
    [, $anvandare, $agent, $container] = egenContainerMedAgent('delete');
    $annan = User::factory()->create();

    expect(fn () => app(UpdateAgentAccountGrants::class)->handle($agent, $annan, ['items' => 'read']))
        ->toThrow(AgentCallerRefusedException::class);

    Auth::shouldUse('mcp');

    expect(fn () => app(UpdateAgentAccountGrants::class)->handle($agent, $anvandare, ['items' => 'read']))
        ->toThrow(AgentCallerRefusedException::class)
        ->and(fn () => app(EnableAgentInContainer::class)->handle($agent, $container, $anvandare))
        ->toThrow(AgentCallerRefusedException::class)
        ->and(fn () => app(DisableAgentInContainer::class)->handle($agent, $container, $anvandare))
        ->toThrow(AgentCallerRefusedException::class);

    $rad = ContainerAccess::factory()->create([
        'container_id' => $container->id, 'grantee_type' => 'user', 'grantee_id' => $annan->id,
        'level' => 'read', 'kind' => 'member', 'granted_by_user_id' => $anvandare->id,
    ]);

    expect(fn () => app(SetMemberAgentLevel::class)->handle($container, $rad, 'read', $anvandare))
        ->toThrow(AgentCallerRefusedException::class);
});
