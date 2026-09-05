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
 * De två storage-metoderna vaktar kontots urvalslista av bilagor
 * (App\Http\Controllers\Api\AccountStorageController): att SE listan och att
 * RENSA den. Båda kräver medlemskap i kontot (`account_user`), ingen
 * rollskillnad — samma linje som ContainerPolicy::isMemberOfOwnerAccount().
 * manageWebhooks() (issue 37a) är strängare: en webhook skickar ut kontots
 * data, så bara `owner` och `admin` får förvalta den.
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

    /**
     * Får användaren förvalta kontots webhooks — lista, registrera, ändra och
     * ta bort endpoints (issue 37a § Beslut 4)? Kräver `owner` eller `admin`,
     * strängare än medlemskapet i viewStorage()/manageStorage(). Skälet: en
     * webhook SKICKAR UT kontots data till en adress medlemmen väljer. En
     * `member` som lägger upp en endpoint mot sin egen server har byggt en
     * exfiltrationsväg ur varvskontot, och till skillnad från en
     * bilageradering ökar handlingen exponeringen i stället för att minska
     * den — se [[Konton och åtkomst]] § Behörighetsregler regel 4 och
     * klassdocblocken ovan för det motsatta fallet.
     */
    public function manageWebhooks(User $user, Account $account): bool
    {
        return $account->users()
            ->whereKey($user->id)
            ->wherePivotIn('role', ['owner', 'admin'])
            ->exists();
    }

    private function isMember(User $user, Account $account): bool
    {
        return $account->users()->whereKey($user->id)->exists();
    }
}
