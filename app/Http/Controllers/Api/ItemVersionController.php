<?php

namespace App\Http\Controllers\Api;

use App\Actions\Item\RestoreItemVersion;
use App\Http\Controllers\Controller;
use App\Http\Resources\ItemResource;
use App\Http\Resources\ItemVersionResource;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemVersion;
use App\Support\Versioning\VersionLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

/**
 * API:ets versionsyta, se [[ADR-0088 Versionshistorik för items]] Riktning 5
 * och issue 413.
 *
 * **Läsning kräver `ItemPolicy::view`, återställning `ItemPolicy::update`.**
 * Ingen ny policymetod: versionerna hör till itemet, och åtkomsten är
 * itemets ([[ADR-0028 Åtkomst på itemnivå]]).
 *
 * **Versionen hämtas alltid genom itemet** (`$item->versions()`), så en
 * version som hör till ett annat item blir 404 — även när båda items ligger i
 * samma container. `{version}` binds därför som en sträng i signaturen.
 *
 * **ULID:er utåt, aldrig löpnummer** (AGENTS.md § Databaskonventioner):
 * `ItemVersionResource` bär versionens egen ULID och pekarna som ULID:er.
 *
 * Listan är sidindelad som de andra listorna under itemet, men bär bara så
 * många versioner som taket tillåter (issue 414 · ADR-0088 Riktning 6).
 * Ligger fler rader kvar — under en rättslig spärr tar trimjobbet ingenting —
 * är det de nyaste som visas, aldrig överskottet. Taket är högst tjugo och
 * sidan tjugofem, så allt som får visas ryms på en sida; sidindelningen finns
 * kvar som form, inte för att det någonsin blir en andra sida.
 */
class ItemVersionController extends Controller
{
    private const PER_PAGE = 25;

    /**
     * GET /api/containers/{container}/items/{item}/versions — 200.
     */
    public function index(Request $request, Container $container, Item $item): JsonResponse
    {
        Gate::authorize('view', $item);

        $limit = VersionLimit::for($container);

        // `limit(0)` ger inga rader, så en avstängd historik svarar tomt utan
        // ett eget specialfall. Sidindelningens total är det som faktiskt
        // visas, inte antalet rader i tabellen.
        $versions = $item->versions()->limit($limit)->get();

        ItemVersionResource::prepare($versions);

        return ItemVersionResource::collection(
            new LengthAwarePaginator($versions, $versions->count(), self::PER_PAGE),
        )->response();
    }

    /**
     * GET /api/containers/{container}/items/{item}/versions/{version} — 200.
     */
    public function show(Request $request, Container $container, Item $item, string $version): ItemVersionResource
    {
        Gate::authorize('view', $item);

        $itemVersion = $this->version($item, $version);

        ItemVersionResource::prepare([$itemVersion]);

        return new ItemVersionResource($itemVersion);
    }

    /**
     * POST /api/containers/{container}/items/{item}/versions/{version}/restore
     * — 200 med `ItemResource` och `meta.skipped`.
     *
     * `meta.skipped` räknar upp de fält som inte längre fanns och därför
     * lämnades orörda: `category`, `tags` och `cover` (ADR-0088 Riktning 5).
     */
    public function restore(Request $request, Container $container, Item $item, string $version, RestoreItemVersion $restoreItemVersion): JsonResponse
    {
        Gate::authorize('update', $item);

        $itemVersion = $this->version($item, $version);
        $skipped = $restoreItemVersion->handle($item, $itemVersion, $request->user());

        // Samma uppladdning som Api\ItemController::update, så resursen aldrig
        // kör en oplanerad lazy-load och svaret speglar det återställda läget.
        $item->loadMissing(['category', 'createdByAccount', 'tags', 'container.fields', 'ownFields', 'fieldValues']);

        return (new ItemResource($item))
            ->additional(['meta' => ['skipped' => $skipped]])
            ->response();
    }

    /**
     * Versionen genom itemet — en ULID från ett annat item ger 404.
     */
    private function version(Item $item, string $ulid): ItemVersion
    {
        return $item->versions()->where('ulid', $ulid)->firstOrFail();
    }
}
