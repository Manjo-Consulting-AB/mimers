<?php

namespace App\Mcp\Tools;

use App\Models\Agent;
use App\Models\Container;
use App\Models\Item;
use App\Support\Mcp\McpToolException;

/**
 * Basen för skrivverktygen som rör ett item, se [[ADR-0087 MCP-server för
 * agenter]] Riktning 1 och 3.
 *
 * Ett item agenten inte når alls är `resource.not_found` — nekandet avslöjar
 * inte att det finns — och ett item agenten når men med för låg nivå är
 * `agent.forbidden`, samma skillnad som App\Mcp\Tools\ReadsItems gör för
 * läsningen. Nivåerna kommer ur App\Actions\Agent\ResolveAgentAccess via
 * App\Mcp\Tools\McpTool::requireLevel(), så ingen behörighetsregel skrivs
 * om här.
 */
abstract class WritesItems extends McpTool
{
    /**
     * @return array{0: Item, 1: Container}
     */
    protected function writableItem(Agent $agent, string $ulid, string $minimum): array
    {
        $item = Item::query()->where('ulid', $ulid)->first();
        $container = $item === null ? null : Container::query()->with('account')->find($item->container_id);

        if ($item === null || $container === null) {
            throw new McpToolException('resource.not_found');
        }

        $this->requireLevel($agent, $minimum, $container, $item);

        return [$item, $container];
    }

    /**
     * En container verktyget skriver i, eller `resource.not_found`.
     */
    protected function writableContainer(Agent $agent, string $ulid, string $minimum): Container
    {
        $container = Container::query()->with('account')->where('ulid', $ulid)->first();

        if ($container === null) {
            throw new McpToolException('resource.not_found');
        }

        $this->requireLevel($agent, $minimum, $container);

        return $container;
    }
}
