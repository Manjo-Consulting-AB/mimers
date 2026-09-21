<?php

namespace App\Http\Controllers\Api;

use App\Actions\Access\ResolveItemScope;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Container;
use App\Support\Cost\CostReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * De fasta kostnadssummeringarna, issue 86 — en per container och en för
 * kontot, se [[ADR-0038 Gränsen för Pro i kostnaderna]]: **en fast
 * summering användaren inte kan ställa frågor till är fri, allt som går att
 * fråga är Pro.** Gränsen flyttade från *summering* till *fråga*, och
 * därför bär de här två ändpunkterna ingen plangrind. Den parametriserade
 * rapporten (App\Http\Controllers\Api\CostReportController) behåller
 * `cost_reports` oförändrat — den ligger utanför den här issuen.
 *
 * **Ändpunkterna tar inga parametrar.** Ingen period, inget filter, ingen
 * gruppering: metoden läser inte ens requesten, så en okänd parameter kan
 * inte påverka utfallet. Läggs en period in här är ändpunkten inte längre
 * fast, och grinden har flyttat sig utan att någon beslutat det
 * (issue 86 § Ändpunkterna tar inga parametrar).
 *
 * **Behörigheten är den enda grinden, och den går först.** Containerrutten
 * frågar `view()` precis som rapporten; kontorutten frågar
 * `AccountPolicy::viewStorage()` — medlemskap i kontot, den enda
 * medlemskapsgrinden som finns där. Att den heter just viewStorage är en
 * följd av att `app/Policies/**` ligger utanför issuen: en metod som heter
 * något om kostnader hade varit rätt namn, men den får inte läggas till här.
 *
 * **Åtkomstfiltret på itemnivå gäller lika mycket här** (issue 74 § Beslut
 * 5): en fast summering får aldrig räkna in rader från items användaren inte
 * når. Upplösningen sker EN gång per request, på en FÄRSK instans — samma
 * skäl och samma grepp som i CostReportController: memon på den
 * `scoped`-bundna instansen finns för ItemPolicy, som frågar en gång per rad
 * i en listning, och för en summering skulle den bara göra frågekostnaden
 * beroende av vad samma PHP-process råkade ha löst upp tidigare.
 *
 * Ingen summeringslogik bor här ([[ADR-0024 Tunna controllers och
 * actions]]): App\Support\Cost\CostReport äger radmängden och räknar i
 * databasen. Kontrollern gör grindarna, löser upp omfånget och lämnar över.
 */
class CostSummaryController extends Controller
{
    /**
     * GET /api/containers/{container}/costs/summary — 200 med containerns
     * fasta kostnadssummering per valuta. Ingen period, inget filter: hela
     * containern, grupperad per valuta och aldrig summerad över dem
     * ([[ADR-0016 Kostnadsregistrering]] § Konsekvenser).
     *
     * Ingen gruppering per item — den är issue 91:s, och att bygga en egen
     * här vore samma arbete två gånger med risken att de två börjar visa
     * olika siffror ([[ADR-0040 Underträdets summor]]). Svaret bär `totals`
     * och ingenting annat.
     */
    public function forContainer(Request $request, Container $container, CostReport $report): JsonResponse
    {
        Gate::authorize('view', $container);

        $scope = app()->build(ResolveItemScope::class)->handle($request->user(), $container);

        return response()->json([
            'data' => ['totals' => $report->summary($container, $scope)],
        ]);
    }

    /**
     * GET /api/accounts/{account}/costs/summary — 200 med kontots fasta
     * kostnadssummering per valuta, summerad över kontots containers. Det är
     * kontots summa och inte den inloggade användarens: kontot är den nivå
     * som bär valutan ([[ADR-0037 Valutans arv]]), och den som splittrar sitt
     * ägande på flera konton ska inte kunna se dem tillsammans — det är
     * motdraget mot flera gratiskonton i [[ADR-0038 Gränsen för Pro i
     * kostnaderna]] § Motivering.
     *
     * Containrarna hämtas ur `container.account_id`, och det globala
     * soft delete-scopet håller papperskorgen utanför: en mjukraderad
     * container nås inte av någon rutt och dess rader ska inte synas i en
     * summa användaren inte kan klicka sig fram till (samma regel som för
     * itemet i CostReport::rowSet()).
     *
     * Omfånget löses upp för ALLA containrarna i ETT anrop
     * (`ResolveItemScope::forContainers()`, byggd för toppnivårutterna) — en
     * gång per request, inte en per container. En användare som når kontot
     * når hela dess containers, så omfånget är obegränsat i dag; filtret
     * läggs ändå på, för regeln ska inte bero på vilken grind som råkar
     * sitta på rutten.
     */
    public function forAccount(Request $request, Account $account, CostReport $report): JsonResponse
    {
        Gate::authorize('viewStorage', $account);

        $containerIds = Container::query()
            ->where('account_id', $account->id)
            ->pluck('id')
            ->all();

        $scopes = app()->build(ResolveItemScope::class)
            ->forContainers($request->user(), $containerIds);

        return response()->json([
            'data' => ['totals' => $report->summaryForContainers($scopes)],
        ]);
    }
}
