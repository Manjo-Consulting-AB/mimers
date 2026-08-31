<?php

namespace App\Http\Requests\Item;

use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validerar items-listningens querysträng på BÅDA rutterna, issue 15b §
 * Beslut 5 och 6: den globala `GET /api/items?q=...` (där `q` är
 * obligatorisk) och containerns `GET /api/containers/{container}/items`
 * (där `q` är valfri och kombineras med 15a:s `tags[]`/`category` med OCH).
 *
 * `q` är `required` på den globala rutten — en global lista över allt
 * användaren äger är inte en sökning, och den kan bli mycket stor — men
 * `sometimes` på containerns lista. Samma request tjänar båda rutterna;
 * skillnaden avgörs av om `{container}` finns i rutten.
 *
 * Fälten nedan är 15a:s filter och finns BARA på containerns rutt. Den
 * globala rutten tar bara `q` (Beslut 6). Ett okänt värde är ett
 * VALIDERINGSFEL (422 `validation.failed`), inte ett tomt resultat — en
 * tagg eller kategori ur en ANNAN container, eller en mjukraderad rad, är
 * en fråga som är fel ställd (issue 15a § Beslut 7). Samma containerscopade
 * regel som 13b § Beslut 5, mot `ulid`, se
 * App\Http\Requests\Container\StoreContainerRequest.
 *
 * `tags` löses upp i EN `Tag::whereIn`-fråga i rules() och varje element
 * kontrolleras sedan i minnet med `Rule::in` — en `Rule::exists` per
 * element skulle köra en `count(*)` per tagg, se 13b § Beslut 6, och låta
 * frågeantalet växa med antalet filtervärden (issue 15a § Beslut 9).
 * `category` är ett enstaka värde, så `Rule::exists` där är en enda konstant
 * fråga.
 */
class IndexItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        // App\Policies\ContainerPolicy::view() avgör behörighet i
        // kontrollern, inte här.
        return true;
    }

    /**
     * Trimma `q` före valideringen — en enbart blank `q` ska vara samma sak
     * som en tom, och därmed 422 `validation.failed` på den globala rutten
     * (issue 15b § Beslut 6).
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('q')) {
            $this->merge(['q' => trim((string) $this->input('q'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $onContainerRoute = $this->route('container') !== null;

        $rules = [
            'q' => [
                $onContainerRoute ? 'sometimes' : 'required',
                'string',
                'max:255',
            ],
        ];

        if (! $onContainerRoute) {
            return $rules;
        }

        $validTagUlids = [];

        if (is_array($tags = $this->input('tags')) && $tags !== []) {
            $tagUlids = array_filter($tags, 'is_string');

            if ($tagUlids !== []) {
                $validTagUlids = Tag::whereIn('ulid', $tagUlids)
                    ->where('container_id', $this->route('container')->id)
                    ->whereNull('deleted_at')
                    ->pluck('ulid')
                    ->all();
            }
        }

        $rules['tags'] = ['sometimes', 'array'];
        $rules['tags.*'] = ['string', Rule::in($validTagUlids)];
        $rules['category'] = [
            'sometimes',
            'string',
            Rule::exists('category', 'ulid')->where(
                fn ($query) => $query->where('container_id', $this->route('container')->id)->whereNull('deleted_at')
            ),
        ];

        return $rules;
    }
}
