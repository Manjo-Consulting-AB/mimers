<?php

namespace App\Support\Item;

/**
 * En förekomst av ett item i strukturen — en nod i ItemTree, se
 * [[ADR-0041 Itemets vy]] § Beslut och issue 94.
 *
 * ETT ITEM KAN HA FLERA NODER. Grafen är en DAG och inte ett träd
 * (App\Actions\Item\LinkItems, klassens docblock), så ett item med två
 * föräldrar förekommer en gång per väg från en rot. Det är inte dubbletter:
 * att slå ihop dem vore att hitta på en huvudplats, och [[ADR-0041 Itemets
 * vy]] avvisar den uttryckligen. Noderna är därför värden och inte
 * identiteter — två noder med samma ULID är samma item på två ställen.
 *
 * Bara det panelen ritar och navigerar med bärs med: ULID:t, som är
 * identifieraren utåt ([[Datamodell – översikt]]), och namnet, som är
 * etiketten och sorteringsnyckeln. Löpnumret stannar i upplösningen —
 * det läcker aldrig ut, och en nod som bar det hade frestat en vy att
 * bygga en väg på det.
 */
final class ItemTreeNode
{
    /**
     * @param  list<ItemTreeNode>  $children  namnet stigande
     */
    public function __construct(
        private readonly string $ulid,
        private readonly string $name,
        private readonly array $children,
    ) {}

    public function ulid(): string
    {
        return $this->ulid;
    }

    /**
     * Namnet vyn ritar — och nyckeln varje nivå är sorterad på. Servern
     * sorterar och vyn sorterar aldrig om (issue 57a § Beslut 8).
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<ItemTreeNode>
     */
    public function children(): array
    {
        return $this->children;
    }
}
