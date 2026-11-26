<?php

namespace App\Http\Controllers\Api;

use App\Actions\Template\DeleteTemplate;
use App\Actions\Template\RenameTemplate;
use App\Actions\Template\SaveContainerTemplate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Template\StoreTemplateRequest;
use App\Http\Requests\Template\UpdateTemplateRequest;
use App\Http\Resources\ContainerTemplateResource;
use App\Models\Container;
use App\Models\ContainerTemplate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * API-ytan för mallar — en containers kategorier och fält sparade på kontot,
 * se issue 421 § 5 och [[ADR-0089 Egna fält på items]] § Riktning 6.
 *
 * **Mallarna ägs av kontot, och bara kontots medlemmar når dem** (issue 421
 * § 4). Den frågan formuleras på ETT ställe: routebindningen i routes/api.php
 * löser `{template}` genom App\Models\ContainerTemplate::scopeMemberOf(), och
 * listningen använder samma scope. En mall utanför användarens konton finns
 * därför inte för henne — 404, inte 403, precis som en container-ULID från en
 * annan container blir 404 i de nästlade rutterna.
 *
 * Att SPARA från en container kräver dessutom `view` i containern och
 * medlemskap i dess konto: en gäst som fått läsrätt ser containern men äger
 * ingen del av kontots mallar. Mallar läggs bara på när en container skapas
 * ([[ADR-0101 Mallarna]]); ingen rutt lägger en mall på en befintlig container.
 *
 * **Ingen behörighetslogik bor i actionerna.** De är verktyget; grinden står
 * här och i routebindningen, samma linje som för App\Actions\Field.
 */
class ContainerTemplateController extends Controller
{
    /**
     * GET /api/templates — 200. Mallarna i de konton användaren är medlem i,
     * sorterade på namn. Mjukraderade är inte med (SoftDeletes globala scope)
     * och upptar ingen av de 50 platserna.
     */
    public function index(Request $request): JsonResponse
    {
        $templates = ContainerTemplate::query()
            ->memberOf($request->user())
            ->with('account')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return ContainerTemplateResource::collection($templates)->response();
    }

    /**
     * POST /api/templates — 201. Sparar containerns kategoriträd och fält som
     * en mall på containerns konto.
     *
     * `container` har redan bevisats existera av StoreTemplateRequest (422
     * annars). Grinden är `view` i containern, och medlemskapet i dess konto
     * prövas separat — tillsammans blir det issue 421 § 4:s regel.
     */
    public function store(StoreTemplateRequest $request, SaveContainerTemplate $saveContainerTemplate): JsonResponse
    {
        $container = Container::query()
            ->where('ulid', $request->validated('container'))
            ->firstOrFail();

        Gate::authorize('view', $container);

        // En gäst med läsrätt ser containern men äger ingen del av kontots
        // mallar: bara medlemmarna får spara (issue 421 § 4). Samma undantag
        // som en nekad grind, så svaret blir 403 `auth.forbidden` — inte ett
        // `abort(403)`, som inte går genom höljet.
        if (! $request->user()->accounts()->whereKey($container->account_id)->exists()) {
            throw new AuthorizationException;
        }

        $template = $saveContainerTemplate->handle(
            $request->user(),
            $container,
            (string) $request->validated('name'),
        );

        $template->setRelation('account', $container->account);

        return (new ContainerTemplateResource($template))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH /api/templates/{template} — 200. Döper om mallen.
     *
     * `{template}` är redan prövad mot användarens konton av routebindningen.
     */
    public function update(UpdateTemplateRequest $request, ContainerTemplate $template, RenameTemplate $renameTemplate): ContainerTemplateResource
    {
        $renameTemplate->handle($template, (string) $request->validated('name'));

        return new ContainerTemplateResource($template);
    }

    /**
     * DELETE /api/templates/{template} — 204, ingen kropp. Mjuk radering;
     * raden upptar ingen av de 50 platserna och släpper sina byte, eftersom
     * en mall inte går att återställa och aldrig gallras (issue 421; se
     * App\Actions\Template\DeleteTemplate). Avvikelsen från ADR-0081 § 3 står
     * i App\Support\Usage\MetadataSize.
     */
    public function destroy(ContainerTemplate $template, DeleteTemplate $deleteTemplate): Response
    {
        $deleteTemplate->handle($template);

        return response()->noContent();
    }
}
