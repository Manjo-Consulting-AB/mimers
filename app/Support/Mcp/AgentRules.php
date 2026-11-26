<?php

namespace App\Support\Mcp;

use App\Models\AgentRulesRevision;
use App\Models\Container;
use App\Models\User;

/**
 * Agentreglerna som de levereras till agenten, se [[ADR-0100 Agentregler]].
 * De är det enda undantaget från att all text i Mimers är användardata, och
 * bärs därför i ett eget fält, `agent_rules`, som säger vem som skrivit dem
 * (`from`). **Bara läsning:** ingen kod här eller i något verktyg skriver
 * reglerna; det görs i appen av en människa (Set*AgentRules).
 *
 * Reglerna snävar bara in. Behörigheterna prövas på servern som förut.
 */
final class AgentRules
{
    /**
     * Användarens regler, eller null när de saknas.
     *
     * @return array{from: string, text: string, revision: int}|null
     */
    public function forUser(User $user): ?array
    {
        $text = $user->agent_rules;

        if (! is_string($text) || $text === '') {
            return null;
        }

        return [
            'from' => 'user',
            'text' => $text,
            'revision' => (int) AgentRulesRevision::query()->where('user_id', $user->id)->max('revision'),
        ];
    }

    /**
     * Containerns regler, eller null när de saknas.
     *
     * @return array{from: string, owner_is_user: bool, text: string, revision: int}|null
     */
    public function forContainer(Container $container, bool $ownerIsUser): ?array
    {
        $text = $container->agent_rules;

        if (! is_string($text) || $text === '') {
            return null;
        }

        return [
            'from' => 'container_owner',
            'owner_is_user' => $ownerIsUser,
            'text' => $text,
            'revision' => $this->containerRevision($container),
        ];
    }

    public function containerRevision(Container $container): int
    {
        return (int) AgentRulesRevision::query()->where('container_id', $container->id)->max('revision');
    }
}
