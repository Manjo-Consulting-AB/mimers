<?php

namespace App\Http\Requests;

use App\Support\Export\BinderBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /containers/{container}/binders — valen i en pärmbeställning (issue 446 ·
 * ADR-0091). Grinden `view` ligger i kontrollern; här valideras bara kroppen.
 *
 * `sections` är en delmängd av pärmens fem avsnitt (`costs` inräknat — det är
 * kostnadernas egen kryssruta), `attachment_mode` är `link` eller `merge` och
 * `attachments` bilage-ULID:er. Vilka bilagor som faktiskt tas med avgör
 * byggaren mot beställarens omfång; ett främmande ULID tas aldrig upp.
 */
class StoreBinderRequest extends FormRequest
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
            'sections' => ['present', 'array'],
            'sections.*' => ['string', Rule::in(BinderBuilder::SECTIONS), 'distinct'],
            'attachment_mode' => ['required', Rule::in(['link', 'merge'])],
            'attachments' => ['present', 'array', 'max:1000'],
            'attachments.*' => ['string', 'size:26', 'distinct'],
        ];
    }

    /**
     * Valen som de lagras i `export.options`, i den form BinderBuilder läser.
     *
     * @return array{sections: list<string>, costs: bool, attachment_mode: string, attachments: list<string>}
     */
    public function options(): array
    {
        $sections = array_values($this->validated('sections'));

        return [
            'sections' => array_values(array_diff($sections, ['costs'])),
            'costs' => in_array('costs', $sections, true),
            'attachment_mode' => $this->validated('attachment_mode'),
            'attachments' => array_values($this->validated('attachments')),
        ];
    }
}
