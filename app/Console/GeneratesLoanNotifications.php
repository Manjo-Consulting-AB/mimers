<?php

namespace App\Console;

use App\Actions\Notification\CreateNotification;
use App\Models\Account;
use App\Models\Loan;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Utlåningsnotiserna — issue 76. Öppna utlåningar vars förfallodatum närmar
 * sig skapar en `loan.due` per mottagare; själva leveransen äger 34a och
 * mallarna 32a. Se [[Notiser]] § Kön och § notification, [[Items och
 * organisation]] § loan och [[ADR-0017 Missbruksvektorer]] § 7.
 *
 * Regeln hela jobbet finns för: systemet mejlar ALDRIG låntagaren.
 * `borrower_email` är en kontaktuppgift i vyn, inte en mottagaradress, och
 * payloaden bär `borrower_name` — aldrig adressen (Beslut 5). En
 * återkommande utskicksström till en overifierad adress är värre än
 * inbjudningarna i ADR-0017 § 5, för en påminnelse fortsätter tills någon
 * bockar av den.
 *
 * Urvalet (Beslut 1): `returned_at IS NULL`, `due_at` satt och `due_at <=
 * idag + config('notiser.loan.remind_days_before')` — redan passerade datum
 * ingår. SoftDeletes' globala scope tar mjukraderade lån, items och
 * containers genom relationerna (Loan → item → container); någon egen
 * DB::table()-fråga byggs inte.
 *
 * Generators har ingen inloggad användare, så loopen går över MOTTAGARE i
 * stället för utlåningar (Beslut 2): för varje användare hämtas hens konton,
 * och utlåningarna på items i containers de kontona äger. En delegerad
 * `container_access` ger INTE utlåningspåminnelser — en gäst med läsrätt på
 * en charterbåt ska inte veta att någon lånat impellernyckeln, samma
 * avgränsning av samma skäl som 34b gjorde.
 *
 * Pro-grinden `loan_reminders` läses, den kastar inte (Beslut 6):
 * `planLimit()` kontrolleras med `=== false`, aldrig
 * `Entitlements::assertFeature()` — den kastar ApiException med 403, rätt i
 * en kontroller, fel i en cron där det blir ett fångat undantag i loggen i
 * stället för ett hoppat konto. Grinden gäller ägarkontots plan, inte
 * mottagarens: det är containerns ägare som betalar.
 *
 * `dedupe_key` bär lån och mottagare, utan datumdel (Beslut 3): jobbet körs
 * dagligen och skulle annars skapa en rad om dagen så länge lånet är öppet —
 * precis den återkommande strömmen ADR-0017 § 7 varnar för, och den blir
 * inte harmlös av att den går rätt håll. Ett lån som blir försenat nöter
 * alltså inte vidare.
 *
 * Ett fel för en användare stoppar inte de andra (Beslut 9): varje användare
 * ligger i ett eget try/catch och ett fångat fel loggas som en varning.
 *
 * Schemaläggs i routes/console.php med `Schedule::call`, aldrig
 * `Schedule::command` — se AGENTS.md § Driftmiljön saknar proc_open.
 */
class GeneratesLoanNotifications
{
    /**
     * Skapar utlåningsnotiser för alla som har en öppen utlåning på väg att
     * förfalla i en container deras konto äger.
     */
    public function handle(): void
    {
        User::query()
            ->whereHas('accounts')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    try {
                        $this->notifyForUser($user);
                    } catch (Throwable $e) {
                        Log::warning('loan_notification.generation_failed', [
                            'user_ulid' => $user->ulid,
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    /**
     * En användare i taget: alla hens konton, och för varje konto vars plan
     * tillåter utlåningspåminnelser de öppna lån som snart förfaller på
     * kontots containers (Beslut 2 och 6).
     */
    private function notifyForUser(User $user): void
    {
        foreach ($user->accounts as $account) {
            if ($account->planLimit('loan_reminders') === false) {
                continue;
            }

            $this->notifyLoansForAccount($account, $user);
        }
    }

    /**
     * Hämtar och notifierar de utlåningar i $account som uppfyller Beslut 1.
     * SoftDeletes-scopen på Loan, Item och Container gäller genom
     * relationerna, så mjukraderade rader dyker aldrig upp här.
     */
    private function notifyLoansForAccount(Account $account, User $user): void
    {
        $deadline = Carbon::today()->addDays((int) config('notiser.loan.remind_days_before'));

        $loans = Loan::query()
            ->with('item.container.account')
            ->whereHas('item.container', function (Builder $query) use ($account): void {
                $query->where('account_id', $account->getKey());
            })
            ->whereNull('returned_at')
            ->whereNotNull('due_at')
            ->whereDate('due_at', '<=', $deadline)
            ->get();

        foreach ($loans as $loan) {
            $this->notifyForLoan($loan, $user);
        }
    }

    /**
     * En notis per lån och mottagare, med en dedupe-nyckel som saknar
     * datumdel — se klassdocblocket om varför. Payloaden bär item, container,
     * borrower och datum; `borrower` är borrower_name, aldrig borrower_email
     * (Beslut 5).
     */
    private function notifyForLoan(Loan $loan, User $user): void
    {
        $container = $loan->item->container;

        app(CreateNotification::class)->handle(
            type: Notification::TYPE_LOAN_DUE,
            account: $container->account,
            user: $user,
            container: $container,
            subject: $loan,
            payload: [
                'item' => $loan->item->name,
                'container' => $container->name,
                'borrower' => $loan->borrower_name,
                'date' => $loan->due_at->toDateString(),
            ],
            dedupeKey: "loan.due:{$loan->ulid}:{$user->ulid}",
        );
    }
}
