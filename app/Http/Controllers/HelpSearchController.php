<?php

namespace App\Http\Controllers;

use App\Actions\Help\CountHelpEvent;
use App\Http\Requests\Help\HelpSearchRequest;
use App\Support\Help\HelpLibrary;
use App\Support\Help\HelpSearch;
use Illuminate\Http\JsonResponse;

/**
 * Sökningen i hjälpen, `GET /help/search?q=…` — M81 · issue 466,
 * [[ADR-0094 Hjälpcentret]] § 5.
 *
 * Kontrollern är ett tunt skal: HelpSearch äger normaliseringen, matchningen,
 * rangordningen och segmenten, och HelpLibrary äger det inlästa innehållet.
 * Här finns bara kopplingen till HTTP — frågan, taket och JSON-svaret.
 *
 * Rutten ligger utanför `auth` och `guest` som de andra `/help`-sidorna: en
 * besökare som ännu inte har ett konto ska kunna söka i hjälpen. Den ligger
 * också före `/help/{category}`, annars hade kategoriruttens enskilda segment
 * fångat `search` (se routes/web.php).
 *
 * **`log=1` räknar sökningen** (M82 · issue 470, ADR-0094 § 8). Den parametern
 * sätter `HelpSearchBox.vue` bara på den ena frågan per stillestånd eller
 * Enter — förslagen som hämtas medan man skriver bär den inte. Gav frågan
 * noll träffar sparas den som en lucka i texterna.
 */
class HelpSearchController extends Controller
{
    public function __invoke(
        HelpSearchRequest $request,
        HelpLibrary $library,
        HelpSearch $search,
        CountHelpEvent $counter,
    ): JsonResponse {
        $query = $request->string('q')->toString();

        $result = $search->search(
            $library->catalog(),
            $query,
            $request->integer('limit', 8),
        );

        $response = response()->json($result->toArray());

        if ($request->boolean('log')) {
            $counter->event(CountHelpEvent::SEARCH);

            if ($result->results === []) {
                $counter->searchGap($query);
            }
        }

        return $response;
    }
}
