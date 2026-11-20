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
use App\Support\Agent\CopyGate;
use App\Support\Agent\ItemAudience;
use App\Support\Agent\ReadAudience;
use App\Support\Mcp\AgentAudit;
use App\Support\Mcp\AgentDeletion;
use App\Support\Mcp\AgentDeletionLimit;
use App\Support\Mcp\McpRateLimiter;
use App\Support\Mcp\McpToolException;
use App\Support\Mcp\ToolError;
use App\Support\Mcp\ToolKind;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
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
 * **Skrivningar loggas som agenten.** `handle()` sätter agenten i det
 * request-bundna sammanhanget (App\Support\Mcp\AgentAudit), och varje
 * `audit_log`-rad som skapas under anropet får `agent_id` när den skrivs, så
 * att historiken visar handlingen som agentens för användarens räkning
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
        $audit = null;

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

            // Raderingstaket prövas efter den vanliga gränsen och före
            // verktyget: det räknar lyckade raderingar ur händelseloggen och
            // släpps av notisen i appen, inte av tiden
            // (App\Support\Mcp\AgentDeletionLimit).
            if ($this->kind === ToolKind::Delete) {
                app(AgentDeletionLimit::class)->assertCanDelete($agent);
            }

            // Historiken märks när raden skrivs, inte efteråt — se
            // App\Support\Mcp\AgentAudit.
            $audit = AgentAudit::forRequest();
            $audit->begin($agent);

            return $this->run($request, $agent);
        } catch (Throwable $e) {
            return ToolError::fromThrowable($e);
        } finally {
            $audit?->end();
        }
    }

    abstract protected function run(Request $request, Agent $agent): Response|ResponseFactory;

    /**
     * Actionerna verktyget anropar, som klassnamn. Tom för ett verktyg som
     * bara läser. `ForbudTest` läser listan och fäller ett verktyg vars namn
     * eller action hör till en förbjuden domän
     * (App\Support\Mcp\ForbiddenDomains, [[ADR-0087 MCP-server för agenter]]
     * Riktning 7).
     *
     * @return list<class-string>
     */
    public function actions(): array
    {
        return [];
    }

    /**
     * Notera vad en läsning lämnade ut, se [[ADR-0087 MCP-server för agenter]]
     * Riktning 10, *Kopiering*: för varje item verktyget svarade med sparas
     * vilka personer som når det, och snittet prövas före varje skrivning.
     *
     * Flera items i samma svar bidrar med SITT snitt — personerna som når
     * allt svaret lämnade ut. Ett tomt svar noterar ingenting: det finns
     * ingen mängd att snitta mot, och en tom mängd hade stängt varje
     * skrivning.
     *
     * @param  list<Item>  $items  de items svaret lämnade ut
     */
    protected function noteReads(Agent $agent, array $items): void
    {
        if ($items === []) {
            return;
        }

        // Containern laddas en gång för hela svaret i stället för en fråga
        // per item (en sida i en sökning kan ha hundra rader).
        $collection = new EloquentCollection($items);

        if ($collection->contains(fn (Item $item): bool => ! $item->relationLoaded('container'))) {
            $collection->load('container');
        }

        $audiences = [];
        $audience = app(ItemAudience::class);

        foreach ($collection as $item) {
            $container = $item->container;

            if ($container !== null) {
                $audiences[] = $audience->forItem($container, $item);
            }
        }

        if ($audiences === []) {
            return;
        }

        $intersection = array_shift($audiences);

        foreach ($audiences as $audience) {
            $intersection = array_values(array_intersect($intersection, $audience));
        }

        app(ReadAudience::class)->note($agent, $intersection);
    }

    /**
     * Kopieringsspärren: når någon utanför agentens lässnitt målet efter
     * skrivningen är anropet `agent.copy_widens_access`, se
     * App\Support\Agent\CopyGate.
     *
     * @param  list<int>  $audienceAfterWrite
     */
    protected function guardCopy(Agent $agent, array $audienceAfterWrite): void
    {
        app(CopyGate::class)->assert($agent, $audienceAfterWrite);
    }

    /**
     * Kör skrivningen. `agent_id` sätts av sammanhanget som `handle()`
     * sätter (App\Support\Mcp\AgentAudit), när raden skapas — inte av en
     * UPDATE efteråt.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    protected function writeAsAgent(Closure $write): mixed
    {
        return $write();
    }

    /**
     * Kör en radering som agenten, med tillfället satt kring actionen, se
     * [[ADR-0087 MCP-server för agenter]] Riktning 8 och 9 och
     * App\Support\Mcp\AgentDeletion.
     *
     * Raden som raderas märks i sin `deleting`-händelse
     * (App\Models\Concerns\RecordsAgentDeletion) och revisionsraden får
     * samma batch i `meta`. Transaktionen gör att märkningen, loggraden och
     * raderingen faller ihop: ett fel lämnar varken en batch eller en rad.
     *
     * **En agent i taget.** Ett radlås på agenten omger taket, tillfället och
     * actionen, så samtidiga anrop från samma agent inte kan räkna förbi
     * taket eller hamna i olika tillfällen (granskningsfynd 5).
     *
     * @template T
     *
     * @param  Closure(): T  $delete
     * @return array{result: T, batch: string}
     */
    protected function deleteAsAgent(Agent $agent, Closure $delete): array
    {
        // Serialiserar en agents raderingar (granskningsfynd 5). Både taket
        // och tillfället är check-then-act — de räknar och läser innan raden
        // raderas — så två samtidiga anrop kunde annars passera taket ihop
        // eller få var sitt tillfälle. Radlåset på agenten tas före båda
        // läsningarna och hålls till actionen är klar; nästa anrop väntar
        // och ser den förras radering.
        return DB::transaction(function () use ($agent, $delete): array {
            Agent::query()->whereKey($agent->getKey())->lockForUpdate()->first();

            // Omprövningen under låset är den som gäller. Den tidiga
            // kontrollen i handle() står kvar så att ett anrop som ändå ska
            // avvisas inte först slår upp målet.
            app(AgentDeletionLimit::class)->assertCanDelete($agent);

            $deletion = AgentDeletion::forRequest();
            $batch = $deletion->begin($agent);

            try {
                return ['result' => $delete(), 'batch' => $batch];
            } finally {
                $deletion->end();
            }
        });
    }

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
     *
     * **Varför nivån inte räcker avgör koden.** Räcker nivån utan
     * skrivspärren (regel 4 i [[Konton och åtkomst]] § Behörighetsregler) är
     * det kontot som är tillfälligt låst, och koden är `auth.forbidden` —
     * samma som API:ets policy ger. `agent.forbidden` är reserverat för det
     * agenten aldrig får göra ([[ADR-0087 MCP-server för agenter]] Riktning 7
     * och 18); en agent som får den koden ska kunna dra slutsatsen att
     * handlingen är förbjuden för gott. En för låg nivå prövas först, så en
     * agent som aldrig hade rätt får `agent.forbidden` även på ett låst konto.
     */
    protected function requireLevel(Agent $agent, string $minimum, Container $container, ?Item $item = null): void
    {
        $resolver = app(ResolveAgentAccess::class);

        // Nivån utan skrivspärren: spärren avgör felkoden, inte nivån.
        $level = $resolver->handle($agent, $this->group, $container, $item, respectWriteLock: false);

        if ($level === null) {
            throw new McpToolException('resource.not_found');
        }

        if (! AccessLevel::atLeast($level, $minimum)) {
            throw new McpToolException('agent.forbidden');
        }

        if (AccessLevel::rank($minimum) > AccessLevel::rank(AccessLevel::READ)
            && $resolver->isWriteLocked($agent, $container)) {
            throw new McpToolException('auth.forbidden');
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
