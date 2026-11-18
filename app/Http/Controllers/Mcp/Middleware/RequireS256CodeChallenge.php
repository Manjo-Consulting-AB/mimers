<?php

namespace App\Http\Controllers\Mcp\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Symfony\Component\HttpFoundation\Response;

/**
 * PKCE med S256 är ett krav, se [[ADR-0087 MCP-server för agenter]]
 * Riktning 5 och issue 400 § 2.
 *
 * Klienterna är publika och har ingen klienthemlighet, så code challenge är
 * det som binder auktoriseringskoden till den som bad om den. `league/
 * oauth2-server` kräver visserligen en challenge för publika klienter, men
 * den accepterar `plain` — och `plain` skickar verifieraren i klartext genom
 * auktoriseringsförfrågan, alltså samma sak som ingen PKCE alls. Den här
 * grinden stänger den vägen innan Passports kontroller tar vid.
 *
 * **Felet går till klientens redirect-URI och inte till en felsida.**
 * OAuth-klienten väntar på `?error=` och kan visa något begripligt; en 400
 * hade lämnat den hängande. Redirect-URI:n prövas mot klientens registrerade
 * lista först — annars vore grinden en öppen omdirigering.
 *
 * Frånvaron av `code_challenge` prövas inte här: den avvisas av
 * auktoriseringsservern för varje publik klient, med samma svar som ett
 * saknat klient-id.
 */
class RequireS256CodeChallenge
{
    public function __construct(
        private readonly ClientRepository $clients,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->query('code_challenge') === null
            || $request->query('code_challenge_method') === 'S256') {
            return $next($request);
        }

        $client = $this->clients->find((string) $request->query('client_id'));
        $redirectUri = (string) $request->query('redirect_uri');
        $registered = array_map('strval', (array) ($client?->getAttribute('redirect_uris') ?? []));

        if ($client === null || ! in_array($redirectUri, $registered, true)) {
            abort(400);
        }

        $params = [
            'error' => 'invalid_request',
            'error_description' => 'PKCE with code_challenge_method=S256 is required.',
        ];

        if (is_string($state = $request->query('state')) && $state !== '') {
            $params['state'] = $state;
        }

        return redirect()->away(
            $redirectUri.(Str::contains($redirectUri, '?') ? '&' : '?').http_build_query($params),
        );
    }
}
