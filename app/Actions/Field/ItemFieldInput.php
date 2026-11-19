<?php

namespace App\Actions\Field;

use App\Models\Container;
use App\Models\Item;
use App\Models\ItemField;
use Illuminate\Support\Collection;

/**
 * Reglerna för `fields` i App\Http\Requests\Item\StoreItemRequest och
 * UpdateItemRequest, se [[ADR-0089 Egna fält på items]] § Riktning 3 och 9
 * och issue 418.
 *
 * Kroppen är en lista med `{ "field": <ULID>, "value": … }`. **Ett värde
 * valideras mot fältets typ**, inte mot en enda generell regel: text har
 * `fields.text_max_length`, tal måste rymmas i `DECIMAL(20,6)` och datum
 * skrivas som `Y-m-d`. Reglerna byggs därför PER INDEX, sedan fälten har
 * slagits upp i EN fråga. Det ger de vanliga `validation.*`-koderna på
 * `fields.N.value` — `validation.numeric`, `validation.date_format`,
 * `validation.max` — i stället för en egen kod klienten inte känner igen.
 *
 * **Ett fält från en annan container, ett annat items eget fält eller ett
 * fält i papperskorgen ger `field.not_applicable` på `fields.N.field`.**
 * Uppslaget görs av App\Actions\Field\FieldRules::applicableFields(), samma
 * mängd som App\Actions\Field\SyncItemFieldValues skriver mot.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av kontrollern.
 */
final class ItemFieldInput
{
    /**
     * Reglerna för `fields` och dess element. `$input` är requestens råa
     * `fields`-värde, så att reglerna kan byggas per index.
     *
     * @return array<string, mixed>
     */
    public static function rules(Container $container, ?Item $item, mixed $input): array
    {
        $rules = [
            'fields' => ['sometimes', 'array'],
            'fields.*' => ['array'],
            'fields.*.field' => ['required', 'string'],
            // Nyckeln måste finnas. Ett `value: null` eller en tom sträng är
            // giltigt och betyder "töm fältet" — ett UTELÄMNAT `value` är
            // däremot ett formfel, för då vet servern inte vad klienten vill.
            'fields.*.value' => ['present'],
        ];

        if (! is_array($input)) {
            return $rules;
        }

        $fields = self::fields($container, $item, $input);
        $applicable = $fields->keys()->all();

        foreach (array_keys($input) as $index) {
            $entry = $input[$index];
            $ulid = is_array($entry) ? ($entry['field'] ?? null) : null;

            $rules["fields.{$index}.field"] = [
                'required',
                'string',
                new FieldAppliesToItem($applicable),
            ];

            $field = is_string($ulid) ? $fields->get($ulid) : null;

            if ($field !== null) {
                $rules["fields.{$index}.value"] = self::valueRules($field);
            }
        }

        return $rules;
    }

    /**
     * Fälten som ULID:erna i indata pekar på OCH som hör till itemet — en
     * fråga oavsett antal värden. Ett okänt ULID saknas helt i svaret, så
     * FieldAppliesToItem avvisar det.
     *
     * @param  array<mixed>  $input
     * @return Collection<string, ItemField>
     */
    public static function fields(Container $container, ?Item $item, array $input): Collection
    {
        $ulids = [];

        foreach ($input as $entry) {
            if (is_array($entry) && is_string($entry['field'] ?? null)) {
                $ulids[] = $entry['field'];
            }
        }

        if ($ulids === []) {
            return collect();
        }

        return FieldRules::applicableFields($container, $item)
            ->whereIn('ulid', array_values(array_unique($ulids)))
            ->get()
            ->keyBy('ulid');
    }

    /**
     * Värdets regler för ett fält, per typ ([[ADR-0089 Egna fält på items]]
     * § Riktning 1 och 9).
     *
     * Talet prövas både mot `DECIMAL(20,6)`:s skala (`decimal:0,6`) och mot
     * dess omfång: 14 heltalssiffror och 6 decimaler, aldrig ett flyttal.
     *
     * @return list<mixed>
     */
    public static function valueRules(ItemField $field): array
    {
        return match ($field->type) {
            'number' => ['present', 'nullable', 'numeric', 'decimal:0,6', 'between:-99999999999999.999999,99999999999999.999999'],
            'date' => ['present', 'nullable', 'date_format:Y-m-d'],
            // `text` är standardtypen; de två andra har egna regler ovan.
            default => ['present', 'nullable', 'string', 'max:'.config('fields.text_max_length')],
        };
    }
}
