<?php

namespace App\Actions\Account;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Auth\TwoFactorRequirement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Slår kontots tvåfaktorkrav på eller av — gäller alla kontots containrar,
 * se [[ADR-0092 Tvåfaktor som krav för delade containrar]] och issue 450.
 *
 * Grinden är `AccountPolicy::update` (ägare eller admin, ej fruset konto).
 * Att slå PÅ kräver att den som gör det har bekräftad tvåfaktor
 * (`two_factor.owner_not_enrolled`); att slå AV kräver ingenting. Ändras inget
 * skrivs ingen rad.
 */
class SetAccountRequireTwoFactor
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    public function handle(Account $account, User $actor, bool $require): Account
    {
        Gate::forUser($actor)->authorize('update', $account);

        if ($require) {
            TwoFactorRequirement::assertOwnerEnrolled($actor);
        }

        return DB::transaction(function () use ($account, $actor, $require): Account {
            $row = Account::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            if ((bool) $row->require_two_factor === $require) {
                return $account;
            }

            $row->require_two_factor = $require;
            $row->save();

            $this->recordAuditEvent->handle(
                action: $require
                    ? AuditLog::ACTION_ACCOUNT_TWO_FACTOR_REQUIRED
                    : AuditLog::ACTION_ACCOUNT_TWO_FACTOR_RELEASED,
                account: $row,
                user: $actor,
                subjectType: 'account',
                subjectUlid: $row->ulid,
            );

            return $account->refresh();
        });
    }
}
