<?php

namespace App\Support\Template;

/**
 * En av Mimers egna mallar, upplöst ur resources/templates/{locale}.php —
 * se [[ADR-0101 Mallarna]]. Innehållet har samma form som
 * `container_template.content`.
 */
final class MimersTemplate
{
    /**
     * @param  array<string, mixed>  $content  `{"categories": [...], "fields": [...]}`
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly array $content,
        public readonly ?string $agentRules = null,
    ) {}

    /**
     * @return array{key: string, name: string, content: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'name' => $this->name, 'content' => $this->content];
    }
}
