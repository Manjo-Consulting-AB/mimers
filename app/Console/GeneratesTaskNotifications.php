<?php

namespace App\Console;

use App\Actions\Access\ResolveItemScope;
use App\Actions\Notification\CreateNotification;
use App\Models\Notification;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Uppgiftsnotiserna — issue 34b. Förekomster som blivit synliga eller
 * förfallit skapar en notis per mottagare; själva leveransen äger 34a och
 * mallarna 32a. Se [[Notiser]] § Kön och § notification.
 *
 * Urvalet är exakt det todo-listan formulerar — `ScheduleOccurrence::
 * scopeTodoFor()` (Beslut 3): öppen, `visible_from <= idag`, aktivt schema
 * och inga blockerande beroenden. Det formuleras inte om här; en andra
 * formulering av samma regel skulle glida isär från listan och låta
 * produktens två ytor säga olika saker om samma uppgift.
 *
 * Generators har ingen inloggad användare, så loopen går över MOTTAGARE i
 * stället för förekomster (Beslut 4): för varje användare hämtas hens konton
 * och todoFor körs en gång. Scopets åtkomstvillkor når även containers via
 * delegerad `container_access`, men bara ägarkontots medlemmar ska få
 * påminnelser — en guest med läsrätt på en charterbåt ska inte få veta att
 * impellern ska bytas (Beslut 4). Frågan begränsas därför till containers
 * som ägs av konton användaren är medlem i.
 *
 * Varje förekomst ger högst två notiser över tid, en per typ: `task.due` när
 * den blir synlig och `task.overdue` när datumet passerats. `dedupe_key` bär
 * förekomstens och användarens ULID, så jobbets 96 körningar per dygn ger en
 * rad — och en ny förekomst efter avbockning har en ny ULID och får en ny
 * påminnelse (Beslut 5).
 *
 * Ett fel för en användare stoppar inte de andra (Beslut 9): varje användare
 * ligger i ett eget try/catch och ett fångat fel loggas som en varning.
 *
 * Memon i ResolveItemScope töms per användare (issue 75 § Beslut 2). Loopen
 * är ETT schemalagt anrop, och `scoped()` töms mellan anrop men inte mellan
 * varv i en loop — utan `flush()` bär jobbet varje användares omfång i varje
 * container hen når, samtidigt, resten av natten.
 *
 * Schemaläggs i routes/console.php med `Schedule::call`, aldrig
 * `Schedule::command` — se AGENTS.md § Driftmiljön saknar proc_open.
 */
class GeneratesTaskNotifications
{
    public function __construct(
        private readonly ResolveItemScope $scopes,
    ) {}

    /**
     * Skapar uppgiftsnotiser för alla som har en synlig eller förfallen
     * förekomst i en container deras konto äger.
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
                        Log::warning('task_notification.generation_failed', [
                            'user_ulid' => $user->ulid,
                            'exception' => $e->getMessage(),
                        ]);
                    } finally {
                        // `finally` och inte slutet av try: ett fångat fel
                        // ska inte lämna en användares omfång kvar till nästa
                        // varv i loopen (Beslut 2).
                        $this->scopes->flush();
                    }
                }
            });
    }

    /**
     * En användare i taget: alla hens konton, och todoFor begränsat till de
     * containers kontona äger — se klassdocblocket om varför begränsningen
     * behövs ovanpå scopet. `$accountIds` är kontona användaren är medlem i.
     */
    private function notifyForUser(User $user): void
    {
        $accountIds = $user->accounts->pluck('id')->values()->all();

        if ($accountIds === []) {
            return;
        }

        $occurrences = ScheduleOccurrence::query()
            ->todoFor($user, $accountIds)
            ->with(['schedule.item.container.account'])
            ->whereHas('schedule.item.container', function (Builder $query) use ($accountIds): void {
                $query->whereIn('account_id', $accountIds);
            })
            ->get();

        foreach ($occurrences as $occurrence) {
            $this->notifyForOccurrence($occurrence, $user);
        }
    }

    /**
     * Typen följer klockan, aldrig en lagrad status: en öppen förekomst vars
     * `due_at` passerats är `task.overdue`, annars `task.due`. Båda kan
     * alltså skapas för samma förekomst över tid — först en `due` när den blir
     * synlig, sedan en `overdue` när datumet passerats — med två dedupe-nycklar.
     */
    private function notifyForOccurrence(ScheduleOccurrence $occurrence, User $user): void
    {
        $container = $occurrence->schedule->item->container;

        $type = $occurrence->due_at->lessThan(Carbon::today())
            ? Notification::TYPE_TASK_OVERDUE
            : Notification::TYPE_TASK_DUE;

        app(CreateNotification::class)->handle(
            type: $type,
            account: $container->account,
            user: $user,
            container: $container,
            subject: $occurrence,
            payload: [
                'title' => $occurrence->schedule->title,
                'item' => $occurrence->schedule->item->name,
                'container' => $container->name,
                'date' => $occurrence->due_at->toDateString(),
            ],
            dedupeKey: "{$type}:{$occurrence->ulid}:{$user->ulid}",
        );
    }
}
