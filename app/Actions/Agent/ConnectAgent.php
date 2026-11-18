<?php

namespace App\Actions\Agent;

use App\Actions\Notification\CreateNotification;
use App\Actions\Security\RecordSecurityEvent;
use App\Models\Agent;
use App\Models\Notification;
use App\Models\SecurityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Client;

/**
 * Skapar eller återanvänder agentraden efter ett godkänt OAuth-flöde, se
 * [[ADR-0087 MCP-server för agenter]] Riktning 12 och issue 400 § 4 och 5.
 *
 * **Tre fall, och två av dem skickar mejl.**
 *
 * 1. *Samma klient igen.* Raden hittas på `(user_id, oauth_client_id)` och
 *    återanvänds med namn, rättigheter och paus. Ingen ny rad, inget mejl —
 *    det här händer när refresh-token löpt ut, efter ett lösenordsbyte eller
 *    när klienten ansluter på nytt. Anslutningen skrivs ändå i säkerhetsloggen,
 *    eftersom det är en anslutning (Riktning 12).
 * 2. *Ny klient som ersätter en befintlig.* Radens `oauth_client_id` byts,
 *    den gamla klientens tokens återkallas, och raden behåller namn,
 *    rättigheter och paus. `agent.replaced` skrivs i säkerhetsloggen. Mejlet
 *    `agent.connected` skickas ändå: en ersättning är just det fall mejlet
 *    och bortkopplingslänken finns för — en kapad session som ansluter
 *    angriparens egen klient — och värre än en ny agent, eftersom den nya
 *    klienten ärver radens rättigheter och påslag i stället för att börja
 *    från noll. Utan mejlet har användaren varken signal eller länk.
 * 3. *Ny klient, ny agent.* En rad skapas, `agent.connected` skrivs och
 *    användaren får notisen `agent.connected` som mejl. Mejlet går inte att
 *    stänga av och samlas aldrig i veckosammanfattningen, se
 *    App\Support\Notification\NotificationPreferences.
 *
 * **Reglerna prövas här och inte bara i vyn.** Ett gratiskonto och en elfte
 * agent avvisas även om någon postar godkännandet förbi sidan; sidan ritar
 * bara samma svar. Plan- och takreglerna formuleras i
 * App\Actions\Agent\InspectAgentConnection och läses härifrån, så vyn och
 * grinden inte kan glida isär.
 *
 * **Allt i EN transaktion.** Raden, säkerhetsloggen och notisen hör till
 * samma händelse: en agent utan loggrad är ett tyst spår, och ett mejl om en
 * anslutning som rullades tillbaka vore ett löfte systemet inte höll.
 *
 * **Taket räknas under lås.** En ny rad prövar plan och tak en gång till
 * sedan personraden låsts, så två samtidiga godkännanden från olika klienter
 * inte båda ser nio agenter och skriver den elfte. Uniknyckeln
 * `(user_id, oauth_client_id)` är spärren för samma klient.
 */
class ConnectAgent
{
    public function __construct(
        private readonly InspectAgentConnection $inspect,
        private readonly RevokeAgentTokens $revokeTokens,
        private readonly RecordSecurityEvent $recordSecurityEvent,
        private readonly CreateNotification $createNotification,
    ) {}

    /**
     * @param  string|null  $replaceUlid  ULID:n för agenten användaren valde
     *                                    att ersätta, eller null för en ny agent.
     *
     * @throws AgentConnectionRefusedException
     */
    public function handle(User $user, Client $client, ?string $replaceUlid = null): Agent
    {
        $state = $this->inspect->handle($user, $client);

        if ($state['requires_pro']) {
            throw new AgentConnectionRefusedException(AgentConnectionRefusedException::REASON_PLAN);
        }

        if ($state['existing'] instanceof Agent) {
            return $this->recordReconnect($state['existing']);
        }

        if ($replaceUlid !== null) {
            return $this->replace($user, $client, $state['agents'], $replaceUlid);
        }

        if ($state['limit_reached']) {
            throw new AgentConnectionRefusedException(AgentConnectionRefusedException::REASON_LIMIT);
        }

        return $this->create($user, $client);
    }

    private function recordReconnect(Agent $agent): Agent
    {
        $this->recordSecurityEvent->handle(
            action: SecurityLog::ACTION_AGENT_CONNECTED,
            account: $agent->account,
            user: $agent->user,
            meta: ['agent_ulid' => $agent->ulid, 'client' => $agent->name],
        );

        return $agent;
    }

    /**
     * @param  Collection<int, Agent>  $agents
     */
    private function replace(User $user, Client $client, Collection $agents, string $replaceUlid): Agent
    {
        $agent = $agents->first(
            fn (Agent $candidate): bool => $candidate->ulid === $replaceUlid,
        );

        if (! $agent instanceof Agent) {
            throw new AgentConnectionRefusedException(AgentConnectionRefusedException::REASON_REPLACEMENT);
        }

        return DB::transaction(function () use ($agent, $client, $user): Agent {
            $previousClientId = (string) $agent->oauth_client_id;

            $agent->oauth_client_id = (string) $client->getKey();
            $agent->save();

            // Den gamla klientens tokens hör till raden som fanns, inte till
            // den nya klienten — de återkallas i samma transaktion, så ingen
            // hinner använda dem mot den nya anslutningen.
            $this->revokeTokens->forClient((int) $user->getKey(), $previousClientId);

            $this->recordSecurityEvent->handle(
                action: SecurityLog::ACTION_AGENT_REPLACED,
                account: $agent->account,
                user: $user,
                meta: [
                    'agent_ulid' => $agent->ulid,
                    'client' => $agent->name,
                    'replaced_client_id' => $previousClientId,
                ],
            );

            // Ersättningen skickar samma notis som en ny agent: det är den
            // vägen användaren får veta att någon anslöt en klient, och
            // länken i mejlet kopplar bort raden. `dedupeKey: null` därför
            // att en ersättning är en engångshändelse — samma ULID kan bära
            // flera ersättningar, och nyckeln `agent.connected:<ulid>` från
            // den ursprungliga anslutningen får inte sluka det nya mejlet.
            $this->createNotification->handle(
                type: Notification::TYPE_AGENT_CONNECTED,
                account: $agent->account,
                user: $user,
                payload: [
                    'agent_ulid' => $agent->ulid,
                    // Namnet på klienten som anslöts, inte radens namn:
                    // raden behåller sitt namn och kan heta något annat.
                    'client' => Str::limit((string) $client->name, 100, ''),
                    'connected_at' => now()->toDayDateTimeString(),
                ],
                dedupeKey: null,
            );

            return $agent;
        });
    }

    private function create(User $user, Client $client): Agent
    {
        try {
            return DB::transaction(function () use ($user, $client): Agent {
                // Lås personraden innan taket räknas: utan låset är
                // kontrollen en läs-sedan-skriv, och två samtidiga
                // godkännanden från olika klienter kan båda se nio rader och
                // skriva den elfte. Läsningen sker efter låset, så den som
                // väntar får det antal som gäller när turen kommer.
                User::query()->whereKey($user->getKey())->lockForUpdate()->first();

                $state = $this->inspect->handle($user, $client);

                if ($state['requires_pro']) {
                    throw new AgentConnectionRefusedException(AgentConnectionRefusedException::REASON_PLAN);
                }

                if ($state['existing'] instanceof Agent) {
                    return $this->recordReconnect($state['existing']);
                }

                if ($state['limit_reached']) {
                    throw new AgentConnectionRefusedException(AgentConnectionRefusedException::REASON_LIMIT);
                }

                $account = $state['account'];

                $agent = new Agent;
                $agent->user_id = $user->getKey();
                $agent->account_id = $account->getKey();
                $agent->oauth_client_id = (string) $client->getKey();
                $agent->name = Str::limit((string) $client->name, 100, '');
                $agent->save();

                $this->recordSecurityEvent->handle(
                    action: SecurityLog::ACTION_AGENT_CONNECTED,
                    account: $account,
                    user: $user,
                    meta: ['agent_ulid' => $agent->ulid, 'client' => $agent->name],
                );

                $this->createNotification->handle(
                    type: Notification::TYPE_AGENT_CONNECTED,
                    account: $account,
                    user: $user,
                    payload: [
                        'agent_ulid' => $agent->ulid,
                        'client' => $agent->name,
                        'connected_at' => now()->toDayDateTimeString(),
                    ],
                    dedupeKey: 'agent.connected:'.$agent->ulid,
                );

                return $agent;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Två godkännanden från samma klient hann båda se att raden
            // saknades. Uniknyckeln är spärren; den som förlorade racet
            // behandlar det som "samma klient igen" och skickar inget mejl.
            $state = $this->inspect->handle($user, $client);

            if ($state['existing'] instanceof Agent) {
                return $this->recordReconnect($state['existing']);
            }

            throw $e;
        }
    }
}
