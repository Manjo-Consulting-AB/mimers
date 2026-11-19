<?php

namespace App\Actions\Field;

use App\Exceptions\Api\ApiException;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemFieldValue;
use App\Support\Usage\MetadataLedger;
use Illuminate\Support\Collection;

/**
 * Skriver itemets fältvärden — `item_field_value` — i SAMMA transaktion som
 * itemet, se [[ADR-0089 Egna fält på items]] § Riktning 3 och 9 och issue
 * 418.
 *
 * **En rad per item och fält, och ett tomt värde har ingen rad.** `null` och
 * en tom sträng tar bort raden; fältet blir tomt i stället för att bära ett
 * tomt värde. Ett värde som inte nämns i `$entries` lämnas orört — listan är
 * "de fält det här anropet rör", inte "hela mängden".
 *
 * **Alla skrivningar går genom App\Support\Usage\MetadataLedger**, så att
 * `metadata_bytes` bokförs och kvoten prövas i samma transaktion som raden
 * ([[ADR-0081 All lagrad data räknas mot kvoten]] § 2 och 3). En borttagen
 * rad minskar räknaren med sin vikt innan den raderas för gott.
 *
 * **Betalaren är itemets `billed_account_id`**, samma regel som itemraden
 * bär (ADR-0081 § 3): den som skapade itemet betalar också för dess värden.
 *
 * **Fältet måste höra till itemet.** Ett värde för ett fält i en annan
 * container eller för ett annat items egna fält kastar
 * `field.not_applicable`. Requesten har redan prövat samma sak
 * (App\Actions\Field\ItemFieldInput), men actionen är också en ingång för
 * MCP och importer och får inte lita på att varje anropare validerade.
 *
 * **Samma fält två gånger i listan är inget fel här.** Det finns en rad per
 * item och fält, så den sista posten vinner; `$rows` uppdateras efter varje
 * skrivning i stället för att läsas en gång före loopen. Utan det hade två
 * poster för samma fält gett två INSERT mot samma unika nyckel och ett
 * databasundantag. Requesten avvisar dubbletten tidigare
 * (`validation.distinct`); hit når den bara från MCP och import.
 *
 * Metoden förutsätter att itemet redan är sparat (`id` satt) och att
 * anroparen står i en transaktion, som MetadataLedger gör.
 */
class SyncItemFieldValues
{
    /**
     * Skriver värdena och svarar med ULID:erna för de fält som FAKTISKT
     * ändrades — för händelseloggen, som namnger fälten men aldrig värdet
     * ([[ADR-0043 Tre loggar]] § Händelseloggen).
     *
     * @param  list<array{field: string, value?: mixed}>  $entries
     * @return list<string>
     */
    public function handle(Item $item, array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        $ulids = array_map(fn (array $entry): string => $entry['field'], $entries);

        $fields = $this->fields($item, $ulids);
        $rows = $this->rows($item, $fields);
        $changed = [];

        foreach ($entries as $entry) {
            $ulid = $entry['field'];
            $field = $fields->get($ulid);

            if ($field === null) {
                throw ApiException::make('field.not_applicable', ['field' => $ulid], 422);
            }

            $value = $entry['value'] ?? null;
            $row = $rows->get($field->id);

            if ($this->isEmpty($value)) {
                if ($row !== null) {
                    MetadataLedger::release($row);
                    $row->delete();
                    // Raden finns inte längre, så en senare post för samma
                    // fält måste skapa en ny i stället för att uppdatera.
                    $rows->forget($field->id);
                    $changed[] = $field->ulid;
                }

                continue;
            }

            if ($row !== null && $this->equals($row, $field->type, $value)) {
                continue;
            }

            $row ??= $this->newRow($item, $field);
            $row->fill($this->attributes($field->type, $value));

            MetadataLedger::save($row);

            // Nästa post för samma fält ska hitta DEN HÄR raden, inte den
            // läsning som gjordes före loopen — annars blir det en andra
            // INSERT mot unique(item_id, item_field_id).
            $rows->put($field->id, $row);

            $changed[] = $field->ulid;
        }

        return array_values(array_unique($changed));
    }

    /**
     * Fälten i indata som hör till itemet, i EN fråga.
     *
     * @param  list<string>  $ulids
     * @return Collection<string, ItemField>
     */
    private function fields(Item $item, array $ulids): Collection
    {
        if ($ulids === []) {
            return collect();
        }

        return FieldRules::applicableFields($item->container, $item)
            ->whereIn('ulid', array_values(array_unique($ulids)))
            ->get()
            ->keyBy('ulid');
    }

    /**
     * De rader som redan finns för fälten, i EN fråga.
     *
     * @param  Collection<string, ItemField>  $fields
     * @return Collection<int, ItemFieldValue>
     */
    private function rows(Item $item, Collection $fields): Collection
    {
        if ($fields->isEmpty()) {
            return collect();
        }

        return ItemFieldValue::query()
            ->where('item_id', $item->id)
            ->whereIn('item_field_id', $fields->pluck('id')->all())
            ->get()
            ->keyBy('item_field_id');
    }

    /**
     * Värdet i rätt kolumn för fältets typ — och `null` i de två andra.
     *
     * @return array{value_text?: string|null, value_number?: string|null, value_date?: string|null}
     */
    private function attributes(string $type, mixed $value): array
    {
        return match ($type) {
            'number' => ['value_text' => null, 'value_number' => $this->number($value), 'value_date' => null],
            'date' => ['value_text' => null, 'value_number' => null, 'value_date' => (string) $value],
            // `text` är standardtypen.
            default => ['value_text' => (string) $value, 'value_number' => null, 'value_date' => null],
        };
    }

    /**
     * En tom sträng är ett tomt värde, precis som `null`
     * ([[ADR-0089 Egna fält på items]] § Riktning 9). `0` och `"0"` är
     * däremot värden.
     */
    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * Om raden redan bär samma värde. Ett tal jämförs som decimal utan
     * flyttal — `"12.5"` och databasens `"12.500000"` är samma värde.
     */
    private function equals(ItemFieldValue $row, string $type, mixed $value): bool
    {
        return match ($type) {
            'number' => $this->sameNumber($row->getRawOriginal('value_number'), $this->number($value)),
            'date' => (string) $row->getRawOriginal('value_date') === (string) $value,
            // `text` är standardtypen.
            default => (string) $row->getRawOriginal('value_text') === (string) $value,
        };
    }

    /**
     * Talet som en decimalsträng utan exponent — `DECIMAL(20,6)` tar inget
     * flyttal, och en klient som skickar `1e3` ska ändå bli `1000`.
     */
    private function number(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        $string = trim((string) $value);

        return stripos($string, 'e') === false
            ? $string
            : sprintf('%.6F', (float) $string);
    }

    /**
     * Två decimalsträngar är samma tal när de har samma siffror sedan
     * inledande och avslutande nollor skalats bort.
     */
    private function sameNumber(mixed $stored, string $value): bool
    {
        if ($stored === null) {
            return false;
        }

        return $this->canonicalDecimal((string) $stored) === $this->canonicalDecimal($value);
    }

    private function canonicalDecimal(string $value): string
    {
        $value = trim($value);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');

        $result = $fraction === '' ? $integer : $integer.'.'.$fraction;

        return $negative && $result !== '0' ? '-'.$result : $result;
    }

    private function newRow(Item $item, ItemField $field): ItemFieldValue
    {
        $row = new ItemFieldValue;
        $row->item_id = $item->id;
        $row->item_field_id = $field->id;
        $row->billed_account_id = $item->billed_account_id;

        return $row;
    }
}
