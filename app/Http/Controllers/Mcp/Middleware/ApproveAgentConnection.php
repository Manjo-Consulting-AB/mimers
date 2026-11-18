<?php

namespace App\Http\Controllers\Mcp\Middleware;

use App\Actions\Agent\AgentConnectionRefusedException;
use App\Actions\Agent\ConnectAgent;
use App\Actions\Agent\InspectAgentConnection;
use App\Models\Agent;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Bridge\Client;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Bridge\User;
use Laravel\Passport\ClientRepository;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gör ett godkännande till en agent, se [[ADR-0087 MCP-server för agenter]]
 * Riktning 12 och issue 400 § 3–5.
 *
 * **Grinden ligger före Passports godkännandekontroller.** Rutten är
 * Passports `POST /oauth/authorize`; middlewaren läggs på den i
 * App\Providers\AppServiceProvider. Den läser samma session som kontrollern
 * — `authToken` och den serialiserade `authRequest` — men PULLAR dem inte, så
 * Passport kan förbruka dem efteråt precis som förut.
 *
 * **Klienten och användaren kommer ur sessionen, inte ur formuläret.** Ett
 * `client_id` i POST-kroppen är bara en påminnelse om vad sidan visade; det
 * som godkänns är den förfrågan Passport själv lade i sessionen. En kropp
 * som pekar på en annan klient ändrar därför ingenting.
 *
 * **Plan, tak och ersättning prövas FÖRE svaret.** Ett gratiskonto och en
 * elfte agent avvisas med 403, även om någon postar förbi sidan. Det som
 * däremot skrivs — raden, säkerhetsloggen och mejlet — sker först när
 * Passport har svarat med en `code` i redirecten: en agent ska inte finnas
 * förrän anslutningen faktiskt blev godkänd.
 *
 * **Koden och raden hör till samma transaktion.** Passport skriver
 * auktoriseringskoden innan svaret går ut, så en vägran EFTER `$next` får
 * inte sväljas: då hade klienten kunnat byta en kod mot tokens utan att
 * någon `agent`-rad fanns, förbi både plan- och takgrinden. `$next` och
 * `ConnectAgent` ligger därför i samma `DB::transaction`; en vägran rullar
 * tillbaka koden och ger 403. Tillståndet kan ha hunnit ändras mellan
 * kontrollen och skrivningen — en annan flik kopplade bort den sista
 * agenten, eller ett samtidigt godkännande tog den sista platsen.
 *
 * **Samma klient igen skickar inget mejl.** `App\Actions\Agent\ConnectAgent`
 * känner igen raden på `(user_id, oauth_client_id)` och återanvänder den.
 */
class ApproveAgentConnection
{
    /**
     * Klasserna som får vakna ur sessionens serialiserade förfrågan — samma
     * lista som Passports egen `RetrievesAuthRequestFromSession`.
     *
     * @var array<int, class-string>
     */
    private const ALLOWED_CLASSES = [
        AuthorizationRequest::class,
        Client::class,
        Scope::class,
        User::class,
    ];

    public function __construct(
        private readonly InspectAgentConnection $inspect,
        private readonly ConnectAgent $connect,
        private readonly ClientRepository $clients,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authRequest = $this->authRequestFromSession($request);
        $user = $request->user();

        if ($authRequest === null || $user === null) {
            // Passport svarar samma sak som förut: ett ogiltigt `auth_token`
            // eller en session utan förfrågan är dess fel, inte vårt.
            return $next($request);
        }

        $client = $this->clients->find(
            (string) $authRequest->getClient()->getIdentifier(),
        );

        if ($client === null) {
            return $next($request);
        }

        $state = $this->inspect->handle($user, $client);

        if ($state['requires_pro']) {
            abort(403);
        }

        $replaceUlid = $this->replacementChoice($request, $state);

        try {
            return DB::transaction(function () use ($request, $next, $user, $client, $replaceUlid): Response {
                $response = $next($request);

                if ($this->isApproved($response)) {
                    // Kastar AgentConnectionRefusedException om tillståndet
                    // hann ändras: då rullas också koden som Passport just
                    // skrev, och inget svar med `code=` går ut.
                    $this->connect->handle($user, $client, $replaceUlid);
                }

                return $response;
            });
        } catch (AgentConnectionRefusedException) {
            abort(403);
        }
    }

    /**
     * Valet ur formuläret, prövat mot det som faktiskt gäller.
     *
     * @param  array{existing: Agent|null, limit_reached: bool, agents: Collection<int, Agent>}  $state
     */
    private function replacementChoice(Request $request, array $state): ?string
    {
        if ($state['existing'] instanceof Agent) {
            return null;
        }

        $choice = (string) $request->input('agent_choice', '');
        $replaceUlid = str_starts_with($choice, 'replace:') ? substr($choice, strlen('replace:')) : null;

        if ($replaceUlid !== null) {
            $target = $state['agents']->first(
                fn (Agent $agent): bool => $agent->ulid === $replaceUlid,
            );

            if (! $target instanceof Agent) {
                abort(403);
            }

            return $replaceUlid;
        }

        if ($state['limit_reached']) {
            abort(403);
        }

        return null;
    }

    private function authRequestFromSession(Request $request): ?AuthorizationRequestInterface
    {
        $posted = $request->input('auth_token');
        $stored = $request->session()->get('authToken');

        if (! is_string($posted) || ! is_string($stored) || ! hash_equals($stored, $posted)) {
            return null;
        }

        $serialized = $request->session()->get('authRequest');

        if (! is_string($serialized)) {
            return null;
        }

        $authRequest = unserialize($serialized, ['allowed_classes' => self::ALLOWED_CLASSES]);

        return $authRequest instanceof AuthorizationRequestInterface ? $authRequest : null;
    }

    /**
     * Passport svarar med en redirect till klientens `redirect_uri` som bär
     * `code=`. Ett avslag bär `error=` i stället.
     */
    private function isApproved(Response $response): bool
    {
        $location = $response->headers->get('Location');

        return $response->isRedirect()
            && is_string($location)
            && str_contains($location, 'code=');
    }
}
