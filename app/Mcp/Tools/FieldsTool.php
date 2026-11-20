<?php

namespace App\Mcp\Tools;

use App\Models\Agent;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemField;
use App\Support\Access\AccessLevel;
use App\Support\Agent\AgentGroup;
use App\Support\Mcp\ContainerGate;
use App\Support\Mcp\McpToolException;

/**
 * Basen för fältverktygen, se [[ADR-0089 Egna fält på items]] § Riktning 8
 * och issue 423.
 *
 * Ett fält hör till containern (`item` utelämnat) eller till ett enskilt
 * item (`item` satt). Skillnaden är bara omfånget: containerns fält kräver
 * skrivrätt i containern, itemets egna kräver skrivrätt på itemet — samma
 * två grindar som App\Http\Controllers\Api\ItemFieldController använder
 * (`ContainerPolicy::update()` respektive `ItemPolicy::update()`,
 * [[ADR-0028 Åtkomst på itemnivå]]).
 *
 * Inkorgen är en plats och inte en pärm: containerns fält kan inte ändras
 * där, precis som kategorierna (App\Mcp\Tools\CategoriesCreate). Ett items
 * egna fält följer itemet och får ändras även när itemet ligger i inkorgen.
 *
 * Uppslagningen av fältet går genom samma relationer som routebindningen i
 * `routes/api.php`: `Container::fields()` för containerns fält och
 * `Item::ownFields()` för itemets egna. En ULID från fel omfång finns därför
 * inte, och svaret är `resource.not_found` i stället för att avslöja att den
 * finns någon annanstans.
 */
abstract class FieldsTool extends McpTool
{
    protected ?AgentGroup $group = AgentGroup::Items;

    /**
     * Containern och itemet (eller null) för en läsning: `read` i verktygets
     * grupp, och containern ska nås av agenten (ContainerGate) när frågan
     * gäller containerns egna fält.
     *
     * @return array{0: Container, 1: ?Item}
     */
    protected function readableScope(Agent $agent, string $containerUlid, ?string $itemUlid): array
    {
        [$container, $item] = $this->locate($containerUlid, $itemUlid);

        if ($item !== null) {
            $level = $this->levelIn($agent, $container, $item);

            if ($level === null || ! AccessLevel::atLeast($level, AccessLevel::READ)) {
                throw new McpToolException('resource.not_found');
            }

            return [$container, $item];
        }

        $level = $this->levelIn($agent, $container);

        if ($level === null
            || ! AccessLevel::atLeast($level, AccessLevel::READ)
            || ! app(ContainerGate::class)->reaches($agent, $container)) {
            throw new McpToolException('resource.not_found');
        }

        return [$container, null];
    }

    /**
     * Containern och itemet för en skrivning: `write` i verktygets grupp.
     *
     * @return array{0: Container, 1: ?Item}
     */
    protected function writableScope(Agent $agent, string $containerUlid, ?string $itemUlid): array
    {
        [$container, $item] = $this->locate($containerUlid, $itemUlid);

        if ($item !== null) {
            $this->requireLevel($agent, AccessLevel::WRITE, $container, $item);

            return [$container, $item];
        }

        if ($container->isInbox()) {
            throw new McpToolException('agent.forbidden');
        }

        $this->requireLevel($agent, AccessLevel::WRITE, $container);

        return [$container, null];
    }

    /**
     * Fältet i rätt omfång, eller `resource.not_found`.
     */
    protected function findField(Container $container, ?Item $item, string $ulid): ItemField
    {
        $field = ($item === null ? $container->fields() : $item->ownFields())
            ->where('ulid', $ulid)
            ->first();

        if ($field === null) {
            throw new McpToolException('resource.not_found');
        }

        return $field;
    }

    /**
     * Fältets form utåt — samma fält som App\Http\Resources\ItemFieldResource,
     * utan tidsstämplarna. `item` är itemets ULID för ett eget fält och null
     * för containerns.
     *
     * @return array<string, mixed>
     */
    protected function fieldRow(ItemField $field): array
    {
        return [
            'ulid' => $field->ulid,
            'label' => $field->label,
            'type' => $field->type,
            'position' => $field->position,
            'item' => $field->item_id === null ? null : $field->item?->ulid,
        ];
    }

    /**
     * @return array{0: Container, 1: ?Item}
     */
    private function locate(string $containerUlid, ?string $itemUlid): array
    {
        $container = Container::query()->with('account')->where('ulid', $containerUlid)->first();

        if ($container === null) {
            throw new McpToolException('resource.not_found');
        }

        if ($itemUlid === null) {
            return [$container, null];
        }

        $item = $container->items()->where('ulid', $itemUlid)->first();

        if ($item === null) {
            throw new McpToolException('resource.not_found');
        }

        return [$container, $item];
    }
}
