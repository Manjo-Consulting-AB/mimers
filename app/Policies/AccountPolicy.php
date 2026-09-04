<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

/**
 * Behörighet till själva kontot, se [[Konton och åtkomst]] § account och
 * issue 28 § Beslut 4. Den andra policyn i projektet — auto-upptäckt av
 * Laravel via namnkonventionen `App\Models\Account` →
 * `App\Policies\AccountPolicy`, ingen registrering behövs.
 *
 * De två metoderna vaktar kontots storage-yta
 * (App\Http\Controllers\Api\AccountStorageController): att SE urvalslistan
 * av bilagor och att RENSA dem. Båda kräver medlemskap i kontot
 * (`account_user`), ingen rollskillnad — samma linje som
 * ContainerPolicy::isMemberOfOwnerAccount(), som inte heller skiljer på
 * `owner`, `admin` och `member`.
 *
 * Ingen `read_only`-kontroll i någondera metoden — med flit. Att radera egna
 * bilagor för att komma under kvoten är samma sorts handling som regel 4:s
 * enda befintliga undantag (att återkalla en åtkomst): den MINSKAR
 * exponeringen i stället för att öka den, och utan den är nedgraderingens
 * steg 2 omöjligt — användaren ska välja själv, och hon kan inte välja om
 * systemet nekar varje skrivning. Se [[Konton och åtkomst]] §
 * Behörighetsregler regel 4.
 */
class AccountPolicy
{
    /**
     * Får användaren se kontots bilagelista (GET /storage)? Bara regel 1 —
     * medlemskap. Läsning påverkas aldrig av regel 4.
     */
    public function viewStorage(User $user, Account $account): bool
    {
        return $this->isMember($user, $account);
    }

    /**
     * Får användaren rensa kontots bilagor (DELETE /storage)? Bara regel 1 —
     * medlemskap, INGEN regel 4-kontroll. Ett `read_only`-konto får rensa
     * sina bilagor; det är hela poängen med ytan (issue 28 § Beslut 4).
     */
    public function manageStorage(User $user, Account $account): bool
    {
        return $this->isMember($user, $account);
    }

    private function isMember(User $user, Account $account): bool
    {
        return $account->users()->whereKey($user->id)->exists();
    }
}
