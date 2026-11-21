<?php

namespace App\Http\Requests\Container;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /containers/{container}/cover/focus — utsnittet på containerns bild,
 * se issue 682 och [[ADR-0047 Containerns bild]], och zoomen i issue 433 ·
 * [[ADR-0090 Dashboardens hjälte]] § 5. Kroppen är tre tal i procent: `x`,
 * `y` och `zoom`.
 *
 * **`x` och `y` är obligatoriska, `zoom` är frivillig.** En punkt är ett par —
 * ett ensamt x är ingen punkt — och kolumnerna på `container` är nullbara
 * TILLSAMMAN (CHECK-villkoret `container_cover_focus_pair`, se
 * database/migrations/2026_10_04_000000). `zoom` utelämnad betyder 100, samma
 * utgångsläge som en bild utan valt utsnitt; kontrollern läser den med
 * `integer('zoom', 100)`. Är den satt gäller samma gränser som profilbildens
 * utsnitt (App\Http\Requests\UserAvatarCropRequest): heltal 100–300.
 * `integer` fångar `'a'`, och `between` fångar ett värde utanför bilden
 * respektive zoomen; båda blir formulärfel på sitt eget fält, som webbens
 * validering alltid blir (AGENTS.md § Felformat i API:et: höljet gäller
 * `/api`, inte webbsidorna).
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
            'zoom' => ['sometimes', 'integer', 'between:100,300'],
        ];
    }
}
