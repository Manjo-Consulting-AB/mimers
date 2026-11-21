<?php

namespace App\Http\Requests\Invitation;

use App\Support\Access\AccessLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH /containers/{container}/invitations/{invitation}, se issue 430:
 * kroppen bär `level` och, när *Agent access* finns, `agent_level`.
 *
 * **E-postadressen och omfånget går aldrig att ändra.** `email` och `item`
 * är `prohibited`, och kontrollen i withValidator() nekar varje annat fält
 * utanför de två tillåtna. Att byta adress eller item är att skicka en ny
 * inbjudan, inte att ändra den här — och `StoreInvitationRequest` hade
 * krävt ett nytt mejl för det.
 *
 * `level` är obligatoriskt: raden redigeras för nivåns skull, och en PATCH
 * utan en nivå säger ingenting om vad som ska gälla. `agent_level` är
 * valfritt och `nullable` — tomt betyder ingen agentåtkomst, samma tolkning
 * som i inbjudningsformuläret (`InvitationForm.vue`).
 *
 * Att raden redan besvarats är däremot inte ett fältfel utan ett
 * tillståndsfel, och prövas i App\Actions\Invitation\UpdateInvitation (422
 * `invitation.not_pending`), samma uppdelning som `RevokeInvitation` gör.
 */
class UpdateInvitationRequest extends FormRequest
{
    /**
     * De enda två fälten kroppen får bära, se klassens docblock.
     *
     * @var list<string>
     */
    private const WRITABLE = ['level', 'agent_level'];

    public function authorize(): bool
    {
        // Behörighet avgörs av App\Policies\ContainerPolicy::manageAccess()
        // i kontrollern, samma mönster som StoreInvitationRequest.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'level' => ['required', 'string', Rule::in(AccessLevel::LADDER)],
            'agent_level' => ['nullable', 'string', Rule::in(AccessLevel::LADDER)],
            'email' => ['prohibited'],
            'item' => ['prohibited'],
        ];
    }

    /**
     * Ett fält utanför WRITABLE är ett 422 även när det inte har en egen
     * namngiven regel ovan — samma form som
     * App\Http\Requests\ContainerAccess\UpdateContainerAccessRequest. Ett
     * fält som redan har ett fel hoppas över, så `email` rapporteras en
     * gång och inte två.
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
