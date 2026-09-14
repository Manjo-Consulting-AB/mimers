<?php

namespace App\Http\Controllers\Api;

use App\Actions\Tag\CreateTag;
use App\Actions\Tag\ListTags;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tag\StoreTagRequest;
use App\Http\Requests\Tag\UpdateTagRequest;
use App\Http\Resources\TagResource;
use App\Models\Container;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * GET /api/containers/{container}/tags — 200. Sorterad `name` stigande,
     * ingen paginering (issue 12 § Beslut 9), omfångsfiltrerad — se
     * App\Actions\Tag\ListTags, som bär hela resonemanget och kroppen
     * (issue 56a § Beslut 7). Ett konstant antal frågor oavsett antal taggar.
     *
     * Träffräknaren byggs fortfarande INTE här. Den bor i `ListTags::counts()`
     * och konsumeras bara av M10:s taggvy (issue 56a § Beslut 6) — `/api` har
     * inte bett om den, och TagResource bär den därför inte. Den här rutten
     * svarar exakt som förut, med samma antal frågor.
     */
    public function index(Request $request, Container $container, ListTags $listTags): JsonResponse
    {
        Gate::authorize('view', $container);

        return TagResource::collection($listTags->handle($request->user(), $container))->response();
    }

    /**
     * POST /api/containers/{container}/tags — 201.
     * `StoreTagRequest::rules()` har redan avvisat en AKTIV dubblett
     * (`Rule::unique` med `whereNull('deleted_at')`, issue 12 § Beslut 5)
     * innan kontrollern ens nås.
     *
     * Skapandet — inklusive ÅTERUPPLIVNINGEN av en mjukraderad tagg med samma
     * namn (issue 12 § Beslut 4) — bor i App\Actions\Tag\CreateTag
     * (issue 56a § Beslut 7).
     */
    public function store(StoreTagRequest $request, Container $container, CreateTag $createTag): JsonResponse
    {
        Gate::authorize('update', $container);

        $tag = $createTag->handle(
            $container,
            $request->validated('name'),
            $request->validated('color'),
        );

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
