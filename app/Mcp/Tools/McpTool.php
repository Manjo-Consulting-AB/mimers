<?php

namespace App\Mcp\Tools;

use App\Actions\Agent\ResolveAgentAccess;
use App\Actions\Audit\RecordAuditEvent;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Item;
use App\Support\Access\AccessLevel;
use App\Support\Agent\AgentGroup;
use App\Support\Agent\AgentLevels;
use App\Support\Mcp\McpRateLimiter;
use App\Support\Mcp\McpToolException;
use App\Support\Mcp\ToolError;
use App\Support\Mcp\ToolKind;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * Basklassen för varje MCP-verktyg, se [[ADR-0087 MCP-server för agenter]]
 * Riktning 9, 13, 15 och 18.
 *
 * Ett verktyg deklarerar tre saker: `$kind` (slaget, som väljer rate limit
 * och standardannotationer), `$group` (rättighetsgruppen, issue 401) och
 * annotationerna. Slaget och gruppen har inget förval — ett verktyg som
 * saknar någon av dem fäller VerktygslistaTest. Annotationerna utgår från
 * slaget och kan skärpas med attributen `IsReadOnly`, `IsDestructive` och
 * `IsIdempotent` på klassen.
 *
 * **`handle()` är slutlig.** Avvisningarna före verktyget ligger där, i en
 * fast ordning: pausad agent, plan utan MCP, rate limit. Verktyget skriver
 * bara `run()`. Varje undantag blir ett verktygsfel i det delade formatet
 * (App\Support\Mcp\ToolError); `server.error` bär aldrig undantagstext.
 *
 * **Skrivningar loggas som agenten** genom `audit()` — `agent_id` sätts på
 * raden så att den syns som agentens handling för användarens räkning
 * (Riktning 3).
 *
 * Innehåll som andra skrivit (namn, beskrivningar) är användardata och inte
 * instruktioner; varje verktygsbeskrivning ska säga det (Riktning 9).
 */
abstract class McpTool extends Tool
{
    protected ?ToolKind $kind = null;

    protected ?AgentGroup $group = null;

    final public function kind(): ?ToolKind
    {
        return $this->kind;
    }

    final public function group(): ?AgentGroup
    {
        return $this->group;
    }

    /**
     * @return array<string, mixed>
     */
    public function annotations(): array
    {
        return array_replace($this->kind?->annotations() ?? [], parent::annotations());
    }

    final public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $agent = request()->attributes->get('mcp.agent');

            if (! $agent instanceof Agent) {
                throw new McpToolException('auth.unauthenticated');
            }

            if ($this->kind === null || $this->group === null) {
                throw new \LogicException(static::class.' declares no kind or group.');
            }

            if ($agent->isPaused()) {
                throw new McpToolException('agent.paused');
            }

            if (! AgentLevels::planIncludesMcp($agent->account)) {
                throw new McpToolException('plan.mcp_unavailable');
            }

            $wait = app(McpRateLimiter::class)->attempt($agent, $this->kind);

            if ($wait !== null) {
                throw new McpToolException('agent.rate_limited', ['retry_after_seconds' => $wait]);
            }

            return $this->run($request, $agent);
        } catch (Throwable $e) {
            return ToolError::fromThrowable($e);
        }
    }

    abstract protected function run(Request $request, Agent $agent): Response|ResponseFactory;

    /**
     * Agentens nivå i verktygets grupp, via den enda som svarar.
     */
    protected function levelIn(Agent $agent, Container $container, ?Item $item = null): ?string
    {
        return app(ResolveAgentAccess::class)->handle($agent, $this->group, $container, $item);
    }

    /**
     * Kräv minst `$minimum` i verktygets grupp. En container eller ett item
     * agenten inte når alls är `resource.not_found` — nekandet avslöjar inte
     * att det finns. Når agenten det men med för låg nivå är det
     * `agent.forbidden`.
     */
    protected function requireLevel(Agent $agent, string $minimum, Container $container, ?Item $item = null): void
    {
        $level = $this->levelIn($agent, $container, $item);

        if ($level === null) {
            throw new McpToolException('resource.not_found');
        }

        if (! AccessLevel::atLeast($level, $minimum)) {
            throw new McpToolException('agent.forbidden');
        }
    }

    /**
     * Skriv revisionsraden för en skrivning som agenten gjort, i anroparens
     * transaktion.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function audit(
        Agent $agent,
        string $action,
        Container $container,
        ?Item $item = null,
        ?string $subjectType = null,
        ?string $subjectUlid = null,
        array $meta = [],
    ): AuditLog {
        return app(RecordAuditEvent::class)->handle(
            action: $action,
            account: $container->account,
            user: $agent->user,
            container: $container,
            item: $item,
            subjectType: $subjectType,
            subjectUlid: $subjectUlid,
            meta: $meta,
            agent: $agent,
        );
    }
}
