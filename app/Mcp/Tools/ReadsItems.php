<?php

namespace App\Mcp\Tools;

use App\Models\Agent;
use App\Models\Container;
use App\Models\Item;
use App\Support\Access\AccessLevel;
use App\Support\Mcp\McpToolException;

/**
 * Basen för läsverktygen som slår upp ett item, se [[ADR-0087 MCP-server för
 * agenter]] Riktning 16. Ett item agenten inte når med `read` i verktygets
 * grupp är `resource.not_found` — nekandet avslöjar inte att det finns.
 */
abstract class ReadsItems extends McpTool
{
    /**
     * @return array{0: Item, 1: Container}
     */
    protected function readableItem(Agent $agent, string $ulid): array
    {
        $item = Item::query()->where('ulid', $ulid)->first();
        $container = $item === null ? null : Container::query()->with('account')->find($item->container_id);

        if ($item === null || $container === null) {
            throw new McpToolException('resource.not_found');
        }

        $level = $this->levelIn($agent, $container, $item);

        if ($level === null || ! AccessLevel::atLeast($level, AccessLevel::READ)) {
            throw new McpToolException('resource.not_found');
        }

        return [$item, $container];
    }

    /**
     * Ursprunget för ett item: `email` för det som kommit in via inkorgen,
     * annars `app`. Text som kommit in utifrån bär sitt ursprung (Riktning 9).
     */
    protected function sourceOf(Container $container): string
    {
        return $container->isInbox() ? 'email' : 'app';
    }
}
