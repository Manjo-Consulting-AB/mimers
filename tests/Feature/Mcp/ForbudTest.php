<?php

use App\Actions\Trash\EmptyTrash;
use App\Mcp\Servers\MimersServer;
use App\Mcp\Tools\McpTool;
use App\Models\Agent;
use App\Support\Agent\AgentGroup;
use App\Support\Mcp\ForbiddenDomains;
use App\Support\Mcp\ToolKind;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;

/*
 * Issue 404 § 3 · Förbuden, se [[ADR-0087 MCP-server för agenter]] Riktning 7.
 *
 * Det finns inga verktyg för papperskorgen, kontots inställningar, plan och
 * betalning, inbjudningar, åtkomst och agenttak, containerns bild och
 * profilbilden, webhooks, kalenderflödet, ägarbyte, agenters rättigheter och
 * tokens, eller export. Provet läser verktygslistan och fäller ett verktyg
 * vars namn eller action hör till en förbjuden domän — och bevisar att det
 * biter med två verktyg som gör just det.
 */

/**
 * Ett verktyg i en förbjuden domän, enbart för att bevisa att provet fäller
 * det. Det finns inte i servern och ska aldrig registreras.
 */
#[Name('trash_empty')]
class ForbjudetNamnVerktyg extends McpTool
{
    protected ?ToolKind $kind = ToolKind::Write;

    protected ?AgentGroup $group = AgentGroup::Items;

    protected function run(Request $request, Agent $agent): Response|ResponseFactory
    {
        return Response::structured([]);
    }
}

/**
 * Ett verktyg med ett oskyldigt namn men en förbjuden action — namnet är
 * inte hela prövningen.
 */
#[Name('items_create')]
class ForbjudenActionVerktyg extends McpTool
{
    protected ?ToolKind $kind = ToolKind::Write;

    protected ?AgentGroup $group = AgentGroup::Items;

    /**
     * @return list<class-string>
     */
    public function actions(): array
    {
        return [EmptyTrash::class];
    }

    protected function run(Request $request, Agent $agent): Response|ResponseFactory
    {
        return Response::structured([]);
    }
}

/**
 * Verktygen som servern faktiskt registrerar.
 *
 * @return list<McpTool>
 */
function forbudVerktyg(): array
{
    $property = new ReflectionProperty(MimersServer::class, 'tools');
    $klasser = $property->getValue((new ReflectionClass(MimersServer::class))->newInstanceWithoutConstructor());

    return array_map(fn (string $klass): McpTool => new $klass, $klasser);
}

/**
 * Överträdelserna i en lista av verktyg, tom när ingen finns.
 *
 * @param  list<McpTool>  $verktyg
 * @return list<string>
 */
function forbudProv(array $verktyg): array
{
    $overtradelser = [];

    foreach ($verktyg as $verktyg) {
        $overtradelse = ForbiddenDomains::offence($verktyg->name(), $verktyg->actions());

        if ($overtradelse !== null) {
            $overtradelser[] = $overtradelse;
        }
    }

    return $overtradelser;
}

it('har inget verktyg i en förbjuden domän', function () {
    expect(forbudProv(forbudVerktyg()))->toBe([]);
});

it('fäller ett verktyg vars namn hör till en förbjuden domän', function () {
    expect(forbudProv([new ForbjudetNamnVerktyg]))->not->toBe([]);
});

it('fäller ett verktyg vars action hör till en förbjuden domän', function () {
    expect(forbudProv([new ForbjudenActionVerktyg]))->not->toBe([]);

    expect(ForbiddenDomains::offence('items_create', [EmptyTrash::class]))->toBe(EmptyTrash::class);
});
