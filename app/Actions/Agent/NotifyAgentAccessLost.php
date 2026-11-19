<?php

namespace App\Actions\Agent;

use App\Actions\Notification\CreateNotification;
use App\Models\Account;
use App\Models\Agent;
use App\Models\AgentContainerGrant;
use App\Models\Notification;
use App\Support\Agent\AgentLevels;
use Illuminate\Support\Facades\DB;

/**
 * Notisen `agent.access_lost` — [[ADR-0087 MCP-server för agenter]] Riktning
 * 11 och 14, issue 409.
 *
 * När en ägares plan blir Gratis tappar andra användares agenter åtkomsten till
 * ägarens containrar, genom planprövningen i `ResolveAgentAccess`. Ingen rad
 * ändras, så det finns ingen skrivning att haka på: planen löper ut med klockan
 * (`Subscription::isCurrent()`). Den här actionen letar därför upp dem som
 * berörs och körs i det nattliga nedgraderingsjobbet.
 *
 * Berörd är en användare vars agent har en rad i `agent_container_grant` för
 * en container som ägs av ett konto utan MCP i planen, när containern inte är
 * användarens egen. En agent vars egen plan saknar MCP berörs inte: den
 * avvisas redan på sin egen plan och har inget att förlora. **En notis per
 * användare och ägare, inte per container** — `dedupe_key` bär ägarkontot,
 * användaren och ägarens periodslut, så en senare nedgradering efter en
 * uppgradering ger en ny notis medan omkörningar inte gör det.
 *
 * Notisen går i appen och som mejl; ingen av kanalerna kan stängas av
 * (App\Support\Notification\NotificationPreferences).
 */
class NotifyAgentAccessLost
{
    public function __construct(private readonly CreateNotification $createNotification) {}

    /**
     * @return int antalet notiser som skapades i den här körningen
     */
    public function handle(): int
    {
        $created = 0;

        $pairs = AgentContainerGrant::query()
            ->join('agent', 'agent.id', '=', 'agent_container_grant.agent_id')
            ->join('container', 'container.id', '=', 'agent_container_grant.container_id')
            ->whereNull('container.inbox_user_id')
            ->select('agent.user_id as user_id', 'container.account_id as owner_id')
            ->distinct()
            ->orderBy('owner_id')
            ->orderBy('user_id')
            ->get();

        $owners = [];

        foreach ($pairs as $pair) {
            $ownerId = (int) $pair->getAttribute('owner_id');
            $userId = (int) $pair->getAttribute('user_id');

            $owner = $owners[$ownerId] ??= Account::query()->with('subscription')->find($ownerId);

            if ($owner === null || AgentLevels::planIncludesMcp($owner)) {
                continue;
            }

            $agents = Agent::query()
                ->where('user_id', $userId)
                ->whereExists(function ($query) use ($ownerId): void {
                    $query->select(DB::raw(1))
                        ->from('agent_container_grant')
                        ->join('container', 'container.id', '=', 'agent_container_grant.container_id')
                        ->whereColumn('agent_container_grant.agent_id', 'agent.id')
                        ->whereNull('container.inbox_user_id')
                        ->where('container.account_id', $ownerId);
                })
                ->with(['user', 'account'])
                ->get()
                // Egen container är inget att tappa, och en agent på Gratis har
                // redan förlorat allt.
                ->filter(fn (Agent $agent): bool => AgentLevels::planIncludesMcp($agent->account)
                    && ! $agent->user->accounts()->whereKey($ownerId)->exists());

            $first = $agents->first();

            if ($first === null) {
                continue;
            }

            $period = $owner->subscription?->current_period_end?->getTimestamp() ?? 0;
            $key = "agent.access_lost:{$owner->ulid}:{$first->user->ulid}:{$period}";

            $before = Notification::query()->where('dedupe_key', $key)->exists();

            $this->createNotification->handle(
                type: Notification::TYPE_AGENT_ACCESS_LOST,
                account: $first->account,
                user: $first->user,
                payload: [
                    'owner' => $owner->name,
                    'agents' => $agents->count(),
                ],
                dedupeKey: $key,
            );

            if (! $before) {
                $created++;
            }
        }

        return $created;
    }
}
