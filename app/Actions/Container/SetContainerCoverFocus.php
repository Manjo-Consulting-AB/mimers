<?php

namespace App\Actions\Container;

use App\Models\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sätter fokuspunkten på containerns bild — den del av bilden som ska synas
 * när en yta beskär den. Se issue 682 och [[ADR-0047 Containerns bild]].
 *
 * **Grinden är `ContainerPolicy::update`**, samma pinne som att sätta bilden
 * (App\Actions\Container\SetContainerCover). Att välja vad på bilden som syns
 * är att ändra containern, och den som bara får se den (en `read`-mottagare)
 * nekas. Kontrollen ligger HÄR och inte hos anroparen, av samma skäl som i
 * SetContainerCover: actionen är ytan för varje anropare.
 *
 * **En container UTAN bild har ingen punkt att sätta.** Saknar raden
 * `cover_attachment_id` returneras `false` och ingenting skrivs — kontrollern
 * gör det till en 404, för en punkt på en bild som inte finns är ett anrop mot
 * ett objekt som inte finns. Det är inte samma no-op som RemoveContainerCover
 * gör när bilden redan är borta: där är frånvaron ett giltigt tillstånd ytan
 * kan visa, här är den ett fel i anropet.
 *
 * **Raden läses och skrivs under radlås** (samma form som SetContainerCover):
 * bytet ligger i en transaktion och `cover_attachment_id` läses ur en färsk
 * rad med `lockForUpdate` — inte ur instansen anroparen skickade in. Utan
 * låset kunde en samtidig borttagning av bilden hinna emellan, och punkten
 * hade skrivits på en container som inte längre har någon bild. `lockForUpdate`
 * är en current read, så den som kommer sist ser den förstas skrivning.
 *
 * Båda kolumnerna skrivs i samma `save()`, så CHECK-villkoret
 * `container_cover_focus_pair` (2026_10_04_000000) aldrig ser en halv punkt:
 * `x` utan `y` når aldrig databasen.
 */
class SetContainerCoverFocus
{
    public function handle(Container $container, int $x, int $y): bool
    {
        Gate::authorize('update', $container);

        return DB::transaction(function () use ($container, $x, $y): bool {
            // withTrashed() — containern kan ha mjukraderats mellan anroparens
            // uppslag och det här låset. Är raden borta finns ingen bild att
            // sätta en punkt på.
            $rad = Container::withTrashed()
                ->whereKey($container->getKey())
                ->lockForUpdate()
                ->first();

            if ($rad === null || $rad->cover_attachment_id === null) {
                return false;
            }

            $rad->cover_focus_x = $x;
            $rad->cover_focus_y = $y;
            $rad->save();

            // Instansen anroparen skickade in bär den nya punkten — annars vore
            // den en förlegad läsning av samma slag som låset stänger.
            $container->refresh();

            return true;
        });
    }
}
