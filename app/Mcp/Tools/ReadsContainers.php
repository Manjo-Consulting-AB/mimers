<?php

namespace App\Mcp\Tools;

use App\Models\Agent;
use App\Models\Container;
use App\Support\Access\AccessLevel;
use App\Support\Mcp\ContainerGate;
use App\Support\Mcp\McpToolException;

/**
 * Basen för läsverktygen som listar något i en container (kategorier,
 * taggar). Agenten behöver `read` i verktygets grupp på containern, och
 * användaren ska nå den (ContainerGate). Annars är containern
 * `resource.not_found`.
 */
abstract class ReadsContainers extends McpTool
{
    protected function readableContainer(Agent $agent, string $ulid): Container
    {
        $container = Container::query()->with('account')->where('ulid', $ulid)->first();

        $level = $container === null ? null : $this->levelIn($agent, $container);

        if ($container === null
            || $level === null
            || ! AccessLevel::atLeast($level, AccessLevel::READ)
            || ! app(ContainerGate::class)->reaches($agent, $container)) {
            throw new McpToolException('resource.not_found');
        }

        return $container;
    }
}
