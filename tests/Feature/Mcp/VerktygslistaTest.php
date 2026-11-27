<?php

use App\Mcp\Servers\MimersServer;
use App\Mcp\Tools\McpTool;

/*
 * Issue 402 · Kontraktsprovet för verktygslistan, se [[ADR-0087 MCP-server
 * för agenter]] Riktning 15. `tools/list` jämförs med en ögonblicksbild —
 * namn, parametrar och annotationer. Ändras något fälls provet tills
 * ögonblicksbilden uppdateras med avsikt, så att en ändring som bryter syns
 * i PR-granskningen.
 *
 * Uppdatera ögonblicksbilden: `UPPDATERA_VERKTYGSLISTA=1 php artisan test
 * --filter=VerktygslistaTest`.
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
});

const VERKTYGSLISTA_FIL = __DIR__.'/verktygslista.json';

/**
 * @return list<array<string, mixed>>
 */
function verktygslistaNu(): array
{
    [, $anvandare] = mcpKonto('pro');
    $token = mcpAnslut($anvandare)['access_token'];

    // `tools/list` sidindelas (15 per sida som standard, högst 50), så
    // kontraktet läses i EN sida. Fler verktyg än så fäller provet här i
    // stället för att tyst lämna de sista utanför ögonblicksbilden.
    $svar = mcpAnropa($token, 'tools/list', ['per_page' => 100])->assertOk();

    expect($svar->json('result.nextCursor'))->toBeNull();

    $verktyg = $svar->json('result.tools');

    $lista = array_map(function (array $v): array {
        $annotationer = $v['annotations'];
        unset($annotationer['title']);
        ksort($annotationer);

        $egenskaper = $v['inputSchema']['properties'] ?? [];
        ksort($egenskaper);

        return [
            'name' => $v['name'],
            'parameters' => array_map(fn (array $p): array => array_intersect_key($p, array_flip(['type', 'minimum', 'maximum'])), $egenskaper),
            'required' => $v['inputSchema']['required'] ?? [],
            'annotations' => $annotationer,
        ];
    }, $verktyg);

    usort($lista, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

    return $lista;
}

it('låser verktygslistan mot ögonblicksbilden', function () {
    $nu = verktygslistaNu();

    if (getenv('UPPDATERA_VERKTYGSLISTA') === '1') {
        file_put_contents(VERKTYGSLISTA_FIL, json_encode($nu, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }

    $sparad = json_decode((string) file_get_contents(VERKTYGSLISTA_FIL), true);

    expect($nu)->toEqual($sparad);
});

it('fäller ett verktyg vars namn inte följer domän_verb på engelska', function () {
    // Ett eller flera understreck: `items_notes_update` (issue 404) är
    // domän_fält_verb, samma form som `items_create` med fältet utskrivet.
    foreach (verktygslistaNu() as $verktyg) {
        expect($verktyg['name'])->toMatch('/^[a-z]+(_[a-z]+)+$/');
    }

    // Provet självt biter: dessa namn ska inte godkännas.
    foreach (['containersList', 'containers-list', 'behållare_lista', 'containers', 'a__b', '_items_create', 'items_create_'] as $dalig) {
        expect($dalig)->not->toMatch('/^[a-z]+(_[a-z]+)+$/');
    }
});

it('kräver slag och grupp på varje verktyg och att alla är registrerade', function () {
    $server = new ReflectionProperty(MimersServer::class, 'tools');
    $registrerade = $server->getValue((new ReflectionClass(MimersServer::class))->newInstanceWithoutConstructor());

    $filer = glob(app_path('Mcp/Tools/*.php'));
    $klasser = [];

    foreach ($filer as $fil) {
        $klass = 'App\\Mcp\\Tools\\'.basename($fil, '.php');
        if (is_subclass_of($klass, McpTool::class) && ! (new ReflectionClass($klass))->isAbstract()) {
            $klasser[] = $klass;
        }
    }

    expect($registrerade)->toEqualCanonicalizing($klasser);

    foreach ($klasser as $klass) {
        $verktyg = app($klass);

        expect($verktyg->kind())->not->toBeNull("$klass saknar slag")
            ->and($verktyg->group())->not->toBeNull("$klass saknar grupp");

        $a = $verktyg->annotations();
        expect($a)->toHaveKeys(['readOnlyHint', 'destructiveHint', 'idempotentHint']);
    }
});
