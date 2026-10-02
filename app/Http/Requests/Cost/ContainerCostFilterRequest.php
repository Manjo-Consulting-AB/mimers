<?php

namespace App\Http\Requests\Cost;

use App\Models\Container;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validerar kostnadsflikens filterfält, issue 176 · [[ADR-0050
 * Desktopdesignen]] § 9. Filtren står i querysträngen på
 * `GET /containers/{container}/costs` (Beslut 1): `from`, `to`, `item`,
 * `category` och `supplier` — samma fem som rapporten tar, och därför samma
 * fem regler.
 *
 * **Reglerna är lånade och inte kopierade.** De bor i
 * CostReportRequest::filterRules(), som är deras ursprung, och den här
 * klassen lägger dem på sin egen querysträng. Två uppräkningar av samma regel
 * glider isär — den ena hade förr eller senare glömt `after_or_equal`, eller
 * släppt in en ULID ur en annan container — och en kostnadsflik som
 * filtrerade på ett item rapporten vägrade vore två svar på frågan *vilka
 * rader finns*. `group_by`, `period` och `tags[]` finns BARA i rapporten: de
 * är grupperingar och etiketter, och fliken har en fast form för dem.
 *
 * **Ingen plangrind här.** Den här requesten vet ingenting om Pro, och
 * CostReportRequest gör det inte heller: behörigheten prövas av
 * `Gate::authorize()` i kontrollern, och planen av samma kontroller —
 * [[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut lägger gränsen vid
 * FRÅGAN, och det är kontrollern som avgör om någon ställer den. Är
 * `canReport` falsk löses den här klassen aldrig upp, och en gratisanvändare
 * med `?from=2020-01-01` får varken ett formulärfel eller en annan sida
 * (Beslut 2). Låg planläsningen här hade den grinden varit osynlig — och
 * felkoden hade blivit `validation.failed` i stället för tystnad.
 *
 * **Formfel, inte felkoder.** Webben kör Inertia och behåller Laravels vanliga
 * valideringsfel ([[ADR-0020 Plattformsidentitet och frontendgräns]]
 * § Konsekvenser) — svaret på ett ogiltigt datum är en redirect tillbaka med
 * felet på fältet, inte API:ets `validation.failed`. API:ets hölje gäller
 * `/api`, och den här rutten ligger inte där.
 */
class ContainerCostFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Container $container */
        $container = $this->route('container');

        return CostReportRequest::filterRules(
            $container,
            $this->filled('from') && $this->filled('to'),
        );
    }
}
