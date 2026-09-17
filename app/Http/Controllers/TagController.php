<?php

namespace App\Http\Controllers;

use App\Actions\Tag\CreateTag;
use App\Actions\Tag\ListTags;
use App\Http\Requests\Tag\StoreTagRequest;
use App\Http\Requests\Tag\UpdateTagRequest;
use App\Http\Resources\ContainerResource;
use App\Http\Resources\TagResource;
use App\Models\Container;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webbens tagglista — listan och skrivningarna, se issue 56a § Beslut 1, 6
 * och 8.
 *
 * **Ingenting av `/api` görs om.** `StoreTagRequest`, `UpdateTagRequest` och
 * `TagResource` delas rakt av, och läsningen och skapandet går genom
 * App\Actions\Tag\ListTags respektive App\Actions\Tag\CreateTag — samma
 * Actions som App\Http\Controllers\Api\TagController anropar (Beslut 7).
 *
 * **KATEGORIN OCH TAGGEN SKA SE OLIKA UT.** [[ADR-0004 Fria taggar och
 * kategorier]]: kategorin är var saken hör hemma, taggarna är allt annat man
 * vill kunna filtrera på. Sidan bär sin egen rubrik och sin egen rad ur
 * `ui.php` som säger det — kategorisidan bär den omvända. Skillnaden syns
 * också i formen: taggen är platt och får en färg, kategorin är ett träd och
 * får en position.
 *
 * **Färgen är valfri och `null` är ett giltigt svar.** `TagResource` säger
 * uttryckligen att valet av färg är presentationens — och därmed den här
 * issuen. Valet är att inte välja åt användaren: ingen standardfärg hittas på
 * när `color` är `null`, vyn visar i stället en omålad prick och erbjuder
 * "ingen färg".
 *
 * **Ingen behörighetslogik bor här.** Grindarna är `ContainerPolicy::view()`
 * (listning) och `update()` (skapa, ändra, radera) — **aldrig `delete()`**,
 * som betyder "får radera containern" och skulle låsa ute en `write`-deltagare
 * från att städa bland sina egna taggar.
 */
class TagController extends Controller
{
    /**
     * GET /containers/{container}/tags — listan.
     *
     * **`counts` är ett uttryckligt tillägg i den här prop:en och finns INTE
     * i `TagResource`** (Beslut 6). `/api` har inte bett om räknaren, och ett
     * fält som bara webben behöver i en delad resurs är precis den drift
     * [[ADR-0021 Frontendteknik]] § Konsekvenser varnar för. Nyckeln är
     * tagg-ULID:n — det är den vyn slår upp på — och skickas som ett objekt,
     * aldrig som en tom PHP-lista: `[]` blir `[]` i JSON och `{}` blir `{}`,
     * och en prop vars form skiftar med innehållet är en form som måste
     * prövas två gånger på klientsidan (samma resonemang som
     * ContainerSharingController § Beslut 6).
     *
     * **Talet är per OMFÅNG, inte per container** (Beslut 6): en mottagare som bara
     * når fyra items ska se att taggen sitter på två av dem, inte att den
     * sitter på nittio. Det räknas av `ListTags::counts()` i en fråga, delad
     * med det svep som avgör vilka taggar som syns — antalet frågor är
     * konstant oavsett antal taggar.
     */
    public function index(Request $request, Container $container, ListTags $listTags): Response
    {
        Gate::authorize('view', $container);

        $container->loadMissing('account');

        $user = $request->user();
        $tags = $listTags->handle($user, $container);

        return Inertia::render('Containers/Tags', [
            'container' => ContainerResource::make($container)->resolve($request),
            'tags' => TagResource::collection($tags)->resolve($request),
            'counts' => (object) $listTags->counts($user, $container, $tags),
            'can' => [
                'manage' => Gate::forUser($user)->allows('update', $container),
            ],
        ]);
    }

    /**
     * POST /containers/{container}/tags — 302 tillbaka.
     *
     * `StoreTagRequest` har redan avvisat en AKTIV dubblett, så den här vägen
     * når bara ett nytt namn eller ett namn som finns på en MJUKRADERAD rad.
     * Den senare återupplivas av App\Actions\Tag\CreateTag med sin gamla ULID
     * — hela avvikelsen från ren CRUD i issue 12 § Beslut 4.
     */
    public function store(StoreTagRequest $request, Container $container, CreateTag $createTag): RedirectResponse
    {
        Gate::authorize('update', $container);

        $createTag->handle(
            $container,
            $request->validated('name'),
            $request->validated('color'),
        );

        return back()->with('status', 'tag-created');
    }

    /**
     * PATCH /containers/{container}/tags/{tag} — 302 tillbaka.
     *
     * `UpdateTagRequest` gör att taggen kan spara sitt eget namn oförändrat
     * (`->ignore($this->route('tag'))`, issue 12 § Beslut 5). Ingen Action:
     * en `fill()` och en `save()` är hela skrivningen, och ett `color: null`
     * betyder "ingen färg" — det är inte en tömning att skydda sig mot.
     */
    public function update(UpdateTagRequest $request, Container $container, Tag $tag): RedirectResponse
    {
        Gate::authorize('update', $container);

        $tag->fill($request->validated());
        $tag->save();

        return back()->with('status', 'tag-updated');
    }

    /**
     * DELETE /containers/{container}/tags/{tag} — 302 tillbaka.
     *
     * Mjuk radering, nekas aldrig: taggen är platt och har inget barn att
     * skydda (issue 12 § Beslut 7, till skillnad från kategorin i issue 11
     * § Beslut 7). Ingen domänfelkod kan komma ur den här vägen, och därför
     * ingen `ApiErrorTranslator` här.
     */
    public function destroy(Container $container, Tag $tag): RedirectResponse
    {
        Gate::authorize('update', $container);

        $tag->delete();

        return back()->with('status', 'tag-deleted');
    }
}
