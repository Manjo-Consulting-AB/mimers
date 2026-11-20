<?php

namespace App\Http\Requests\Item;

use App\Actions\Item\ResolveFieldFilters;
use App\Exceptions\Api\ApiException;
use App\Models\Container;
use App\Models\ItemField;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
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
 * `tags` och `category` är 15a:s filter och finns BARA på containerns rutt.
 * Den globala rutten tar `q` (Beslut 6) och de egna fälten. Ett okänt värde
 * är ett VALIDERINGSFEL (422 `validation.failed`), inte ett tomt resultat —
 * en tagg eller kategori ur en ANNAN container, eller en mjukraderad rad, är
 * en fråga som är fel ställd (issue 15a § Beslut 7). Samma containerscopade
 * regel som 13b § Beslut 5, mot `ulid`, se
 * App\Http\Requests\Container\StoreContainerRequest.
 *
 * `tags` löses upp i EN `Tag::whereIn`-fråga i rules() och varje element
 * kontrolleras sedan i minnet med `Rule::in` — en `Rule::exists` per
 * element skulle köra en `count(*)` per tagg, se 13b § Beslut 6, och låta
 * frågeantalet växa med antalet filtervärden (issue 15a § Beslut 9).
 * `category` är ett enskilt värde, så `Rule::exists` där är en enda konstant
 * fråga.
 *
 * **Egna fält (issue 424).** Båda rutterna tar `field[<ULID>][eq|min|max]`:
 * `eq` för text, `min`/`max` för tal och datum, och flera fält kombineras
 * med OCH. Uppslaget och reglerna bor i
 * App\Actions\Item\ResolveFieldFilters — bara containerns egna fält
 * (`item_id IS NULL`) deltar (issue 424 § arkitektsvar); ett items eget
 * fält, ett fält ur en annan container eller ur papperskorgen ger 422
 * `field.not_applicable`, precis som ett värde i fel fält (issue 418).
 * Containerns lista tar dessutom `sort` (containerns fält-ULID) och
 * `direction` (`asc`/`desc`).
 */
class IndexItemRequest extends FormRequest
{
    /**
     * Fälten `field`-nycklarna pekar på, uppslagna EN gång i rules() och
     * återanvända av fieldFilters(). Null tills reglerna byggts.
     *
     * @var Collection<string, ItemField>|null
     */
    private ?Collection $fieldFilterFields = null;

    /**
     * Sorteringen, uppslagen i rules() — samma skäl.
     *
     * @var array{field_id: int, ulid: string, type: string, direction: string}|null
     */
    private ?array $fieldSort = null;

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
        $routeContainer = $this->route('container');
        $onContainerRoute = $routeContainer !== null;
        $container = $routeContainer instanceof Container ? $routeContainer : null;

        $rules = [
            'q' => [
                $onContainerRoute ? 'sometimes' : 'required',
                'string',
                'max:255',
            ],
        ];

        // Egna fält gäller båda rutterna: listan frågar inom containern,
        // sökningen över de containers användaren når.
        $rules = [...$rules, ...$this->fieldRules($container)];

        if (! $onContainerRoute || $container === null) {
            return $rules;
        }

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
        $rules['category'] = [
            'sometimes',
            'string',
            Rule::exists('category', 'ulid')->where(
                fn ($query) => $query->where('container_id', $container->id)->whereNull('deleted_at')
            ),
        ];
        $rules['sort'] = ['sometimes', 'nullable', 'string'];
        $rules['direction'] = ['sometimes', 'nullable', 'string', Rule::in(['asc', 'desc'])];

        return $rules;
    }

    /**
     * Fältfiltren som App\Actions\Item\ResolveFieldFilters ska applicera.
     *
     * @return list<array{field_id: int, ulid: string, type: string, op: string, value: string, raw: mixed}>
     */
    public function fieldFilters(): array
    {
        $user = $this->user();

        if ($this->fieldFilterFields === null || ! $user instanceof User) {
            return [];
        }

        return app(ResolveFieldFilters::class)->specs(
            $this->fieldFilterFields,
            $this->input('field'),
            $user,
        );
    }

    /**
     * Sorteringen på ett containerfält, eller null när ingen är angiven.
     *
     * @return array{field_id: int, ulid: string, type: string, direction: string}|null
     */
    public function fieldSort(): ?array
    {
        return $this->fieldSort;
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldRules(?Container $container): array
    {
        $resolver = app(ResolveFieldFilters::class);
        $input = $this->input('field');

        $this->fieldFilterFields = collect();

        // Utan `field` och `sort` kostar reglerna ingenting: den vanliga
        // listningen ska inte betala ett uppslag den inte bad om.
        if ($input === null) {
            if ($container !== null && $this->hasSortInput()) {
                $this->fieldSort = $this->sortRule($resolver, $resolver->candidatesForContainer($container));
            }

            return $resolver->rules(collect(), $input);
        }

        $candidates = $container !== null
            ? $resolver->candidatesForContainer($container)
            : $this->searchCandidates($resolver);

        [$fields, $missing] = $resolver->match($candidates, $input);

        // Ett items eget fält, ett fält i en annan container eller i
        // papperskorgen är en fråga som är fel ställd — samma domänkod som
        // ett värde i fel fält (issue 418, arkitektsvaret på 424).
        if ($missing !== []) {
            throw ApiException::make('field.not_applicable', ['field' => $missing[0]], 422);
        }

        $this->fieldFilterFields = $fields;

        if ($container !== null) {
            $this->fieldSort = $this->sortRule($resolver, $candidates);
        }

        return $resolver->rules($fields, $input);
    }

    /**
     * @return Collection<string, ItemField>
     */
    private function searchCandidates(ResolveFieldFilters $resolver): Collection
    {
        $user = $this->user();

        return $user instanceof User
            ? $resolver->candidatesForSearch($user)
            : collect();
    }

    private function hasSortInput(): bool
    {
        $ulid = $this->input('sort');

        return is_string($ulid) && trim($ulid) !== '';
    }

    /**
     * `sort` pekar på ett containerns fält. Ett okänt ULID, ett fält i
     * papperskorgen eller ett items eget fält ger `field.not_applicable`.
     *
     * @param  Collection<string, ItemField>  $candidates
     * @return array{field_id: int, ulid: string, type: string, direction: string}|null
     */
    private function sortRule(ResolveFieldFilters $resolver, Collection $candidates): ?array
    {
        if (! $this->hasSortInput()) {
            return null;
        }

        $ulid = (string) $this->input('sort');
        $sort = $resolver->sort($candidates, $ulid, $this->input('direction'));

        if ($sort === null) {
            throw ApiException::make('field.not_applicable', ['field' => $ulid], 422);
        }

        return $sort;
    }
}
