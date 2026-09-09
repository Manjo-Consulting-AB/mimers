<?php

namespace App\Http\Requests\Cost;

use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validerar kostnadsrapportens querysträng, issue 46 § Beslut 3. Alla
 * parametrar är valfria utom `group_by`; `period` är obligatorisk när
 * grupperingen är per period och förbjuden annars.
 *
 * ULID-parametrarna (`category`, `tags[]`, `item`) bevisar att de pekar på
 * en rad I DEN HÄR CONTAINERN som inte är mjukraderad — samma regel som
 * issue 13a § Beslut 7 och IndexItemRequest: ett okänt ULID är 422
 * `validation.failed`, aldrig ett tomt resultat. `tags[]` löses upp i EN
 * `Tag::whereIn`-fråga i rules() och varje element kontrolleras i minnet
 * med `Rule::in`, så frågeantalet inte växer med antalet filtervärden
 * (issue 13b § Beslut 6).
 *
 * Behörigheten avgörs av Gate::authorize() i kontrollern, aldrig här — samma
 * val som IndexItemRequest::authorize().
 */
class CostReportRequest extends FormRequest
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
        $container = $this->route('container');
        $groupBy = $this->input('group_by');

        $rules = [
            'group_by' => ['required', 'string', Rule::in(['item', 'supplier', 'category', 'tag', 'period'])],

            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'supplier' => ['nullable', 'string', 'max:255'],
        ];

        // `period` är en form av tidsperiod (month/year), inte en
        // datumgräns. Obligatorisk bara för periodgrupperingen — och
        // förbjuden för alla andra, en period som ignoreras vore en fråga
        // som är fel ställd (Beslut 3).
        $periodRules = ['string', Rule::in(['month', 'year'])];

        if ($groupBy === 'period') {
            $periodRules[] = 'required';
        } else {
            $periodRules[] = 'prohibited';
        }

        $rules['period'] = $periodRules;

        // `to` ska inte kunna ligga före `from`; utan `from` finns ingen
        // övre gräns att jämföra mot, så regeln läggs bara på när båda finns.
        if ($this->filled('from') && $this->filled('to')) {
            $rules['to'][] = 'after_or_equal:from';
        }

        $rules['category'] = [
            'nullable',
            'string',
            Rule::exists('category', 'ulid')->where(
                fn ($query) => $query->where('container_id', $container->id)->whereNull('deleted_at')
            ),
        ];

        $rules['item'] = [
            'nullable',
            'string',
            Rule::exists('item', 'ulid')->where(
                fn ($query) => $query->where('container_id', $container->id)->whereNull('deleted_at')
            ),
        ];

        $validTagUlids = [];

        if (is_array($tags = $this->input('tags')) && $tags !== []) {
            $tagUlids = array_filter($tags, 'is_string');

            if ($tagUlids !== []) {
                $validTagUlids = Tag::whereIn('ulid', $tagUlids)
                    ->where('container_id', $container->id)
                    ->whereNull('deleted_at')
                    ->pluck('ulid')
                    ->all();
            }
        }

        $rules['tags'] = ['sometimes', 'array'];
        $rules['tags.*'] = ['string', Rule::in($validTagUlids)];

        return $rules;
    }
}
