<?php

namespace App\Mcp\Http;

use App\Actions\Agent\ResolveAgentAccess;
use App\Actions\Agent\ResolveAgentAccount;
use App\Actions\Attachment\StoreAttachment;
use App\Actions\Usage\CountFeatureUse;
use App\Models\Agent;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Support\Access\AccessLevel;
use App\Support\Agent\AgentGroup;
use App\Support\Agent\CopyGate;
use App\Support\Agent\ItemAudience;
use App\Support\Mcp\AgentAudit;
use App\Support\Mcp\AttachmentUploadLink;
use App\Support\Mcp\McpToolException;
use App\Support\Mcp\UploadBuffer;
use App\Support\Plan\Entitlements;
use App\Support\Usage\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Uppladdningen bakom adressen från `attachments_upload_url`, se
 * App\Support\Mcp\AttachmentUploadLink och [[ADR-0087 MCP-server för
 * agenter]] Riktning 13 samt issue 405.
 *
 * Rutten är `signed` — signaturen är autentiseringen, ingen Sanctum-token —
 * men åtkomsten prövas på nytt vid användningen: agenten ska finnas kvar,
 * vara aktiv och fortfarande ha `create` i gruppen `files` på itemet. En
 * paus, en återkallad rättighet eller en nedgradering stänger alltså en
 * adress som redan lämnats ut. Kopieringsspärren prövas också här, inte
 * bara när adressen lämnades: en läsning som görs mellan de två anropen kan
 * ha krympt agentens lässnitt.
 *
 * **Storleken.** Adressen är bunden till den angivna storleken, som är ett
 * tak — bytena får vara mindre, aldrig större. `Content-Length` läses aldrig
 * som sanning: kroppen strömmas till en temporär fil och räknas under vägen,
 * så en lögnaktig längd inte kan fylla minnet. Planens `max_file_bytes`
 * prövas mot de faktiska byten, eftersom planen kan ha sänkts sedan adressen
 * lämnades.
 *
 * **En användning.** Signaturen spärras i cachen med `Cache::add`, som är
 * atomär, innan kroppen läses. Ett misslyckat försök lämnar adressen öppen
 * igen — det skapade ingen bilaga, och agenten ska kunna rätta sig. Svaren
 * är statuskoder som nedladdningsadressens; kvot- och storleksfel som
 * Entitlements kastar renderas i felhöljet av ApiException själv.
 *
 * `StoreAttachment` är samma väg som appens: samma plangräns, virusskanning,
 * dedup, referensräkning och kvot. `billed_account_id` är agentens
 * användares personkonto (Riktning 4), och händelseloggen märks med agenten
 * genom App\Support\Mcp\AgentAudit — adressens anrop går inte genom
 * McpTool::handle(), så sammanhanget sätts här.
 */
class AttachmentUploadController
{
    public function __invoke(
        Request $request,
        Item $item,
        Agent $agent,
        StoreAttachment $storeAttachment,
        Entitlements $entitlements,
    ): JsonResponse {
        $filename = (string) $request->query('filename', '');
        $byteSize = (int) $request->query('byte_size', 0);

        // Signerade värden; middlewaret har redan prövat signaturen. Saknas
        // de är URL:en inte den vi lämnade.
        if ($filename === '' || $byteSize < 1) {
            abort(422);
        }

        $container = Container::query()->with('account')->find($item->container_id);

        if ($container === null || $item->trashed() || $container->trashed()) {
            abort(404);
        }

        $level = app(ResolveAgentAccess::class)->handle($agent, AgentGroup::Files, $container, $item);

        if ($level === null) {
            abort(404);
        }

        if (! AccessLevel::atLeast($level, AccessLevel::CREATE)) {
            abort(403);
        }

        try {
            app(CopyGate::class)->assert($agent, app(ItemAudience::class)->forItem($container, $item));
        } catch (McpToolException) {
            abort(403);
        }

        $key = 'mcp-upload:'.hash('sha256', (string) $request->query('signature'));

        if (! Cache::add($key, 1, now()->addMinutes(AttachmentUploadLink::TTL_MINUTES))) {
            abort(410);
        }

        $buffer = new UploadBuffer($filename);
        $stored = false;

        try {
            $buffer = $this->readBody($request, $buffer, $byteSize);

            if ($buffer->bytes() === 0) {
                abort(422);
            }

            $account = app(ResolveAgentAccount::class)->handle($agent->user);

            // Planens styckgräns mot de FAKTISKA byten — den angivna
            // storleken var bara ett tak. Kvoten prövas billigt här och
            // auktoritativt inne i StoreAttachments transaktion.
            $entitlements->assertFileWithinLimit($account, $buffer->bytes());
            $entitlements->assertStorageWithinLimit($account, $buffer->bytes());

            $audit = AgentAudit::forRequest();
            $audit->begin($agent);

            try {
                $attachment = $storeAttachment->handle(
                    item: $item,
                    file: $buffer->uploadedFile(),
                    user: $agent->user,
                    account: $account,
                );
            } finally {
                $audit->end();
            }

            app(CountFeatureUse::class)->handle(Features::ATTACHMENT_UPLOAD, $agent->user);

            $stored = true;

            return response()->json($this->payload($attachment, $item), 201);
        } finally {
            $buffer->discard();

            if (! $stored) {
                // Ingen bilaga skapades: adressen ska gå att använda igen.
                Cache::forget($key);
            }
        }
    }

    /**
     * Strömma kroppen till den temporära filen och avvisa så fort den går
     * över den angivna storleken. `getContent(true)` ger `php://input` i
     * drift och en minnesström i testsviten, så samma kod läser båda.
     */
    private function readBody(Request $request, UploadBuffer $buffer, int $maxBytes): UploadBuffer
    {
        $input = $request->getContent(true);

        while (! feof($input)) {
            $chunk = fread($input, 65536);

            if ($chunk === false || $chunk === '') {
                break;
            }

            if ($buffer->bytes() + strlen($chunk) > $maxBytes) {
                abort(413);
            }

            $buffer->write($chunk);
        }

        return $buffer;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Attachment $attachment, Item $item): array
    {
        $file = $attachment->storedFile;

        return [
            'ulid' => $attachment->ulid,
            'filename' => $attachment->filename,
            'kind' => $attachment->kind,
            'mime_type' => $file->mime_type,
            'byte_size' => (int) $file->byte_size,
            'item' => ['ulid' => $item->ulid, 'name' => $item->name],
        ];
    }
}
