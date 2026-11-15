<?php

namespace App\Policies;

use App\Models\Container;
use App\Models\User;

/**
 * Behörighet till en person — i dag bara hennes profilbild, se
 * [[ADR-0076 Profilbilden]] § 4 och [[M59 Profilbilden]] § 379.
 *
 * Auto-upptäckt av Laravel via namnkonventionen `App\Models\User` →
 * `App\Policies\UserPolicy`, ingen registrering behövs — samma form som
 * App\Policies\ContainerPolicy.
 *
 * **Synligheten är skriven för mer än profilbilden.** ADR-0076 § 4 säger att
 * bilden får ses av personen själv och av den som delar minst ett konto eller
 * en container med henne, och att regeln ska täcka inbjudningar, uppladdare
 * och skapare utan att ändras. Därför är frågan "delar de här två personerna
 * en yta?" och inte "får den här personen se den här bilden?" — vilket gör
 * metoden återanvändbar när bilden senare ritas vid en inbjudan eller bredvid
 * ett item.
 *
 * **Frågan om en gemensam container ställs med samma fråga som resten av
 * systemet**: App\Models\Container::scopeAccessibleBy(), som är
 * App\Policies\ContainerPolicy::view() i frågeform — kontomedlemskap eller en
 * giltig `container_access` på container- eller itemnivå, med inboxens
 * undantag. Att formulera om den regeln här hade varit en andra sanning om
 * samma sak; nu kan de två inte glida isär. Se [[ADR-0028 Åtkomst på
 * itemnivå]] och [[Konton och åtkomst]] § Behörighetsregler.
 *
 * Ingen `ContainerPolicy::view()` per container: den hade blivit en fråga per
 * gemensam kandidat. `accessibleBy()` svarar på mängden i en fråga, och
 * skärningen görs i databasen.
 */
class UserPolicy
{
    /**
     * Får `$viewer` se `$owner`s profilbild?
     *
     * Sant när de är samma person, när de är medlemmar i samma konto, eller
     * när båda når minst en gemensam container — genom kontomedlemskap eller
     * en grant på container- eller itemnivå ([[ADR-0076 Profilbilden]] § 4).
     *
     * Medlemskapet prövas mot kontona och inte mot en container: ett konto
     * kan sakna containrar och ändå vara den yta två personer delar. Det är
     * därför den grenen står före containerfrågan.
     */
    public function viewAvatar(User $viewer, User $owner): bool
    {
        if ((int) $viewer->id === (int) $owner->id) {
            return true;
        }

        $viewerAccountIds = $viewer->accounts
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $ownerAccountIds = $owner->accounts
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if (array_intersect($viewerAccountIds, $ownerAccountIds) !== []) {
            return true;
        }

        // containrarna ÄGAREN når, som en delställning i samma villkor.
        $ownerContainers = Container::query()
            ->accessibleBy($owner, $ownerAccountIds)
            ->select('container.id');

        return Container::query()
            ->accessibleBy($viewer, $viewerAccountIds)
            ->whereIn('container.id', $ownerContainers)
            ->exists();
    }
}
