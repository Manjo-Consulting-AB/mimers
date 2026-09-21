<?php

namespace App\Support\Item;

/**
 * Strukturen i itemmockupens vänsterpanel: containerns items som användaren
 * når, med sina föräldrakanter och i namnets ordning — svaret från
 * App\Actions\Item\ResolveItemTree, se [[ADR-0041 Itemets vy]] § Beslut och
 * issue 94.
 *
 * Ett VÄRDEOBJEKT i app/Support/, som App\Support\Access\ItemScope: det
 * beskriver ett svar, det filtrerar ingen fråga. Rötterna är de items vars
 * samtliga föräldrar ligger utanför omfånget eller saknas, och ett item med
 * två föräldrar förekommer under båda som två noder — se ItemTreeNode.
 *
 * INGEN RÄKNARE BOR HÄR, och ingen får läggas till: svaret får inte avslöja
 * hur många items som filtrerats bort (issue 73 § Beslut 6, [[ADR-0028
 * Åtkomst på itemnivå]] § Konsekvenser). Ett tomt träd betyder "hon når
 * ingenting" — eller "containern är tom" — och de två går inte att skilja
 * åt, med flit.
 */
final class ItemTree
{
    /**
     * @param  list<ItemTreeNode>  $roots
     */
    private function __construct(private readonly array $roots) {}

    /**
     * @param  list<ItemTreeNode>  $roots
     */
    public static function of(array $roots): self
    {
        return new self($roots);
    }

    /**
     * Rötterna, namnet stigande — och varje nods barn i samma ordning.
     *
     * @return list<ItemTreeNode>
     */
    public function roots(): array
    {
        return $this->roots;
    }
}
