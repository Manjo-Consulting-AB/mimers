<?php

namespace App\Http\Controllers;

use App\Actions\Agent\DisableAgentInContainer;
use App\Actions\Agent\EnableAgentInContainer;
use App\Actions\Agent\ResolveAgentAccess;
use App\Http\Controllers\Settings\AgentController;
use App\Models\Agent;
use App\Models\Container;
use App\Models\User;
use App\Support\Access\AccessLevel;
use App\Support\Agent\AgentGroup;
use App\Support\Agent\AgentLevels;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Avsnittet *Agents* i containerns inställningar, se [[ADR-0087 MCP-server för
 * agenter]] Riktning 4 och 6 och issue 408.
 *
 * **Allt är avslaget från start.** Utan rader i `agent_container_grant` har
 * agenten ingen åtkomst; avsnittet ritar en rad per agent och låter
 * användaren slå på en — antingen allt upp till kontots nivå (en
 * ögonblicksbild) eller en nivå per grupp. Varje val sparas direkt.
 *
 * **Avsnittet finns bara med Pro eller Pro+ hos både användaren och
 * containerns ägarkonto, och med `mcp.enabled`** (`available()`). Annars
 * skickas ingen prop och skrivrutterna svarar 404.
 *
 * **Taket per grupp är det lägsta av kontots nivå och, i någon annans
 * container, ägarens `agent_level`.** Servern kapar (App\Actions\Agent\
 * ApplyAgentContainerGrants); vyn erbjuder bara nivåerna upp till taket.
 *
 * Ingen rättighetslogik bor här: skrivningarna går genom App\Actions\Agent\*,
 * som själva avvisar en agent som anropare. `{agent}` binds på ULID och prövas
 * mot inloggad användare, och en annans agent ger 404.
 */
class ContainerAgentController extends Controller
{
    /**
     * Syns avsnittet och fälten för användaren i den här containern?
     */
    public static function available(?User $user, Container $container): bool
    {
        return AgentController::available($user)
            && AgentLevels::planIncludesMcp($container->account);
    }

    /**
     * Propen till containerns inställningssida, eller `null` när avsnittet
     * inte ska ritas.
     *
     * @return array<string, mixed>|null
     */
    public static function section(User $user, Container $container): ?array
    {
        if (! self::available($user, $container)) {
            return null;
        }

        $resolve = app(ResolveAgentAccess::class);

        $agents = Agent::query()
            ->where('user_id', $user->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->with(['accountGrants', 'containerGrants' => fn ($query) => $query->where('container_id', $container->id)])
            ->get();

        // Ägarens tak räknas på användaren, inte på agenten, så det är samma
        // för alla rader; en agent behövs bara som bärare av användaren.
        $ceiling = $agents->isEmpty() ? null : $resolve->containerCeiling($agents->first(), $container);
        $own = $agents->isNotEmpty() && $resolve->isOwnContainer($agents->first(), $container);

        return [
            'levels' => AccessLevel::LADDER,
            'own' => $own,
            'ownerAllows' => $agents->isEmpty() || $ceiling !== null,
            'ceiling' => $ceiling,
            'groups' => array_map(fn (AgentGroup $group): string => $group->value, AgentGroup::cases()),
            'agents' => $agents->map(function (Agent $agent) use ($ceiling): array {
                $account = $agent->accountGrants->pluck('level', 'group');
                $saved = $agent->containerGrants->pluck('level', 'group');

                $limits = [];
                $levels = [];
                $all = $saved->isNotEmpty();

                foreach (AgentGroup::cases() as $group) {
                    $limit = AgentLevels::min($account[$group->value] ?? null, $ceiling);
                    $limits[$group->value] = $limit;
                    $levels[$group->value] = $saved[$group->value] ?? null;

                    if ($limit !== null && ($saved[$group->value] ?? null) !== $limit) {
                        $all = false;
                    }
                }

                return [
                    'ulid' => $agent->ulid,
                    'name' => $agent->name,
                    'enabled' => $saved->isNotEmpty(),
                    'all' => $all,
                    'limits' => $limits,
                    'levels' => $levels,
                ];
            })->values()->all(),
        ];
    }

    /**
     * PUT /containers/{container}/agents/{agent} — slår på agenten.
     *
     * Kroppen är `{all: true}` för allt upp till taket, eller `{levels:
     * {grupp: nivå|null}}` för en nivå per grupp. En nivå över taket kapas av
     * actionen.
     */
    public function update(Request $request, Container $container, Agent $agent, EnableAgentInContainer $enable): RedirectResponse
    {
        $user = $this->authorizeFor($request, $container, $agent);

        $data = $request->validate([
            'all' => ['nullable', 'boolean'],
            'levels' => ['nullable', 'array'],
            'levels.*' => ['nullable', 'string', Rule::in(AccessLevel::LADDER)],
        ]);

        $levels = $data['levels'] ?? [];

        foreach (array_keys($levels) as $group) {
            if (AgentGroup::tryFrom((string) $group) === null) {
                throw ValidationException::withMessages(['levels' => __('ui.agents.container.unknown_group')]);
            }
        }

        if (app(ResolveAgentAccess::class)->containerCeiling($agent, $container) === null) {
            throw ValidationException::withMessages(['agent' => __('ui.agents.container.owner_disallows')]);
        }

        $enable->handle($agent, $container, $user, ($data['all'] ?? false) ? null : $levels);

        return back()->with('status', 'agent-enabled');
    }

    /**
     * DELETE /containers/{container}/agents/{agent} — slår av agenten.
     */
    public function destroy(Request $request, Container $container, Agent $agent, DisableAgentInContainer $disable): RedirectResponse
    {
        $user = $this->authorizeFor($request, $container, $agent);

        $disable->handle($agent, $container, $user);

        return back()->with('status', 'agent-disabled');
    }

    private function authorizeFor(Request $request, Container $container, Agent $agent): User
    {
        /** @var User $user */
        $user = $request->user();

        Gate::forUser($user)->authorize('view', $container);

        $container->loadMissing('account');

        abort_unless(self::available($user, $container), 404);
        abort_unless((int) $agent->user_id === (int) $user->id, 404);

        return $user;
    }
}
