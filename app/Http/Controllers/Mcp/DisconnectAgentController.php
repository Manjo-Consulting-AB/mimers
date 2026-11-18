<?php

namespace App\Http\Controllers\Mcp;

use App\Actions\Agent\DisconnectAgent;
use App\Models\Agent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mejlets signerade bortkoppling, se [[ADR-0087 MCP-server för agenter]]
 * Riktning 12 och issue 400 § 5.
 *
 * **Ingen inloggning, och det är avsiktligt.** Länken finns därför att en
 * kapad session kan ansluta angriparens egen klient — `claude.ai` är tillåten
 * för alla — och den som får mejlet ska kunna stänga dörren utan att först
 * logga in. Signaturen är beviset: den gäller i sju dagar och bara för den
 * agenten. En ändrad eller utgången länk avvisas av `signed`-middlewaret och
 * rör ingenting.
 *
 * **Länken raderar ingenting när den hämtas.** Mejlskannrar och
 * länkförhandsvisningar hämtar länkar automatiskt; en GET som kopplade bort
 * hade låtit dem stänga av en legitim agent. `confirm()` ritar därför bara en
 * bekräftelseknapp, och `store()` — som POSTar mot samma signerade URL,
 * alltså med samma skydd — är den som raderar. Det skyddar också mot en
 * kapad session på samma sätt som förut: utan signaturen går ingen av
 * rutterna att anropa.
 *
 * **Raden raderas och tokens återkallas**, se App\Actions\Agent\
 * DisconnectAgent. Sidan efteråt säger vad som hände; den är den enda ytan en
 * utloggad person kan mötas av efter klicket.
 */
class DisconnectAgentController
{
    public function confirm(Agent $agent, Request $request): Response
    {
        return Inertia::render('Mcp/DisconnectConfirm', [
            'agentName' => $agent->name,
            // Den signerade URL:en som sidan kom in på — POST:en går till
            // exakt samma adress, så signaturen gäller oförändrad.
            'actionUrl' => $request->fullUrl(),
        ]);
    }

    public function store(
        Agent $agent,
        Request $request,
        DisconnectAgent $disconnect,
    ): Response {
        $disconnect->handle($agent, $request->ip(), $request->userAgent());

        return Inertia::render('Mcp/Disconnected');
    }
}
