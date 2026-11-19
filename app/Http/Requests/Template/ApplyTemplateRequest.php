<?php

namespace App\Http\Requests\Template;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/containers/{container}/template — tillämpa en mall på en
 * befintlig container, se issue 421 § 3 och 5.
 *
 * Kroppen bär EXAKT en av två saker:
 *
 * - `template`, en mall-ULID i ett konto användaren är medlem i. Regeln är
 *   `exists` och inte routebindning — parametern kommer i kroppen, och en
 *   okänd mall är därför ett valideringsfel precis som `account` i
 *   StoreContainerRequest.
 * - `template_content`, samma JSON som en malls `content`. Det är den
 *   inbyggda uppsättning som frontenden skickar, och den tillämpas utan att
 *   något sparas ([[ADR-0089 Egna fält på items]] § Riktning 6).
 *
 * `required_without` gör att ett anrop utan någondera avvisas, och `prohibits`
 * att båda skickas — rutten har inget rimligt svar på vilken som skulle gälla.
 * Skrivrätten i containern prövas av kontrollern (issue 421 § 4).
 */
class ApplyTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Behörigheten prövas av kontrollern — se klassens docblock.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'template' => [
                'nullable',
                'string',
                'required_without:template_content',
                'prohibits:template_content',
                Rule::exists('container_template', 'ulid')->where(
                    fn ($query) => $query
                        ->whereNull('deleted_at')
                        ->whereIn('account_id', $this->user()?->accounts()->pluck('account.id')->all() ?? [])
                ),
            ],
            'template_content' => ['nullable', 'array', 'required_without:template'],
            ...TemplateContentRules::rules('template_content.'),
        ];
    }
}
