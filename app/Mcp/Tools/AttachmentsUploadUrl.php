<?php

namespace App\Mcp\Tools;

use App\Actions\Agent\ResolveAgentAccount;
use App\Models\Agent;
use App\Models\Container;
use App\Models\Item;
use App\Support\Access\AccessLevel;
use App\Support\Agent\AgentGroup;
use App\Support\Agent\ItemAudience;
use App\Support\Mcp\AttachmentUploadLink;
use App\Support\Mcp\McpToolException;
use App\Support\Mcp\ToolKind;
use App\Support\Plan\Entitlements;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * `attachments_upload_url` — den signerade uppladdningsadressen för filer
 * som inte får plats i verktygsanropet, se [[ADR-0087 MCP-server för
 * agenter]] Riktning 13 och issue 405.
 *
 * Adressen gäller i 15 minuter, kan användas en gång och är bunden till
 * agenten, itemet, filnamnet och den angivna storleken
 * (App\Support\Mcp\AttachmentUploadLink). Den tar emot en `PUT` med filens
 * byten och lämnar bilagans ULID. Den är ingen REST-rutt under `/api` och
 * kräver ingen Sanctum-token.
 *
 * **Gränsen är planens `max_file_bytes`.** `byte_size` är ett tak som
 * agenten anger, och det prövas mot planen redan här; den faktiska filen
 * prövas en gång till vid `PUT`, eftersom planen kan ha sänkts under
 * adressens livstid. Kvoten prövas på samma sätt vid uppladdningen.
 *
 * Rättigheten `create` i gruppen `files` och kopieringsspärren prövas som
 * för `attachments_upload`; adressens `PUT` prövar båda en gång till, så att
 * en paus eller en återkallad rättighet stänger en adress som redan lämnats
 * ut.
 */
#[Name('attachments_upload_url')]
#[Description('Returns a short-lived signed URL for uploading one file to an item. PUT the raw file bytes to the URL: it is valid for 15 minutes, usable once, and bound to this agent, this item, the file name and byte_size. byte_size is an upper bound and may not exceed the plan limit. The PUT response contains the attachment ULID. Use attachments_upload for files up to 20 MB.')]
class AttachmentsUploadUrl extends McpTool
{
    protected ?ToolKind $kind = ToolKind::Upload;

    protected ?AgentGroup $group = AgentGroup::Files;

    public function schema(JsonSchema $schema): array
    {
        return [
            'item' => $schema->string()->description('The ULID of the item the file belongs to.')->required(),
            'filename' => $schema->string()->description('The file name shown to people.')->required(),
            'byte_size' => $schema->integer()->min(1)->description('The size of the file in bytes. An upper bound for the PUT; must not exceed the plan limit.')->required(),
        ];
    }

    protected function run(Request $request, Agent $agent): Response|ResponseFactory
    {
        $args = $request->validate([
            'item' => ['required', 'string'],
            'filename' => ['required', 'string', 'max:255'],
            'byte_size' => ['required', 'integer', 'min:1'],
        ]);

        $byteSize = (int) $args['byte_size'];

        $item = Item::query()->where('ulid', $args['item'])->first();
        $container = $item === null ? null : Container::query()->with('account')->find($item->container_id);

        if ($item === null || $container === null || $item->trashed() || $container->trashed()) {
            throw new McpToolException('resource.not_found');
        }

        $this->requireLevel($agent, AccessLevel::CREATE, $container, $item);

        $this->guardCopy($agent, app(ItemAudience::class)->forItem($container, $item));

        $account = app(ResolveAgentAccount::class)->handle($agent->user);
        app(Entitlements::class)->assertFileWithinLimit($account, $byteSize);

        return Response::structured([
            'upload_url' => AttachmentUploadLink::make($agent, $item, $args['filename'], $byteSize),
            'method' => 'PUT',
            'expires_in_minutes' => AttachmentUploadLink::TTL_MINUTES,
            'single_use' => true,
            'filename' => $args['filename'],
            'byte_size' => $byteSize,
        ]);
    }
}
