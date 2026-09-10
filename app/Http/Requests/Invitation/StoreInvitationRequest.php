<?php

namespace App\Http\Requests\Invitation;

use App\Support\Access\AccessLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/containers/{container}/invitations, se issue 10a § Beslut 11:
 * kroppen är `{email, level, item?}` och ingenting annat.
 *
 * Inget `kind` — vad en accepterad inbjudan blir för sorts
 * `container_access` avgörs i 10b, och kolumnen finns inte i `invitation`.
 * Ingen `expires_at` heller: TTL:en är App\Models\Invitation::TTL_DAYS och
 * tas aldrig emot från klienten (§ Beslut 8).
 *
 * `email` valideras bara som en adress, inte mot `user`-tabellen — hela
 * poängen med en inbjudan är att mottagaren ännu INTE har konto
 * ([[ADR-0003 Åtkomstmodell]]). Normaliseringen till gemener (§ Beslut 6)
 * görs av kontrollern, inte här, så duplikatspärren och den lagrade raden
 * garanterat använder samma sträng.
 *
 * Att spärra en inbjudan till någon som redan har åtkomst hör till 10b,
 * vid accept — se issue 10a § Omfång.
 *
 * Sedan issue 72 § Beslut 1 och 2 bär kroppen även `level` ur hela laddern
 * (AccessLevel::LADDER) och ett valfritt `item`. `item` följer `category` i
 * App\Http\Requests\Item\StoreItemRequest exakt: ULID:en slås upp INOM den
 * container rutten bär och får inte vara mjukraderad, så en ULID ur en
 * annan container är ett valideringsfel (422), aldrig 404 och aldrig en
 * tyst container-bred inbjudan. `invitation.item_id` speglar
 * `container_access.item_id`, se [[Konton och åtkomst]] § invitation.
 */
class StoreInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Behörighet avgörs av App\Policies\ContainerPolicy::manageAccess()
        // i kontrollern, samma mönster som
        // App\Http\Requests\ContainerAccess\StoreContainerAccessRequest.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'level' => ['required', 'string', Rule::in(AccessLevel::LADDER)],
            'item' => [
                'nullable',
                'string',
                Rule::exists('item', 'ulid')->where(
                    fn ($query) => $query->where('container_id', $this->route('container')->id)->whereNull('deleted_at')
                ),
            ],
        ];
    }
}
