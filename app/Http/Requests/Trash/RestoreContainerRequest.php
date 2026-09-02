<?php

namespace App\Http\Requests\Trash;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/trash/containers/restore, se issue 20c § Beslut 1 och 3. Kroppen
 * är `{"ulid": "..."}` — samma form som 20a § Beslut 6, ett steg upp. Här
 * finns ingen `{container}`-ruttparameter: en raderad container kan inte
 * nästlas under sig själv (ruttbindningen ser bara levande rader), så ULID:en
 * ligger i kroppen och toppnivårutten gör hela arbetet.
 *
 * Valideringen bevisar att ULID:en finns i `container`-tabellen och är
 * mjukraderad — en levande container eller en okänd ULID är 422
 * `validation.failed`. `Rule::exists` går direkt mot tabellen och ser även
 * mjukraderade rader, vilket är precis det vi vill här — i kontrast till de
 * flesta andra uppslag som tvärtom lägger `whereNull('deleted_at')`.
 *
 * Betydelsefullt: utgångna containers (äldre än retentionen) faller INTE
 * här — de finns och är mjukraderade, så `exists` passerar dem. Att de ändå
 * inte går att återställa (404 `resource.not_found`, § Beslut 3) avgörs av
 * uppslaget i kontrollern, inte av valideringen: att filtrera på retention
 * här skulle göra en utgången container till ett 422-valideringsfel. Samma
 * gränsdragning som RestoreRequest (issue 20a § Beslut 6 och 5).
 *
 * Auktorisationen avgörs av kontrollern mot `ContainerPolicy::delete()`,
 * inte här — samma mönster som RestoreRequest::authorize() (issue 20a §
 * Beslut 6).
 */
class RestoreContainerRequest extends FormRequest
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
        return [
            'ulid' => ['required', 'string', Rule::exists('container', 'ulid')->where(
                fn ($query) => $query->whereNotNull('deleted_at')
            )],
        ];
    }
}
