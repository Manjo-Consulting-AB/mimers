<?php

namespace App\Support\Mcp;

/**
 * Slaget av verktyg, som väljer rate limit och standardannotationer — se
 * [[ADR-0087 MCP-server för agenter]] Riktning 13 och 9. Värdena är
 * nycklarna i `config('mcp.rate_limits')`.
 */
enum ToolKind: string
{
    case Read = 'read';

    case Write = 'write';

    case Delete = 'delete';

    case Upload = 'upload';

    /**
     * Annotationerna slaget ger, innan verktyget själv ändrar dem.
     *
     * @return array<string, bool>
     */
    public function annotations(): array
    {
        return match ($this) {
            self::Read => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true],
            self::Write, self::Upload => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false],
            self::Delete => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false],
        };
    }
}
