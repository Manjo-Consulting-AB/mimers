<?php

namespace App\Console;

use App\Actions\Agent\NotifyAgentAccessLost;
use App\Actions\Trash\PurgeContent;
use App\Actions\Usage\TransferBilling;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
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
 * `active` på gratisnivån.
 *
 * **Steg 4 enligt [[ADR-0081 All lagrad data räknas mot kvoten]] § 4 och 6**
 * (issue 1304), som ersätter ADR-0009 Beslut 5 ("items raderas aldrig"):
 * först flyttas det kontot betalar för i ANDRAS containrar till ägaren
 * (TransferBilling, samma kod som kontoraderingen) — där raderas ingenting.
 * Sedan, i kontots EGNA containrar och så länge `storage_bytes +
 * metadata_bytes` ligger över gränsen: slängda bilagor (äldst slängd först),
 * levande bilagor (nyast först), slängda items (äldst slängd först), levande
 * items (nyast först). Ett item vars underträd bär något ett annat konto
 * betalar för hoppas över. Räcker det inte loggas `downgrade.content_exhausted`
 * och kontot återgår till `active` över gränsen; nya skrivningar som växer
 * nekas då av kvoten.
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
 * den raderats fysiskt. Steg 4 raderar därför för gott och aldrig till
 * papperskorgen (§ Uppföljning 2026-11-14, Tonys beslut 2026-11-14): först
 * töms det som redan är slängt — äldst `deleted_at` först — och sedan raderas
 * kontots LEVANDE bilagor, nyast först (`created_at` fallande med `id`
 * fallande), tills kontots förbrukning ligger på eller under gränsen. Båda
 * vägarna går genom PurgeContent::attachment(), samma väg som lagringsvyn
 * (StorageController). En fysisk radering minskar räknaren direkt, så loopen
 * styrs av `UsageCounter::usedBytes()` och läses om efter varje bilaga — någon lokal räkning
 * av de levande bytena behövs inte. Den slutar så snart räknaren ligger lika
 * med eller under gränsen, eller när bilagorna tar slut; den snurrar aldrig.
 * Efter steg 4 ligger kontot inom kvoten, och ingenting jobbet tagit bort
 * ligger kvar i papperskorgen och räknas. Målet är gratisplanens
 * `storage_bytes` (Beslut 3), läst genom planuppslaget — aldrig en konstant
 * och aldrig kontots gamla plan.
 *
 * **Händelseloggen får ingen rad.** Lagringsvyns radering skriver ingen rad
 * där: PurgeContent::attachment() går genom PurgeAttachment, som varken
 * loggar eller känner en användare. Steg 4 gör då detsamma — en
 * `attachment.deleted`-rad med `user_id` null skulle beskriva en radering
 * användaren inte gjort och som ingen kan handla efter. Tömningen syns i
 * stället i driftloggen (`downgrade.enforced`).
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
 * Klassens beroende (PurgeContent) injiceras för att testerna ska kunna låta
 * en bilaga kasta — samma mönster som PurgesExpiredTrash.
 */
class EnforcesDowngrades
{
    public function __construct(
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

        $this->notifyAgentAccessLost();
    }

    /**
     * Issue 409: en ägare som gått till Gratis har tagit andra användares
     * agenter ur sina containrar, och de användarna får `agent.access_lost`.
     * Planen löper ut med klockan och ingen rad ändras, så det nattliga jobbet
     * är den enda platsen att hitta dem på. Ett fel här stoppar inte det
     * övriga och tas om nästa natt.
     */
    private function notifyAgentAccessLost(): void
    {
        try {
            app(NotifyAgentAccessLost::class)->handle();
        } catch (Throwable $e) {
            Log::error('downgrade.agent_access_lost_failed', ['exception' => $e->getMessage()]);
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

            // Steg 4a (ADR-0081 § 6a): det kontot betalar för i ANDRAS
            // containrar raderas aldrig — det flyttas till containerns ägare,
            // utan kvotprövning, genom samma action som DeleteAccount. Först
            // av allt, så att loopen nedan bara ser kontots egna rader.
            (new TransferBilling)->handle($row);

            $removed = 0;
            $purged = 0;
            $itemsPurged = 0;
            $bytesFreed = 0;
            $limit = $this->freeStorageLimit();

            // En gräns som är null betyder obegränsat (issue 25): det finns
            // inget att komma under, och ingenting att radera.
            if ($limit !== null) {
                $ownContainerIds = Container::withTrashed()
                    ->where('account_id', $row->id)
                    ->pluck('id')
                    ->all();
                /** @var list<int> $skipped Items med andras innehåll i underträdet. */
                $skipped = [];

                // Räknaren är måttet hela vägen — filer och text tillsammans
                // (UsageCounter::usedBytes, som kvotprövningen i #1303): en
                // fysisk radering minskar den direkt, så loopen läses om
                // efter varje rad och behöver ingen lokal räkning.
                while (UsageCounter::usedBytes($row->id) > $limit) {
                    // 1. Slängda bilagor, äldst slängd först. De räknas
                    // fortfarande i kvoten, så det är den billigaste
                    // frigöringen.
                    $trashed = Attachment::onlyTrashed()
                        ->where('billed_account_id', $row->id)
                        ->orderBy('deleted_at')
                        ->orderBy('id')
                        ->first();

                    if ($trashed !== null) {
                        $this->purgeContent->attachment($trashed);
                        $purged++;

                        continue;
                    }

                    // 2. Levande bilagor, nyast först, för gott — samma väg
                    // som lagringsvyn (StorageController).
                    $attachment = Attachment::query()
                        ->where('billed_account_id', $row->id)
                        ->orderByDesc('created_at')
                        ->orderByDesc('id')
                        ->first();

                    if ($attachment !== null) {
                        $byteSize = (int) $attachment->storedFile()->value('byte_size');

                        $this->purgeContent->attachment($attachment);
                        $removed++;
                        $bytesFreed += $byteSize;

                        continue;
                    }

                    // 3. Slängda items, äldst slängd först, och 4. levande
                    // items, nyast först — sist, och bara när filerna inte
                    // räckte.
                    $item = $this->nextItem($row->id, $ownContainerIds, $skipped, trashed: true)
                        ?? $this->nextItem($row->id, $ownContainerIds, $skipped, trashed: false);

                    if ($item === null) {
                        Log::warning('downgrade.content_exhausted', [
                            'account_ulid' => $row->ulid,
                            'storage_bytes' => (int) UsageCounter::query()->where('account_id', $row->id)->value('storage_bytes'),
                            'metadata_bytes' => (int) UsageCounter::query()->where('account_id', $row->id)->value('metadata_bytes'),
                            'limit_bytes' => $limit,
                        ]);

                        break;
                    }

                    // Ett item vars underträd bär något ett annat konto
                    // betalar för frigör inte kontots byte och raderar någon
                    // annans arbete: det hoppas över.
                    if ($this->subtreeHasForeignPayer($item, $row->id)) {
                        $skipped[] = $item->id;

                        continue;
                    }

                    $this->purgeContent->item($item);
                    $itemsPurged++;
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
                'items_purged' => $itemsPurged,
                'bytes_freed' => $bytesFreed,
            ]);
        });
    }

    /**
     * Nästa item att radera: kontots eget (`billed_account_id`), i en container
     * kontot äger, och inte ett som redan hoppats över. Slängda äldst slängd
     * först, levande nyast först.
     *
     * @param  list<int>  $ownContainerIds
     * @param  list<int>  $skipped
     */
    private function nextItem(int $accountId, array $ownContainerIds, array $skipped, bool $trashed): ?Item
    {
        $query = Item::withTrashed()
            ->where('billed_account_id', $accountId)
            ->whereIn('container_id', $ownContainerIds)
            ->whereNotIn('id', $skipped);

        if ($trashed) {
            return $query->whereNotNull('deleted_at')->orderBy('deleted_at')->orderBy('id')->first();
        }

        return $query->whereNull('deleted_at')->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    /**
     * Bär itemets underträd — itemet själv, barnitems (länkar med relationen
     * `parent`, där `from_item_id` är föräldern), scheman, förekomster,
     * kostnadsrader, lån och bilagor — något som ett ANNAT konto betalar för?
     * Rader utan betalare (en förekomst utan anteckning) räknas inte.
     */
    private function subtreeHasForeignPayer(Item $item, int $accountId): bool
    {
        $ids = [$item->id];
        $frontier = [$item->id];

        while ($frontier !== []) {
            $children = DB::table('item_link')
                ->whereNull('item_link.deleted_at')
                ->where('relation', 'parent')
                ->whereIn('from_item_id', $frontier)
                ->pluck('to_item_id')
                ->map(fn ($id): int => (int) $id)
                ->diff($ids)
                ->values()
                ->all();

            $ids = [...$ids, ...$children];
            $frontier = $children;
        }

        $foreign = fn (string $table, string $column = 'item_id') => DB::table($table)
            ->whereIn($column, $ids)
            ->whereNotNull('billed_account_id')
            ->where('billed_account_id', '!=', $accountId)
            ->exists();

        return $foreign('item', 'id')
            || $foreign('schedule')
            || $foreign('item_transaction')
            || $foreign('loan')
            || $foreign('attachment')
            || DB::table('schedule_occurrence')
                ->join('schedule', 'schedule.id', '=', 'schedule_occurrence.schedule_id')
                ->whereIn('schedule.item_id', $ids)
                ->whereNotNull('schedule_occurrence.billed_account_id')
                ->where('schedule_occurrence.billed_account_id', '!=', $accountId)
                ->exists();
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
