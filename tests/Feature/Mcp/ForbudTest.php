<?php

use App\Actions\Template\DeleteTemplate;
use App\Actions\Template\RenameTemplate;
use App\Actions\Template\SaveContainerTemplate;
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
 * det. Det finns inte i servern och ska aldrig registreras. Namnet är
 * prefixat med filens eget namn: klasserna är globala i testsviten.
 */
#[Name('trash_empty')]
class ForbudProvForbjudetNamnVerktyg extends McpTool
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
class ForbudProvForbjudenActionVerktyg extends McpTool
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
 * `actions()` deklareras för hand av varje verktyg och är ett KONTRAKT, inte
 * en avläsning av koden: ett verktyg som anropar en förbjuden action men
 * inte listar den här fångas inte. Kontraktet är det provet kan pröva, och
 * granskningen av varje nytt verktyg får kontrollera att listan stämmer.
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
    expect(forbudProv([new ForbudProvForbjudetNamnVerktyg]))->not->toBe([]);
});

it('fäller ett verktyg vars action hör till en förbjuden domän', function () {
    expect(forbudProv([new ForbudProvForbjudenActionVerktyg]))->not->toBe([]);

    expect(ForbiddenDomains::offence('items_create', [EmptyTrash::class]))->toBe(EmptyTrash::class);
});

/*
 * Issue 423 · [[ADR-0089 Egna fält på items]] § Riktning 8: en agent får
 * lista och använda mallar, aldrig spara, döpa om eller ta bort dem. Mallarna
 * ligger på kontot, nära förbudet mot kontoinställningar, och förbudet skrivs
 * därför här i stället för som ett ord i ForbiddenDomains — `templates` är
 * också namnet på det läsverktyg som ska finnas.
 */
it('har inget verktyg som sparar, döper om eller tar bort en mall', function () {
    $mallNamn = [];

    foreach (forbudVerktyg() as $verktyg) {
        if (str_starts_with($verktyg->name(), 'templates_')) {
            $mallNamn[] = $verktyg->name();
        }

        foreach ($verktyg->actions() as $action) {
            expect($action)
                ->not->toBeIn([SaveContainerTemplate::class, RenameTemplate::class, DeleteTemplate::class]);
        }
    }

    expect($mallNamn)->toBe(['templates_list']);
});
