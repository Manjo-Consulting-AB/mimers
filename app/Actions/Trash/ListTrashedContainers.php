<?php

namespace App\Actions\Trash;

use App\Models\Container;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Papperskorgen för raderade containers: de mjukraderade containrar vars
 * ägarkonto användaren är medlem i, senast raderad först — se issue 62b
 * § Beslut 2, 3 och 7 och issue 20c § Beslut 1–3.
 *
 * Kroppen är `App\Http\Controllers\Api\ContainerTrashController::index()`s,
 * övertagen oförändrad: frågan, retentionsgränsen, sorteringen och
 * `entry()`-byggandet. `/api` anropar Actionen för att listan inte ska bli en
 * andra formulering av samma fråga, och webben anropar den av samma skäl.
 *
 * **Listningen är en FRÅGA, inte en behörighetsprövning per rad** (Beslut 3).
 * Urvalet är "containrar vars ägarkonto användaren är medlem i" — samma
 * villkor som App\Policies\ContainerPolicy::isMemberOfOwnerAccount()
 * uttrycker, och samma mönster som
 * App\Http\Controllers\Api\ContainerController::index() ställer för levande
 * containrar (issue 8 § Beslut 6). En delegerad `container_access` räcker
 * alltså INTE: att radera en container är förbehållet ägarkontot, och att ta
 * tillbaka den är samma beslut i motsatt riktning. Grinden för själva
 * återställningen stannar i kontrollern.
 *
 * **Utgångna containrar finns inte** (issue 20c § Beslut 3): rader vars
 * `deleted_at` är äldre än retentionen listas inte, även om gallringsjobbet
 * (20b/20c) ännu inte hunnit köra — svaret får aldrig bero på cronjobbets
 * tajmning. Samma regel som issue 20a § Beslut 5 och samma gräns som
 * ListTrash drar.
 *
 * **`entry()` är ListTrash::entry(), inte en egen byggare** (Beslut 7).
 * Formen är sex namngivna nycklar och `TrashEntryResource` är kontraktet; två
 * byggare av samma form glider isär. Att containern har `type` = `container`
 * och `context` = null är allt som skiljer den från innehållsraderna.
 *
 * **`containers` är den andra halvan av svaret.** Varje post bär sin laddade
 * rad, nycklad på ULID — webbkontrollern frågar `ContainerPolicy::delete()`
 * per rad för återställningsknappen (Beslut 3), och en andra hämtning av
 * samma rader vore en fråga för mycket. `/api` läser bara `entries` och rör
 * den inte. Ägarkontot eager-loadas av samma skäl: `delete()` läser
 * `$container->account` för frysthetsregeln, och en lat hämtning per rad är
 * precis den N+1-fråga ContainerController::index() varnar för.
 *
 * **`$user` är nollbar** därför att `Illuminate\Http\Request::user()` är det.
 * En saknad användare ger en tom lista, aldrig en obegränsad — samma linje
 * som App\Actions\Trash\ListTrash.
 */
class ListTrashedContainers
{
    /**
     * @return array{entries: list<array{type: string, ulid: string, label: string, context: string|null, deleted_at: Carbon|null, expires_at: Carbon|null}>, containers: array<string, Container>}
     */
    public function handle(?User $user): array
    {
        if ($user === null) {
            return ['entries' => [], 'containers' => []];
        }

        $retentionDays = (int) config('files.trash_retention_days');
        $cutoff = now()->subDays($retentionDays);

        $entries = [];
        $containers = [];

        foreach (Container::onlyTrashed()
            ->with('account')
            ->whereHas('account.users', fn (Builder $query) => $query->whereKey($user->id))
            ->where('deleted_at', '>=', $cutoff)
            ->orderByDesc('deleted_at')
            ->get(['id', 'ulid', 'name', 'deleted_at', 'account_id']) as $container) {
            $entries[] = ListTrash::entry(
                'container',
                $container->ulid,
                $container->name,
                null,
                $container->deleted_at,
                $retentionDays,
            );

            $containers[$container->ulid] = $container;
        }

        return ['entries' => $entries, 'containers' => $containers];
    }
}
