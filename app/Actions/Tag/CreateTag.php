<?php

namespace App\Actions\Tag;

use App\Models\Container;
use App\Models\Tag;

/**
 * Skapar en tagg i containern — se issue 56a § Beslut 7 och issue 12
 * § Beslut 4 och 8.
 *
 * Kroppen är `App\Http\Controllers\Api\TagController::store()`s, övertagen
 * oförändrad, och **återupplivningen är hela avvikelsen från ren CRUD**:
 * finns en MJUKRADERAD tagg med samma namn i containern (`withTrashed()` — en
 * vanlig fråga ser den inte) återställs DEN raden i stället för att en ny
 * skapas. Samma ULID som före raderingen, `deleted_at` nollställs, `color`
 * sätts till det nya värdet. Utan den kan en användare inte skapa om en tagg
 * hon nyss raderade — `StoreTagRequest` avvisar bara en AKTIV dubblett
 * (`Rule::unique` med `whereNull('deleted_at')`), så en ny rad hade krockat
 * med det unika indexet `(container_id, name)`.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen — samma
 * linje som issue 54 § Beslut 3, 55a § Beslut 8 och 55b § Beslut 7.
 */
class CreateTag
{
    public function handle(Container $container, string $name, ?string $color): Tag
    {
        $tag = $container->tags()->withTrashed()->where('name', $name)->first();

        if ($tag !== null) {
            $tag->restore();
            $tag->color = $color;
            $tag->save();

            return $tag;
        }

        $tag = new Tag(['name' => $name, 'color' => $color]);
        $tag->container_id = $container->id;
        $tag->save();

        return $tag;
    }
}
