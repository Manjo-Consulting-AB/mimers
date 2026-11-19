<?php

use App\Models\Agent;
use App\Models\AgentAccountGrant;
use App\Models\AgentContainerGrant;
use App\Models\Container;
use App\Models\Item;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Agent\AgentGroup;
use App\Support\Mcp\ContainerGate;
use App\Support\Mcp\Cursor;
use App\Support\Mcp\Hints;
use App\Support\Mcp\McpRateLimiter;
use App\Support\Mcp\ToolError;
use App\Support\Mcp\ToolKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;

use function Pest\Laravel\postJson;
use function Pest\Laravel\travel;

/*
 * Issue 402 · MCP-endpointen `/mcp` och de två första verktygen, se
 * [[ADR-0087 MCP-server för agenter]] Riktning 3, 9, 13, 15, 16 och 18.
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
    RateLimiter::clear('x');
});

/**
 * Ett JSON-RPC-anrop till `/mcp` med en Bearer-token.
 *
 * @param  array<string, mixed>  $params
 */
function mcpAnropa(?string $token, string $metod, array $params = []): TestResponse
{
    auth()->forgetGuards();

    $rubriker = ['Accept' => 'application/json, text/event-stream'];

    if ($token !== null) {
        $rubriker['Authorization'] = 'Bearer '.$token;
    }

    return postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $metod,
        'params' => (object) $params,
    ], $rubriker);
}

/**
 * @param  array<string, mixed>  $argument
 */
function mcpVerktyg(string $token, string $namn, array $argument = []): TestResponse
{
    return mcpAnropa($token, 'tools/call', ['name' => $namn, 'arguments' => (object) $argument]);
}

/**
 * Verktygsfelet ur ett svar: `{"error": {...}}` ur textinnehållet.
 *
 * @return array<string, mixed>
 */
function mcpFel(TestResponse $svar): array
{
    $svar->assertOk()->assertJsonPath('result.isError', true);

    return json_decode($svar->json('result.content.0.text'), true)['error'];
}

/**
 * @return array<string, mixed>
 */
function mcpResultat(TestResponse $svar): array
{
    $svar->assertOk();

    expect($svar->json('result.isError'))->toBeFalse();

    return $svar->json('result.structuredContent');
}

/**
 * En ansluten agent på Pro med rättigheter överallt på kontot.
 *
 * @return array{0: Agent, 1: string, 2: User}
 */
function mcpAgentMedToken(string $plan = 'pro'): array
{
    [, $anvandare] = mcpKonto($plan === 'free' ? 'pro' : $plan);
    $flode = mcpAnslut($anvandare);

    foreach (AgentGroup::values() as $grupp) {
        $rad = new AgentAccountGrant;
        $rad->agent_id = $flode['agent']->id;
        $rad->group = $grupp;
        $rad->level = 'delete';
        $rad->save();
    }

    return [$flode['agent'], $flode['access_token'], $anvandare];
}

function mcpSlaPaI(Agent $agent, Container $container, string $niva = 'delete'): void
{
    foreach (AgentGroup::values() as $grupp) {
        $rad = new AgentContainerGrant;
        $rad->agent_id = $agent->id;
        $rad->container_id = $container->id;
        $rad->group = $grupp;
        $rad->level = $niva;
        $rad->save();
    }
}

function mcpEgenContainer(Agent $agent, ?string $namn = null): Container
{
    $container = Container::factory()->for($agent->account, 'account')
        ->create($namn === null ? [] : ['name' => $namn]);
    mcpSlaPaI($agent, $container);

    return $container;
}

it('svarar 404 på /mcp när MCP_ENABLED är falsk', function () {
    [, $token] = mcpAgentMedToken();
    config(['mcp.enabled' => false]);

    mcpAnropa($token, 'tools/list')->assertNotFound();
});

it('svarar 401 med WWW-Authenticate utan token', function () {
    $svar = mcpAnropa(null, 'tools/list');

    $svar->assertUnauthorized();
    expect($svar->headers->get('WWW-Authenticate'))
        ->toContain('Bearer')->toContain('resource_metadata');
});

it('svarar 401 med en ogiltig token', function () {
    mcpAnropa('inte-en-token', 'tools/list')->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');
});

it('svarar 401 med en utgången token', function () {
    // Passport kontrollerar `exp` mot systemklockan och inte mot Carbon, så
    // tokenen utfärdas redan utgången i stället för att tiden flyttas fram.
    Passport::tokensExpireIn(now()->subMinute());
    [, $token] = mcpAgentMedToken();

    mcpAnropa($token, 'tools/list')->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');
});

it('svarar 401 med en Sanctum-token', function () {
    [, , $anvandare] = mcpAgentMedToken();
    $sanctum = $anvandare->createToken('api')->plainTextToken;

    mcpAnropa($sanctum, 'tools/list')->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');
});

it('svarar 401 när agentraden saknas men tokenen finns', function () {
    [$agent, $token] = mcpAgentMedToken();
    DB::table('agent')->where('id', $agent->id)->delete();

    $svar = mcpAnropa($token, 'tools/list')->assertUnauthorized();
    expect($svar->headers->get('WWW-Authenticate'))
        ->toContain('Bearer')->toContain('resource_metadata');
});

it('svarar på initialize med appens release som serverInfo.version', function () {
    [, $token] = mcpAgentMedToken();
    config(['app.version' => 'v9.8.7']);

    mcpAnropa($token, 'initialize', [
        'protocolVersion' => '2025-06-18',
        'capabilities' => (object) [],
        'clientInfo' => ['name' => 'prov', 'version' => '1'],
    ])->assertOk()->assertJsonPath('result.serverInfo.version', 'v9.8.7');
});

it('sätter last_used_at men högst en gång per minut', function () {
    [$agent, $token] = mcpAgentMedToken();
    $agent->forceFill(['last_used_at' => null])->saveQuietly();

    mcpAnropa($token, 'tools/list')->assertOk();
    $forsta = $agent->refresh()->last_used_at;
    expect($forsta)->not->toBeNull();

    travel(30)->seconds();
    mcpAnropa($token, 'tools/list')->assertOk();
    expect($agent->refresh()->last_used_at->equalTo($forsta))->toBeTrue();

    travel(31)->seconds();
    mcpAnropa($token, 'tools/list')->assertOk();
    expect($agent->refresh()->last_used_at->greaterThan($forsta))->toBeTrue();
});

it('avvisar en pausad agent med agent.paused och hint', function () {
    [$agent, $token] = mcpAgentMedToken();
    $agent->forceFill(['paused_at' => now()])->saveQuietly();

    $fel = mcpFel(mcpVerktyg($token, 'containers_list'));

    expect($fel['code'])->toBe('agent.paused')
        ->and($fel['hint'])->toBeString()
        ->and((object) $fel['data'])->toEqual((object) []);
});

it('avvisar en användare på Gratis med plan.mcp_unavailable och hint', function () {
    [$agent, $token] = mcpAgentMedToken();
    Subscription::query()->where('account_id', $agent->account_id)->delete();

    $svar = mcpVerktyg($token, 'containers_list');
    $fel = mcpFel($svar);

    expect($fel['code'])->toBe('plan.mcp_unavailable')->and($fel['hint'])->toBeString();
    // `data` är ett objekt, aldrig en lista.
    expect($svar->json('result.content.0.text'))->toContain('"data":{}');
});

it('lämnar hint ur tabellen och utelämnar den när koden saknas', function () {
    expect(Hints::for('agent.paused'))->toBeString()
        ->and(Hints::for('finns.inte'))->toBeNull();

    $svar = ToolError::response('finns.inte');
    $fel = json_decode($svar->content()->__toString(), true)['error'];

    expect($fel)->toHaveKeys(['code', 'data']);
    expect(array_key_exists('hint', $fel))->toBeFalse();
});

it('bär ingen undantagstext i server.error', function () {
    [$agent, $token] = mcpAgentMedToken();
    mcpEgenContainer($agent);

    // Rättighetsprövningen kastar ett undantag med en text som inte får ut.
    app()->bind(ContainerGate::class, fn () => throw new RuntimeException('hemlig-text-123'));

    $svar = mcpVerktyg($token, 'containers_list');
    $fel = mcpFel($svar);

    expect($fel['code'])->toBe('server.error')
        ->and($svar->getContent())->not->toContain('hemlig-text-123');
});

it('ger validation.failed med kod per fält', function () {
    [$agent, $token] = mcpAgentMedToken();
    $container = mcpEgenContainer($agent);

    $fel = mcpFel(mcpVerktyg($token, 'containers_tree', ['container' => $container->ulid, 'depth' => 9]));

    expect($fel['code'])->toBe('validation.failed')
        ->and($fel['data']['fields']['depth'][0]['code'])->toBe('validation.max')
        ->and($fel['data']['fields']['depth'][0]['data']['max'])->toBe(5);
});

it('ger agent.rate_limited på den 121:a läsningen men bara för den agenten', function () {
    [$agent, $token] = mcpAgentMedToken();
    [, $andraToken] = (function () use ($agent) {
        $flode = mcpAnslut(User::query()->findOrFail($agent->user_id), mcpKlient('Andra'));

        return [$flode['agent'], $flode['access_token']];
    })();

    for ($i = 0; $i < 120; $i++) {
        expect(mcpVerktyg($token, 'containers_list')->json('result.isError'))->toBeFalse();
    }

    $fel = mcpFel(mcpVerktyg($token, 'containers_list'));

    expect($fel['code'])->toBe('agent.rate_limited')
        ->and($fel['data']['retry_after_seconds'])->toBeInt()->toBeGreaterThan(0)
        ->and($fel['hint'])->toBeString();

    // Den andra agenten på samma konto (och användare) är orörd. Dess
    // egna rader saknar rättigheter, så listan är tom — men inget fel.
    expect(mcpVerktyg($andraToken, 'containers_list')->json('result.isError'))->toBeFalse();
});

it('stoppar en agent när kontots gräns nås', function () {
    config(['mcp.rate_limits.read.account' => [[3, 60]]]);
    [$agent, $token] = mcpAgentMedToken();
    $flode = mcpAnslut(User::query()->findOrFail($agent->user_id), mcpKlient('Andra'));

    mcpVerktyg($token, 'containers_list')->assertOk();
    mcpVerktyg($token, 'containers_list')->assertOk();
    mcpVerktyg($flode['access_token'], 'containers_list')->assertOk();

    expect(mcpFel(mcpVerktyg($flode['access_token'], 'containers_list'))['code'])->toBe('agent.rate_limited');
    expect(mcpFel(mcpVerktyg($token, 'containers_list'))['code'])->toBe('agent.rate_limited');
});

it('räknar skrivningar och raderingar mot egna gränser', function () {
    [$agent] = mcpAgentMedToken();
    $gransare = app(McpRateLimiter::class);

    for ($i = 0; $i < 30; $i++) {
        expect($gransare->attempt($agent, ToolKind::Write))->toBeNull();
    }
    expect($gransare->attempt($agent, ToolKind::Write))->toBeInt();

    // Raderingens tak per agent bor i App\Support\Mcp\AgentDeletionLimit
    // sedan issue 406: det räknar LYCKADE raderingar och släpps av notisen i
    // appen, inte av tiden. McpRateLimiter har därför ingen egen hink för
    // slaget — bara kontots, som ligger kvar på 40 i timmen.
    for ($i = 0; $i < 40; $i++) {
        expect($gransare->attempt($agent, ToolKind::Delete))->toBeNull();
    }
    expect($gransare->attempt($agent, ToolKind::Delete))->toBeInt();

    for ($i = 0; $i < 10; $i++) {
        expect($gransare->attempt($agent, ToolKind::Upload))->toBeNull();
    }
    expect($gransare->attempt($agent, ToolKind::Upload))->toBeInt();
});

it('listar bara containrar där agenten har minst read i någon grupp', function () {
    [$agent, $token] = mcpAgentMedToken();
    $synlig = mcpEgenContainer($agent, 'Båten');

    // Påslagen men utan nivå i någon grupp, och en utan påslag alls.
    $avslagen = Container::factory()->for($agent->account, 'account')->create(['name' => 'Garaget']);
    Container::factory()->for($agent->account, 'account')->create(['name' => 'Ouppräknad']);
    $rad = new AgentContainerGrant;
    $rad->agent_id = $agent->id;
    $rad->container_id = $avslagen->id;
    $rad->group = 'items';
    $rad->level = 'read';
    $rad->save();
    AgentAccountGrant::query()->where('agent_id', $agent->id)->where('group', 'items')->delete();
    AgentAccountGrant::query()->where('agent_id', $agent->id)->where('group', 'containers')->update(['level' => 'read']);

    $svar = mcpResultat(mcpVerktyg($token, 'containers_list'));

    expect(collect($svar['containers'])->pluck('name')->all())->toBe(['Båten'])
        ->and($svar['containers'][0]['ulid'])->toBe($synlig->ulid)
        ->and($svar['containers'][0]['owned_by_user'])->toBeTrue()
        ->and($svar['next_cursor'])->toBeNull()
        ->and($svar)->not->toHaveKey('hint');
});

it('sidindelar containers_list med markör', function () {
    [$agent, $token] = mcpAgentMedToken();

    foreach (range(1, 3) as $nr) {
        mcpEgenContainer($agent, "C$nr");
    }

    $forsta = mcpResultat(mcpVerktyg($token, 'containers_list', ['limit' => 2]));
    expect(collect($forsta['containers'])->pluck('name')->all())->toBe(['C1', 'C2'])
        ->and($forsta['next_cursor'])->toBeString();

    $andra = mcpResultat(mcpVerktyg($token, 'containers_list', ['limit' => 2, 'cursor' => $forsta['next_cursor']]));
    expect(collect($andra['containers'])->pluck('name')->all())->toBe(['C3'])
        ->and($andra['next_cursor'])->toBeNull();

    $fel = mcpFel(mcpVerktyg($token, 'containers_list', ['limit' => 101]));
    expect($fel['code'])->toBe('validation.failed');

    $fel = mcpFel(mcpVerktyg($token, 'containers_list', ['cursor' => '%%%']));
    expect($fel['code'])->toBe('validation.failed');
});

it('ger en ny agent utan påslag en tom containers_list med hint', function () {
    [, $anvandare] = mcpKonto('pro');
    $flode = mcpAnslut($anvandare);
    Container::factory()->for($flode['agent']->account, 'account')->create();

    $svar = mcpResultat(mcpVerktyg($flode['access_token'], 'containers_list'));

    expect($svar['containers'])->toBe([])
        ->and($svar['hint'])->toBe(Hints::EMPTY_CONTAINERS)
        ->and($svar['hint'])->toContain('/settings/agents');
});

/**
 * Ett träd: Båten › Motor › Kolv, och Båten › Segel, och en rot Verktyg.
 *
 * @return array{0: Container, 1: array<string, Item>}
 */
function mcpTrad(Agent $agent): array
{
    $container = mcpEgenContainer($agent);
    $item = fn (string $namn): Item => Item::factory()->create(['container_id' => $container->id, 'name' => $namn]);
    $i = ['batan' => $item('Båten'), 'motor' => $item('Motor'), 'kolv' => $item('Kolv'), 'segel' => $item('Segel'), 'verktyg' => $item('Verktyg')];

    foreach ([['batan', 'motor'], ['motor', 'kolv'], ['batan', 'segel']] as [$foralder, $barn]) {
        DB::table('item_link')->insert([
            'from_item_id' => $i[$foralder]->id,
            'to_item_id' => $i[$barn]->id,
            'relation' => 'parent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return [$container, $i];
}

it('respekterar depth i containers_tree', function () {
    [$agent, $token] = mcpAgentMedToken();
    [$container, $i] = mcpTrad($agent);

    $namn = fn (array $svar): array => collect($svar['items'])->pluck('name')->sort()->values()->all();

    expect($namn(mcpResultat(mcpVerktyg($token, 'containers_tree', ['container' => $container->ulid, 'depth' => 1]))))
        ->toBe(['Båten', 'Verktyg']);

    // Standard är 2.
    $standard = mcpResultat(mcpVerktyg($token, 'containers_tree', ['container' => $container->ulid]));
    expect($namn($standard))->toBe(['Båten', 'Motor', 'Segel', 'Verktyg']);

    $djup = mcpResultat(mcpVerktyg($token, 'containers_tree', ['container' => $container->ulid, 'depth' => 3]));
    expect($namn($djup))->toBe(['Båten', 'Kolv', 'Motor', 'Segel', 'Verktyg']);

    $kolv = collect($djup['items'])->firstWhere('name', 'Kolv');
    expect($kolv['parents'])->toBe([$i['motor']->ulid])->and($kolv['depth'])->toBe(3);
});

it('sidindelar containers_tree med markör', function () {
    [$agent, $token] = mcpAgentMedToken();
    [$container] = mcpTrad($agent);

    $sidor = [];
    $markor = null;

    do {
        $svar = mcpResultat(mcpVerktyg($token, 'containers_tree', array_filter([
            'container' => $container->ulid, 'depth' => 3, 'limit' => 2, 'cursor' => $markor,
        ])));
        $sidor[] = count($svar['items']);
        $markor = $svar['next_cursor'];
    } while ($markor !== null);

    expect($sidor)->toBe([2, 2, 1]);
});

it('visar inga items användaren inte når i containers_tree', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();

    // Någon annans container där användaren bara har ett item delat.
    [, $agare] = mcpKonto('pro');
    $container = Container::factory()->for($agare->accounts()->first(), 'account')->create();
    mcpSlaPaI($agent, $container);
    $delat = Item::factory()->create(['container_id' => $container->id, 'name' => 'Delat']);
    Item::factory()->create(['container_id' => $container->id, 'name' => 'Hemligt']);
    $rad = beviljaAccess($container, $anvandare, 'read', 'member');
    $rad->forceFill(['item_id' => $delat->id, 'agent_level' => 'read'])->save();

    $svar = mcpResultat(mcpVerktyg($token, 'containers_tree', ['container' => $container->ulid]));

    expect(collect($svar['items'])->pluck('name')->all())->toBe(['Delat']);
});

it('listar och öppnar en container där medlemmen bara har en item-delning', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();

    [, $agare] = mcpKonto('pro');
    $container = Container::factory()->for($agare->accounts()->first(), 'account')->create();
    mcpSlaPaI($agent, $container);
    $delat = Item::factory()->create(['container_id' => $container->id, 'name' => 'Delat']);
    $rad = beviljaAccess($container, $anvandare, 'read', 'member');
    $rad->forceFill(['item_id' => $delat->id, 'agent_level' => 'read'])->save();

    $lista = mcpResultat(mcpVerktyg($token, 'containers_list'));

    expect(collect($lista['containers'])->pluck('ulid')->all())->toBe([$container->ulid])
        ->and($lista['containers'][0]['owned_by_user'])->toBeFalse();

    $trad = mcpResultat(mcpVerktyg($token, 'containers_tree', ['container' => $container->ulid]));

    expect(collect($trad['items'])->pluck('name')->all())->toBe(['Delat']);
});

it('svarar resource.not_found för en container agenten inte är påslagen i', function () {
    [$agent, $token] = mcpAgentMedToken();
    $container = Container::factory()->for($agent->account, 'account')->create();

    expect(mcpFel(mcpVerktyg($token, 'containers_tree', ['container' => $container->ulid]))['code'])
        ->toBe('resource.not_found');
});

it('avvisar en markör från ett annat verktyg med validation.failed', function () {
    [$agent, $token] = mcpAgentMedToken();
    [$container] = mcpTrad($agent);

    $listmarkor = Cursor::encode([5]);
    $tradmarkor = Cursor::encode([1, 5]);

    expect(mcpFel(mcpVerktyg($token, 'containers_tree', ['container' => $container->ulid, 'cursor' => $listmarkor]))['code'])
        ->toBe('validation.failed')
        ->and(mcpFel(mcpVerktyg($token, 'containers_list', ['cursor' => $tradmarkor]))['code'])
        ->toBe('validation.failed');
});

it('prövar högst ett tak av items per anrop i containers_tree och ger next_cursor', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();

    [, $agare] = mcpKonto('pro');
    $container = Container::factory()->for($agare->accounts()->first(), 'account')->create();
    mcpSlaPaI($agent, $container);

    $delat = Item::factory()->create(['container_id' => $container->id, 'name' => 'Delat']);
    for ($n = 0; $n < 250; $n++) {
        Item::factory()->create(['container_id' => $container->id, 'name' => 'Dolt '.$n]);
    }
    $rad = beviljaAccess($container, $anvandare, 'read', 'member');
    $rad->forceFill(['item_id' => $delat->id, 'agent_level' => 'read'])->save();

    DB::enableQueryLog();
    $forsta = mcpResultat(mcpVerktyg($token, 'containers_tree', ['container' => $container->ulid]));
    $fragor = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Taket på 200 prövade items: de dolda går inte att fylla en sida med,
    // så anropet slutar i förtid och pekar vidare.
    expect($forsta['next_cursor'])->toBeString()
        ->and($fragor)->toBeLessThan(1500);

    $namn = collect($forsta['items'])->pluck('name')->all();
    $markor = $forsta['next_cursor'];
    while ($markor !== null) {
        $svar = mcpResultat(mcpVerktyg($token, 'containers_tree', ['container' => $container->ulid, 'cursor' => $markor]));
        $namn = array_merge($namn, collect($svar['items'])->pluck('name')->all());
        $markor = $svar['next_cursor'];
    }

    expect($namn)->toBe(['Delat']);
});
