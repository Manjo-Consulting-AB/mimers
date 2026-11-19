<?php

namespace App\Actions\Agent;

use App\Models\Agent;
use App\Models\User;

/**
 * Byter namn på en agent (issue 407). Namnet är klientens vid
 * registreringen och bara en etikett för användaren; ingen logg skrivs.
 */
class RenameAgent
{
    use RefusesAgentCaller;

    public function handle(Agent $agent, User $actor, string $name): void
    {
        $this->assertOwnAgent($agent, $actor);

        $agent->name = $name;
        $agent->save();
    }
}
