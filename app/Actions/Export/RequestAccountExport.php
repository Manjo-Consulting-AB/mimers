<?php

namespace App\Actions\Export;

use App\Actions\Security\RecordSecurityEvent;
use App\Exceptions\Api\ApiException;
use App\Jobs\BuildAccountExport;
use App\Models\Account;
use App\Models\Export;
use App\Models\SecurityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Beställer en kontoexport åt personen själv — issue 1702 ·
 * [[ADR-0098 Kontoexporten]]. Delas av webbens och `/api`:s kontroller så att
 * "högst en pågående export" bara är formulerad en gång.
 *
 * **Objektet är personens eget personkonto** (owner på ett `personal`-konto,
 * som profilsidan). Ingen ruttparameter pekar ut ett annat konto.
 *
 * Dubblettspärren och raden ligger i en transaktion med kontoraden låst, av
 * samma skäl som containerexportens: två samtidiga beställningar måste köa så
 * att bara den första skapar en `pending`-rad. Säkerhetsraden skrivs och jobbet
 * köas EFTER transaktionen — en dubblettspärrad beställning har inte hänt.
 */
class RequestAccountExport
{
    public function __construct(private readonly RecordSecurityEvent $recordSecurityEvent) {}

    /**
     * @throws ApiException `account_export.in_progress` (409) eller
     *                      `account_export.no_account` (404)
     */
    public function handle(User $user, ?string $ip = null, ?string $userAgent = null): Export
    {
        $account = $this->personalAccount($user);

        $export = DB::transaction(function () use ($user, $account): Export {
            Account::whereKey($account->id)->lockForUpdate()->first();

            $existing = Export::query()
                ->where('kind', Export::KIND_ACCOUNT)
                ->where('account_id', $account->id)
                ->whereIn('status', [Export::STATUS_PENDING, Export::STATUS_RUNNING])
                ->first();

            if ($existing instanceof Export) {
                throw ApiException::make('account_export.in_progress', ['export' => $existing->ulid], 409);
            }

            $export = new Export;
            $export->container_id = null;
            $export->account_id = $account->id;
            $export->requested_by_user_id = $user->id;
            $export->kind = Export::KIND_ACCOUNT;
            $export->status = Export::STATUS_PENDING;
            $export->save();

            return $export;
        });

        $this->recordSecurityEvent->handle(
            action: SecurityLog::ACTION_ACCOUNT_EXPORT_REQUESTED,
            account: $account,
            user: $user,
            ip: $ip,
            userAgent: $userAgent,
            meta: ['export' => $export->ulid],
        );

        BuildAccountExport::dispatch($export);

        return $export;
    }

    /**
     * Personkontot: kontot där personen är `owner` och `type` är `personal`.
     */
    public static function personalAccountFor(User $user): ?Account
    {
        return $user->accounts()
            ->where('account.type', 'personal')
            ->wherePivot('role', 'owner')
            ->first();
    }

    private function personalAccount(User $user): Account
    {
        return self::personalAccountFor($user)
            ?? throw ApiException::make('account_export.no_account', [], 404);
    }
}
