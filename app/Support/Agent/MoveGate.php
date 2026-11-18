<?php

namespace App\Support\Agent;

use App\Models\Container;
use App\Models\Item;
use App\Support\Mcp\McpToolException;

/**
 * Flyttspärren, se [[ADR-0087 MCP-server för agenter]] Riktning 10 och
 * [[ADR-0069 Flytta ett item inom containern]].
 *
 * Åtkomst ärvs nedåt längs `parent` ([[ADR-0028 Åtkomst på itemnivå]] regel
 * 1), så en flytt in under ett delat item ger fler personer åtkomst utan att
 * någon bjudits in — av misstag när agenten städar, eller avsiktligt via
 * prompt injection. Spärren jämför MÄNGDERNA personer före och efter: får en
 * ny person åtkomst avvisas anropet, även om färre ser itemet efteråt.
 *
 * Prövningen gäller inte bara flyttverktyget, utan varje handling som ger ett
 * item en ny förälder (Riktning 10):
 *
 * 1. `items_move` — itemet och dess underträd före och efter flytten.
 * 2. `relations_create` med `parent`/`child` — den nya föräldrakanten ger
 *    barnet förälderns läsare. Ett item får ha flera föräldrar
 *    (`LinkItems` § Beslut 6), så kanten vidgar åtkomsten utan att itemet
 *    flyttas.
 * 3. `items_create` med en förälder — det nya itemet får sin förälders
 *    läsare. Det finns inget "före" för itemet, så jämförelsen är mot
 *    containerns egen publik: en förälder som delas med fler än containern
 *    (någon som bara har en itemgrant) vidgar åtkomsten, och då avvisas
 *    skapandet.
 *
 * Bara nya personer avvisar. En flytt ut, och en flytt inom samma åtkomst,
 * är tillåtna.
 */
final class MoveGate
{
    public function __construct(private readonly ItemAudience $audience) {}

    /**
     * @throws McpToolException `agent.move_widens_access`
     */
    public function assertMoveKeepsAudience(Container $container, Item $item, ?Item $fromParent, ?Item $toParent): void
    {
        $this->refuseNewReaders(
            $this->audience->forItem($container, $item),
            $this->audience->afterMoving($container, $item, $fromParent, $toParent),
        );
    }

    /**
     * En ny föräldrakant till `$parent` under `$item` (barnet).
     *
     * @throws McpToolException `agent.move_widens_access`
     */
    public function assertParentLinkKeepsAudience(Container $container, Item $item, Item $parent): void
    {
        $this->refuseNewReaders(
            $this->audience->forItem($container, $item),
            $this->audience->afterLinkingParent($container, $item, $parent),
        );
    }

    /**
     * Ett nytt item under `$parent`, jämfört med containerns egen publik.
     *
     * @throws McpToolException `agent.move_widens_access`
     */
    public function assertCreateUnderParentKeepsAudience(Container $container, Item $parent): void
    {
        $this->refuseNewReaders(
            $this->audience->forContainer($container),
            $this->audience->forItem($container, $parent),
        );
    }

    /**
     * @param  list<int>  $before
     * @param  list<int>  $after
     */
    private function refuseNewReaders(array $before, array $after): void
    {
        if (array_diff($after, $before) !== []) {
            throw new McpToolException('agent.move_widens_access');
        }
    }
}
