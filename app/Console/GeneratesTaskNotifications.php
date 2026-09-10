<?php

namespace App\Console;

use App\Actions\Access\ResolveItemScope;
use App\Actions\Notification\CreateNotification;
use App\Models\Container;
use App\Models\Notification;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use App\Support\Access\ItemScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Uppgiftsnotiserna — issue 34b. Förekomster som blivit synliga eller
 * förfallit skapar en notis per mottagare; själva leveransen äger 34a och
 * mallarna 32a. Se [[Notiser]] § Kön och § notification.
 *
 * Urvalet är exakt det todo-listan formulerar — `ScheduleOccurrence::
 * scopeTodoFor()` (Beslut 3): öppen, `visible_from <= idag`, aktivt schema,
 * inga blockerande beroenden, och bara de items mottagaren når. Det
 * formuleras inte om här; en andra formulering av samma regel skulle glida
 * isär från listan och låta produktens två ytor säga olika saker om samma
 * uppgift.
 *
 * Generators har ingen inloggad användare, så loopen går över MOTTAGARE i
 * stället för förekomster (Beslut 4): för varje användare hämtas hens konton
 * och todoFor körs en gång. VEM som får en påminnelse är en egen fråga som
 * scopet inte svarar på — den avgörs i mayNotify(): ägarkontots medlemmar
 * (34b § Beslut 4, oförändrat) och, sedan M11, mottagare av ett enskilt item.
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
 * container hen når, samtidigt, resten av natten. mayNotify() löser upp
 * omfånget på den instansen, så memon är belastad och tömningen behövs.
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
     * förekomst inom sitt omfång — se mayNotify() för vem det är.
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
     * items hen når — se klassdocblocket. `$accountIds` är kontona användaren
     * är medlem i; vem som får en påminnelse avgörs av mayNotify().
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
            ->get();

        if ($occurrences->isEmpty()) {
            return;
        }

        // Containrarna som faktiskt bär en förekomst, upplösta i ETT anrop —
        // frågekostnaden får inte växa med antalet containers (Beslut 2).
        $containerIds = $occurrences
            ->map(fn (ScheduleOccurrence $occurrence): int => $occurrence->schedule->item->container_id)
            ->unique()
            ->values()
            ->all();

        $scopes = $this->scopes->forContainers($user, $containerIds);

        foreach ($occurrences as $occurrence) {
            $container = $occurrence->schedule->item->container;

            if (! $this->mayNotify($container, $scopes[$container->id], $accountIds)) {
                continue;
            }

            $this->notifyForOccurrence($occurrence, $user);
        }
    }

    /**
     * Vem generatorn får påminna. Urvalet av FÖREKOMSTER är todoFor:s och
     * rörs inte (Beslut 1); det här är recipientfrågan ovanpå det.
     *
     * Ägarkontots medlem når hela containern (ResolveItemScope regel 1) och
     * får påminnelser om allt i den, precis som före M11. En mottagare av ett
     * enskilt item får ett BEGRÄNSAT omfång och påminnelser om exakt det
     * itemet och dess ättlingar — vad hon når, aldrig mer (issue 75 § Klart
     * när; ADR-0028 § Konsekvenser, "Notisgeneratorerna").
     *
     * En container-bred delegering är obegränsad precis som ägaren
     * (`item_id IS NULL`) men saknar ägarskapet, och får därför inga
     * påminnelser: den är 34b § Beslut 4 och rörs inte här. Skillnaden
     * mellan de två är hela poängen med mayNotify() — `unrestricted()` är
     * samma svar för en ägare och en container-bred gäst, så ägarskapet
     * måste prövas för sig.
     *
     * @param  list<int>  $accountIds
     */
    private function mayNotify(Container $container, ItemScope $scope, array $accountIds): bool
    {
        return in_array($container->account_id, $accountIds, true)
            || ! $scope->isUnrestricted();
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
