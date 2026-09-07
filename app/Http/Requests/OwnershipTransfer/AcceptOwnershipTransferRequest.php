<?php

namespace App\Http\Requests\OwnershipTransfer;

use App\Models\OwnershipTransfer;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/transfers/{transfer}/accept, se issue 39b § Beslut 2.
 *
 * Kroppen bär `to_account` — mottagarkontots ULID — men bara när det finns
 * ett val att göra. Är `to_account_id` redan satt på raden är mottagaren
 * bestämd av avsändaren: kroppens `to_account` är då frivillig och måste i
 * så fall peka på SAMMA konto, annars `validation.failed`. Är bara
 * `to_email` satt måste kroppen däremot bära `to_account`: den inloggade
 * användaren kan vara medlem i flera konton, och systemet får inte gissa
 * vilket av dem som köpte båten.
 *
 * Vilket av de två lägena som gäller avgörs av raden i rutten — den är
 * upplöst av route-modellbindningen när den här klassen validerar, samma
 * mönster som StoreOwnershipTransferRequest::withValidator().
 *
 * Behörighet avgörs inte här: mottagarens urval (vilka rader som ÄR hennes)
 * prövas av App\Http\Controllers\Api\OwnershipTransferController, aldrig av
 * en policy — en rad som inte pekar på användaren ska vara osynlig (issue
 * 39a § Beslut 15). En utomstående ska få 404, inte ett valideringssvar som
 * läcker att raden finns; se Frågor och antaganden i PR:en för den
 * begränsning som följer av att `to_account`-regeln måste formas före det
 * urvalet.
 */
class AcceptOwnershipTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $transfer = $this->route('transfer');

        if ($transfer instanceof OwnershipTransfer) {
            return $transfer->to_account_id === null
                ? $this->toEmailRegler()
                : $this->toAccountRegler($transfer);
        }

        return ['to_account' => ['nullable', 'string', 'ulid']];
    }

    /**
     * Raden nåddes på `to_email`: kroppen MÅSTE peka ut mottagarkontot, och
     * kontot måste vara ett som användaren är medlem i — annars vore varje
     * gissning på vilket konto som köpte båten en felaktig sådan.
     *
     * @return array<string, mixed>
     */
    private function toEmailRegler(): array
    {
        $user = $this->user();

        $regler = [
            'to_account' => ['required', 'string', 'ulid'],
        ];

        if ($user instanceof User) {
            $accountIds = $user->accounts->pluck('id');

            $regler['to_account'][] = Rule::exists('account', 'ulid')
                ->where(fn ($query) => $query->whereIn('id', $accountIds));
        }

        return $regler;
    }

    /**
     * Raden har `to_account_id` redan satt: kroppens `to_account` är
     * frivillig, men skickas den måste den vara samma konto — en avsändare
     * som bestämt mottagaren ska inte kunna dirigeras om av en kropp.
     *
     * @return array<string, mixed>
     */
    private function toAccountRegler(OwnershipTransfer $transfer): array
    {
        return [
            'to_account' => [
                'nullable',
                'string',
                'ulid',
                Rule::exists('account', 'ulid')
                    ->where(fn ($query) => $query->where('id', $transfer->to_account_id)),
            ],
        ];
    }
}
