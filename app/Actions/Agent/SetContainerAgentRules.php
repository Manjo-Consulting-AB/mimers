<?php

namespace App\Actions\Agent;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AgentRulesRevision;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sätter containerns agentregler, fältet som gäller varje agent som når
 * containern ([[ADR-0100 Agentregler]]). Texten trimmas, tom sträng blir
 * NULL och mer än 2 000 tecken avvisas med `validation.max`.
 *
 * Oförändrad text skriver ingenting. Annars skrivs fältet, en revision och
 * en `audit_log`-rad `container.agent_rules_updated` i samma transaktion,
 * med containerraden låst så att två samtidiga ändringar inte får samma
 * `revision`. **`meta` bär bara revisionsnumret, aldrig texten**
 * ([[Konton och åtkomst]] § audit_log: `meta` bär aldrig fritext).
 *
 * `$author` är NULL vid ägarbyte (App\Actions\OwnershipTransfer\
 * AcceptOwnershipTransfer), som nollställer fältet utan någon skribent.
 * Behörigheten är kontrollerns: ContainerPolicy::manageAgentRules().
 */
class SetContainerAgentRules
{
    use NormalisesAgentRules;

    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @return int aktuell revision, 0 om fältet aldrig satts
     */
    public function handle(Container $container, ?string $rules, ?User $author): int
    {
        $rules = $this->normaliseRules($rules);

        return DB::transaction(function () use ($container, $rules, $author): int {
            $locked = Container::withTrashed()->whereKey($container->getKey())->lockForUpdate()->firstOrFail();

            $current = (int) AgentRulesRevision::query()->where('container_id', $locked->id)->max('revision');

            if ($locked->agent_rules === $rules) {
                return $current;
            }

            $locked->forceFill(['agent_rules' => $rules])->saveQuietly();

            $revision = new AgentRulesRevision;
            $revision->container_id = $locked->id;
            $revision->author_user_id = $author?->id;
            $revision->rules = $rules;
            $revision->revision = $current + 1;
            $revision->save();

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_CONTAINER_AGENT_RULES_UPDATED,
                account: $locked->account,
                user: $author,
                container: $locked,
                subjectType: 'container',
                subjectUlid: $locked->ulid,
                meta: ['revision' => $current + 1],
            );

            $container->forceFill(['agent_rules' => $rules])->syncOriginalAttribute('agent_rules');

            return $current + 1;
        });
    }
}
