<?php

namespace App\Console;

use App\Actions\Plan\NotifyDowngradeWarning;
use App\Models\Account;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Varningarna en månad och en vecka före steg 4 — issue 1457. Se
 * [[Planer och kvoter]] § Nedgradering (*"Varningar går ut vid steg 1, samt
 * en månad och en vecka innan steg 4"*) och
 * App\Actions\Plan\NotifyDowngradeWarning.
 *
 * Steg 1-varningen skapas av StartDowngrade i samma transaktion som kontot
 * blir `read_only`; det här jobbet tar de två senare tillfällena. Fristen
 * löper med klockan och ingen rad ändras när den passerar en tröskel, så det
 * nattliga jobbet är den enda platsen att hitta kontona på.
 *
 * **Bara konton som fortfarande är nedgraderade.** Urvalet är samma som
 * EnforcesDowngrades': `read_only` med ett skäl i `payment_failed` och
 * `over_quota`. Ett konto som uppgraderat har lämnat `read_only`, och
 * uppgraderingen nollställer `grace_until` (GrantInternalPro,
 * RedeemVoucher), så det faller bort på båda villkoren och får inga fler
 * varningar. `inactivity` hör till kontolivscykeln och rörs aldrig här.
 *
 * **Ett konto utan frist får inga senare varningar.** `grace_until` sätts
 * bara när en prenumeration finns, och EnforcesDowngrades raderar aldrig ett
 * konto utan frist — det finns inget steg 4 att varna för.
 *
 * **Trösklarna är datum, inte intervall.** Varningen är due när `now()` nått
 * `grace_until` minus en månad respektive en vecka. Avdubblingen ligger i
 * NotifyDowngradeWarning, så en omkörning samma dygn skapar ingenting nytt,
 * och en missad körning tar igen sig nästa natt.
 *
 * Ett fel för ett konto stoppar inte de andra: varje konto ligger i ett eget
 * try/catch och ett fångat fel loggas som en varning.
 *
 * Schemaläggs i routes/console.php med `Schedule::call`, aldrig
 * `Schedule::command` — se AGENTS.md § Subprocesser i driftmiljön.
 */
class GeneratesDowngradeWarnings
{
    /** @var list<string> */
    private const REASONS = ['payment_failed', 'over_quota'];

    public function handle(): void
    {
        Account::query()
            ->where('status', 'read_only')
            ->whereIn('read_only_reason', self::REASONS)
            ->with('subscription')
            ->chunkById(100, function ($accounts): void {
                foreach ($accounts as $account) {
                    try {
                        $this->warnIfDue($account);
                    } catch (Throwable $e) {
                        Log::warning('downgrade_warning.generation_failed', [
                            'account_ulid' => $account->ulid,
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            }, 'account.id', 'id');
    }

    /**
     * Skickar de varningar vars tillfälle passerats. Båda kan vara due i
     * samma körning om jobbet legat nere över en tröskel — då skickas båda,
     * eftersom varje tillfälle ska ha sin varning.
     */
    private function warnIfDue(Account $account): void
    {
        $subscription = $account->subscription;

        if (! $subscription instanceof Subscription || $subscription->grace_until === null) {
            return;
        }

        $graceUntil = $subscription->grace_until;

        // Steg 4 har tagit vid (eller gör det i natt): då är det
        // EnforcesDowngrades som äger kontot, inte en varning.
        if (! $graceUntil->isFuture()) {
            return;
        }

        $action = app(NotifyDowngradeWarning::class);

        // `subMonthNoOverflow`: fristen är satt med addMonths(3) och kan
        // ligga på en månadsdag som inte finns i februari. En vanlig
        // subtraktion hade då spillt över till mars och flyttat tröskeln.
        if (now()->greaterThanOrEqualTo($graceUntil->copy()->subMonthNoOverflow())) {
            $action->handle($account, $subscription, NotifyDowngradeWarning::STAGE_MONTH);
        }

        if (now()->greaterThanOrEqualTo($graceUntil->copy()->subWeek())) {
            $action->handle($account, $subscription, NotifyDowngradeWarning::STAGE_WEEK);
        }
    }
}
