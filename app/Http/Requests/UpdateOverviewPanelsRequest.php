<?php

namespace App\Http\Requests;

use App\Support\Frontend\OverviewPanels;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Listan personen sparar, se [[ADR-0073 Översiktens ordning]] § 7: varje
 * känd nyckel exakt en gång och en känd bredd per post. Annars valideringsfel
 * och ingenting skrivs.
 *
 * Fem poster som alla är kända och inbördes olika (`distinct`) är exakt
 * panellistan, så `size` + `in` + `distinct` räcker som täckningsprov.
 */
class UpdateOverviewPanelsRequest extends FormRequest
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
            'panels' => ['required', 'array', 'size:'.count(OverviewPanels::keys())],
            'panels.*' => ['required', 'array'],
            'panels.*.key' => ['required', 'string', 'distinct', Rule::in(OverviewPanels::keys())],
            'panels.*.width' => ['required', 'string', Rule::in(OverviewPanels::WIDTHS)],
        ];
    }
}
