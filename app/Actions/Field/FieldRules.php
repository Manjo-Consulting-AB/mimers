<?php

namespace App\Actions\Field;

use App\Exceptions\Api\ApiException;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reglerna för egna fält, på ett ställe: gränserna, rubrikens unikhet och
 * typens lås, se [[ADR-0089 Egna fält på items]] § Riktning 3 och 4 samt
 * issue 417.
 *
 * Klassen finns för att fyra anropare ska ställa SAMMA fråga: att skapa
 * (App\Actions\Field\CreateField), att ändra (UpdateField), att återställa ur
 * papperskorgen (App\Actions\Trash\RestoreContent) och testerna. Skulle varje
 * anropare formulera "vilka fält syns på det här itemet" själv hade gränsen
 * och rubrikkrocken glidit isär — och en krock som bara prövas på en av
 * vägarna är en krock som går att kringgå.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av kontrollern, som för
 * App\Actions\Category. Det här är verktyget, inte grinden.
 *
 * **Rubriken jämförs av databasen, inte i PHP.** Kolumnen är `nocase` på
 * sqlite och `utf8mb4_unicode_ci` på mysql, så `"Färg"` och `"färg"` är
 * samma rubrik utan en egen `strtolower` — samma lösning och samma
 * dokumenterade drivrutinsgap som `tag.name` (se migrationen för `tag`).
 */
final class FieldRules
{
    /**
     * Gränsen för omfånget: containerns fält eller itemets egna.
     */
    public static function max(?Item $item): int
    {
        return (int) config($item === null ? 'fields.max_per_container' : 'fields.max_per_item');
    }

    /**
     * Omfångets namn i `field.limit_reached.data`, som klienten översätter.
     */
    public static function scope(?Item $item): string
    {
        return $item === null ? 'container' : 'item';
    }

    /**
     * Fälten som hör till omfånget, utan dem i papperskorgen (globala
     * SoftDeletes-scopet).
     *
     * @return Builder<ItemField>
     */
    public static function fieldsInScope(Container $container, ?Item $item): Builder
    {
        return $item === null
            ? ItemField::query()->where('container_id', $container->id)->whereNull('item_id')
            : ItemField::query()->where('container_id', $container->id)->where('item_id', $item->id);
    }

    /**
     * Fälten som SYNS på ett item — containerns plus itemets egna — eller
     * alla fält i containern när frågan gäller ett containerfält. Det är
     * mängden rubriken ska vara unik inom ([[ADR-0089 Egna fält på items]]
     * § Riktning 9).
     *
     * @return Builder<ItemField>
     */
    public static function visibleFields(Container $container, ?Item $item): Builder
    {
        $query = ItemField::query()->where('container_id', $container->id);

        if ($item === null) {
            // Ett containerfält syns på ALLA items i containern, så det
            // prövas mot containerns fält OCH mot alla items egna fält.
            return $query;
        }

        // Ett eget fält syns bara på sitt item: containerns fält och
        // itemets andra egna.
        return $query->where(function (Builder $query) use ($item): void {
            $query->whereNull('item_id')->orWhere('item_id', $item->id);
        });
    }

    /**
     * Det 26:e containerfältet och det 11:e egna fältet avvisas med 422
     * `field.limit_reached` och gränsen i `data`. Bara levande fält räknas —
     * ett fält i papperskorgen upptar ingen plats.
     */
    public static function assertWithinLimit(Container $container, ?Item $item): void
    {
        $limit = self::max($item);

        if (self::fieldsInScope($container, $item)->count() < $limit) {
            return;
        }

        throw ApiException::make('field.limit_reached', [
            'limit' => $limit,
            'scope' => self::scope($item),
        ], 422);
    }

    /**
     * Rubriken är unik utan hänsyn till skiftläge inom `visibleFields()`.
     * En krock ger 422 `field.label_taken` med rubriken i `data`.
     *
     * `$ignoreFieldId` är fältet som ändras eller återställs — det ska inte
     * krocka med sig självt.
     */
    public static function assertLabelAvailable(Container $container, ?Item $item, string $label, ?int $ignoreFieldId = null): void
    {
        $label = trim($label);

        $conflict = self::visibleFields($container, $item)
            ->where('label', $label)
            ->when($ignoreFieldId !== null, fn (Builder $query) => $query->where('id', '!=', $ignoreFieldId))
            ->exists();

        if (! $conflict) {
            return;
        }

        throw ApiException::make('field.label_taken', ['label' => $label], 422);
    }

    /**
     * Typen är låst när fältet har minst ett värde
     * ([[ADR-0089 Egna fält på items]] § Riktning 3). Prövningen ligger här
     * redan i 417, mot `item_field_value`, så att issue 418 bara behöver
     * skriva värden — spärren finns på plats innan det första värdet kan
     * skapas.
     *
     * `item_field_value` läses med query builder: tabellen har ingen modell
     * som behöver laddas för en `exists()`, och ett mjukraderat fält har
     * kvar sina värden (de följer definitionen).
     */
    public static function assertTypeChangeAllowed(ItemField $field, ?string $type): void
    {
        if ($type === null || $type === $field->type) {
            return;
        }

        if (! DB::table('item_field_value')->where('item_field_id', $field->getKey())->exists()) {
            return;
        }

        throw ApiException::make('field.type_locked', ['field' => $field->ulid], 422);
    }

    /**
     * Reglerna för en återställning ur papperskorgen: gränsen och
     * rubrikkrocken prövas på nytt, mot de fält som lever NU, med samma två
     * felkoder som en skapning (issue 417 § 5).
     *
     * Ett eget fält vars item ligger i papperskorgen kan inte återställas
     * för sig — det skulle bli ett fält på ett item ingen ser. Samma svar som
     * för en bilaga eller en underkategori med en raderad förälder:
     * `trash.parent_deleted`.
     */
    public static function assertRestorable(ItemField $field): void
    {
        $container = Container::query()->findOrFail($field->container_id);
        $item = $field->item_id === null
            ? null
            : Item::withTrashed()->find($field->item_id);

        if ($item !== null && $item->trashed()) {
            throw ApiException::make('trash.parent_deleted', [
                'type' => 'item',
                'ulid' => $item->ulid,
            ], 422);
        }

        $limit = self::max($item);

        if (self::fieldsInScope($container, $item)->count() >= $limit) {
            throw ApiException::make('field.limit_reached', [
                'limit' => $limit,
                'scope' => self::scope($item),
            ], 422);
        }

        self::assertLabelAvailable($container, $item, $field->label);
    }
}
