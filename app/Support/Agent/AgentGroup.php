<?php

namespace App\Support\Agent;

/**
 * Rättighetsgrupperna för en agent, se [[ADR-0087 MCP-server för agenter]]
 * Riktning 1 och 6. Varje grupp sätts på samma ladder som
 * `container_access.level` (`read`, `create`, `write`, `delete`); ingen rad
 * betyder ingen åtkomst.
 */
enum AgentGroup: string
{
    /** Items, relationer, kategorier och taggar. */
    case Items = 'items';

    /** Scheman, förekomster och uppgifter. */
    case Schedules = 'schedules';

    case Costs = 'costs';

    case Loans = 'loans';

    /** Bilagor. */
    case Files = 'files';

    /** Containerns egna fält. */
    case Containers = 'containers';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $group): string => $group->value, self::cases());
    }
}
