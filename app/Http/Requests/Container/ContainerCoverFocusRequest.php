<?php

namespace App\Http\Requests\Container;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /containers/{container}/cover/focus — fokuspunkten på containerns
 * bild, se issue 682 och [[ADR-0047 Containerns bild]]. Kroppen är två tal i
 * procent: `x` och `y`.
 *
 * **Båda är obligatoriska och heltal mellan 0 och 100.** En punkt är ett par —
 * ett ensamt x är ingen punkt — och kolumnerna på `container` är nullbara
 * TILLSAMMANS (CHECK-villkoret `container_cover_focus_pair`, se
 * database/migrations/2026_10_04_000000). `integer` fångar `'a'`, och
 * `between:0,100` fångar ett värde utanför bilden; båda blir formulärfel på
 * sitt eget fält, som webbens validering alltid blir (AGENTS.md § Felformat i
 * API:et: höljet gäller `/api`, inte webbsidorna).
 *
 * **`authorize()` returnerar `true`.** Behörigheten är `ContainerPolicy::update`
 * och prövas i App\Actions\Container\SetContainerCoverFocus::handle() — samma
 * pinne som att sätta bilden, och samma uppdelning som ContainerCoverRequest.
 */
class ContainerCoverFocusRequest extends FormRequest
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
            'x' => ['required', 'integer', 'between:0,100'],
            'y' => ['required', 'integer', 'between:0,100'],
        ];
    }
}
