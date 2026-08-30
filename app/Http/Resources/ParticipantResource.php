<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * En post i deltagarlistan, se issue 9c § Beslut 6. Bär IDENTITET, inte
 * förvaltningsdata: `level`, `expires_at`, `granted_by` och `revoked_at`
 * finns medvetet inte här, och inte heller åtkomstradens egen ULID — den
 * identifierar en förvaltningsresurs som bara ägarkontot får röra, och
 * redovisas av App\Http\Resources\ContainerAccessResource i stället.
 *
 * INGEN e-postadress lämnar den här resursen. Identiteten är `user.name`
 * respektive `account.name`, under samma nyckel `name` i båda fallen så
 * klienten inte behöver två avpackningsvägar. Båda kolumnerna är NOT NULL
 * (issue 3b § Beslut 1), så ingen fallback ska skrivas — varken
 * `?? $user->email`, `?? 'Okänd'` eller en `when()`-gren. Se
 * [[Konton och åtkomst]] § Behörighetsregler, sista stycket.
 *
 * Till skillnad från de övriga resurserna i projektet lindar den här ingen
 * Eloquent-modell: App\Http\Controllers\Api\ContainerParticipantController
 * bygger färdiga rader (`type`, `ulid`, `name`, `role`) ur tre platta
 * frågor — en åtkomstrad och ägarkontot har inte samma modelltyp, och en
 * `grantee()`-relation finns medvetet inte (se App\Models\ContainerAccess
 * docblock). Resursen redovisar alltså bara raden som den är, och slår
 * aldrig upp något själv.
 *
 * @property array{type: string, ulid: string, name: string, role: string} $resource
 */
class ParticipantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->resource['type'],
            'ulid' => $this->resource['ulid'],
            'name' => $this->resource['name'],
            'role' => $this->resource['role'],
        ];
    }
}
