<?php

namespace App\Support\Mcp;

use RuntimeException;

/**
 * Ett fel verktyget vill lämna som verktygsresultat med `isError: true`, se
 * App\Support\Mcp\ToolError. Samma koder som REST-API:et.
 *
 * `$hint` är för det fåtal fall där den fasta meningen per kod i
 * App\Support\Mcp\Hints inte räcker — verktyget vet något om just det här
 * felet som agenten behöver, som att filen ska skickas via
 * `attachments_upload_url` i stället (issue 405).
 */
final class McpToolException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly array $data = [],
        public readonly ?string $hint = null,
    ) {
        parent::__construct($errorCode);
    }
}
