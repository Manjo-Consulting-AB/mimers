<?php

namespace App\Support\Usage;

use App\Models\User;

/**
 * Planen en mätrad räknas på: `guest` utan inloggning, annars planen på
 * personkontot (lägst id; annars användarens första konto) — samma val som
 * App\Support\Account\ActingAccount gör när ingen container styr. Läser bara
 * relationer som HandleInertiaRequests redan laddat.
 */
final class UsagePlan
{
    public const GUEST = 'guest';

    public static function code(?User $user): string
    {
        if ($user === null) {
            return self::GUEST;
        }

        $user->loadMissing('accounts.subscription.plan');

        $accounts = $user->accounts->sortBy('id');
        $account = $accounts->firstWhere('type', 'personal') ?? $accounts->first();

        return $account?->currentPlan()->code ?? 'unknown';
    }
}
