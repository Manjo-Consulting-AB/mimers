<?php

namespace App\Http\Requests\Container;

use App\Models\Container;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * PATCH /containers/{container}/versioning — containerns eget tak, se
 * [[ADR-0088 Versionshistorik för items]] Riktning 1 och issue 411.
 *
 * Kroppen bär bara `versioning_max_versions`:
 *
 * - `null` betyder *Follow account*: containern följer kontots värde.
 * - `0` betyder *Off*: versionshistoriken är av i containern.
 * - Ett tal är containerns eget tak och får aldrig överstiga kontots.
 *
 * Talet över kontots avvisas med felkoden `versioning.above_account`.
 * Meddelandet ligger i `lang/en/validation.php` under just den nyckeln, samma
 * väg som App\Rules\RedeemableVoucher och App\Rules\IsoCurrency använder.
 *
 * **Behörigheten prövas FÖRE valideringen.** Grinden är
 * `ContainerPolicy::delete`, samma som raderingen: bara ägarkontots egna
 * medlemmar får röra taket. Den står i authorize() och inte bara i
 * App\Actions\Container\SetContainerVersioning, eftersom en inbjuden med
 * `write` annars fick 422 ur `above_account` och kunde sondera kontots tak
 * innan 403:an kom. Actionen prövar grinden på nytt — den är ytan för varje
 * anropare.
 */
class UpdateContainerVersioningRequest extends FormRequest
{
    public function authorize(): bool
    {
        $container = $this->route('container');

        return $container instanceof Container
            && Gate::allows('delete', $container);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'versioning_max_versions' => ['present', 'nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Containerns värde får inte ligga över kontots. Kontrollen behöver
     * containern från rutten och kan därför inte vara en fältregel.
     *
     * Ett `null` är alltid tillåtet — det är *Follow account* — och ett värde
     * som redan fallit på `integer`/`min:0` får inget andra fel ovanpå.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $value = $this->input('versioning_max_versions');

            if ($value === null || ! is_numeric($value)) {
                return;
            }

            $container = $this->route('container');

            if (! $container instanceof Container) {
                return;
            }

            $accountLimit = (int) $container->account->versioning_max_versions;

            if ((int) $value > $accountLimit) {
                $validator->errors()->add(
                    'versioning_max_versions',
                    trans('validation.versioning.above_account'),
                );
            }
        });
    }
}
