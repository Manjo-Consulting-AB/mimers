<?php

namespace App\Actions\Container;

use App\Models\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sätter containerns tak för versionshistoriken — `NULL` följer kontot,
 * `0` stänger av, ett tal sänker kontots tak. Se [[ADR-0088
 * Versionshistorik för items]] Riktning 1 och issue 411.
 *
 * **Grinden är `ContainerPolicy::delete`**, samma pinne som raderingen.
 * Versionshistoriken är containerns ägares val, och en inbjuden med `write`
 * — hur bred hennes åtkomst än är — får varken se eller ändra taket. Att
 * pröva grinden här och inte bara i kontrollern gör actionen till ytan för
 * varje anropare, samma form som SetContainerCoverFocus.
 *
 * **Inget värde kopieras.** Actionen skriver bara containerns egen kolumn;
 * vilket tak som gäller räknas fram vid varje skrivning av
 * App\Support\Versioning\VersionLimit::for(). Att sänka taket tar bort
 * överskottet först i issue 414 — här ändras ingen version.
 *
 * Raden läses och skrivs under radlås, som SetContainerCoverFocus: containern
 * kan ha hunnit ändras mellan anroparens uppslag och skrivningen.
 */
class SetContainerVersioning
{
    public function handle(Container $container, ?int $maxVersions): Container
    {
        Gate::authorize('delete', $container);

        return DB::transaction(function () use ($container, $maxVersions): Container {
            $rad = Container::withTrashed()
                ->whereKey($container->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $rad->versioning_max_versions = $maxVersions;
            $rad->save();

            $container->refresh();

            return $container;
        });
    }
}
