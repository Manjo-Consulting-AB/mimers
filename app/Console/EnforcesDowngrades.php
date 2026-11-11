<?php

namespace App\Console;

use App\Actions\Attachment\TrashAttachment;
use App\Actions\Trash\PurgeContent;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\LegalHold;
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
 * **Papperskorgen räknas i kvoten** ([[ADR-0009 Kvoter och livscykel]] §
 * Uppföljning 2026-11-11): en slängd bilaga ligger i `usage_counter` tills
 * den raderats fysiskt. Steg 4 går därför i två steg. Först töms det som
 * redan är slängt — äldst `deleted_at` först, genom PurgeContent::attachment()
 * — så länge räknaren ligger över gränsen. Räcker det inte slängs kontots
 * LEVANDE bilagor, nyast först, genom TrashAttachment tills de levande
 * ligger på eller under gränsen; räknaren sjunker inte av en slängning, så
 * loopen styrs av ett levande tal som räknas en gång och dras ned lokalt.
 * De nyslängda bilagorna ligger sedan i papperskorgen i 30 dagar och räknas
 * under tiden, så kontot kan vara `active` på gratisnivån och ändå ligga
 * över kvoten — uppladdningar är spärrade tills papperskorgen tömts. Det är
 * avsett. Målet är gratisplanens `storage_bytes` (Beslut 3), läst genom
 * planuppslaget — aldrig en konstant och aldrig kontots gamla plan.
 *
 * Förbrukningen i steg 1 läses ur `usage_counter`, inte som en egen SUM, och
 * läses om efter varje fysisk radering. Loopen slutar så snart räknaren
 * ligger lika med eller under gränsen, eller när bilagorna tar slut — den
 * snurrar aldrig.
 *
 * **Rättslig spärr:** täcks kontot av en spärr (LegalHold::covers) görs
 * ingenting — ingen radering, ingen statusändring. `downgrade.legal_hold`
 * loggas och kontot förblir `read_only`.
 *
 * Ett konto i taget, en transaktion per konto (Beslut 8): faller ett konto
 * rullas bara dess egen transaktion tillbaka, felet loggas och de andra
 * kontona blir klara. Nästa natt tar om det som fallerade.
 *
 * Schemaläggs i routes/console.php med `Schedule::call(...)`, aldrig
 * `Schedule::command(...)` — se AGENTS.md § Subprocesser i driftmiljön.
 * Klassens beroenden (TrashAttachment, PurgeContent) injiceras för att testerna ska
 * kunna låta en bilaga kasta — samma mönster som PurgesExpiredTrash.
 */
class EnforcesDowngrades
{
    public function __construct(
        private readonly TrashAttachment $trashAttachment,
        private readonly PurgeContent $purgeContent,
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

            // Rättslig spärr: varken radering eller statusändring. Kontot
            // förblir read_only och spärren syns i loggen.
            if (LegalHold::covers($row)) {
                Log::warning('downgrade.legal_hold', ['account_ulid' => $row->ulid]);

                return;
            }

            $removed = 0;
            $purged = 0;
            $bytesFreed = 0;
            $limit = $this->freeStorageLimit();

            // En gräns som är null betyder obegränsat (issue 25): det finns
            // inget att komma under, och ingenting att radera.
            if ($limit !== null) {
                // Steg 1: töm det som redan är slängt, äldst slängd först.
                // En fysisk radering minskar räknaren; finns inget slängt
                // kvar går vi vidare till de levande.
                while ($this->usedBytes($row->id) > $limit) {
                    $trashed = Attachment::onlyTrashed()
                        ->where('billed_account_id', $row->id)
                        ->orderBy('deleted_at')
                        ->orderBy('id')
                        ->first();

                    if ($trashed === null) {
                        break;
                    }

                    $this->purgeContent->attachment($trashed);
                    $purged++;
                }

                // Steg 2: släng levande bilagor, nyast först, tills de
                // LEVANDE ligger på eller under gränsen. Räknaren rör sig
                // inte av en slängning, så det levande talet räknas en gång
                // och dras ned lokalt.
                $liveBytes = (int) DB::table('attachment')
                    ->join('stored_file', 'stored_file.id', '=', 'attachment.stored_file_id')
                    ->where('attachment.billed_account_id', $row->id)
                    ->whereNull('attachment.deleted_at')
                    ->sum('stored_file.byte_size');

                while ($liveBytes > $limit) {
                    $attachment = Attachment::query()
                        ->where('billed_account_id', $row->id)
                        ->orderByDesc('created_at')
                        ->orderByDesc('id')
                        ->first();

                    if ($attachment === null) {
                        Log::warning('downgrade.attachments_exhausted', [
                            'account_ulid' => $row->ulid,
                            'used_bytes' => $this->usedBytes($row->id),
                            'limit_bytes' => $limit,
                        ]);

                        break;
                    }

                    $byteSize = (int) $attachment->storedFile()->value('byte_size');

                    if ($this->trashAttachment->handle($attachment)) {
                        $removed++;
                        $bytesFreed += $byteSize;
                    }

                    $liveBytes -= $byteSize;
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
                'purged' => $purged,
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
