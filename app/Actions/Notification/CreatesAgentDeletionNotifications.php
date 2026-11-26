<?php

namespace App\Actions\Notification;

use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Item;
use App\Models\Notification;
use App\Support\Mcp\AgentDeletion;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Skapar notisen `agent.deleted` — en gång per tillfälle och mottagare, 15
 * minuter efter tillfällets sista radering. Se [[ADR-0087 MCP-server för
 * agenter]] Riktning 8, 9 och 14 och issue 406 § 4.
 *
 * **Underlaget är händelseloggen.** Varje radering skrev sin `*.deleted`-rad i
 * samma transaktion som raderingen (issue 406 § 2), och `meta` bär tillfällets
 * ULID. Där finns alla slagen — också scheman, kostnadsrader och fält, som
 * inte listas i papperskorgen — så notisen räknar upp allt agenten raderade och
 * taket (App\Support\Mcp\AgentDeletionLimit) räknar samma rader.
 *
 * **Strikt mer än 15 minuter efter den SISTA lyckade raderingen.** Gränsen är
 * densamma som batchen (AgentDeletion::openBatch): en radering på exakt 15
 * minuter hör fortfarande till tillfället, så notisen får inte vara skapad då
 * — den hade aldrig kunnat omnämna raderingen (granskningsfynd 3). Ett
 * avvisat försök skriver ingen rad och skjuter därför aldrig notisen framåt
 * (Riktning 9). Cronen (routes/console.php) kallar den varje minut;
 * `dedupe_key` med agenten, tillfället och mottagaren gör att en andra
 * körning inte skapar en andra notis, och ett tillfälle som redan fått sin
 * notis hoppas över helt (granskningsfynd 7).
 *
 * **Mottagarna.** Agentens användare får allt. En container som någon annan
 * äger ger dessutom ägarkontots medlemmar en notis — men bara om det som
 * raderats i deras containrar, så att en ägare inte ser en annan ägares
 * innehåll i sin rad. Appkanalen kan inte stängas av; mejlet kan
 * (App\Support\Notification\NotificationPreferences).
 *
 * **En raderad container räknas som EN radering i taket men notisen säger hur
 * många items den innehöll**, eftersom innehållet följer med containern till
 * papperskorgen (Riktning 9). Antalet är de levande items som ligger i
 * containern; items som redan låg i papperskorgen kommer inte tillbaka med
 * containern och räknas därför inte.
 */
final class CreatesAgentDeletionNotifications
{
    /**
     * Handling → slag i `payload.items`, så notisen kan räkna upp innehållet.
     *
     * @var array<string, string>
     */
    private const TYPES = [
        AuditLog::ACTION_ITEM_DELETED => 'item',
        AuditLog::ACTION_ATTACHMENT_DELETED => 'attachment',
        AuditLog::ACTION_CONTAINER_DELETED => 'container',
        AuditLog::ACTION_SCHEDULE_DELETED => 'schedule',
        AuditLog::ACTION_COST_ENTRY_DELETED => 'cost_entry',
        AuditLog::ACTION_FIELD_DELETED => 'field',
        // ADR-0097 § 8: en borttagen relation räknas som en egen post.
        AuditLog::ACTION_ITEM_LINK_DELETED => 'link',
    ];

    public function __construct(private readonly CreateNotification $createNotification) {}

    /**
     * @return int antalet skapade notisrader (0 när ingenting var moget)
     */
    public function handle(): int
    {
        // Kandidaterna är de tillfällen som HAR en radering äldre än
        // gränsen: ett tillfälle som fortfarande fylls på har ingen sådan.
        // Fönstret på ett dygn håller körningen borta från historiken; en
        // notis som missats där är redan förbrukad, och cronen går varje
        // minut. Att läsa hela tillfället efteråt — inte bara raderna i
        // fönstret — är det som ger rätt antal när ett tillfälle varat
        // längre än ett dygn (granskningsfynd 7).
        $rows = AuditLog::query()
            ->whereNotNull('agent_id')
            ->whereIn('action', AgentDeletion::DELETE_ACTIONS)
            ->where('created_at', '<', $this->matureBefore())
            ->where('created_at', '>=', now()->subDay())
            ->get(['id', 'action', 'agent_id', 'container_id', 'created_at', 'meta']);

        $batches = [];

        foreach ($rows as $row) {
            $batch = $row->meta['agent_deletion_batch'] ?? null;

            if (is_string($batch) && $batch !== '') {
                $batches[$batch] = (int) $row->agent_id;
            }
        }

        $created = 0;

        foreach ($batches as $batch => $agentId) {
            $agent = Agent::query()->find($agentId);

            if ($agent === null || $agent->user === null) {
                continue;
            }

            // Ett tillfälle som redan fått sina notiser rörs inte: en
            // omkörning ska inte betala för containerfrågor, itemantal och
            // dedupe-uppslag en gång i minuten (granskningsfynd 7).
            if ($this->alreadyNotified($agent, (string) $batch)) {
                continue;
            }

            $batchRows = $this->batchRows((string) $batch);
            $last = $this->lastDeletion($batchRows->all());

            // Tillfället är öppet så länge raderingar kommer högst
            // BATCH_MINUTES efter den förra. Vid EXAKT gränsen hör
            // raderingen fortfarande till tillfället (AgentDeletion::
            // openBatch), och då får notisen inte redan vara skapad — den
            // hade aldrig omnämnt raderingen. Därför krävs strikt mer än
            // gränsen, samma jämförelse på sekunder som batchen använder
            // (granskningsfynd 3).
            if ($last === null
                || $last->getTimestamp() >= now()->subMinutes(AgentDeletion::BATCH_MINUTES)->getTimestamp()) {
                continue;
            }

            $created += $this->forBatch($agent, (string) $batch, $batchRows->all());
        }

        return $created;
    }

    /**
     * Den tidigaste raderingen som kan höra till ett STÄNGT tillfälle.
     */
    private function matureBefore(): CarbonInterface
    {
        return now()->subMinutes(AgentDeletion::BATCH_MINUTES);
    }

    /**
     * Har tillfället redan fått sin notis? `dedupe_key` bär agenten och
     * batchen, så uppslaget träffar bara det här tillfället.
     */
    private function alreadyNotified(Agent $agent, string $batch): bool
    {
        return Notification::query()
            ->where('type', Notification::TYPE_AGENT_DELETED)
            ->where('dedupe_key', 'like', 'agent.deleted:'.$agent->ulid.':'.$batch.':%')
            ->exists();
    }

    /**
     * Hela tillfället, utan fönstret som kandidatfrågan använder.
     *
     * @return Collection<int, AuditLog>
     */
    private function batchRows(string $batch): Collection
    {
        return AuditLog::query()
            ->whereNotNull('agent_id')
            ->whereIn('action', AgentDeletion::DELETE_ACTIONS)
            ->where('meta->agent_deletion_batch', $batch)
            ->get(['id', 'action', 'agent_id', 'container_id', 'created_at', 'meta']);
    }

    /**
     * @param  list<AuditLog>  $rows
     */
    private function forBatch(Agent $agent, string $batch, array $rows): int
    {
        $containerIds = array_values(array_unique(array_map(
            static fn (AuditLog $row): int => (int) $row->container_id,
            array_filter($rows, static fn (AuditLog $row): bool => $row->container_id !== null),
        )));

        $containers = Container::query()
            ->withTrashed()
            ->with('account.users')
            ->whereIn('id', $containerIds)
            ->get()
            ->keyBy('id');

        $recipients = [];

        // Agentens egen användare först och med allt.
        $recipients[(int) $agent->user_id] = [
            'user' => $agent->user,
            'account' => $agent->account,
            'container_ids' => null,
        ];

        // Sedan varje annan ägare, med bara sina egna containrar.
        foreach ($containers as $container) {
            foreach ($container->account->users as $user) {
                if ((int) $user->id === (int) $agent->user_id) {
                    continue;
                }

                if (! isset($recipients[(int) $user->id])) {
                    $recipients[(int) $user->id] = [
                        'user' => $user,
                        'account' => $container->account,
                        'container_ids' => [],
                    ];
                }

                $recipients[(int) $user->id]['container_ids'][] = (int) $container->id;
            }
        }

        $created = 0;

        foreach ($recipients as $recipient) {
            $visible = $recipient['container_ids'] === null
                ? $rows
                : array_values(array_filter(
                    $rows,
                    static fn (AuditLog $row): bool => in_array((int) $row->container_id, $recipient['container_ids'], true),
                ));

            if ($visible === []) {
                continue;
            }

            $notification = $this->createNotification->handle(
                type: Notification::TYPE_AGENT_DELETED,
                account: $recipient['account'],
                user: $recipient['user'],
                // Dedupe-nyckeln bär agenten och tillfället, plus mottagaren:
                // samma tillfälle ger en rad per mottagare (Riktning 14).
                payload: $this->payload($agent, $batch, $visible, $containers),
                dedupeKey: 'agent.deleted:'.$agent->ulid.':'.$batch.':'.$recipient['user']->ulid,
            );

            // En redan skapad notis (en omkörning) räknas inte som ny.
            if ($notification->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * @param  list<AuditLog>  $rows
     * @param  Collection<int, Container>  $containers
     * @return array<string, mixed>
     */
    private function payload(Agent $agent, string $batch, array $rows, $containers): array
    {
        $items = [];

        foreach ($rows as $row) {
            $type = self::TYPES[$row->action] ?? null;

            if ($type !== null) {
                $items[$type] = ($items[$type] ?? 0) + 1;
            }
        }

        // En raderad container bär sitt innehåll med sig till papperskorgen,
        // men taket räknade den som en radering — notisen säger hur många
        // items den innehöll (Riktning 9).
        $deletedContainers = [];

        foreach ($rows as $row) {
            if ($row->action !== AuditLog::ACTION_CONTAINER_DELETED) {
                continue;
            }

            $container = $containers->get((int) $row->container_id);

            if ($container === null) {
                continue;
            }

            $deletedContainers[] = [
                'name' => $container->name,
                'items' => Item::query()->where('container_id', $container->id)->count(),
            ];
        }

        return [
            'agent' => $agent->name,
            'agent_ulid' => $agent->ulid,
            'batch' => $batch,
            'total' => count($rows),
            'items' => $items,
            'containers' => $deletedContainers,
            'container_items' => array_sum(array_column($deletedContainers, 'items')),
        ];
    }

    /**
     * @param  list<AuditLog>  $rows
     */
    private function lastDeletion(array $rows): ?CarbonInterface
    {
        $last = null;

        foreach ($rows as $row) {
            if ($last === null || $row->created_at->gt($last)) {
                $last = $row->created_at;
            }
        }

        return $last;
    }
}
