<?php

namespace App\Actions\Agent;

use App\Models\AgentRulesRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sätter användarens agentregler, fältet som följer hens agenter överallt
 * ([[ADR-0100 Agentregler]]). Texten trimmas, tom sträng blir NULL och mer
 * än 2 000 tecken avvisas med `validation.max`.
 *
 * Oförändrad text skriver ingenting. Annars skrivs fältet och en revision i
 * samma transaktion, med användarraden låst så att två samtidiga ändringar
 * inte får samma `revision`. Användarens fält loggas inte i `audit_log`;
 * revisionerna räcker.
 *
 * Behörigheten är kontrollerns: en användare skriver bara sina egna regler,
 * och en agent skriver aldrig några.
 */
class SetUserAgentRules
{
    use NormalisesAgentRules;

    /**
     * @return int aktuell revision, 0 om fältet aldrig satts
     */
    public function handle(User $user, ?string $rules, ?User $author = null): int
    {
        $rules = $this->normaliseRules($rules);

        return DB::transaction(function () use ($user, $rules, $author): int {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $current = (int) AgentRulesRevision::query()->where('user_id', $locked->id)->max('revision');

            if ($locked->agent_rules === $rules) {
                return $current;
            }

            $locked->forceFill(['agent_rules' => $rules])->saveQuietly();

            $revision = new AgentRulesRevision;
            $revision->user_id = $locked->id;
            $revision->author_user_id = $author->id ?? $locked->id;
            $revision->rules = $rules;
            $revision->revision = $current + 1;
            $revision->save();

            $user->forceFill(['agent_rules' => $rules])->syncOriginalAttribute('agent_rules');

            return $current + 1;
        });
    }
}
