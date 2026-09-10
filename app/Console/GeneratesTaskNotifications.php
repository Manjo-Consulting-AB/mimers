<?php

namespace App\Console;

use App\Actions\Access\ResolveItemScope;
use App\Actions\Notification\CreateNotification;
use App\Models\Container;
use App\Models\Notification;
use App\Models\ScheduleOccurrence;
use App\Models\User;
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
 * delegerad `container_access`, men en gäst med container-BRED åtkomst ska
 * inte få påminnelser — en guest med läsrätt på hela charterbåten ska inte få
 * veta att impellern ska bytas (Beslut 4, 34b). En gäst med ett ITEM-omfång
 * ska däremot (issue 75 § Beslut 1): todoFor() har redan begränsat
 * förekomsterna till de items hon når, så `recipientReachesContainer()`
 * avgör bara om containern räknas alls — ägd av hennes konto, eller
 * `restricted()` hos henne. En container som är `unrestricted()` hos henne
 * UTAN att vara ägd är exakt gästen med container-bred åtkomst, och den
 * grenen är oförändrad sedan 34b.
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
     * En användare i taget: alla hens konton, och todoFor som redan
     * begränsar förekomsterna till de items hon når (issue 74). Kvar att
     * avgöra här är bara vilka CONTAINERS som räknas alls — se
     * recipientReachesContainer() och klassdocblocket. `$accountIds` är
     * kontona användaren är medlem i.
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
            ->get()
            ->filter(fn (ScheduleOccurrence $occurrence): bool => $this->recipientReachesContainer(
                $user,
                $accountIds,
                $occurrence->schedule->item->container,
            ));

        foreach ($occurrences as $occurrence) {
            $this->notifyForOccurrence($occurrence, $user);
        }
    }

    /**
     * Ägd av ett av användarens konton — alltid med (rule 1 ger henne redan
     * hela containern). Annars bara med om hennes omfång i containern är
     * `restricted()`: en itemgrant, som todoFor() redan har filtrerat
     * förekomsterna efter. Är omfånget `unrestricted()` utan att vara ägt är
     * det en container-bred gäst, och Beslut 4 (34b) håller henne utanför.
     *
     * ResolveItemScope::forContainers() har redan körts inne i todoFor() för
     * VARJE container användaren når (issue 74), så anropet här träffar
     * memon och kostar noll extra frågor.
     */
    private function recipientReachesContainer(User $user, array $accountIds, Container $container): bool
    {
        if (in_array($container->account_id, $accountIds, true)) {
            return true;
        }

        return ! $this->scopes->handle($user, $container)->isUnrestricted();
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
