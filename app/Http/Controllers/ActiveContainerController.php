<?php

namespace App\Http\Controllers;

use App\Models\Container;
use App\Support\Frontend\ActiveContainer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * PUT /containers/{container}/active — gör en pärm till sessionens aktiva,
 * se issue 54 § Beslut 1 och 6.
 *
 * Invokable: det finns exakt en handling, samma form som
 * App\Http\Controllers\Auth\VerifyEmailController och regeln i
 * [[ADR-0024 Tunna controllers och actions]] § Beslut ("en invokable
 * controller används bara när det verkligen finns exakt en handling").
 *
 * **Auktoriseringen är `view`, inte `update`.** Att välja vilken pärm man
 * arbetar i är att läsa, inte att skriva: en `read`-innehavare ska kunna göra
 * pärmen aktiv, och det är samma grind som `GET /containers` ställer för att
 * raden alls ska synas i listan. `ActiveContainer::set()` prövar dessutom
 * åtkomsten en gång till och glömmer nyckeln i stället för att skriva den om
 * svaret skulle vara nej — ingen krasch, sessionen är användarens.
 *
 * Svaret är `back()` och ingenting annat: anroparen står i listan och ska
 * stanna där.
 */
class ActiveContainerController extends Controller
{
    public function __invoke(Request $request, Container $container, ActiveContainer $activeContainer): RedirectResponse
    {
        Gate::authorize('view', $container);

        $activeContainer->set($request->user(), $container);

        return back();
    }
}
