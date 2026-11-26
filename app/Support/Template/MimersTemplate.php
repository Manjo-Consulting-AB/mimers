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
     * Innehållet med `agent_rules`, i samma form som en sparad mall — för
     * förhandsvisningen och `templates_list`.
     *
     * @return array<string, mixed>
     */
    public function contentWithRules(): array
    {
        return $this->agentRules === null ? $this->content : [...$this->content, 'agent_rules' => $this->agentRules];
    }

    /**
     * @return array{key: string, name: string, content: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'name' => $this->name, 'content' => $this->contentWithRules()];
    }
}
