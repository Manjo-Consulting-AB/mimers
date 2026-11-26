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
 * **Samma fält två gånger i listan är ett formfel** (`validation.distinct`):
 * `SyncItemFieldValues` är en rad per item och fält, så två poster för samma
 * fält hade gett två INSERT mot samma unika nyckel. Requesten stoppar det
 * innan actionen nås.
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
            'fields.*.field' => ['required', 'string', 'distinct'],
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
                'distinct',
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
     * Ett JSON-tal kommer in som `int` eller `float`, och ett flyttal kan
     * bära exponent när det kastas till sträng: `1e3` blir `1000.0`, men
     * `1.0E+15` blir `"1.0E+15"`. Omfångsregeln prövar strängen, så ett
     * flyttal i ett TALFÄLT görs om till en decimalsträng utan exponent
     * FÖRE valideringen — annars hade ett giltigt `1000.0` fallit på
     * regexen bara för att det skrevs med exponent. Ett för stort tal
     * faller fortfarande på sina heltalssiffror i stället för i databasen.
     *
     * Bara talfält normaliseras, så ett flyttal i ett textfält ger
     * alltjämt `validation.string`.
     *
     * @param  array<mixed>  $input
     * @return array<mixed>
     */
    public static function normalizeNumbers(Container $container, ?Item $item, array $input): array
    {
        $numbers = self::fields($container, $item, $input)
            ->filter(fn (ItemField $field): bool => $field->type === 'number');

        if ($numbers->isEmpty()) {
            return $input;
        }

        foreach ($input as $index => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $ulid = $entry['field'] ?? null;
            $value = $entry['value'] ?? null;

            if (is_string($ulid) && is_float($value) && $numbers->has($ulid)) {
                $input[$index]['value'] = sprintf('%.6F', $value);
            }
        }

        return $input;
    }

    /**
     * Värdets regler för ett fält, per typ ([[ADR-0089 Egna fält på items]]
     * § Riktning 1 och 9).
     *
     * Talet prövas som en decimalsträng med högst 14 heltalssiffror och 6
     * decimaler — precis det `DECIMAL(20,6)` rymmer. En regex i stället för
     * `between`/`decimal` därför att de jämför som flyttal: gränsen
     * avrundades till 1e14 och släppte igenom ett 15-siffrigt tal som
     * databasen sedan avvisade med 500. Ett flyttal normaliseras av
     * normalizeNumbers() innan regeln ser det.
     *
     * @return list<mixed>
     */
    public static function valueRules(ItemField $field): array
    {
        return match ($field->type) {
            'number' => ['present', 'nullable', 'numeric', 'regex:/^[+-]?\d{1,14}(\.\d{1,6})?$/'],
            'date' => ['present', 'nullable', 'date_format:Y-m-d'],
            // `true`, `1` och `"1"` kryssar i; `false`, `0`, `"0"` och null
            // kryssar ur ([[ADR-0099 Kryssrutefält]]).
            'boolean' => ['present', 'nullable', 'boolean'],
            // `text` är standardtypen; de andra har egna regler ovan.
            default => ['present', 'nullable', 'string', 'max:'.config('fields.text_max_length')],
        };
    }
}
