<?php

use App\Actions\Agent\UpdateAgentAccountGrants;
use App\Models\Account;
use App\Models\Agent;
use App\Models\AgentAccountGrant;
use App\Models\AgentContainerGrant;
use App\Models\Container;
use App\Models\SecurityLog;
use App\Models\User;
use App\Support\Security\DeviceName;
use App\Support\Security\IpGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/*
 * Issue 416 · Agentens rättigheter på kontot i säkerhetsloggen, se
 * [[ADR-0087 MCP-server för agenter]] Riktning 12 och [[ADR-0043 Tre
 * loggar]] § Säkerhetsloggen.
 *
 * Rättigheterna hör till kontot och inte till en container, så ändringen
 * skrivs i säkerhetsloggen — ändringar i en container står i
 * händelseloggen. En rad per anrop, och bara när något faktiskt ändrades:
 * en kapad session ska inte kunna ändra rättigheterna utan att det syns,
 * och en begäran som inte ändrar något ska inte fylla loggen.
 *
 * Hjälparna heter `rattighetslogg*` för att inte krocka med
 * `rattighets*` i AgentRattigheterTest — Pests funktioner är globala och
 * hela sviten körs i en process.
 */

function rattighetsloggAgent(User $anvandare, Account $konto, string $namn = 'Claude'): Agent
{
    $agent = new Agent;
    $agent->user_id = $anvandare->id;
    $agent->account_id = $konto->id;
    $agent->oauth_client_id = (string) Str::uuid();
    $agent->name = $namn;
    $agent->save();

    return $agent->refresh();
}

function rattighetsloggKontoniva(Agent $agent, string $grupp, string $niva): void
{
    $rad = new AgentAccountGrant;
    $rad->agent_id = $agent->id;
    $rad->group = $grupp;
    $rad->level = $niva;
    $rad->save();
}

function rattighetsloggContainerniva(Agent $agent, Container $container, string $grupp, string $niva): void
{
    $rad = new AgentContainerGrant;
    $rad->agent_id = $agent->id;
    $rad->container_id = $container->id;
    $rad->group = $grupp;
    $rad->level = $niva;
    $rad->save();
}

/**
 * Raderna för `agent.grants_changed`, äldst först.
 *
 * @return Collection<int, SecurityLog>
 */
function rattighetsloggRader(): Collection
{
    return SecurityLog::query()
        ->where('action', SecurityLog::ACTION_AGENT_GRANTS_CHANGED)
        ->orderBy('id')
        ->get();
}

it('skriver en rad med kontot, användaren och den nya nivån när en grupp höjs', function () {
    [$konto, $anvandare] = mcpKonto('pro');
    $agent = rattighetsloggAgent($anvandare, $konto);
    rattighetsloggKontoniva($agent, 'items', 'read');

    app(UpdateAgentAccountGrants::class)->handle($agent, $anvandare, ['items' => 'write']);

    $rad = rattighetsloggRader()->sole();

    expect($rad->account_id)->toBe($konto->id)
        ->and($rad->user_id)->toBe($anvandare->id)
        ->and($rad->meta)->toBe([
            'agent_ulid' => $agent->ulid,
            'changed' => ['items' => ['read', 'write']],
        ]);
});

it('räknar containerraderna som sänktes och skriver en borttagen grupp som null', function () {
    [$konto, $anvandare] = mcpKonto('pro');
    $agent = rattighetsloggAgent($anvandare, $konto);
    rattighetsloggKontoniva($agent, 'items', 'delete');
    rattighetsloggKontoniva($agent, 'costs', 'write');

    $forsta = Container::factory()->for($konto, 'account')->create();
    $andra = Container::factory()->for($konto, 'account')->create();
    rattighetsloggContainerniva($agent, $forsta, 'items', 'delete');
    rattighetsloggContainerniva($agent, $andra, 'items', 'delete');
    rattighetsloggContainerniva($agent, $andra, 'costs', 'write');

    app(UpdateAgentAccountGrants::class)->handle($agent, $anvandare, ['items' => 'write']);

    $rad = rattighetsloggRader()->sole();

    expect($rad->meta)->toBe([
        'agent_ulid' => $agent->ulid,
        'changed' => ['items' => ['delete', 'write']],
        'containers_lowered' => 2,
    ])
        ->and(AgentContainerGrant::query()
            ->where('agent_id', $agent->id)
            ->where('group', 'items')
            ->pluck('level')->all())
        ->toBe(['write', 'write']);

    // Att ta bort gruppen ger `[gammal, null]`, och containerns rad för
    // gruppen räknas som sänkt.
    app(UpdateAgentAccountGrants::class)->handle($agent, $anvandare, ['costs' => null]);

    $borttagen = rattighetsloggRader()->last();

    expect($borttagen->meta)->toBe([
        'agent_ulid' => $agent->ulid,
        'changed' => ['costs' => ['write', null]],
        'containers_lowered' => 1,
    ])
        ->and(AgentContainerGrant::query()
            ->where('agent_id', $agent->id)
            ->where('group', 'costs')
            ->exists())
        ->toBeFalse();
});

it('skriver create_containers som gammalt och nytt bara när den ändras', function () {
    [$konto, $anvandare] = mcpKonto('pro');
    $agent = rattighetsloggAgent($anvandare, $konto);

    app(UpdateAgentAccountGrants::class)->handle($agent, $anvandare, [], true);

    expect(rattighetsloggRader()->sole()->meta)->toBe([
        'agent_ulid' => $agent->ulid,
        'create_containers' => [false, true],
    ]);

    // Samma värde igen är ingen ändring.
    app(UpdateAgentAccountGrants::class)->handle($agent, $anvandare, [], true);

    expect(rattighetsloggRader()->count())->toBe(1);
});

it('skriver ingen rad när anropet inte ändrar något', function () {
    [$konto, $anvandare] = mcpKonto('pro');
    $agent = rattighetsloggAgent($anvandare, $konto);
    rattighetsloggKontoniva($agent, 'items', 'read');

    app(UpdateAgentAccountGrants::class)->handle($agent, $anvandare, ['items' => 'read']);
    app(UpdateAgentAccountGrants::class)->handle($agent, $anvandare, []);
    app(UpdateAgentAccountGrants::class)->handle($agent, $anvandare, [], false);

    expect(rattighetsloggRader()->count())->toBe(0);
});

it('skriver ingen rad för en okänd grupp', function () {
    [$konto, $anvandare] = mcpKonto('pro');
    $agent = rattighetsloggAgent($anvandare, $konto);

    expect(fn () => app(UpdateAgentAccountGrants::class)->handle($agent, $anvandare, ['okand' => 'read']))
        ->toThrow(InvalidArgumentException::class);

    expect(rattighetsloggRader()->count())->toBe(0);
});

it('skriver en rad för flera grupper i samma anrop', function () {
    [$konto, $anvandare] = mcpKonto('pro');
    $agent = rattighetsloggAgent($anvandare, $konto);
    rattighetsloggKontoniva($agent, 'items', 'read');

    app(UpdateAgentAccountGrants::class)->handle($agent, $anvandare, ['items' => 'write', 'costs' => 'read']);

    $rader = rattighetsloggRader();

    expect($rader)->toHaveCount(1)
        ->and($rader->sole()->meta)->toBe([
            'agent_ulid' => $agent->ulid,
            'changed' => [
                'items' => ['read', 'write'],
                'costs' => [null, 'read'],
            ],
        ]);
});

it('skriver ip_group och device_name och bara de fyra nycklarna i meta', function () {
    [$konto, $anvandare] = mcpKonto('pro');
    $agent = rattighetsloggAgent($anvandare, $konto);
    rattighetsloggKontoniva($agent, 'items', 'delete');
    rattighetsloggKontoniva($agent, 'costs', 'read');
    $container = Container::factory()->for($konto, 'account')->create();
    rattighetsloggContainerniva($agent, $container, 'items', 'delete');

    $ip = '203.0.113.10';
    $userAgent = 'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0';

    app(UpdateAgentAccountGrants::class)->handle(
        $agent,
        $anvandare,
        ['items' => 'write', 'costs' => null],
        true,
        $ip,
        $userAgent,
    );

    $rad = rattighetsloggRader()->sole();

    expect($rad->ip_group)->toBe(IpGroup::from($ip))
        ->and($rad->device_name)->toBe(DeviceName::from($userAgent))
        ->and(array_keys($rad->meta))->toEqualCanonicalizing([
            'agent_ulid',
            'changed',
            'create_containers',
            'containers_lowered',
        ])
        ->and(array_keys($rad->meta))->toHaveCount(4);
});
