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
 * **`avatar` är personens profilbild** ([[ADR-0076 Profilbilden]] § 2, 6 och
 * 7, issue 380 och 382): `null` när ingen bild är satt, annars bilagans
 * `ulid`, `has_thumb`, `focus` och `zoom`. Fältet finns alltid, som
 * `email_verified_at` — vyn ska kunna skilja "ingen bild" från "fältet finns
 * inte" och rita initialerna i det första fallet. `has_thumb` är samma
 * läsning som App\Actions\Item\ResolveItemCover::thumbnails(): miniatyren
 * ritas bara när varianten `thumb` finns (issue 61b § Beslut 1). En
 * mjukraderad bilaga svarar `null`, eftersom relationen bär SoftDeletes-scopet.
 *
 * **`focus` och `zoom` är beskärningen** (ADR-0076 § 7). Kolumnerna är
 * nullbara, och NULL betyder mitten respektive 100 % — resursen fyller i
 * standardvärdena så att klienten slipper två former av samma sak: den som
 * aldrig beskuret sin bild får `{ focus: { x: 50, y: 50 }, zoom: 100 }`.
 * Räkningen som gör värdena till en ritad bild bor i
 * resources/js/composables/useAvatarCrop.js.
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
        $avatar = $this->avatarAttachment;

        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'unit_system' => $this->unit_system,
            'avatar' => $avatar === null ? null : [
                'ulid' => $avatar->ulid,
                'has_thumb' => $avatar->storedFile->derivatives->contains('variant', 'thumb'),
                'focus' => [
                    'x' => $this->avatar_focus_x ?? 50,
                    'y' => $this->avatar_focus_y ?? 50,
                ],
                'zoom' => $this->avatar_zoom ?? 100,
            ],
        ];
    }
}
