<?php

namespace App\Support\Mcp;

use App\Models\Agent;
use App\Models\Attachment;
use App\Support\Files\FileOrigin;
use Illuminate\Support\Facades\URL;

/**
 * Den signerade nedladdningsadressen för en bilaga över 10 MB, se
 * [[ADR-0087 MCP-server för agenter]] Riktning 13 och 16: den gäller i 15
 * minuter, kan användas en gång och är bunden till agenten och bilagan.
 *
 * Bindningen ligger i signaturen — både bilagans och agentens ULID ingår i
 * adressen, så att byta någon av dem bryter signaturen. Engångsanvändningen
 * hålls av App\Mcp\Http\AttachmentDownloadController.
 */
final class AttachmentDownloadLink
{
    public const TTL_MINUTES = 15;

    public const ROUTE = 'mcp.files.download';

    public static function make(Agent $agent, Attachment $attachment): string
    {
        return URL::temporarySignedRoute(
            self::ROUTE,
            now()->addMinutes(self::TTL_MINUTES),
            ['attachment' => $attachment->ulid, 'agent' => $agent->ulid],
        );
    }

    /**
     * Filoriginens värdnamn när ett sådant finns — användarfiler serveras
     * aldrig från appens egen origin ([[Filer och lagring]] § Säkerhet vid
     * leverans).
     */
    public static function host(): ?string
    {
        return FileOrigin::host();
    }
}
