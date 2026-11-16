<?php

namespace App\Actions\Access;

use App\Actions\Usage\TransferBilling;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use Illuminate\Support\Facades\DB;

/**
 * Regeln i [[ADR-0081 All lagrad data räknas mot kvoten]] § 4 (issue 1305):
 * förlorar ett konto sin sista GILTIGA åtkomst till en container får
 * containerns ägare betala för det kontot betalade för där.
 *
 * Kontot X har åtkomst till C om någon giltig åtkomst
 * (`ContainerAccess::scopeValid`) i C, på container- eller itemnivå, har X
 * som grantee, eller har en person som grantee vars personkonto är X. Bara
 * personkonton nås genom en persongrant: en medlem i ett företagskonto
 * betalar via ägarkontot (ActingAccount), inte via sin personliga åtkomst.
 *
 * Delad av RevokeContainerAccess (direkt vid återkallelse) och
 * App\Console\TransfersOrphanedBilling (nattens svep för det som gått ut).
 * Anroparen står i en transaktion. Ingen kvotprövning, inget raderas.
 */
class ReleaseOrphanedBilling
{
    public function __construct(private readonly TransferBilling $transferBilling) {}

    public function hasAccess(Account $account, Container $container): bool
    {
        return ContainerAccess::query()
            ->where('container_id', $container->id)
            ->valid()
            ->where(function ($q) use ($account) {
                $q->where(fn ($a) => $a->where('grantee_type', 'account')->where('grantee_id', $account->id));

                if ($account->type === 'personal') {
                    // Personens åtkomst via en kontogrant på ett konto hen är
                    // medlem i (t.ex. ett företag) räknas också.
                    $q->orWhere(fn ($a) => $a->where('grantee_type', 'account')->whereIn(
                        'grantee_id',
                        DB::table('account_user')->whereIn(
                            'user_id',
                            DB::table('account_user')->where('account_id', $account->id)->select('user_id'),
                        )->select('account_id'),
                    ));

                    $q->orWhere(fn ($u) => $u->where('grantee_type', 'user')->whereIn(
                        'grantee_id',
                        DB::table('account_user')->where('account_id', $account->id)->select('user_id'),
                    ));
                }
            })
            ->exists();
    }

    /**
     * Flyttar kontots betalning i containern till ägaren om kontot inte
     * längre har någon giltig åtkomst dit.
     *
     * @return bool Sant om en flytt gjordes.
     */
    public function handle(Account $account, Container $container): bool
    {
        if ($account->id === $container->account_id || $this->hasAccess($account, $container)) {
            return false;
        }

        $this->transferBilling->forContainer($account, $container);

        return true;
    }
}
