<?php

namespace App\Http\Controllers;

use App\Actions\Template\SaveContainerTemplate;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Template\StoreTemplateRequest;
use App\Models\Container;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Webbens mallåtgärder på en container — *Save as template* och *Apply
 * template*, se issue 422 och [[ADR-0089 Egna fält på items]] § Riktning 6.
 *
 * **Ingenting av `/api` görs om.** Båda rutterna delar sina FormRequests med
 * App\Http\Controllers\Api\ContainerTemplateController
 * (`StoreTemplateRequest`), och skrivningarna går
 * genom samma Actions under App\Actions\Template. Skillnaden är svaret:
 * `/api` svarar JSON, webben svarar `back()` med en flash-kod
 * ([[ADR-0020 Plattformsidentitet och frontendgräns]] § Konsekvenser).
 *
 * **Grindarna är desamma som på `/api`.** Att SPARA kräver `view` i
 * containern och medlemskap i dess konto — en gäst med läsrätt ser containern
 * men äger ingen del av kontots mallar. Att TILLÄMPA kräver `update`, för
 * det lägger till kategorier och fält. Flaggan `can.saveTemplate` i
 * App\Http\Controllers\ContainerController::edit() ritar ytan ur samma fråga;
 * den är presentation och varje rutt auktoriserar på nytt.
 *
 * **Ett domänfel blir ett fältfel, aldrig en JSON-kropp.** Taket
 * (`template.limit_reached`), det totala kategoritaket
 * (`template.max_categories_exceeded`) och fältgränsen
 * (`field.limit_reached`) fångas och formuleras som en mening på det fält
 * felet hör till, samma mönster som App\Http\Controllers\CategoryController.
 */
class ContainerTemplateController extends Controller
{
    /**
     * POST /containers/{container}/templates — sparar containerns kategorier
     * och fält som en mall på dess konto, 302 tillbaka.
     *
     * `container` i kroppen prövas av `StoreTemplateRequest`, men containern
     * som sparas är den URL:en pekar ut: `$container` binds på ULID och
     * grinden prövas mot den. En kropp som pekar på en annan container kan
     * alltså inte flytta skrivningen.
     */
    public function store(
        StoreTemplateRequest $request,
        Container $container,
        SaveContainerTemplate $saveContainerTemplate,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('view', $container);

        // En gäst med läsrätt ser containern men äger ingen del av kontots
        // mallar: bara medlemmarna får spara (issue 421 § 4). Samma undantag
        // som en nekad grind, så svaret blir 403.
        if (! $request->user()->accounts()->whereKey($container->account_id)->exists()) {
            throw new AuthorizationException;
        }

        try {
            $saveContainerTemplate->handle(
                $request->user(),
                $container,
                (string) $request->validated('name'),
                $request->boolean('include_agent_rules', true),
            );
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['name' => $translator->message($e)]);
        }

        return back()->with('status', 'template-saved');
    }
}
