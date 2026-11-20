<?php

namespace App\Actions\Item;

use App\Models\Container;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Egna fälts filter och sortering — uppslagningen, valideringsreglerna och
 * frågeformuleringen på ett ställe, se [[ADR-0089 Egna fält på items]]
 * § Riktning 1 och [[Items och organisation]] § Sök och filtrering.
 *
 * Issuen tar `field[<ULID>][eq|min|max]` på listan och sökningen. Text
 * filtreras med `eq`, tal och datum med `min` och `max`, och flera fält
 * kombineras med OCH precis som taggarna. Bara containerns egna fält
 * (`item_id IS NULL`) deltar: ett items eget fält, ett fält i en annan
 * container eller i papperskorgen är `field.not_applicable`, inte ett tomt
 * svar ([[ADR-0089 Egna fält på items]] § Riktning 9).
 *
 * **Formuleringen bor HÄR.** Listan, den globala sökningen och MCP-verktyget
 * ställer samma fråga, och en andra avskrift av `EXISTS`-villkoret hade
 * glidit isär — samma skäl som App\Models\Item::scopeMatchingText() bär
 * fritexten. Klassens tre anropare delar därför både uppslaget och SQL:en.
 *
 * **Datum tar relativa värden**, till exempel *inom 30 dagar*: `today`,
 * `within:N` och `past:N` räknas mot `User::today()` och därmed användarens
 * dag ([[ADR-0044 Användarens dag]]), aldrig mot UTC eller webbläsaren.
 * Ett intervall blir `min=today&max=within:30` — operatorerna är desamma som
 * för absoluta datum.
 *
 * **Ett värde lagras som en rad per item och fält** ([[Items och
 * organisation]] § item_field_value). Filtret är därför en `EXISTS` mot
 * `item_field_value`, inte en join: en join med flera fältfilter hade gett
 * en radkombination per fält och krävt `DISTINCT`. Uppslaget kan använda
 * datamodellens index `(item_field_id, value_number)` respektive
 * `(item_field_id, value_date)`; `EXPLAIN` mot MariaDB väljer i dag det
 * unika `(item_id, item_field_id)` för samma rader, och båda är byggda för
 * den här frågan.
 *
 * **Sorteringen är en korrelerad underfråga i `ORDER BY`**, så att den kan
 * kombineras med `withAllTags()`:s `GROUP BY` utan att lägga kolumner till
 * grupperingen. `IS NULL ASC` först gör att items utan värde hamnar sist i
 * BÅDA riktningarna, och kolumnen är typad — tal sorteras som tal och datum
 * som datum.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen; uppslaget
 * här begränsas till de fält anroparen redan har skickat in.
 */
class ResolveFieldFilters
{
    /**
     * De relativa datumvärdena. `within:N` är N dagar framåt och `past:N` N
     * dagar bakåt, båda räknade från användarens dag. Fyra siffror räcker
     * för ett intervall som fortfarande är ett datum.
     */
    public const RELATIVE_PATTERN = '/^(today|within:[1-9]\d{0,3}|past:[1-9]\d{0,3})$/';

    /**
     * Operatorerna per typ: text jämförs med `eq`, tal och datum med
     * `min` och `max` ([[Items och organisation]] § Sök och filtrering).
     *
     * @var array<string, list<string>>
     */
    public const OPERATORS = [
        'text' => ['eq'],
        'number' => ['min', 'max'],
        'date' => ['min', 'max'],
    ];

    /**
     * Containerns fält i EN fråga — alltså `item_id IS NULL`. Ett items eget
     * fält gäller ett item och är ingen kolumn för listan: det ger
     * `field.not_applicable`, precis som ett fält i en annan container
     * (issue 424 § arkitektsvar). Ett fält i papperskorgen är inte med
     * (SoftDeletes-scopet).
     *
     * @return Collection<string, ItemField>
     */
    public function candidatesForContainer(Container $container): Collection
    {
        return ItemField::query()
            ->where('container_id', $container->id)
            ->whereNull('item_id')
            ->get()
            ->keyBy('ulid');
    }

    /**
     * Containernas egna fält i de containers användaren når, för den globala
     * sökningen. Itemens egna fält är inte med — de gäller ett item, inte en
     * lista (issue 424 § arkitektsvar).
     *
     * @return Collection<string, ItemField>
     */
    public function candidatesForSearch(User $user): Collection
    {
        $accountIds = $user->accounts->pluck('id')->values()->all();

        $ids = Container::query()
            ->accessibleBy($user, $accountIds)
            ->listable()
            ->pluck('id');

        return ItemField::query()
            ->whereIn('container_id', $ids)
            ->whereNull('item_id')
            ->get()
            ->keyBy('ulid');
    }

    /**
     * Delar upp indata i de fält som finns bland kandidaterna och de ULID:er
     * som inte gör det. Anroparen avgör om ett okänt värde är ett fel
     * (`field.not_applicable`, som API:et) eller ett bortfallet filter (som
     * webbsidans gamla bokmärke).
     *
     * @param  Collection<string, ItemField>  $candidates
     * @return array{0: Collection<string, ItemField>, 1: list<string>}
     */
    public function match(Collection $candidates, mixed $input): array
    {
        if (! is_array($input)) {
            return [collect(), []];
        }

        $resolved = collect();
        $missing = [];

        foreach (array_keys($input) as $ulid) {
            $field = is_string($ulid) ? $candidates->get($ulid) : null;

            if ($field === null) {
                $missing[] = (string) $ulid;

                continue;
            }

            $resolved->put($ulid, $field);
        }

        return [$resolved, $missing];
    }

    /**
     * Valideringsreglerna för `field` och dess operatorer, byggda per fält
     * sedan fälten slagits upp: typen bestämmer vilka operatorer som finns
     * och vad värdet måste vara.
     *
     * Ett okänt operatornamn avvisas av `array:`-regeln på fältet, och ett
     * relativt datum prövas av samma mönster som `specs()` läser — annars
     * hade `today` fallit på `date_format:Y-m-d`.
     *
     * @param  Collection<string, ItemField>  $fields
     * @return array<string, mixed>
     */
    public function rules(Collection $fields, mixed $input): array
    {
        $rules = [
            'field' => ['sometimes', 'array'],
            'field.*' => ['array'],
        ];

        foreach ($fields as $ulid => $field) {
            $operators = self::OPERATORS[$field->type] ?? [];
            $rules["field.{$ulid}"] = ['array:'.implode(',', $operators)];

            foreach ($operators as $operator) {
                $raw = is_array($input) && is_array($input[$ulid] ?? null)
                    ? ($input[$ulid][$operator] ?? null)
                    : null;

                $rules["field.{$ulid}.{$operator}"] = $this->operatorRules($field, $raw);
            }
        }

        return $rules;
    }

    /**
     * Filtrens SQL-värden, färdiga att applicera. Ett värde som inte är
     * giltigt för sin typ hoppas över — API:et och MCP har redan validerat
     * det, och webbens gamla länk ska inte ge ett fel.
     *
     * @param  Collection<string, ItemField>  $fields
     * @return list<array{field_id: int, ulid: string, type: string, op: string, value: string, raw: mixed}>
     */
    public function specs(Collection $fields, mixed $input, User $user): array
    {
        if (! is_array($input)) {
            return [];
        }

        $specs = [];

        foreach ($fields as $ulid => $field) {
            $operators = self::OPERATORS[$field->type] ?? [];
            $given = $input[$ulid] ?? null;

            if (! is_array($given)) {
                continue;
            }

            foreach ($operators as $operator) {
                $raw = $given[$operator] ?? null;
                $value = $this->value($field, $raw, $user);

                if ($value === null) {
                    continue;
                }

                $specs[] = [
                    'field_id' => $field->id,
                    'ulid' => $ulid,
                    'type' => $field->type,
                    'op' => $operator,
                    'value' => $value,
                    'raw' => $raw,
                ];
            }
        }

        return $specs;
    }

    /**
     * Sorteringen på ett containerfält, eller null när inget fält är angett.
     *
     * Bara containerns fält går att sortera på ([[M68 Egna fält]] issue 424):
     * ett items eget fält gäller ett item och är ingen kolumn för listan.
     * Riktningen är `asc` om inget annat sägs.
     *
     * @param  Collection<string, ItemField>  $candidates
     * @return array{field_id: int, ulid: string, type: string, direction: string}|null
     */
    public function sort(Collection $candidates, mixed $ulid, mixed $direction): ?array
    {
        if (! is_string($ulid) || trim($ulid) === '') {
            return null;
        }

        $field = $candidates->get($ulid);

        if ($field === null || $field->item_id !== null) {
            return null;
        }

        return [
            'field_id' => $field->id,
            'ulid' => $ulid,
            'type' => $field->type,
            'direction' => $direction === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * Lägger fältfiltren på frågan — ett `EXISTS` per filter, alltså OCH.
     *
     * @param  Builder<Item>|HasMany<Item, Container>  $query
     * @param  list<array{field_id: int, type: string, op: string, value: string}>  $specs
     * @return Builder<Item>|HasMany<Item, Container>
     */
    public function apply(Builder|HasMany $query, array $specs): Builder|HasMany
    {
        foreach ($specs as $spec) {
            $column = $this->column($spec['type']);
            $operator = $this->operator($spec['op']);

            $query->whereExists(function (QueryBuilder $sub) use ($spec, $column, $operator): void {
                $sub->selectRaw('1')
                    ->from('item_field_value as field_filter_value')
                    ->whereColumn('field_filter_value.item_id', 'item.id')
                    ->where('field_filter_value.item_field_id', $spec['field_id']);

                // Datum jämförs som datum. `value_date` är DATE i MariaDB men
                // SQLite lagrar den `date`-castade strängen med tid — samma
                // drivrutinsgap som due_at, och samma lösning: whereDate().
                if ($spec['type'] === 'date') {
                    $sub->whereDate("field_filter_value.{$column}", $operator, $spec['value']);

                    return;
                }

                $sub->where("field_filter_value.{$column}", $operator, $spec['value']);
            });
        }

        return $query;
    }

    /**
     * Sorterar på fältets värde med items utan värde sist i båda
     * riktningarna. Anroparen lägger namnordningen efter, som skiljetecken.
     *
     * @param  Builder<Item>|HasMany<Item, Container>  $query
     * @param  array{field_id: int, type: string, direction: string}|null  $sort
     * @return Builder<Item>|HasMany<Item, Container>
     */
    public function order(Builder|HasMany $query, ?array $sort): Builder|HasMany
    {
        if ($sort === null) {
            return $query;
        }

        $column = $this->column($sort['type']);
        $direction = $sort['direction'] === 'desc' ? 'desc' : 'asc';
        $value = '(select field_sort_value.'.$column
            .' from item_field_value as field_sort_value'
            .' where field_sort_value.item_id = item.id'
            .' and field_sort_value.item_field_id = ? limit 1)';

        return $query
            ->orderByRaw("{$value} is null asc", [$sort['field_id']])
            ->orderByRaw("{$value} {$direction}", [$sort['field_id']]);
    }

    /**
     * @return list<mixed>
     */
    private function operatorRules(ItemField $field, mixed $raw): array
    {
        return match ($field->type) {
            'number' => ['sometimes', 'nullable', 'numeric', 'regex:/^[+-]?\d{1,14}(\.\d{1,6})?$/'],
            'date' => is_string($raw) && preg_match(self::RELATIVE_PATTERN, $raw) === 1
                ? ['sometimes', 'nullable', 'string', 'regex:'.self::RELATIVE_PATTERN]
                : ['sometimes', 'nullable', 'string', 'date_format:Y-m-d'],
            default => ['sometimes', 'nullable', 'string', 'max:'.config('fields.text_max_length')],
        };
    }

    /**
     * Värdet i den form SQL:en jämför med, eller null när det inte är
     * giltigt för typen.
     */
    private function value(ItemField $field, mixed $raw, User $user): ?string
    {
        return match ($field->type) {
            'number' => $this->number($raw),
            'date' => $this->date($raw, $user),
            default => $this->text($raw),
        };
    }

    private function text(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $raw = trim($raw);

        return $raw === '' ? null : $raw;
    }

    private function number(mixed $raw): ?string
    {
        if (is_float($raw)) {
            $raw = sprintf('%.6F', $raw);
        } elseif (is_int($raw)) {
            $raw = (string) $raw;
        }

        if (! is_string($raw)) {
            return null;
        }

        $raw = trim($raw);

        return preg_match('/^[+-]?\d{1,14}(\.\d{1,6})?$/', $raw) === 1 ? $raw : null;
    }

    /**
     * Ett absolut datum eller ett relativt värde räknat i användarens dag.
     */
    private function date(mixed $raw, User $user): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $raw = trim($raw);

        if ($raw === 'today') {
            return $user->today()->toDateString();
        }

        if (preg_match('/^(within|past):(\d{1,4})$/', $raw, $match) === 1) {
            $days = (int) $match[2];

            return $user->today()
                ->addDays($match[1] === 'within' ? $days : -$days)
                ->toDateString();
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $raw);

            return $date->format('Y-m-d') === $raw ? $raw : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function column(string $type): string
    {
        return match ($type) {
            'number' => 'value_number',
            'date' => 'value_date',
            default => 'value_text',
        };
    }

    private function operator(string $op): string
    {
        return match ($op) {
            'min' => '>=',
            'max' => '<=',
            default => '=',
        };
    }
}
