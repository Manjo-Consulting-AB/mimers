<?php

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /containers/{container}/categories/preset, se issue 56b § Beslut 3.
 * Kroppen är en nästlad lista, `{"categories": [{"name", "children"?}, …]}` —
 * namnen kommer ur `resources/js/data/categoryPresets.js` och servern vet
 * ingenting om vad de betyder ([[ADR-0004 Fria taggar och kategorier]]
 * § Konsekvenser).
 *
 * **Taket är bindande, inte en artighet.** En yta som skapar obegränsat många
 * rader per anrop är en missbruksvektor ([[ADR-0017 Missbruksvektorer]]), och
 * kategorier har ingen kvot som fångar det. Tolv rotkategorier och tolv barn
 * per gren räcker med marginal för varje uppsättning i Beslut 1 — den största
 * har åtta rötter och två barn.
 *
 * **Ingen `parent`, ingen `position`.** Föräldern är rotkategorin den här
 * requesten själv skapar, i den ordning listan kommer (Beslut 3), och
 * positionen sätts av App\Actions\Category\CreateCategory precis som när
 * användaren skapar en kategori för hand. Att ta emot en `parent`-ULID här
 * vore ett andra sätt att bygga ett träd, vid sidan av kategorisidans ytor.
 *
 * Ingen unikhetsregel på `name` — kategorier har ingen, se issue 11
 * § Beslut 11 och StoreCategoryRequest. Trimmas av Laravels globala
 * `TrimStrings`-middleware.
 */
class StoreCategoryPresetRequest extends FormRequest
{
    /**
     * Det bindande taket, se klassens docblock. Stavas som konstanter och inte
     * som två literaler, så att reglerna och testet läser samma tal.
     */
    public const MAX_ROOTS = 12;

    public const MAX_CHILDREN = 12;

    public function authorize(): bool
    {
        // Behörigheten prövas av App\Policies\ContainerPolicy::update() i
        // kontrollern, samma grind som att skapa en kategori för hand —
        // det är precis vad det här är (Beslut 3).
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'categories' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROOTS],
            'categories.*.name' => ['required', 'string', 'max:255'],
            'categories.*.children' => ['sometimes', 'array', 'max:'.self::MAX_CHILDREN],
            'categories.*.children.*' => ['required', 'string', 'max:255'],
        ];
    }
}
