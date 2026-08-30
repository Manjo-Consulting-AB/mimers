<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tag\StoreTagRequest;
use App\Http\Requests\Tag\UpdateTagRequest;
use App\Http\Resources\TagResource;
use App\Models\Container;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * CRUD-ytan för tag, se issue 12. INGEN behörighetslogik bor här — varje
 * metod anropar bara `Gate::authorize()` mot de BEFINTLIGA grindarna
 * `view` (listning) och `update` (skapa/ändra/radera) på
 * App\Policies\ContainerPolicy, se issue 12 § Beslut 2. Ingen ny
 * policymetod, ingen `TagPolicy` — `delete` är MEDVETET fel grind här: den
 * kräver medlemskap i ägarkontot, men en `write`-deltagare ska kunna städa
 * bland taggarna (regel 3, [[Konton och åtkomst]]).
 *
 * routes/api.php nästlar `{tag}` under `{container}` med
 * `->scopeBindings()` — kräver App\Models\Container::tags(), se issue 12 §
 * Beslut 1.
 *
 * Ingen Action: [[ADR-0024 Tunna controllers och actions]] § Konsekvenser
 * namnger issue 12 som en av dem som förmodligen inte behöver någon (issue
 * 12 § Beslut 8). Återupplivningen i store() nedan är hela avvikelsen.
 */
class TagController extends Controller
{
    /**
     * GET /api/containers/{container}/tags — 200. Sorterad `name`
     * stigande, ingen paginering (issue 12 § Beslut 9). Ett konstant antal
     * frågor oavsett antal taggar — inga relationer att ladda i förväg
     * här, TagResource läser bara kolumner på raden själv.
     */
    public function index(Container $container): JsonResponse
    {
        Gate::authorize('view', $container);

        $tags = $container->tags()
            ->orderBy('name')
            ->get();

        return TagResource::collection($tags)->response();
    }

    /**
     * POST /api/containers/{container}/tags — 201.
     * `StoreTagRequest::rules()` har redan avvisat en AKTIV dubblett
     * (`Rule::unique` med `whereNull('deleted_at')`, issue 12 § Beslut 5)
     * innan kontrollern ens nås.
     *
     * Issue 12 § Beslut 4 — återupplivningen, hela avvikelsen från en ren
     * CRUD-controller (§ Beslut 8): finns en MJUKRADERAD tagg med samma
     * namn i containern (`withTrashed()` — en vanlig fråga ser den inte,
     * se issue 12 § Att se upp med) återställs DEN raden i stället för att
     * en ny skapas. Samma ULID som före raderingen, `deleted_at`
     * nollställs, `color` sätts till kroppens värde. Annars: skapa en ny
     * rad.
     */
    public function store(StoreTagRequest $request, Container $container): JsonResponse
    {
        Gate::authorize('update', $container);

        $tag = $container->tags()->withTrashed()->where('name', $request->validated('name'))->first();

        if ($tag !== null) {
            $tag->restore();
            $tag->color = $request->validated('color');
            $tag->save();
        } else {
            $tag = new Tag($request->safe()->only(['name', 'color']));
            $tag->container_id = $container->id;
            $tag->save();
        }

        return (new TagResource($tag))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH /api/containers/{container}/tags/{tag} — 200.
     * `UpdateTagRequest::rules()` har redan avvisat en AKTIV dubblett,
     * `->ignore($this->route('tag'))` gör att taggen kan spara sitt eget
     * namn oförändrat (issue 12 § Beslut 5).
     */
    public function update(UpdateTagRequest $request, Container $container, Tag $tag): TagResource
    {
        Gate::authorize('update', $container);

        $tag->fill($request->validated());
        $tag->save();

        return new TagResource($tag);
    }

    /**
     * DELETE /api/containers/{container}/tags/{tag} — 204, ingen kropp.
     * Mjuk radering (`SoftDeletes`), nekas aldrig — taggen är platt, inget
     * barn att skydda (issue 12 § Beslut 7, till skillnad från kategorin i
     * issue 11 § Beslut 7). Vad som händer med `item_tag`-kopplingarna
     * avgörs av issue 13b, inte här.
     */
    public function destroy(Container $container, Tag $tag): Response
    {
        Gate::authorize('update', $container);

        $tag->delete();

        return response()->noContent();
    }
}
