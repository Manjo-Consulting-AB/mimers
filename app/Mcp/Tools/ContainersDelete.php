<?php

namespace App\Mcp\Tools;

use App\Actions\Container\TrashContainer;
use App\Models\Agent;
use App\Models\Container;
use App\Support\Access\AccessLevel;
use App\Support\Agent\AgentGroup;
use App\Support\Mcp\McpToolException;
use App\Support\Mcp\ToolKind;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * `containers_delete` — mjukraderar en container, se [[ADR-0087 MCP-server
 * för agenter]] Riktning 7, 8 och 9 och issue 406 § 1.
 *
 * **Två grindar, och den andra är användarens.** Agenten behöver `delete` i
 * gruppen `containers`, och användaren själv måste få radera containern:
 * `ContainerPolicy::delete()` släpper bara igenom medlemmar i ägarkontot. En
 * agent kan alltså aldrig radera någon annans container, hur mycket åtkomst
 * ägaren än gett (Riktning 7). Inkorgen nekas av samma policy.
 *
 * Samma action som appen (App\Actions\Container\TrashContainer), så
 * containerantalet minskas och innehållet ligger kvar orört.
 */
#[Name('containers_delete')]
#[Description('Deletes one container together with everything in it. The user must be allowed to delete it themselves, so this is only possible for the user\'s own containers. The container and its content go to the trash and can be restored together. Confirm with the user before deleting. Afterwards, tell the user only that the container is in the trash and can be restored; do not list its content.')]
class ContainersDelete extends McpTool
{
    protected ?ToolKind $kind = ToolKind::Delete;

    protected ?AgentGroup $group = AgentGroup::Containers;

    /**
     * @return list<class-string>
     */
    public function actions(): array
    {
        return [TrashContainer::class];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'container' => $schema->string()->description('The ULID of the container to delete.')->required(),
        ];
    }

    protected function run(Request $request, Agent $agent): Response|ResponseFactory
    {
        $args = $request->validate(['container' => ['required', 'string']]);

        $container = Container::query()->with('account')->where('ulid', $args['container'])->first();

        if ($container === null) {
            throw new McpToolException('resource.not_found');
        }

        $this->requireLevel($agent, AccessLevel::DELETE, $container);

        // `ContainerPolicy::delete()` är användarens eget svar, inte agentens
        // nivå — Riktning 7: agenten får radera bara det användaren själv får.
        if (! Gate::forUser($agent->user)->allows('delete', $container)) {
            throw new McpToolException('agent.forbidden');
        }

        ['batch' => $batch] = $this->deleteAsAgent(
            $agent,
            fn () => app(TrashContainer::class)->handle($container, $agent->user),
        );

        return Response::structured([
            'deleted' => true,
            'container' => $container->ulid,
            'batch' => $batch,
        ]);
    }
}
