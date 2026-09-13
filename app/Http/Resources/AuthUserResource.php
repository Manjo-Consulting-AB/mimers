<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Den inloggade personens EGEN vy av sig själv, se issue 51 § Beslut 3.
 *
 * Medvetet inte samma resurs som App\Http\Resources\ParticipantResource:
 * den bär hur en användare ser ut för ANDRA och lämnar aldrig en
 * e-postadress. Den här bär personens egna uppgifter — e-post och
 * inställningar — och får bara delas med personen själv, via
 * HandleInertiaRequests::share(). De två får aldrig slås ihop; läser du
 * ParticipantResources docblock ser du varför.
 *
 * `ulid` utåt, aldrig löpnumret, och tidsstämplar i ISO 8601 — samma
 * prejudikat som App\Http\Resources\ContainerResource (AGENTS.md
 * § Databaskonventioner). `email_verified_at` är null för en overifierad
 * användare, aldrig utelämnad: klienten ska kunna skilja "inte verifierad"
 * från "fältet finns inte".
 *
 * @mixin User
 */
class AuthUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'unit_system' => $this->unit_system,
        ];
    }
}
