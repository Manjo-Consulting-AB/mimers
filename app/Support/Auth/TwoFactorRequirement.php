<?php

namespace App\Support\Auth;

use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Frågorna kring tvåfaktorkravet för delade containrar, se [[ADR-0092
 * Tvåfaktor som krav för delade containrar]] och issue 449–450.
 *
 * Själva flaggan och låsningen bor i Container::requiresTwoFactor(),
 * Container::scopeAccessibleBy() och ResolveItemScope. Den här klassen svarar
 * på de tre frågor ägaren ställer *innan* hon rör flaggan eller sin egen
 * tvåfaktor:
 *
 *  1. Vilka mottagare förlorar åtkomsten om kravet slås på? ({@see lacking()})
 *  2. Har ägaren själv tvåfaktor? ({@see assertOwnerEnrolled()})
 *  3. Får ägaren stänga av sin tvåfaktor? ({@see assertMayDisable()})
 *
 * Ägarkontots medlemmar räknas aldrig som mottagare: de når containern genom
 * medlemskapet (regel 1) och berörs inte av kravet.
 */
final class TwoFactorRequirement
{
    /**
     * Mottagarna med en giltig åtkomst — direkt, via ett konto eller bara
     * till ett item — i `$containers` som saknar bekräftad tvåfaktor.
     * Ägarkontots egna medlemmar utelämnas.
     *
     * @param  Collection<int, Container>  $containers
     * @return Collection<int, User>
     */
    public static function lacking(Collection $containers): Collection
    {
        if ($containers->isEmpty()) {
            return collect();
        }

        $ownerAccountOf = $containers->pluck('account_id', 'id');

        $accesses = ContainerAccess::query()
            ->valid()
            ->whereIn('container_id', $containers->pluck('id')->all())
            ->get(['container_id', 'grantee_type', 'grantee_id']);

        $accountIds = $accesses->where('grantee_type', 'account')->pluck('grantee_id')->unique()->all();

        $membersOf = $accountIds === []
            ? collect()
            : User::query()
                ->join('account_user', 'account_user.user_id', '=', 'user.id')
                ->whereIn('account_user.account_id', $accountIds)
                ->get(['user.id', 'account_user.account_id as via_account_id'])
                ->groupBy('via_account_id');

        $ownerMembers = User::query()
            ->join('account_user', 'account_user.user_id', '=', 'user.id')
            ->whereIn('account_user.account_id', $ownerAccountOf->unique()->all())
            ->get(['user.id', 'account_user.account_id as via_account_id'])
            ->groupBy('via_account_id');

        $userIds = [];

        foreach ($accesses as $access) {
            $candidates = $access->grantee_type === 'user'
                ? [$access->grantee_id]
                : $membersOf->get($access->grantee_id, collect())->pluck('id')->all();

            $owners = $ownerMembers->get($ownerAccountOf[$access->container_id], collect())->pluck('id')->all();

            foreach (array_diff($candidates, $owners) as $id) {
                $userIds[$id] = $id;
            }
        }

        if ($userIds === []) {
            return collect();
        }

        return User::query()
            ->whereIn('id', array_values($userIds))
            ->whereNull('totp_confirmed_at')
            ->orderBy('name')
            ->get();
    }

    /**
     * ULID:erna på containerns giltiga åtkomster vars mottagare saknar
     * tvåfaktor — det delningslistan märker. En kontoåtkomst räknas när någon
     * av kontots medlemmar saknar den. Ägarkontots medlemmar utelämnas.
     *
     * @return list<string>
     */
    public static function lackingAccessUlids(Container $container): array
    {
        $accesses = ContainerAccess::query()->valid()->where('container_id', $container->id)->get();

        if ($accesses->isEmpty()) {
            return [];
        }

        $accountIds = $accesses->where('grantee_type', 'account')->pluck('grantee_id')->unique()->all();

        $members = $accountIds === []
            ? collect()
            : DB::table('account_user')->whereIn('account_id', $accountIds)->get(['account_id', 'user_id'])->groupBy('account_id');

        $ownerIds = DB::table('account_user')->where('account_id', $container->account_id)->pluck('user_id')->all();

        $candidatesOf = fn (ContainerAccess $access): array => array_diff(
            $access->grantee_type === 'user'
                ? [$access->grantee_id]
                : $members->get($access->grantee_id, collect())->pluck('user_id')->all(),
            $ownerIds,
        );

        $allIds = $accesses->flatMap($candidatesOf)->unique()->values()->all();

        $lackingIds = $allIds === []
            ? []
            : User::query()->whereIn('id', $allIds)->whereNull('totp_confirmed_at')->pluck('id')->all();

        return $accesses
            ->filter(fn (ContainerAccess $access): bool => array_intersect($candidatesOf($access), $lackingIds) !== [])
            ->pluck('ulid')
            ->values()
            ->all();
    }

    /**
     * Mottagarna utan tvåfaktor i en enda container, som en lista som vyn kan
     * märka rader med.
     *
     * @return Collection<int, User>
     */
    public static function lackingIn(Container $container): Collection
    {
        return self::lacking(collect([$container]));
    }

    /**
     * Mottagarna utan tvåfaktor i alla kontots containrar (kontonivån).
     *
     * @return Collection<int, User>
     */
    public static function lackingInAccount(Account $account): Collection
    {
        return self::lacking(Container::query()->where('account_id', $account->id)->get());
    }

    /**
     * Servern prövar att den som slår på kravet själv har tvåfaktor.
     */
    public static function assertOwnerEnrolled(User $owner): void
    {
        if (! $owner->hasConfirmedTwoFactor()) {
            throw ApiException::make('two_factor.owner_not_enrolled');
        }
    }

    /**
     * Antalet containrar i `$user`s konton som kräver tvåfaktor, och om
     * kontokravet gäller. Kontokravet räknar alla kontots containrar.
     *
     * @return array{account: bool, containers: int}
     */
    public static function requirementsOf(User $user): array
    {
        $accountIds = $user->accounts()->pluck('account.id')->all();

        if ($accountIds === []) {
            return ['account' => false, 'containers' => 0];
        }

        $account = Account::query()->whereIn('id', $accountIds)->where('require_two_factor', true)->exists();

        $containers = Container::query()
            ->whereIn('account_id', $accountIds)
            ->when(! $account, fn ($query) => $query->where('require_two_factor', true))
            ->count();

        return ['account' => $account, 'containers' => $containers];
    }

    /**
     * Ägaren får inte stänga av sin tvåfaktor medan ett krav gäller — då
     * skulle hon själv gå förbi det hon krävt av andra.
     */
    public static function assertMayDisable(User $user): void
    {
        $requirements = self::requirementsOf($user);

        if ($requirements['account'] || $requirements['containers'] > 0) {
            throw ApiException::make('two_factor.required_by_sharing', ['containers' => $requirements['containers']]);
        }
    }
}
