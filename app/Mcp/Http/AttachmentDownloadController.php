<?php

namespace App\Mcp\Http;

use App\Actions\Agent\ResolveAgentAccess;
use App\Models\Agent;
use App\Models\Attachment;
use App\Support\Access\AccessLevel;
use App\Support\Agent\AgentGroup;
use App\Support\Files\AttachmentDelivery;
use App\Support\Mcp\AttachmentDownloadLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nedladdningen bakom adressen från `attachments_get`, se
 * App\Support\Mcp\AttachmentDownloadLink. Rutten är `signed` — signaturen är
 * autentiseringen — men åtkomsten prövas på nytt vid användningen: agenten
 * ska finnas kvar, vara aktiv och fortfarande nå bilagan med `read` i gruppen
 * `files`. En paus eller en återkallad rättighet stänger alltså en adress som
 * redan lämnats ut.
 *
 * **En användning.** Signaturen spärras i cachen med `Cache::add`, som är
 * atomär, först när allt annat har godkänts: ett nekat försök bränner inte
 * adressen. Spärren lever lika länge som adressen.
 */
class AttachmentDownloadController
{
    public function __invoke(Request $request, Attachment $attachment, Agent $agent): Response
    {
        $attachment->load(['storedFile', 'item.container.account', 'container.account']);

        $subject = $attachment->accessSubject();
        $container = $attachment->owningContainer();

        if ($container === null || $subject === null || $subject->trashed() || $container->trashed()) {
            abort(404);
        }

        $item = $attachment->item_id === null ? null : $attachment->item;
        $level = app(ResolveAgentAccess::class)->handle($agent, AgentGroup::Files, $container, $item);

        if ($level === null || ! AccessLevel::atLeast($level, AccessLevel::READ)) {
            abort(404);
        }

        $key = 'mcp-download:'.hash('sha256', (string) $request->query('signature'));

        if (! Cache::add($key, 1, now()->addMinutes(AttachmentDownloadLink::TTL_MINUTES))) {
            abort(410);
        }

        return AttachmentDelivery::make($attachment, null, inline: false);
    }
}
