<?php

namespace App\Console;

use App\Actions\Account\DeleteAccount;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\LegalHold;
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
 * `read_only_reason = 'inactivity'`, ingen prenumeration som bär sin plan och
 * inaktivt sedan gränsen — ett konto som aldrig passerat 29a:s stängning
 * raderas aldrig. Formuleringen av inaktivitet återanvänder
 * Account::scopeInactiveSince() utan att skrivas om.
 *
 * Innan något raderas prövas de villkor ADR-0009 kräver (Beslut 2–4), igen
 * under radlås i samma transaktion som raderingen:
 *
 * - En prenumeration som bär sin plan undantar alltid (Beslut 2) — se
 *   App\Models\Subscription::isCurrent(). Ett utgånget Pro gör det inte
 *   (issue 265 § Beslut 1), och kontot raderas som vilket gratiskonto som
 *   helst.
 * - En ägd container med aktiva medlemmar — en giltig container_access eller
 *   en obesvarad, icke utgången inbjudan — blockerar hela kontot (Beslut
 *   3–4). Ägarskapet ska erbjudas dem först, och det är M6 issue 39; tills
 *   dess är det enda korrekta svaret att låta kontot vara. Delvis radering
 *   är förbjuden: inte heller de containers som saknar medlemmar rörs.
 *
 * Beslut 5 — bilagor kontot betalar för i andras containers — spärrade
 * tidigare också (29b). Den spärren är BORTTAGEN (issue 143 · [[ADR-0045
 * Radering av konto och person]] § Beslut 1): innehållet i en främmande
 * container hindrar inte längre raderingen, för DeleteAccount flyttar
 * `billed_account_id` till containerns ägarkonto i stället. Samma sak gäller
 * författarkolumnerna mot kontot (ADR-0045 § Beslut 2), som nollställs av
 * samma action. Jobbet är fortfarande grinden och actionen verktyget — men
 * det finns inget kvar att spärra på.
 *
 * Den rättsliga spärren (issue 112) prövas först av alla, direkt efter
 * statuskontrollen: ett konto som är spärrat raderas inte, hur vilande det
 * än är. Kontrollen ligger i jobbet och inte i DeleteAccount — jobbet är
 * grinden, actionen är verktyget — och den ställs med LegalHold::covers(),
 * samma enda fråga som papperskorgens gallring ställer. Ett spärrat kontos
 * innehåll skyddas därmed på båda vägarna: det gallras inte ur
 * papperskorgen (PurgesExpiredTrash) och kontot försvinner inte med sitt
 * innehåll.
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
                ->whereDoesntHave('subscription', function (Builder $q): void {
                    /** @var Builder<Subscription> $q */
                    $q->current();
                })
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

            // Den rättsliga spärren (issue 112) — kontots innehåll är bevis
            // och får inte gallras medan en utredning pågår. Spärren är ett
            // beslut och inte ett fel, därför info och inte warning; raden i
            // legal_hold är själva spåret, loggen bara kvittot på att den fick
            // verkan i natt.
            if (LegalHold::covers($row)) {
                Log::info('account.deletion_blocked', [
                    'account_ulid' => $row->ulid,
                    'reason' => 'legal_hold',
                ]);

                return;
            }

            // Beslut 2 — undantaget läses under lås, som 29a gör: en betalning
            // som går igenom precis när nattjobbet kör ska inte radera kontot.
            // `current()` och inte `whereIn('status', ...)`: ett utgånget Pro
            // undantar inte (issue 265 § Beslut 1).
            $subscription = Subscription::query()
                ->where('account_id', $row->id)
                ->current()
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
}
