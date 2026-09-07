<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

/**
 * Resursformatet för en audit_log-rad, se issue 40 § Beslut 11. Inga
 * löpnummer: varken radens egen `id`, den handlande användarens eller
 * subjektets — `subject_id` bär redan subjektets ULID (Beslut 5).
 *
 * Den handlande användaren redovisas som `user` med `ulid` och `name`,
 * aldrig med en e-postadress — samma identitetsregel som deltagarlistan,
 * [[Konton och åtkomst]] § Behörighetsregler sista stycket. Är `user_id`
 * null (en händelse ett jobb orsakat) är `user` null, inte en påhittad
 * systemanvändare. Relationen `user` måste vara laddad — kontrollern gör
 * `->with('user')` — annars blir listan N+1.
 *
 * `meta` är händelsens data. Tom serialiseras som `{}`, aldrig `[]` (Beslut
 * 6) — samma regel som felformatets `data`, se AGENTS.md § Felformat.
 * `response()->json()` gör `[]` av en tom PHP-array, så den tomma arrayen
 * lindas i stdClass här innan serialisering.
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'action' => $this->action,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'meta' => $this->meta === [] ? new stdClass : $this->meta,
            'user' => $this->whenLoaded('user', fn (): ?array => $this->user !== null
                ? ['ulid' => $this->user->ulid, 'name' => $this->user->name]
                : null),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
