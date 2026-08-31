<?php

namespace App\Http\Requests\Item;

use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/containers/{container}/items, issue 15a. Validerar
 * querysträngens filter: `tags[]` (en lista av tagg-ULID:er) och
 * `category` (en kategori-ULID), båda valfria. Utan parametrar är
 * beteendet exakt som i 13a.
 *
 * Ett okänt värde är ett VALIDERINGSFEL (422 `validation.failed`), inte ett
 * tomt resultat — en tagg eller kategori ur en ANNAN container, eller en
 * mjukraderad rad, är en fråga som är fel ställd (issue 15a § Beslut 7).
 * Samma containerscopade regel som 13b § Beslut 5, mot `ulid`, se
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
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

        return [
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', Rule::in($validTagUlids)],
            'category' => [
                'sometimes',
                'string',
                Rule::exists('category', 'ulid')->where(
                    fn ($query) => $query->where('container_id', $this->route('container')->id)->whereNull('deleted_at')
                ),
            ],
        ];
    }
}
