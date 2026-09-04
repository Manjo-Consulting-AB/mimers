<?php

namespace App\Console;

use App\Models\Account;
use App\Models\Subscription;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kontolivscykeln, steg 12 och 15 månader — issue 29a. Se [[Planer och
 * kvoter]] § Kontolivscykel, [[ADR-0009 Kvoter och livscykel]] och
 * config/konton.php.
 *
 * Tre övergångar i en nattlig körning:
 *
 * - 15 månader utan aktivitet: kontot stängs (`closed`) med
 *   `read_only_reason = 'inactivity'` (Beslut 5). Bara statusraden ändras —
 *   data behålls, och ett `closed`-konto är fryst för skrivning, se
 *   App\Policies\ContainerPolicy.
 * - 12 månader, men ännu inte 15: kontot loggas som påminnelsepliktigt
 *   (Beslut 4). Ingenting skickas — kanalerna byggs i M5, vars issue anropar
 *   Account::scopeInactiveSince() för att hämta mängden. Steget är medvetet
 *   en halv implementation tills M5 finns.
 * - En medlem som gjort ett API-anrop inom gränsen öppnar ett stängt konto
 *   igen (Beslut 7): `active` med `read_only_reason = null`. Bara konton
 *   stängda för inaktivitet öppnas — ett `read_only`-konto (utebliven
 *   betalning) öppnas aldrig av att någon loggar in.
 *
 * Kontots aktivitet är den senaste aktiviteten bland dess medlemmar
 * (`MAX(user.last_active_at)` över account_user), härledd och aldrig lagrad
 * — se Beslut 1 och Account::scopeInactiveSince(). Ett konto utan medlemmar
 * har ingen aktivitet alls och räknas som inaktivt sedan `created_at`.
 *
 * En aktiv prenumeration undantar alltid (Beslut 6): status `active` eller
 * `past_due` skyddar mot både påminnelse och stängning. `cancelled`
 * undantar inte.
 *
 * Stängningen körs före påminnelsen: ett konto som passerat 15 månader ska
 * stängas, inte få en påminnelse och stängas i samma körning. När
 * påminnelsesteget sedan väljer bland `active`-konton är de redan borta ur
 * urvalet.
 *
 * Ett konto i taget, en transaktion per konto, och ett fel stoppar inte de
 * andra (Beslut 8). Urvalet görs med `chunkById` så att antalet
 * urvalsfrågor inte växer med antalet konton.
 *
 * Schemaläggs i routes/console.php med `Schedule::call(...)`, aldrig
 * `Schedule::command(...)` — se AGENTS.md § Driftmiljön saknar proc_open.
 * Klassens enda uppgift är att driva förloppet; den är en vanlig
 * closure-kallbar, testad direkt i tests/Feature/Konto/LivscykelTest.php.
 */
class AdvancesAccountLifecycle
{
    /**
     * Kör ett steg av kontolivscykeln: stänger förfallna konton, loggar
     * påminnelsepliktiga och öppnar konton vars medlemmar återvänt.
     */
    public function handle(): void
    {
        $closeCutoff = now()->subMonths((int) config('konton.inactivity_close_months'));
        $noticeCutoff = now()->subMonths((int) config('konton.inactivity_notice_months'));

        $this->closeInactiveAccounts($closeCutoff);
        $this->logNoticeDueAccounts($noticeCutoff);
        $this->reopenReturnedAccounts($closeCutoff);
    }

    /**
     * Steg 2: konton som passerat 15 månader stängs. Urvalet är exakt
     * (Beslut 5): `active`, ingen aktiv prenumeration, och inaktivt sedan
     * gränsen. Ett `read_only`-konto (betalning) flyttas aldrig till
     * `closed` — skälet i urvalet och i transaktionen nedan nämner båda
     * `status` med flit.
     */
    private function closeInactiveAccounts(Carbon $closeCutoff): void
    {
        $this->accountsToProcess(
            Account::query()
                ->where('status', 'active')
                ->whereDoesntHave('subscription', fn (Builder $q) => $q->whereIn('status', ['active', 'past_due']))
                ->inactiveSince($closeCutoff),
            fn (Account $account) => $this->closeAccount($account),
        );
    }

    /**
     * Steg 1: konton som passerat 12 månader loggas som påminnelsepliktiga.
     * Bara en loggrad (Beslut 4) — ingen bokföring av skickade påminnelser,
     * den historiken bär M5:s outbox (Beslut 3). Stängningen ovan har redan
     * lyft bort konton som passerat 15, så urvalet här är de som ligger i
     * fönstret 12–15 månader.
     */
    private function logNoticeDueAccounts(Carbon $noticeCutoff): void
    {
        $this->accountsToProcess(
            Account::query()
                ->where('status', 'active')
                ->whereDoesntHave('subscription', fn (Builder $q) => $q->whereIn('status', ['active', 'past_due']))
                ->inactiveSince($noticeCutoff),
            function (Account $account): void {
                Log::info('account.inactivity_notice_due', [
                    'account_ulid' => $account->ulid,
                    'inactive_since' => $this->inactiveSince($account)->toDateTimeString(),
                ]);
            },
        );
    }

    /**
     * Steg 3: ett `closed`-konto vars medlemmar gjort ett API-anrop inom
     * gränsen öppnas igen (Beslut 7). Utan det finns ingen väg tillbaka —
     * det finns ingen administratörsyta i MVP. Läsning är aldrig spärrad,
     * så en återvändande medlems anrop syns i `last_active_at`, och det är
     * den signalen som räcker. Bara `read_only_reason = 'inactivity'`
     * öppnas; ett konto fruset för utebliven betalning öppnas aldrig här.
     */
    private function reopenReturnedAccounts(Carbon $closeCutoff): void
    {
        $this->accountsToProcess(
            Account::query()
                ->where('status', 'closed')
                ->where('read_only_reason', 'inactivity')
                ->whereHas('users', fn (Builder $q) => $q->where('user.last_active_at', '>=', $closeCutoff)),
            fn (Account $account) => $this->reopenAccount($account),
        );
    }

    /**
     * Kör igenom ett urval konto för konto. `chunkById` och inte `chunk`:
     * urvalet förändras under iterationen (konton stängs och öppnas) och
     * pagingen måste följa primärnyckeln (Beslut 8). Ett fel på ett konto
     * loggas och stoppar inte de andra.
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
                    Log::error('account.lifecycle_failed', [
                        'account_ulid' => $account->ulid,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * Stänger ett konto. Allt i EN transaktion (Beslut 8): raden läses om
     * under radlåset — en current read, samma mönster som EnforcesDowngrades
     * — så ett konto som nedgraderats (eller stängts) sedan urvalet inte
     * rörs av misstag. Ändras tillståndet under oss är det inte längre den
     * här klassens sak.
     */
    private function closeAccount(Account $account): void
    {
        DB::transaction(function () use ($account): void {
            $row = Account::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->first();

            if ($row === null || $row->status !== 'active') {
                return;
            }

            $exempt = Subscription::query()
                ->where('account_id', $row->id)
                ->whereIn('status', ['active', 'past_due'])
                ->exists();

            if ($exempt) {
                return;
            }

            $row->status = 'closed';
            $row->read_only_reason = 'inactivity';
            $row->save();

            Log::info('account.closed', ['account_ulid' => $row->ulid]);
        });
    }

    /**
     * Öppnar ett stängt konto igen. Samma transaktionsmönster som
     * stängningen — raden läses om under lås och villkoren prövas på nytt.
     */
    private function reopenAccount(Account $account): void
    {
        DB::transaction(function () use ($account): void {
            $row = Account::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->first();

            if ($row === null || $row->status !== 'closed' || $row->read_only_reason !== 'inactivity') {
                return;
            }

            $row->status = 'active';
            $row->read_only_reason = null;
            $row->save();

            Log::info('account.reopened', ['account_ulid' => $row->ulid]);
        });
    }

    /**
     * Kontots aktivitetstidpunkt — samma härledning som scopet i Beslut 1:
     * senaste aktiviteten bland medlemmarna, med `created_at` som fallback
     * när det inte finns någon (MAX över noll rader ger NULL).
     */
    private function inactiveSince(Account $account): Carbon
    {
        $senaste = $account->users()->max('last_active_at');

        return Carbon::parse($senaste ?? $account->created_at);
    }
}
