<?php

// rott-pa-basen: hjälparfil utan egna tester - ren flytt av delade Pest-hjälpare, ingen acceptanskriterie-yta att vara röd på basen.

/*
 * Globala Pest-hjälpare för MCP-proven som mer än en testfil anropar.
 *
 * Samma princip som i tests/Support/Testhjalpare.php: Pest laddar bara de
 * testfiler man kör, så en hjälpare som definieras i en annan fil ger
 * "Call to undefined function" när filen körs ensam. Den här filen laddas av
 * Composers autoloader (autoload-dev.files i composer.json) innan något test
 * körs, oavsett hur delmängden väljs.
 *
 * Varje funktion nedan är en ren flytt — namn, signatur och beteende är
 * oförändrade. Ursprungsfilen står i funktionens docblock. Hjälpare som bara
 * en enda testfil använder står kvar där.
 */

use App\Models\Agent;
use App\Models\AgentAccountGrant;
use App\Models\AgentContainerGrant;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use App\Support\Agent\AgentGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\postJson;

/**
 * Ett JSON-RPC-anrop till `/mcp` med en Bearer-token.
 *
 * Ursprung: tests/Feature/Mcp/McpEndpointTest.php.
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
 * Ursprung: tests/Feature/Mcp/McpEndpointTest.php.
 *
 * @param  array<string, mixed>  $argument
 */
function mcpVerktyg(string $token, string $namn, array $argument = []): TestResponse
{
    return mcpAnropa($token, 'tools/call', ['name' => $namn, 'arguments' => (object) $argument]);
}

/**
 * Verktygsfelet ur ett svar: `{"error": {...}}` ur textinnehållet.
 *
 * Ursprung: tests/Feature/Mcp/McpEndpointTest.php.
 *
 * @return array<string, mixed>
 */
function mcpFel(TestResponse $svar): array
{
    $svar->assertOk()->assertJsonPath('result.isError', true);

    return json_decode($svar->json('result.content.0.text'), true)['error'];
}

/**
 * Ursprung: tests/Feature/Mcp/McpEndpointTest.php.
 *
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
 * Ursprung: tests/Feature/Mcp/McpEndpointTest.php.
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

/**
 * Ursprung: tests/Feature/Mcp/McpEndpointTest.php.
 */
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

/**
 * Ursprung: tests/Feature/Mcp/McpEndpointTest.php.
 */
function mcpEgenContainer(Agent $agent, ?string $namn = null): Container
{
    $container = Container::factory()->for($agent->account, 'account')
        ->create($namn === null ? [] : ['name' => $namn]);
    mcpSlaPaI($agent, $container);

    return $container;
}

/**
 * Ett träd: Båten › Motor › Kolv, och Båten › Segel, och en rot Verktyg.
 *
 * Ursprung: tests/Feature/Mcp/McpEndpointTest.php.
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

/**
 * Sänker eller höjer agentens nivå i en grupp — både kontoraden och
 * containerraden, eftersom ResolveAgentAccess tar den lägsta av dem.
 *
 * Ursprung: tests/Feature/Mcp/SkrivverktygTest.php.
 */
function skrivNiva(Agent $agent, Container $container, string $grupp, string $niva): void
{
    AgentAccountGrant::query()
        ->where('agent_id', $agent->id)
        ->where('group', $grupp)
        ->update(['level' => $niva]);

    AgentContainerGrant::query()
        ->where('agent_id', $agent->id)
        ->where('container_id', $container->id)
        ->where('group', $grupp)
        ->update(['level' => $niva]);
}
