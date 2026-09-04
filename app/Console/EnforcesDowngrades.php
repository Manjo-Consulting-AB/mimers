<?php

namespace App\Console;

use App\Actions\Attachment\TrashAttachment;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verkställer nedgraderingen när fristen gått ut — issue 28, steg 3–5. Se
 * [[Planer och kvoter]] § Nedgradering och [[ADR-0009 Kvoter och livscykel]].
 *
 * Steg 1 och 2 byggdes i 28a (StartDowngrade sätter `read_only` och
 * `grace_until`, urvalslistan och rensningen av ett fruset konto). Den här
 * klassen kör förloppet i mål: bilagor raderas automatiskt, nyast först,
 * tills kontot ligger under gratisplanens gräns, och kontot återgår till
 * `active` på gratisnivån. Items raderas aldrig — bara bilagor, det är hela
 * ADR-0009 (Beslut 5).
 *
 * Urvalet är exakt (Beslut 2): `status = 'read_only'`, `read_only_reason`
 * i `payment_failed`/`over_quota`, och en `grace_until` som passerat.
 * `inactivity` hör till kontolivscykeln (29a/29b) och rörs aldrig här. Ett
 * konto utan `grace_until` — ingen prenumeration, eller en prenumeration där
 * någon satt null för hand — raderas aldrig: fristen finns inte, så det finns
 * inget slutdatum att respektera. Kontot loggas som `downgrade.no_grace_deadline`
 * och lämnas orört; det är ett tillstånd som bara kan ha uppstått för hand och
 * det ska synas.
 *
 * Raderingen går genom App\Actions\Attachment\TrashAttachment (Beslut 4):
 * mjukradering plus förbrukningsminskning i en transaktion, samma väg och
 * samma skyddsnät som varje annan radering i systemet. Bilagorna hamnar i
 * papperskorgen och gallras 30 dagar senare av issue 20b. Målet är
 * gratisplanens `storage_bytes` (Beslut 3), läst genom planuppslaget — aldrig
 * en konstant och aldrig kontots gamla plan.
 *
 * Förbrukningen läses ur `usage_counter`, inte som en egen SUM, och läses om
 * efter varje radering — räknaren är sanningen kontrollerna använder (26a,
 * 27b) och TrashAttachment minskar den i sin egen transaktion. Loopen slutar
 * så snart räknaren ligger lika med eller under gränsen, eller när bilagorna
 * tar slut (räknaren kan vara felställd och bytena ligga någon annanstans) —
 * den snurrar aldrig.
 *
 * Ett konto i taget, en transaktion per konto (Beslut 8): faller ett konto
 * rullas bara dess egen transaktion tillbaka, felet loggas och de andra
 * kontona blir klara. Nästa natt tar om det som fallerade.
 *
 * Schemaläggs i routes/console.php med `Schedule::call(...)`, aldrig
 * `Schedule::command(...)` — se AGENTS.md § Driftmiljön saknar proc_open.
 * Klassens enda beroende (TrashAttachment) injiceras för att testerna ska
 * kunna låta en bilaga kasta — samma mönster som PurgesExpiredTrash.
 */
class EnforcesDowngrades
{
    public function __construct(
        private readonly TrashAttachment $trashAttachment,
    ) {}

    /**
     * Verkställer nedgraderingen för vartenda konto vars frist gått ut.
     */
    public function handle(): void
    {
        $accounts = Account::query()
            ->where('status', 'read_only')
            ->whereIn('read_only_reason', ['payment_failed', 'over_quota'])
            ->get();

        foreach ($accounts as $account) {
            try {
                $this->enforceAccount($account);
            } catch (Throwable $e) {
                Log::error('downgrade.enforce_failed', [
                    'account_ulid' => $account->ulid,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Nedgraderingen av ett konto: radera bilagor till gratisgränsen och
     * återgå till active. Allt i EN transaktion (Beslut 8) — ett konto som
     * faller rullas tillbaka helt och tas om nästa natt.
     */
    private function enforceAccount(Account $account): void
    {
        DB::transaction(function () use ($account): void {
            $row = Account::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->first();

            // Raden läses om under radlåset — en current read, samma mönster
            // som StartDowngrade och TrashAttachment. Har kontot ändrats
            // sedan urvalet är det inte längre den här klassens sak.
            if ($row === null
                || $row->status !== 'read_only'
                || ! in_array($row->read_only_reason, ['payment_failed', 'over_quota'], true)) {
                return;
            }

            $subscription = Subscription::query()
                ->where('account_id', $row->id)
                ->lockForUpdate()
                ->first();

            if ($subscription === null || $subscription->grace_until === null) {
                Log::warning('downgrade.no_grace_deadline', ['account_ulid' => $row->ulid]);

                return;
            }

            if ($subscription->grace_until->isFuture()) {
                return;
            }

            $removed = 0;
            $bytesFreed = 0;
            $limit = $this->freeStorageLimit();

            // En gräns som är null betyder obegränsat (issue 25): det finns
            // inget att komma under, och ingenting att radera.
            while ($limit !== null && $this->usedBytes($row->id) > $limit) {
                $attachment = Attachment::query()
                    ->where('billed_account_id', $row->id)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->first();

                if ($attachment === null) {
                    break;
                }

                $byteSize = (int) $attachment->storedFile()->value('byte_size');

                if ($this->trashAttachment->handle($attachment)) {
                    $removed++;
                    $bytesFreed += $byteSize;
                }
            }

            // Steg 5 (Beslut 6): kontot återgår till active på gratisnivån.
            // `cancelled` på prenumerationen får planuppslaget att falla
            // tillbaka på free; plan_id rörs inte, historiken står kvar.
            $row->status = 'active';
            $row->read_only_reason = null;
            $row->save();

            $subscription->status = 'cancelled';
            $subscription->grace_until = null;
            $subscription->save();

            Log::info('downgrade.enforced', [
                'account_ulid' => $row->ulid,
                'removed' => $removed,
                'bytes_freed' => $bytesFreed,
            ]);
        });
    }

    /**
     * Kontots förbrukning — alltid räknarens tal, aldrig en egen SUM
     * (issue 28 § Att se upp med). Saknas raden är förbrukningen noll.
     */
    private function usedBytes(int $accountId): int
    {
        return (int) UsageCounter::query()
            ->where('account_id', $accountId)
            ->value('storage_bytes');
    }

    /**
     * Gratisplanens lagringstak, läst genom planuppslaget (issue 25) —
     * Beslut 3: målet är gratisplanens gräns, inte kontots gamla plan.
     */
    private function freeStorageLimit(): ?int
    {
        $limit = Plan::query()->where('code', 'free')->firstOrFail()->planLimit('storage_bytes');

        return $limit === null ? null : (int) $limit;
    }
}
