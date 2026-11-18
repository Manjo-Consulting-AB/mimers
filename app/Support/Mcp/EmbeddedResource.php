<?php

namespace App\Support\Mcp;

use Laravel\Mcp\Server\Concerns\HasMeta;
use Laravel\Mcp\Server\Contracts\Content;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\Tool;

/**
 * En inbäddad resurs som verktygsinnehåll (`type: resource`), se
 * [[ADR-0087 MCP-server för agenter]] Riktning 16. Paketets `Blob` får inte
 * användas i ett verktygssvar, så filer upp till 10 MB lämnas genom den här.
 */
final class EmbeddedResource implements Content
{
    use HasMeta;

    public function __construct(
        private readonly string $uri,
        private readonly string $mimeType,
        private readonly string $data,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toTool(Tool $tool): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function toPrompt(Prompt $prompt): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function toResource(Resource $resource): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->mergeMeta([
            'type' => 'resource',
            'resource' => [
                'uri' => $this->uri,
                'mimeType' => $this->mimeType,
                'blob' => base64_encode($this->data),
            ],
        ]);
    }
}
