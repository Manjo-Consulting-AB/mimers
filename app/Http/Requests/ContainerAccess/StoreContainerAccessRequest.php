<?php

namespace App\Http\Requests\ContainerAccess;

use App\Models\Account;
use App\Models\Container;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/containers/{container}/accesses, se issue 9b § Beslut 3:
 * kroppen är `{grantee_type, grantee, level, kind, expires_at?}`.
 *
 * `grantee` är ett ULID (mottagarens, inte ett löpnummer), precis som
 * `account` i App\Http\Requests\Container\StoreContainerRequest — samma
 * 422-kontra-403-resonemang: ett `grantee`-ULID som inte finns ALLS är ett
 * VALIDERINGSFEL (`exists`-regeln nedan, se issue 9b § Beslut 5), skiljt
 * från ett behörighetsfel som avgörs av App\Policies\ContainerPolicy i
 * kontrollern, inte här.
 *
 * `exists`-regeln byggs utifrån det INSKICKADE `grantee_type` och hoppas
 * helt över när `grantee_type` inte är `user` eller `account` —
 * `Rule::in` på `grantee_type` ger då redan sitt eget 422, och en `exists`
 * mot en påhittad tabell ska aldrig hinna köras, se issue 9b § Beslut 5.
 *
 * Två ytterligare kontroller kräver mer än en enskild fältregel och bor
 * därför i withValidator() nedan, se issue 9b § Beslut 4 och § Beslut 6:
 * de är formkontroller av indata, inte behörighetslogik, och `kind` får
 * bara granskas HÄR — ingen annanstans i kodbasen.
 */
class StoreContainerAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Behörighet avgörs av App\Policies\ContainerPolicy::manageAccess()
        // i kontrollern, se klassdokumentationen ovan.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $granteeType = $this->input('grantee_type');

        $granteeRules = ['required', 'string'];

        if ($granteeType === 'user') {
            $granteeRules[] = Rule::exists('user', 'ulid');
        } elseif ($granteeType === 'account') {
            $granteeRules[] = Rule::exists('account', 'ulid');
        }

        return [
            'grantee_type' => ['required', 'string', Rule::in(['user', 'account'])],
            'grantee' => $granteeRules,
            'level' => ['required', 'string', Rule::in(['read', 'write'])],
            'kind' => ['required', 'string', Rule::in(['member', 'managed', 'guest'])],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * Två kontroller som behöver mer än ett fält var för sig:
     *
     * - Issue 9b § Beslut 4: `kind` och `grantee_type` måste stämma
     *   överens — `managed` kräver `grantee_type = account`,
     *   `member`/`guest` kräver `grantee_type = user`.
     * - Issue 9b § Beslut 6: ägarkontot kan inte beviljas åtkomst till sin
     *   egen container — kräver containern från rutten, så kontrollen kan
     *   inte uttryckas som en fristående fältregel.
     *
     * Båda hoppar över sig själva om de fält de bygger på redan är
     * ogiltiga (fångat av `Rule::in` ovan) eller saknas — ingen mening att
     * lägga ett andra, missvisande fel ovanpå det första.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $kind = $this->input('kind');
            $granteeType = $this->input('grantee_type');

            if (in_array($kind, ['member', 'guest', 'managed'], true) && in_array($granteeType, ['user', 'account'], true)) {
                $expectedGranteeType = $kind === 'managed' ? 'account' : 'user';

                if ($granteeType !== $expectedGranteeType) {
                    $validator->errors()->add('kind', 'validation.failed');
                }
            }

            if ($granteeType === 'account' && is_string($this->input('grantee'))) {
                $container = $this->route('container');

                if ($container instanceof Container) {
                    $account = Account::where('ulid', $this->input('grantee'))->first();

                    if ($account && $account->id === $container->account_id) {
                        $validator->errors()->add('grantee', 'validation.failed');
                    }
                }
            }
        });
    }
}
