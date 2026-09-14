<?php

namespace App\Http\Controllers\Api;

use App\Actions\Access\ListParticipants;
use App\Http\Controllers\Controller;
use App\Http\Resources\ParticipantResource;
use App\Models\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Deltagarlistan: vilka som har åtkomst till containern JUST NU, läsbar för
 * varje deltagare — inte bara för ägarkontot. Se issue 9c och
 * [[Konton och åtkomst]] § Behörighetsregler, stycket "Att hantera
 * åtkomster och att se dem är två olika saker".
 *
 * En IDENTITETSLISTA, inte en förvaltningsvy. Förvaltningsvyn — nivåer,
 * utgångsdatum, vem som beviljade, återkallad historik och obesvarade
 * inbjudningar — är ägarkontots och bor i
 * App\Http\Controllers\Api\ContainerAccessController respektive
 * App\Http\Controllers\Api\ContainerInvitationController. Den här klassen
 * lägger sig BREDVID dem, den ändrar dem inte.
 *
 * INGEN behörighetslogik bor här (ADR-0024, issue 9c § Beslut 9): metoden
 * anropar bara `Gate::authorize('view', ...)` och litar på svaret från
 * App\Policies\ContainerPolicy::view(). Grinden är `view` och INTE
 * `viewAccesses()` — de heter nästan likadant och betyder motsatta saker.
 * `viewAccesses()` är ägarkontots förvaltningsvy; `view` är "får läsa
 * containern", vilket inkluderar en `read`-guest. Det är hela poängen: den
 * som LÄSER något känsligt ska veta vem mer som kan.
 */
class ContainerParticipantController extends Controller
{
    /**
     * GET /api/containers/{container}/participants — 200.
     *
     * Auktoriseringen är hela den här metodens logik sedan issue 55a
     * § Beslut 8: kroppen — frågorna, grupperingen på
     * `(grantee_type, grantee_id)`, ägaren först och den deterministiska
     * sorteringen — bor i App\Actions\Access\ListParticipants, som webbens
     * delningssida anropar. Se Actionens docblock för resonemanget; det
     * flyttade med, så att nästa läsare inte "förbättrar" sorteringen till
     * namnordning.
     */
    public function index(Container $container, ListParticipants $listParticipants): JsonResponse
    {
        Gate::authorize('view', $container);

        return ParticipantResource::collection($listParticipants->handle($container))->response();
    }
}
