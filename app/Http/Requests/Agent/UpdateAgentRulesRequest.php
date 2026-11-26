<?php

namespace App\Http\Requests\Agent;

use App\Http\Controllers\Settings\AgentController;
use App\Models\Container;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Agentreglernas text, för både användarens fält (`PUT /settings/agents/rules`)
 * och containerns (`PUT /containers/{container}/agent-rules`), se
 * [[ADR-0100 Agentregler]].
 *
 * Behörigheten prövas FÖRE valideringen så att den som inte får skriva får 403
 * och inte ett fältfel som läcker gränsen: användarens fält kräver Pro eller
 * Pro+ (samma regel som resten av `/settings/agents`), containerns kräver
 * `manageAgentRules`. En agent når aldrig hit — rutterna ligger i webbens
 * sessionsgrupp.
 */
class UpdateAgentRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $container = $this->route('container');

        if ($container instanceof Container) {
            return Gate::forUser($user)->allows('manageAgentRules', $container);
        }

        return AgentController::available($user);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'rules' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
