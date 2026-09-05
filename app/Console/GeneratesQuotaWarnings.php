<?php

namespace App\Console;

use App\Actions\Notification\CreateNotification;
use App\Models\Account;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kvotvarningarna — issue 34b. Konton som förbrukat 80 % eller 100 % av
 * lagringsgränsen får en notis per ägare och administratör; själva
 * leveransen äger 34a och mallarna 32a. Se [[Notiser]] § Kön och
 * [[Planer och kvoter]] § usage_counter.
 *
 * Källan är `usage_counter.storage_bytes` (Beslut 7) — aldrig en omräkning
 * av summorna här; 26b:s nattliga avstämning äger det talet, och jobbet
 * schemaläggs efter den i routes/console.php så varningen bygger på ett
 * rättat värde. Ett konto utan räknarrad har noll förbrukning och varnas
 * inte (issue 27a § Beslut 4).
 *
 * Trösklarna bor i config/notiser.php § quota (Beslut 7). Bara den HÖGSTA
 * tröskel som slagits i skickas per körning: ett konto på 105 % får en
 * varning med `percent` 100, inte två. En `null`-gräns betyder obegränsat
 * och hoppas över INNAN någon jämförelse — `$used >= $limit` med null-limit
 * vore sant i PHP och skulle ge varje sådant konto en varning varje dag
 * (issue 27a § Att se upp med).
 *
 * `dedupe_key` bär konto, MOTTAGARE, percent och månad: varningen upprepas
 * en gång i månaden per mottagare i stället för en enda gång någonsin —
 * [[Planer och kvoter]] § Kontolivscykel kräver uttryckligen fler än en
 * varning. Mottagarens ULID avviker från Beslut 5-tabellen (som visar
 * `quota.warning:{account}:{percent}:{YYYY-MM}`): utan den skulle alla
 * owner/admin på kontot dela samma nyckel och bara den första få en rad —
 * Beslut 7 gör dem alla till mottagare, och en notisrad bär en mottagare.
 *
 * Ett fel för ett konto stoppar inte de andra (Beslut 9): varje konto ligger
 * i ett eget try/catch och ett fångat fel loggas som en varning.
 *
 * Schemaläggs i routes/console.php med `Schedule::call`, aldrig
 * `Schedule::command` — se AGENTS.md § Driftmiljön saknar proc_open.
 */
class GeneratesQuotaWarnings
{
    /**
     * Skapar kvotvarningar för konton som passerat en tröskel.
     */
    public function handle(): void
    {
        Account::query()
            ->with(['subscription.plan'])
            ->leftJoin('usage_counter', 'usage_counter.account_id', '=', 'account.id')
            ->select('account.*', DB::raw('COALESCE(usage_counter.storage_bytes, 0) AS used_bytes'))
            ->chunkById(100, function ($accounts): void {
                foreach ($accounts as $account) {
                    try {
                        $this->warnIfOverThreshold($account);
                    } catch (Throwable $e) {
                        Log::warning('quota_warning.generation_failed', [
                            'account_ulid' => $account->ulid,
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            }, 'account.id', 'id');
    }

    /**
     * Varnar ägare och administratörer på ett konto vars förbrukning passerat
     * en tröskel. En `member` kan inte uppgradera planen och kan inte göra
     * något åt varningen, så den får ingen (Beslut 7).
     */
    private function warnIfOverThreshold(Account $account): void
    {
        $used = (int) ($account->used_bytes ?? 0);

        if ($used < 1) {
            return;
        }

        // planLimit() kastar på en okänd nyckel (issue 25 § Beslut 7) — men
        // `storage_bytes` finns i båda planerna. `null` betyder obegränsat.
        $limit = $account->planLimit('storage_bytes');

        if (! is_int($limit) || $limit < 1) {
            return;
        }

        $percent = $this->highestThresholdHit($used, $limit);

        if ($percent === null) {
            return;
        }

        $month = now()->format('Y-m');

        $recipients = $account->users()->wherePivotIn('role', ['owner', 'admin'])->get();

        foreach ($recipients as $recipient) {
            app(CreateNotification::class)->handle(
                type: Notification::TYPE_QUOTA_WARNING,
                account: $account,
                user: $recipient,
                subject: $account,
                payload: [
                    'percent' => $percent,
                    'used_bytes' => $used,
                    'limit_bytes' => $limit,
                ],
                dedupeKey: "quota.warning:{$account->ulid}:{$recipient->ulid}:{$percent}:{$month}",
            );
        }
    }

    /**
     * Den högsta tröskel ur config/notiser.php vars andel av gränsen
     * förbrukningen passerat, annars null. Heltalsjämförelsen (`$used * 100`)
     * undviker flyttalsavrundning på stora BIGINT-värden.
     *
     * @return int|null tröskeln i procent, eller null om ingen slagits i.
     */
    private function highestThresholdHit(int $used, int $limit): ?int
    {
        $thresholds = collect(config('notiser.quota.warning_thresholds', []))
            ->map(fn ($threshold): int => (int) $threshold)
            ->sortDesc()
            ->values();

        foreach ($thresholds as $threshold) {
            if ($used * 100 >= $limit * $threshold) {
                return $threshold;
            }
        }

        return null;
    }
}
