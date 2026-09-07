<?php

namespace App\Http\Requests\OwnershipTransfer;

use App\Models\Container;
use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/containers/{container}/transfers, se issue 39a § Beslut 4 och 5.
 *
 * Exakt en mottagarväg (Beslut 4): kroppen har `to_account` (ett kontos
 * ULID) ELLER `to_email` — aldrig båda, aldrig ingen. `to_account` löses upp
 * till id av kontrollern; `to_email` normaliseras till gemener där, som
 * inbjudningarna (issue 10a § Beslut 6).
 *
 * `excluded_items` (Beslut 5) är en lista av item-ULID:er, alltid frivillig —
 * tom lista serialiseras som `[]`. Varje ULID måste peka på ett icke
 * mjukraderat item i DEN HÄR containern; kontrollen behöver containern från
 * rutten och bor därför i withValidator() nedan. Ett item i någon annans
 * pärm i listan är inte ett stavfel, det är ett försök.
 *
 * `retain_access_level` (Beslut 6) är `read` eller `write`, eller null —
 * samma två nivåer som `container_access.level` bär i dag. Laddern
 * (read < create < write < delete) är issue 69 i M11 och finns ännu inte i
 * koden.
 */
class StoreOwnershipTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Behörighet avgörs av App\Policies\ContainerPolicy::transfer() i
        // kontrollern, inte här — se klassdokumentationen ovan.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to_account' => ['nullable', 'string', 'ulid', Rule::exists('account', 'ulid'), 'prohibits:to_email', 'required_without:to_email'],
            'to_email' => ['nullable', 'string', 'email', 'max:255', 'prohibits:to_account', 'required_without:to_account'],
            'excluded_items' => ['sometimes', 'array'],
            'excluded_items.*' => ['string'],
            'retain_access_level' => ['nullable', 'string', Rule::in(['read', 'write'])],
        ];
    }

    /**
     * Varje ULID i `excluded_items` måste peka på ett levande item i
     * containern från rutten — en enda COUNT mot `item` med SoftDeletes'
     * globala scope (mjukraderade items försvinner ur frågan av sig själva).
     * Duplikat i listan är ofarliga, så kontrollen räknar UNIKA värden.
     *
     * Fältet markeras med `errors()->add()` i stället för en fristående
     * regel — villkoret går inte att uttrycka som en fältregel, och
     * höljet `validation.failed` är detsamma, se samma mönster som
     * StoreContainerAccessRequest::withValidator().
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $items = $this->input('excluded_items');

            if (! is_array($items) || $items === []) {
                return;
            }

            $container = $this->route('container');

            if (! $container instanceof Container) {
                return;
            }

            $unique = array_values(array_unique($items));
            $found = Item::query()
                ->where('container_id', $container->id)
                ->whereIn('ulid', $unique)
                ->count();

            if ($found !== count($unique)) {
                $validator->errors()->add('excluded_items', 'validation.failed');
            }
        });
    }
}
