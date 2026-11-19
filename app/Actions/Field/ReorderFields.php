<?php

namespace App\Actions\Field;

use App\Models\Container;
use App\Models\Item;
use App\Models\ItemField;
use App\Support\Usage\MetadataLedger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sorterar fälten i ett omfång — containerns fält eller ett items egna — se
 * [[ADR-0089 Egna fält på items]] § Riktning 9 och issue 417.
 *
 * Kroppen är en lista av fält-ULID:er i den ordning de ska stå. Listan
 * behöver inte vara fullständig: de nämnda fälten får position 1, 2, 3 … i
 * listans ordning, och de som inte nämns behåller sin inbördes ordning och
 * läggs efter. Det gör att en klient som bara flyttar ett fält räcker med att
 * skicka den ordningen — och att en samtidig skapning inte får hela
 * sorteringen att avvisas. ULID:er som inte hör till omfånget ignoreras;
 * valideringen i ReorderFieldsRequest har redan avvisat dem som 422.
 *
 * Positionen är 1-baserad, som kategoriernas (issue 11 § Beslut 6), och
 * skrivs bara när den faktiskt ändras. Positionen räknas inte i
 * `metadata_bytes`, men skrivningen går ändå genom MetadataLedger: alla
 * skrivningar mot en räknad tabell går samma väg.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen.
 */
class ReorderFields
{
    /**
     * @param  list<string>  $ulids  Fältens ULID:er i önskad ordning.
     * @return Collection<int, ItemField> Fälten i den nya ordningen.
     */
    public function handle(Container $container, ?Item $item, array $ulids): Collection
    {
        $fields = FieldRules::fieldsInScope($container, $item)->get();

        $byUlid = $fields->keyBy('ulid');
        $ordered = new Collection;

        foreach ($ulids as $ulid) {
            $field = $byUlid->get($ulid);

            if ($field !== null) {
                $ordered->push($field);
            }
        }

        $named = $ordered->pluck('ulid')->all();

        foreach ($fields as $field) {
            if (! in_array($field->ulid, $named, true)) {
                $ordered->push($field);
            }
        }

        DB::transaction(function () use ($ordered): void {
            foreach ($ordered->values() as $index => $field) {
                $position = $index + 1;

                if ((int) $field->position === $position) {
                    continue;
                }

                $field->position = $position;
                MetadataLedger::save($field);
            }
        });

        return $ordered->values();
    }
}
