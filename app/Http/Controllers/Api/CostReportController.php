<?php

namespace App\Http\Controllers\Api;

use App\Actions\Access\ResolveItemScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cost\CostReportRequest;
use App\Models\Container;
use App\Support\Cost\CostReport;
use App\Support\Plan\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Kostnadsrapporten, issue 46 — registrering är fri, summering kräver Pro
 * ([[Planer och kvoter]] § Kontrollpunkter). Två grindar i den ordningen
 * (issue 46 § Beslut 2): behörigheten först, planen sedan — en användare
 * utan åtkomst ska få `auth.forbidden`, inte `plan.feature_unavailable`,
 * som skulle avslöja att containern finns. Planen som räknas är
 * ÄGARKONTOTS, `$container->account`, inte den anropande användarens: en
 * GET bär inget konto att välja bland, och rättigheten hör till containern
 * (samma läsning som delningstaket i Entitlements::assertCanShareContainer()).
 *
 * Det enda stället i M8 där en plangrind sitter (ADR-0016 § Konsekvenser):
 * en egen frontend ska inte kunna summera raderna genom att hämta dem och
 * räkna själv, så kontrollen ligger här i API:et — en gratisanvändare får
 * 403 innan någon rad summeras.
 *
 * Kontrollern gör inget mer än grindarna: CostReportRequest bevisar
 * parametrarna, och App\Support\Cost\CostReport bygger grupper och totaler.
 * Inget räknande i kontrollern — M11 lade åtkomstfiltret på itemnivå på
 * exakt den här frågan, och den ändringen landade i Support-klassen
 * ([[ADR-0024 Tunna controllers och actions]]).
 *
 * Issue 74 § Beslut 5 och 10: kontrollern löser upp omfånget — EN gång per
 * request, via den `scoped`-memoiserade ResolveItemScope — och skickar in
 * det. CostReport anropar inte upplösningen själv: den är en Support-klass
 * som tar en container och parametrar och ska förbli testbar utan en
 * inloggad användare.
 */
class CostReportController extends Controller
{
    public function __invoke(CostReportRequest $request, Container $container, Entitlements $entitlements, CostReport $report, ResolveItemScope $resolveItemScope): JsonResponse
    {
        Gate::authorize('view', $container);

        $entitlements->assertFeature($container->account, 'cost_reports');

        $scope = $resolveItemScope->handle($request->user(), $container);

        return response()->json([
            'data' => $report->build($container, $request->validated(), $scope),
        ]);
    }
}
