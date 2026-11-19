<?php

namespace App\Support\Mcp;

use App\Models\Agent;
use App\Models\Item;
use Illuminate\Support\Facades\URL;

/**
 * Den signerade uppladdningsadressen från `attachments_upload_url`, se
 * [[ADR-0087 MCP-server för agenter]] Riktning 13 och issue 405: den gäller i
 * 15 minuter, kan användas en gång och är bunden till agenten, itemet,
 * filnamnet och den angivna storleken.
 *
 * Bindningen ligger i signaturen — agentens och itemets ULID:er står i
 * sökvägen, filnamnet och storleken i frågesträngen, och alla fyra ingår i
 * den signerade URL:en. Att byta något av dem bryter signaturen.
 * Engångsanvändningen hålls av App\Mcp\Http\AttachmentUploadController.
 *
 * Adressen är ingen REST-rutt under `/api` och kräver ingen Sanctum-token:
 * signaturen är autentiseringen, precis som för nedladdningsadressen i
 * App\Support\Mcp\AttachmentDownloadLink.
 */
final class AttachmentUploadLink
{
    public const TTL_MINUTES = 15;

    public const ROUTE = 'mcp.files.upload';

    public static function make(Agent $agent, Item $item, string $filename, int $byteSize): string
    {
        return URL::temporarySignedRoute(
            self::ROUTE,
            now()->addMinutes(self::TTL_MINUTES),
            [
                'item' => $item->ulid,
                'agent' => $agent->ulid,
                'filename' => $filename,
                'byte_size' => $byteSize,
            ],
        );
    }
}
