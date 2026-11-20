<?php

namespace App\Actions\Plan;

use App\Actions\Agent\AccountHasConnectedAgent;
use App\Actions\Notification\CreateNotification;
use App\Models\Account;
use App\Models\Notification;
use App\Models\Subscription;

/**
 * Varningen till det nedgraderade kontot självt — issue 1457, uppföljningen
 * av issue 409. Se [[Planer och kvoter]] § Nedgradering (*"Varningar går ut
 * vid steg 1, samt en månad och en vecka innan steg 4"* och *"Skicka fler än
 * en varning"*) och [[ADR-0087 MCP-server för agenter]] Riktning 11.
 *
 * Tre tillfällen, en notistyp: `start` när StartDowngrade sätter kontot i
 * `read_only`, `month` och `week` en månad respektive en vecka före
 * `grace_until`. Meningen är densamma vid alla tre — det som skiljer är hur
 * nära datumet är, och datumet står i raden.
 *
 * **Mottagarna är kontots ägare och administratörer**, samma krets som
 * kvotvarningen (App\Console\GeneratesQuotaWarnings): det är de som kan
 * betala eller exportera. En `member` kan göra ingendera.
 *
 * **Avdubblingen bär kontot, mottagaren, tillfället och fristen.**
 * `dedupe_key` gör det dagliga jobbet ofarligt att köra om, och gör en ny
 * nedgradering efter en uppgradering till en ny svit varningar: den
 * nedgraderingen sätter en ny `grace_until`, alltså en ny nyckel. Ett konto
 * utan prenumeration har ingen frist — då är `occasion` strängen `none`, och
 * det finns bara steg 1 att skicka (steg 4 hoppar över sådana konton, se
 * App\Console\EnforcesDowngrades).
 *
 * **MCP-meningen följer med bara när det finns en anslutning att tappa.**
 * `has_agents` räknas av App\Actions\Agent\AccountHasConnectedAgent, en gång
 * per konto och utskick. Texten väljer varianten; payloaden bär bara data
 * ([[Notiser]] § notification).
 *
 * Notisen går i appen och som mejl och kan inte stängas av
 * (App\Support\Notification\NotificationPreferences) — den varnar för
 * radering.
 */
class NotifyDowngradeWarning
{
    /** Steget då kontot blir `read_only`. */
    public const STAGE_START = 'start';

    /** En månad före `grace_until`. */
    public const STAGE_MONTH = 'month';

    /** En vecka före `grace_until`. */
    public const STAGE_WEEK = 'week';

    public function __construct(
        private readonly CreateNotification $createNotification,
        private readonly AccountHasConnectedAgent $hasConnectedAgent,
    ) {}

    /**
     * @param  'start'|'month'|'week'  $stage
     * @return int antalet notiser som skapades i den här körningen
     */
    public function handle(Account $account, ?Subscription $subscription, string $stage): int
    {
        $graceUntil = $subscription?->grace_until;

        $recipients = $account->users()
            ->wherePivotIn('role', ['owner', 'admin'])
            ->get();

        if ($recipients->isEmpty()) {
            return 0;
        }

        $payload = ['stage' => $stage];

        if ($graceUntil !== null) {
            $payload['grace_until'] = $graceUntil->toDateString();
        }

        $payload['has_agents'] = $this->hasConnectedAgent->handle($account);

        $occasion = $graceUntil?->getTimestamp() ?? 'none';
        $created = 0;

        foreach ($recipients as $recipient) {
            $key = "account.downgrade_warning:{$account->ulid}:{$recipient->ulid}:{$stage}:{$occasion}";

            // CreateNotification är idempotent mot nyckeln, men den svarar
            // med den befintliga raden — räkningen här säger hur många som
            // faktiskt skapades, samma form som NotifyAgentAccessLost.
            $before = Notification::query()->where('dedupe_key', $key)->exists();

            $this->createNotification->handle(
                type: Notification::TYPE_ACCOUNT_DOWNGRADE_WARNING,
                account: $account,
                user: $recipient,
                payload: $payload,
                dedupeKey: $key,
            );

            if (! $before) {
                $created++;
            }
        }

        return $created;
    }
}
