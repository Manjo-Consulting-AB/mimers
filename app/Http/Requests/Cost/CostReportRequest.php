<?php

namespace App\Http\Requests\Cost;

use App\Models\Container;
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
 *
 * **Sedan issue 176 bor de fem FILTERREGLERNA i `filterRules()`** — `from`,
 * `to`, `supplier`, `category` och `item`. Containerns kostnadsflik validerar
 * samma fem parametrar i querysträngen
 * (App\Http\Requests\Cost\ContainerCostFilterRequest), och två uppräkningar
 * av samma regel glider isär: den ena hade förr eller senare glömt
 * `after_or_equal`, eller släppt in en ULID ur en annan container. Reglerna
 * bor här därför att det är den här requesten som är deras ursprung; den
 * andra lånar dem. Den här filens EGNA beteende är oförändrat.
 */
class CostReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * De fem filterreglerna, gemensamma för rapporten och kostnadsflikens
     * filterfält — EN definition, se klassens docblock.
     *
     * `$bounded` är om BÅDE `from` och `to` finns i anropet: `to` ska inte
     * kunna ligga före `from`, men utan `from` finns ingen undre gräns att
     * jämföra mot, så regeln läggs bara på när båda finns.
     *
     * `category` och `item` bevisar att ULID:n pekar på en rad I DEN HÄR
     * CONTAINERN som inte är mjukraderad — ett okänt ULID är 422
     * `validation.failed`, aldrig ett tomt resultat. Regeln gäller i BÅDA
     * anropen: en kategori ur en annan container är en fråga som är fel ställd
     * också när den ställs till fliken.
     *
     * @return array<string, list<mixed>>
     */
    public static function filterRules(Container $container, bool $bounded): array
    {
        $rules = [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'supplier' => ['nullable', 'string', 'max:255'],
        ];

        if ($bounded) {
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

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Container $container */
        $container = $this->route('container');

        $groupBy = $this->input('group_by');

        $rules = [
            'group_by' => ['required', 'string', Rule::in(['item', 'supplier', 'category', 'tag', 'period'])],
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

        // De fem filterreglerna kommer ur filterRules() — samma definition som
        // kostnadsflikens filterfält använder (issue 176).
        $rules += self::filterRules(
            $container,
            $this->filled('from') && $this->filled('to'),
        );

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
