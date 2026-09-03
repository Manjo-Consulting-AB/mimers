<?php

namespace App\Http\Controllers\Api;

use App\Actions\Usage\AdjustUsage;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trash\RestoreContainerRequest;
use App\Http\Resources\TrashEntryResource;
use App\Models\Container;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Papperskorgen för raderade containers — issue 20c. Listar mjukraderade
 * containers för konton användaren är medlem i och återställer dem. En
 * raderad container kan inte nästlas under sig själv (ruttbindningen
 * `{container}` ser bara levande rader), så rutterna ligger på toppnivå —
 * `/api/trash/containers` — och återställningen tar emot ULID:en i kroppen
 * (Beslut 1). Innehållspapperskorgen (20a) är nästlad under en levande
 * container och rörs inte här.
 *
 * 20a:s form återanvänds rakt av (Beslut 3): samma svarsnycklar
 * (TrashEntryResource), samma härledda `expires_at` (`deleted_at` plus
 * retentionen, aldrig en lagrad kolumn), samma sortering på `deleted_at`
 * fallande utan paginering. En klient som ritar papperskorgen ska kunna
 * använda samma komponent för båda listorna.
 *
 * INGEN behörighetslogik bor här (ADR-0024, Beslut 2): index begränsas av
 * själva frågan — containers vars ägarkonto användaren är medlem i, samma
 * villkor som ContainerPolicy::isMemberOfOwnerAccount() uttrycker — och
 * restore anropar bara Gate::authorize('delete', ...) mot den BEFINTLIGA
 * policymetoden på App\Policies\ContainerPolicy. Ingen ny policymetod, ingen
 * ContainerTrashPolicy. En delegerad `container_access` ger INTE rätt att
 * återuppliva en container: att radera den är redan förbehållet ägarkontot
 * (issue 9a § Beslut 6), och att ta tillbaka den är samma beslut i motsatt
 * riktning.
 *
 * Återställningen rör BARA container-raden (Beslut 4): innehållet
 * mjukraderades aldrig (issue 8 — att radera en container sätter
 * `deleted_at` på container-raden och ingenting annat), så items, kategorier,
 * taggar och bilagor kommer tillbaka av sig själva, och åtkomster och
 * inbjudningar ligger kvar orörda och gäller igen. Inga kaskader här.
 */
class ContainerTrashController extends Controller
{
    /**
     * GET /api/trash/containers — 200.
     *
     * Containers vars `account` användaren är medlem i och som ligger i
     * papperskorgen, sorterade på `deleted_at` fallande — det senast raderade
     * är det som oftast ska tillbaka. Listningen är en FRÅGA ("vilka raderade
     * containers finns i mina konton"), inte en behörighetsprövning per rad —
     * samma mönster som App\Http\Controllers\Api\ContainerController::index()
     * (issue 8 § Beslut 6). En delegerad åtkomst räcker alltså inte: radens
     * ägarkonto måste vara ett konto användaren är medlem i (Beslut 2).
     *
     * Utgångna containers finns inte (Beslut 3): rader vars `deleted_at` är
     * äldre än retentionen listas inte, även om gallringsjobbet (20b/20c)
     * ännu inte hunnit köra — svaret får aldrig bero på cronjobbets tajmning.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $retentionDays = (int) config('files.trash_retention_days');
        $cutoff = now()->subDays($retentionDays);

        $rows = [];

        foreach (Container::onlyTrashed()
            ->whereHas('account.users', fn (Builder $query) => $query->whereKey($user->id))
            ->where('deleted_at', '>=', $cutoff)
            ->orderByDesc('deleted_at')
            ->get(['ulid', 'name', 'deleted_at']) as $container) {
            $rows[] = $this->entry($container, $retentionDays);
        }

        return TrashEntryResource::collection($rows)->response();
    }

    /**
     * POST /api/trash/containers/restore — 200 med posten i samma form som
     * listan.
     *
     * RestoreContainerRequest har redan bevisat att ULID:en finns i
     * `container` och är mjukraderad — en levande container eller en okänd
     * ULID är 422 `validation.failed` (Beslut 1), och en gallrad container
     * har ingen rad kvar, så också den faller i valideringen som 422 och
     * når aldrig hit. Grinden prövas på den mjukraderade instansen (Beslut
     * 2): policyn läser `$container->account`, som inte är mjukraderad, så
     * Gate::authorize('delete', ...) fungerar.
     *
     * Den enda realistiska vägen till 404 `resource.not_found` (Beslut 3,
     * samma regel och samma skäl som 20a § Beslut 5) är en container vars
     * `deleted_at` passerat retentionen: valideringen filtrerar inte på
     * retention, så den utgångna containern passerar den och avvisas här —
     * efter att grinden godkänt — i stället för att bli ett
     * 422-valideringsfel. `null`-grenen i uppslaget nedan är ingen av två
     * normala vägar utan ett kapplöpningsskydd: den täcker bara att
     * nattjobbet hunnit gallra containern mellan valideringens
     * existensbevis och uppslaget här.
     *
     * Själva återställningen är `restore()` på EN rad (Beslut 4), ingen
     * kaskad och ingen genomgång av innehållet. Svaret bär posten med
     * `deleted_at`/`expires_at` null — klienten kan ta bort den ur
     * papperskorgsvyn utan en ny hämtning.
     */
    public function restore(RestoreContainerRequest $request, AdjustUsage $adjustUsage): JsonResponse
    {
        $retentionDays = (int) config('files.trash_retention_days');
        $cutoff = now()->subDays($retentionDays);

        $container = Container::onlyTrashed()
            ->where('ulid', $request->validated('ulid'))
            ->first();

        if ($container === null) {
            throw ApiException::make('resource.not_found', [], 404);
        }

        Gate::authorize('delete', $container);

        if ($container->deleted_at->lt($cutoff)) {
            throw ApiException::make('resource.not_found', [], 404);
        }

        DB::transaction(function () use ($container, $adjustUsage): void {
            // Återställningen och ökningen i en transaktion (issue 26a):
            // containern blir levande igen och kommer tillbaka i ägarkontots
            // räknare. Beslutet att öka grundas på radens tillstånd UNDER
            // radlåset (granskningsfynd 1): två samtidiga återställningar av
            // samma container skulle annars båda se en mjukraderad rad och öka
            // räknaren två gånger. withTrashed — raden ligger i papperskorgen.
            $rad = Container::withTrashed()
                ->whereKey($container->getKey())
                ->lockForUpdate()
                ->first();

            if ($rad === null) {
                return;
            }

            $varMjukraderad = $rad->trashed();

            // restore() på instansen även när en samtidig återställning redan
            // hunnit först — en no-op i databasen som synkar instansens
            // deleted_at, så svaret bär posten som levande.
            $container->restore();

            if ($varMjukraderad) {
                $adjustUsage->handle($container->account_id, containersDelta: 1);
            }
        });

        return (new TrashEntryResource($this->entry($container, $retentionDays)))->response();
    }

    /**
     * Bygger en papperskorgspost för en container. `type` är alltid
     * `container`, `context` alltid null — en container har ingen förälder
     * att beskriva den med. `deleted_at` är en Carbon eller null (efter
     * återställning) och `expires_at` härleds ur den plus retentionen —
     * aldrig en lagrad kolumn (Beslut 3).
     *
     * @return array{type: string, ulid: string, label: string, context: null, deleted_at: Carbon|null, expires_at: Carbon|null}
     */
    private function entry(Container $container, int $retentionDays): array
    {
        return [
            'type' => 'container',
            'ulid' => $container->ulid,
            'label' => $container->name,
            'context' => null,
            'deleted_at' => $container->deleted_at,
            'expires_at' => $container->deleted_at?->copy()->addDays($retentionDays),
        ];
    }
}
