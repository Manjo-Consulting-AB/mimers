<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Agent\ApplyAgentContainerGrants;
use App\Actions\Agent\DisableAgentInContainer;
use App\Actions\Agent\DisconnectAgent;
use App\Actions\Agent\EnableAgentInContainer;
use App\Actions\Agent\RenameAgent;
use App\Actions\Agent\ResolveAgentAccount;
use App\Actions\Agent\SetAgentPaused;
use App\Actions\Agent\UpdateAgentAccountGrants;
use App\Actions\Inbox\ResolveInbox;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentContainerGrant;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\User;
use App\Support\Access\AccessLevel;
use App\Support\Agent\AgentGroup;
use App\Support\Agent\AgentLevels;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/settings/agents` — användarens anslutna agenter, se [[ADR-0087 MCP-server
 * för agenter]] Riktning 6 och 12 samt issue 407.
 *
 * **Sidan finns bara med Pro eller Pro+ och med `mcp.enabled`.** Annars 404 —
 * samma svar som `/mcp` ger, och länken i menyn ritas inte heller
 * (`mcpAvailable` i App\Http\Middleware\HandleInertiaRequests). Planen läses
 * på agentens konto, personkontot (ResolveAgentAccount).
 *
 * **Bara egna agenter.** `{agent}` binds på ULID och prövas mot inloggad
 * användare; någon annans agent ger 404, inte 403.
 *
 * **Ingen rättighetslogik bor här.** Varje skrivning går genom en action under
 * App\Actions\Agent, som själv avvisar en agent som anropare.
 */
class AgentController extends Controller
{
    /** Antalet händelser agentens sida visar. */
    private const EVENT_LIMIT = 20;

    /**
     * Är MCP tillgängligt för användaren? Delas med menyn.
     */
    public static function available(?User $user): bool
    {
        if ($user === null || ! config('mcp.enabled')) {
            return false;
        }

        try {
            $account = app(ResolveAgentAccount::class)->handle($user);
        } catch (ModelNotFoundException) {
            // Användare utan konto: skalet ska fortsätta fungera.
            return false;
        }

        return AgentLevels::planIncludesMcp($account);
    }

    public function index(Request $request): Response
    {
        $this->ensureAvailable($request);

        $agents = Agent::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $containerCounts = AgentContainerGrant::query()
            ->whereIn('agent_id', $agents->pluck('id'))
            ->whereNotIn('container_id', Container::query()->whereNotNull('inbox_user_id')->select('id'))
            ->select('agent_id', DB::raw('COUNT(DISTINCT container_id) as total'))
            ->groupBy('agent_id')
            ->pluck('total', 'agent_id');

        return Inertia::render('Settings/Agents', [
            'agents' => $agents->map(fn (Agent $agent): array => [
                'ulid' => $agent->ulid,
                'name' => $agent->name,
                'connectedAt' => $agent->created_at?->toIso8601String(),
                'lastUsedAt' => $agent->last_used_at?->toIso8601String(),
                'containerCount' => (int) ($containerCounts[$agent->id] ?? 0),
                'paused' => $agent->isPaused(),
            ])->all(),
            'mcpUrl' => $this->mcpUrl(),
        ]);
    }

    public function show(Request $request, Agent $agent): Response
    {
        $this->ensureOwn($request, $agent);

        $levels = $agent->accountGrants()->pluck('level', 'group');

        $containers = Container::query()
            ->whereIn('id', $agent->containerGrants()->select('container_id'))
            ->whereNull('inbox_user_id')
            ->orderBy('name')
            ->get();

        $events = AuditLog::query()
            ->where('agent_id', $agent->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::EVENT_LIMIT)
            ->get();

        return Inertia::render('Settings/AgentShow', [
            'agent' => [
                'ulid' => $agent->ulid,
                'name' => $agent->name,
                'connectedAt' => $agent->created_at?->toIso8601String(),
                'lastUsedAt' => $agent->last_used_at?->toIso8601String(),
                'paused' => $agent->isPaused(),
                'createContainers' => (bool) $agent->create_containers,
            ],
            'groups' => collect(AgentGroup::cases())->map(fn (AgentGroup $group): array => [
                'key' => $group->value,
                'level' => $levels[$group->value] ?? null,
            ])->all(),
            'levels' => AccessLevel::LADDER,
            'inbox' => $this->inboxProp($agent, $levels),
            'containers' => $containers->map(fn (Container $container): array => [
                'ulid' => $container->ulid,
                'name' => $container->name,
                'url' => "/containers/{$container->ulid}/edit",
            ])->all(),
            'events' => $events->map(fn (AuditLog $event): array => [
                'id' => $event->id,
                'action' => $event->action,
                'at' => $event->created_at->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Namnbytet — sparas med knappen *Save*.
     */
    public function update(Request $request, Agent $agent, RenameAgent $rename): RedirectResponse
    {
        $this->ensureOwn($request, $agent);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $rename->handle($agent, $request->user(), trim($data['name']));

        return back();
    }

    /**
     * Rättigheterna på kontot — ett val sparas direkt, så en begäran bär bara
     * det som ändrades: `levels` (grupp => nivå eller null) och/eller
     * `create_containers`.
     */
    public function grants(Request $request, Agent $agent, UpdateAgentAccountGrants $update): RedirectResponse
    {
        $this->ensureOwn($request, $agent);

        $data = $request->validate([
            'levels' => ['sometimes', 'array'],
            'levels.*' => ['nullable', 'string', Rule::in(AccessLevel::LADDER)],
            'create_containers' => ['sometimes', 'boolean'],
        ]);

        $levels = $data['levels'] ?? [];

        foreach (array_keys($levels) as $group) {
            abort_unless(in_array($group, AgentGroup::values(), true), 422);
        }

        $update->handle(
            $agent,
            $request->user(),
            $levels,
            array_key_exists('create_containers', $data) ? (bool) $data['create_containers'] : null,
        );

        return back();
    }

    /**
     * Slår agenten på eller av i användarens inbox (issue 1685). Inboxen löses
     * (och skapas om den saknas) med ResolveInbox; sedan går skrivningen
     * genom samma actions som en container. Bara `schedules` och `files`
     * tas emot, och ApplyAgentContainerGrants upprepar spärren.
     */
    public function inbox(
        Request $request,
        Agent $agent,
        ResolveInbox $resolveInbox,
        EnableAgentInContainer $enable,
        DisableAgentInContainer $disable,
    ): RedirectResponse {
        $this->ensureOwn($request, $agent);

        $data = $request->validate([
            'levels' => ['required', 'array'],
            'levels.*' => ['nullable', 'string', Rule::in(AccessLevel::LADDER)],
        ]);

        foreach (array_keys($data['levels']) as $group) {
            abort_unless(in_array($group, ApplyAgentContainerGrants::INBOX_GROUPS, true), 422);
        }

        $user = $request->user();
        $container = $resolveInbox->handle($user)->container;

        $levels = [];

        foreach (ApplyAgentContainerGrants::INBOX_GROUPS as $group) {
            $levels[$group] = $data['levels'][$group] ?? null;
        }

        if (array_filter($levels) === []) {
            $disable->handle($agent, $container, $user);
        } else {
            $enable->handle($agent, $container, $user, $levels);
        }

        return back();
    }

    /**
     * @param  Collection<string, string>  $accountLevels
     * @return array{levels: array<string, string|null>, limits: array<string, string|null>}
     */
    private function inboxProp(Agent $agent, $accountLevels): array
    {
        $inbox = Container::query()->where('inbox_user_id', $agent->user_id)->first();

        $saved = $inbox === null
            ? collect()
            : $agent->containerGrants()->where('container_id', $inbox->id)->pluck('level', 'group');

        $levels = [];
        $limits = [];

        foreach (ApplyAgentContainerGrants::INBOX_GROUPS as $group) {
            $levels[$group] = $saved[$group] ?? null;
            $limits[$group] = $accountLevels[$group] ?? null;
        }

        return ['levels' => $levels, 'limits' => $limits];
    }

    public function pause(Request $request, Agent $agent, SetAgentPaused $setPaused): RedirectResponse
    {
        $this->ensureOwn($request, $agent);

        $data = $request->validate(['paused' => ['required', 'boolean']]);

        $setPaused->handle($agent, $request->user(), (bool) $data['paused'], $request->ip(), $request->userAgent());

        return back();
    }

    public function destroy(Request $request, Agent $agent, DisconnectAgent $disconnect): RedirectResponse
    {
        $this->ensureOwn($request, $agent);

        $disconnect->handle($agent, $request->ip(), $request->userAgent());

        return redirect('/settings/agents');
    }

    private function ensureAvailable(Request $request): void
    {
        abort_unless(self::available($request->user()), 404);
    }

    private function ensureOwn(Request $request, Agent $agent): void
    {
        $this->ensureAvailable($request);

        abort_unless($agent->user_id === $request->user()->id, 404);
    }

    private function mcpUrl(): string
    {
        return 'mimers.app/mcp';
    }
}
