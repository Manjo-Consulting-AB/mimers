<?php

namespace App\Http\Requests\ContainerAccess;

use App\Support\Access\AccessLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH /api/containers/{container}/accesses/{access}, se issue 72 § Beslut
 * 4: kroppen får bära `level` och `expires_at`, ingenting annat.
 *
 * `item_id`, `grantee_type`, `grantee_id` och `kind` går ALDRIG att ändra.
 * Att flytta en befintlig grant från ett item till ett annat, eller från en
 * mottagare till en annan, är inte en ändring av en relation — det är att
 * avsluta en och börja en annan, och historiken ska visa det. Därför
 * `prohibited` på de fyra namnen i kroppen (`item`, `grantee`,
 * `grantee_type`, `kind`): ett skickat värde är ett 422, aldrig en tyst
 * ignorering.
 *
 * Kontrollen i withValidator() täcker resten: ett fält utanför de två
 * tillåtna är 422 även om det inte finns en namngiven regel för det.
 * `Rule::prohibited` ger den exakta fältkoden för de fyra kända namnen,
 * loopen fångar allt annat — och hoppar över ett fält som redan har ett fel
 * så samma fält inte rapporteras två gånger.
 *
 * Ingen `required` på någotdera: en `PATCH` med bara `level` ska lämna
 * `expires_at` orörd, och tvärtom. Ett `expires_at: null` i kroppen rensar
 * utgången — `nullable` kortsluter `after:now`.
 *
 * Att raden redan är återkallad eller utgången är inte ett fältfel utan ett
 * tillståndsfel, och prövas i kontrollern (422
 * `container_access.revoked`), se § Beslut 4 och samma mönster i
 * App\Http\Controllers\Api\ContainerAccessController::store().
 */
class UpdateContainerAccessRequest extends FormRequest
{
    /**
     * De enda två fälten kroppen får bära, se klassens docblock.
     *
     * @var list<string>
     */
    private const WRITABLE = ['level', 'expires_at'];

    public function authorize(): bool
    {
        // Behörighet avgörs av App\Policies\ContainerPolicy::manageAccess()
        // i kontrollern, se klassens docblock.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'level' => ['sometimes', 'string', Rule::in(AccessLevel::LADDER)],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'item' => ['prohibited'],
            'grantee' => ['prohibited'],
            'grantee_type' => ['prohibited'],
            'kind' => ['prohibited'],
        ];
    }

    /**
     * Ett fält utanför WRITABLE är ett 422 även när det inte har en egen
     * namngiven regel ovan. Fält som redan har ett fel hoppas över, så ett
     * `item` i kroppen rapporteras en gång (av `prohibited`) och inte två.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (array_keys($this->all()) as $field) {
                if (in_array($field, self::WRITABLE, true) || $validator->errors()->has($field)) {
                    continue;
                }

                $validator->errors()->add($field, 'validation.prohibited');
            }
        });
    }
}
