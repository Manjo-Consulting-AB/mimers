<?php

namespace App\Support\Mcp;

use RuntimeException;

/**
 * Ett fel verktyget vill lämna som verktygsresultat med `isError: true`, se
 * App\Support\Mcp\ToolError. Samma koder som REST-API:et.
 */
final class McpToolException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public readonly string $errorCode, public readonly array $data = [])
    {
        parent::__construct($errorCode);
    }
}
