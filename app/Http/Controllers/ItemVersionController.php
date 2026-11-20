<?php

namespace App\Http\Controllers;

use App\Actions\Item\RestoreItemVersion;
use App\Models\Container;
use App\Models\Item;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Webbens återställning av en version, se [[ADR-0088 Versionshistorik för
 * items]] Riktning 5 och issue 413.
 *
 * **En rutt och ingen sida.** Versionerna läses i itemets *History*-flik och
 * kommer med detaljvyns props när fliken är aktiv; den enda skrivningen är
 * återställningen, och den svarar `back()` med en flash-kod, som varje annan
 * skrivyta i webben (issue 51 § Beslut 5).
 *
 * **Grinden är itemets `update`.** Den som får se itemet ser versionerna,
 * men bara den som får ändra itemet får skriva tillbaka en — en `write`-
 * mottagare, enligt [[ADR-0028 Åtkomst på itemnivå]]. Ingen ny policymetod.
 *
 * **Versionen hämtas alltid genom itemet** (`$item->versions()`), så en
 * version som hör till ett annat item blir 404 även när båda items ligger i
 * samma container. Ruttens `{version}` binds därför som en sträng.
 *
 * Hoppade fält följer med som `restored_skipped` i sessionen, så att
 * itemets sida kan räkna upp dem jämte kvittot — `flash.status` bär bara en
 * kod och inga parametrar.
 */
class ItemVersionController extends Controller
{
    /**
     * POST /containers/{container}/items/{item}/versions/{version}/restore.
     */
    public function restore(Request $request, Container $container, Item $item, string $version, RestoreItemVersion $restoreItemVersion): RedirectResponse
    {
        Gate::authorize('update', $item);

        $itemVersion = $item->versions()->where('ulid', $version)->firstOrFail();

        $skipped = $restoreItemVersion->handle($item, $itemVersion, $request->user());

        return redirect()
            ->route('containers.items.show', [
                'container' => $container,
                'item' => $item,
                'tab' => 'history',
            ])
            ->with('status', 'item-version-restored')
            ->with('restored_skipped', $skipped);
    }
}
