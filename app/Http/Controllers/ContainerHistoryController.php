<?php

namespace App\Http\Controllers;

use App\Actions\Audit\ListAuditEvents;
use App\Actions\Audit\PresentAuditEvents;
use App\Http\Resources\ContainerResource;
use App\Models\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Containerns historikflik — `GET /containers/{container}/history`, se issue
 * 116 och [[ADR-0043 Tre loggar]] § Händelseloggen.
 *
 * **Containerns flik är en egen sida på en egen rutt**, som de andra flikarna
 * i `containerTabs` (issue 101): översikten, items och inställningssidan har
 * var sin adress, och historiken hade blivit den enda fliken utan. Sidan
 * ligger i `ContainerLayout` och bär samma prop som sina grannar.
 *
 * **Läsregeln bor inte här.** Grinden svarar på om användaren når containern
 * alls — och den är `viewAuditLog`, samma grind som
 * App\Http\Controllers\Api\AuditLogController::index() prövar: en gäst med
 * containerns `read` eller `write` når ytan, och främlingen får 403 och inte
 * en tom lista. VILKA rader hon sedan läser svarar
 * App\Actions\Audit\ListAuditEvents på, i EN fråga för både ägaren och gästen
 * (issue 108). Kontrollern filtrerar ingenting själv: två formuleringar av
 * läsregeln glider isär, och den ena hade blivit en väg runt `ItemPolicy`.
 *
 * **Namnen slås upp när raden läses.** `audit_log` bär identifierare och inte
 * namn, så raderna kommer ur ListAuditEvents och formen ur
 * App\Actions\Audit\PresentAuditEvents — samma två steg som `/api` går, och
 * samma uppslag som gör att en gallrad item- eller användarrad får sin
 * neutrala ersättare i stället för ett tomt fält.
 *
 * **De hundra senaste, och ingen paginering.** Gränsen står i ListAuditEvents
 * och är API:ets egen (issue 40 § Beslut 8). Behövs mer är det ett eget
 * beslut, inte en parameter här.
 */
class ContainerHistoryController extends Controller
{
    public function __construct(
        private readonly ListAuditEvents $listAuditEvents,
        private readonly PresentAuditEvents $presentAuditEvents,
    ) {}

    /**
     * GET /containers/{container}/history — 200.
     */
    public function index(Request $request, Container $container): Response
    {
        Gate::authorize('viewAuditLog', $container);

        $container->loadMissing('account');

        return Inertia::render('Containers/History', [
            'container' => ContainerResource::make($container)->resolve($request),
            'rows' => $this->presentAuditEvents->handle(
                $this->listAuditEvents->forContainer($request->user(), $container),
            ),
        ]);
    }
}
