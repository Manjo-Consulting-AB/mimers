<?php

namespace App\Http\Controllers;

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
 * Ingen räkning av sökningar här — hjälpens mätning är 470 (ADR-0094 § 8).
 */
class HelpSearchController extends Controller
{
    public function __invoke(HelpSearchRequest $request, HelpLibrary $library, HelpSearch $search): JsonResponse
    {
        $result = $search->search(
            $library->catalog(),
            $request->string('q')->toString(),
            $request->integer('limit', 8),
        );

        return response()->json($result->toArray());
    }
}
