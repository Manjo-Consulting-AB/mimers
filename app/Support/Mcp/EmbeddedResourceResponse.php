<?php

namespace App\Support\Mcp;

use Laravel\Mcp\Response;

/**
 * Ett verktygssvar som bär en inbäddad resurs, se EmbeddedResource.
 */
final class EmbeddedResourceResponse extends Response
{
    public static function of(string $uri, string $mimeType, string $data): self
    {
        return new self(new EmbeddedResource($uri, $mimeType, $data));
    }
}
