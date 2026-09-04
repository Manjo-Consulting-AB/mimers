<?php

namespace App\Console;

use App\Actions\Account\DeleteAccount;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Subscription;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kontolivscykeln, sista steget — raderingen vid 18 månader (issue 29b).
 * Se [[Planer och kvoter]] § Kontolivscykel, [[ADR-0009 Kvoter och
 * livscykel]] och config/konton.php.
 *
 * 29a byggde påminnelsen och stängningen; det här jobbet tar bort konton
 * som stängts för inaktivitet och legat orörda i `inactivity_delete_months`.
 * Urvalet (issue 29b § Beslut 1) är exakt: `status = 'closed'`,
 * `read_only_reason = 'inactivity'`, ingen aktiv prenumeration och inaktivt
 * sedan gränsen — ett konto som aldrig passerat 29a:s stängning raderas
 * aldrig. Formuleringen av inaktivitet återanvänder
 * Account::scopeInactiveSince() utan att skrivas om.
 *
 * Innan något raderas prövas de villkor ADR-0009 kräver (Beslut 2–5), igen
 * under radlås i samma transaktion som raderingen:
 *
 * - Aktiv prenumeration undantar alltid (Beslut 2).
 * - En ägd container med aktiva medlemmar — en giltig container_access eller
 *   en obesvarad, icke utgången inbjudan — blockerar hela kontot (Beslut
 *   3–4). Ägarskapet ska erbjudas dem först, och det är M6 issue 39; tills
 *   dess är det enda korrekta svaret att låta kontot vara. Delvis radering
 *   är förbjuden: inte heller de containers som saknar medlemmar rörs.
 * - Bilagor kontot betalar för i andras containers blockerar (Beslut 5):
 *   de är kundens innehåll, och FK:n tillåter inte att kontot raderas medan
 *   de finns kvar. Radera aldrig i en främmande pärm — hoppa över kontot
 *   och lämna frågan om vad som ska hända med innehållet till [[Tankar]]
 *   (se Frågor och antaganden i PR:n för 29b).
 *
 * Ett konto i taget, en transaktion per konto, och ett fel stoppar inte de
 * andra (Beslut 8). Schemaläggs i routes/console.php med
 * `Schedule::call(...)`, aldrig `Schedule::command(...)` — se AGENTS.md §
 * Driftmiljön saknar proc_open.
 */
class DeletesDormantAccounts
{
    public function __construct(
        private readonly DeleteAccount $deleteAccount,
    ) {}

    /**
     * Kör ett steg av kontolivscykeln: raderar konton som passerat
     * arton månader utan aktivitet.
     */
    public function handle(): void
    {
        $deleteCutoff = now()->subMonths((int) config('konton.inactivity_delete_months'));

        $this->accountsToProcess(
            Account::query()
                ->where('status', 'closed')
                ->where('read_only_reason', 'inactivity')
                ->whereDoesntHave('subscription', fn (Builder $q) => $q->whereIn('status', ['active', 'past_due']))
                ->inactiveSince($deleteCutoff),
            fn (Account $account) => $this->deleteEligibleAccount($account),
        );
    }

    /**
     * Kör igenom ett urval konto för konto. `chunkById` och inte `chunk`:
     * urvalet förändras under iterationen (konton försvinner) och pagingen
     * måste följa primärnyckeln (Beslut 8). Ett fel på ett konto loggas och
     * stoppar inte de andra.
     *
     * @template TModel of Account
     *
     * @param  Builder<TModel>  $query
     * @param  Closure(TModel): void  $perAccount
     */
    private function accountsToProcess(Builder $query, Closure $perAccount): void
    {
        $query->chunkById(100, function ($accounts) use ($perAccount): void {
            foreach ($accounts as $account) {
                try {
                    $perAccount($account);
                } catch (Throwable $e) {
                    Log::error('account.deletion_failed', [
                        'account_ulid' => $account->ulid,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * Raderar ett konto om det fortfarande får raderas. Allt i EN transaktion
     * (Beslut 8): raden läses om under radlåset — en current read, samma
     * mönster som AdvancesAccountLifecycle — så ett konto som öppnats igen
     * eller nedgraderats sedan urvalet inte rörs av misstag. Varje spärr
     * prövas på nytt under låset; en kontroll som förlitar sig på att
     * urvalet gjorde rätt är ingen kontroll.
     */
    private function deleteEligibleAccount(Account $account): void
    {
        DB::transaction(function () use ($account): void {
            $row = Account::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->first();

            if ($row === null || $row->status !== 'closed' || $row->read_only_reason !== 'inactivity') {
                return;
            }

            // Beslut 2 — undantaget läses under lås, som 29a gör: en betalning
            // som går igenom precis när nattjobbet kör ska inte radera kontot.
            $subscription = Subscription::query()
                ->where('account_id', $row->id)
                ->whereIn('status', ['active', 'past_due'])
                ->lockForUpdate()
                ->first();

            if ($subscription !== null) {
                return;
            }

            // Beslut 3 och 4 — delade containers. Hoppas kontot över och
            // loggas; ingenting raderas, inte heller de containers som saknar
            // medlemmar.
            $blockedContainers = $this->ownedContainersWithActiveMembers($row);

            if ($blockedContainers->isNotEmpty()) {
                Log::warning('account.deletion_blocked', [
                    'account_ulid' => $row->ulid,
                    'reason' => 'shared_container',
                    'containers' => $blockedContainers->all(),
                ]);

                return;
            }

            // Beslut 5 — bilagor kontot betalar för i containers det inte äger.
            // De är kundens innehåll och kan inte raderas här, men FK:n
            // (`attachment.billed_account_id`, RESTRICT) hindrar kontoraderingen
            // medan de finns kvar. Radera ingenting; frågan om vad som ska hända
            // med dem hör hemma i [[Tankar]] (se Frågor och antaganden i PR:n
            // för 29b).
            if ($this->hasForeignBilledAttachment($row)) {
                Log::warning('account.deletion_blocked', [
                    'account_ulid' => $row->ulid,
                    'reason' => 'foreign_billed_attachments',
                ]);

                return;
            }

            $this->deleteAccount->handle($row);
        });
    }

    /**
     * ULID:erna för de containers kontot äger som har aktiva medlemmar —
     * minst en giltig container_access (ContainerAccess::scopeValid) eller en
     * obesvarad, icke utgången inbjudan. Samma räkning som delningstaket i
     * 27a § Beslut 5.
     *
     * Mjukraderade containers räknas också: en soft delete sätter bara
     * `deleted_at` på container-raden och återkallar inte container_access —
     * innehållet ligger kvar, oftast helt levande, tills gallringsjobbet tar
     * det. En mjukraderad container med aktiva medlemmar ska därför blockera
     * lika mycket som en levande: DeleteAccount tömmer den med withTrashed(),
     * och radering utan att ägarskapet erbjudits är precis vad
     * acceptanskriteriet förbjuder.
     *
     * @return Collection<int, string>
     */
    private function ownedContainersWithActiveMembers(Account $account): Collection
    {
        return Container::withTrashed()
            ->where('account_id', $account->id)
            ->where(function (Builder $query) {
                $query->whereHas('accesses', function (Builder $query) {
                    /** @var Builder<ContainerAccess> $query */
                    $query->valid();
                })->orWhereHas('invitations', function (Builder $query) {
                    $query->where('status', 'pending')->where('expires_at', '>', now());
                });
            })
            ->pluck('ulid');
    }

    /**
     * Har kontot lämnat en RESTRICT-referens i en container det inte äger: en
     * bilaga det betalar för (Beslut 5) — kundens innehåll i en främmande
     * pärm. Den kan inte raderas av den här raderingen men hindrar
     * account-raden från att försvinna. Svaret är att hoppa över kontot,
     * aldrig att städa i främmande pärmar.
     *
     * Under implementeringen hittades ytterligare två RESTRICT-referenser av
     * samma sort — `item.created_by_account_id` och
     * `schedule_occurrence.completed_by_account_id` — som inte står i Beslut
     * 5. De är noterade i Frågor och antaganden (PR:n för 29b) som en fråga
     * för [[Tankar]], inte implementerade här.
     */
    private function hasForeignBilledAttachment(Account $account): bool
    {
        return Attachment::withTrashed()
            ->where('billed_account_id', $account->id)
            ->whereHas('item', function (Builder $query) use ($account): void {
                /** @var Builder<Item> $query */
                $query->withTrashed()->whereHas('container', function (Builder $query) use ($account): void {
                    /** @var Builder<Container> $query */
                    $query->withTrashed()->where('account_id', '!=', $account->id);
                });
            })
            ->exists();
    }
}
