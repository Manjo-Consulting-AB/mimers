<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebhookEndpointRequest;
use App\Http\Requests\UpdateWebhookEndpointRequest;
use App\Http\Resources\WebhookEndpointResource;
use App\Models\Account;
use App\Models\WebhookEndpoint;
use App\Support\Notification\UnsafeUrlException;
use App\Support\Notification\UrlSafetyValidator;
use App\Support\Plan\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * CRUD-ytan för kontots webhook-endpoints, issue 37a. Registrera, lista,
 * ändra och ta bort de URL:er kontot vill att notiserna skickas till. Själva
 * leveransen — HMAC-signaturen, omförsöken och den andra SSRF-kontrollen — är
 * 37b och rör inte den här klassen.
 *
 * INGEN behörighetslogik bor här: varje metod börjar med
 * `Gate::authorize('manageWebhooks', $account)` och litar på svaret från
 * App\Policies\AccountPolicy — den nya metoden i Beslut 4, som kräver `owner`
 * eller `admin`. Samma grind på alla fyra rutterna, läsning inräknad: listan
 * bär vilka URL:er kontot skickar till, vilket är samma känsliga uppgift.
 *
 * Plangrinden (Beslut 5) sitter på POST och PATCH, inte på GET och DELETE —
 * ett konto som nedgraderats till Free ska kunna se och ta bort sina
 * endpoints, annars är nedgraderingen en fälla. Ordningen är bindande (27a §
 * Beslut 3): `Gate::authorize()` först, `Entitlements::assertFeature()` sedan.
 * En användare som inte får förvalta kontots webhooks ska få auth.forbidden —
 * inte veta vilken plan kontot ligger på.
 *
 * SSRF-valideringen (Beslut 6) körs på POST och — när url ändras — på PATCH,
 * och ett `UnsafeUrlException` blir `webhook.unsafe_url` med `reason` i data,
 * 422: URL:en är ogiltig, inte nekad.
 *
 * {webhook} binds inom {account} av routes/api.php:s scopeBindings() genom
 * App\Models\Account::webhooks() — en endpoint-ULID från ett annat konto ger
 * 404 resource.not_found (Beslut 3).
 */
class WebhookEndpointController extends Controller
{
    private const SECRET_LENGTH = 64;

    /**
     * GET /api/accounts/{account}/webhooks — 200. Kontots endpoints, sorterade
     * på skapelsetid fallande. Bara GET-listan, ingen show(): en enskild rad
     * hämtas inte förrän den ändras eller tas bort, och listan är hela
     * förvaltningsvyn (samma val som CalendarFeedController).
     */
    public function index(Account $account): JsonResponse
    {
        Gate::authorize('manageWebhooks', $account);

        $endpoints = $account->webhooks()
            ->orderByDesc('created_at')
            ->get();

        return WebhookEndpointResource::collection($endpoints)->response();
    }

    /**
     * POST /api/accounts/{account}/webhooks — 201. Registrerar en endpoint och
     * returnerar klartexthemligheten — den ENDA gången den finns någonstans
     * (Beslut 2). Tappas den bort skapar man en ny endpoint; ingen
     * rotationsyta byggs i MVP (Frågor och antaganden).
     *
     * `secret` genereras här, sätts explicit och krypteras av modellens cast
     * vid sparning. `url` och `event_types` är massilldelningsbara, men sätts
     * explicit ändå för att hålla skapandet på en rad — förutom att
     * event_types-dubbletter tas bort innan de sparas (Beslut 7).
     */
    public function store(
        StoreWebhookEndpointRequest $request,
        Account $account,
        UrlSafetyValidator $urlSafety,
        Entitlements $entitlements,
    ): JsonResponse {
        Gate::authorize('manageWebhooks', $account);

        $entitlements->assertFeature($account, 'webhooks');

        $url = $request->validated('url');

        $this->assertSafe($urlSafety, $url);

        $rawSecret = Str::random(self::SECRET_LENGTH);

        $endpoint = new WebhookEndpoint;
        $endpoint->account_id = $account->id;
        $endpoint->url = $url;
        $endpoint->secret = $rawSecret;
        $endpoint->event_types = $this->withoutDuplicates($request->validated('event_types'));
        // Sätts explicit, inte via databasens default: modellinstansen ska
        // spegla raden redan i svaret (en ny endpoint är alltid aktiv).
        $endpoint->is_active = true;
        $endpoint->save();

        return (new WebhookEndpointResource($endpoint))
            ->additional(['secret' => $rawSecret])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH /api/accounts/{account}/webhooks/{webhook} — 200. Ändrar `url`,
     * `event_types` och/eller `is_active` (Beslut 3). `secret` ändras inte —
     * en ny hemlighet är en ny endpoint — och `consecutive_failures` skrivs
     * bara i ETT fall här: en återaktivering (is_active sätts till true på en
     * inaktiv endpoint) nollställer räknaren, annars inaktiveras endpointen
     * igen efter ett enda fel.
     */
    public function update(
        UpdateWebhookEndpointRequest $request,
        Account $account,
        WebhookEndpoint $webhook,
        UrlSafetyValidator $urlSafety,
        Entitlements $entitlements,
    ): WebhookEndpointResource {
        Gate::authorize('manageWebhooks', $account);

        $entitlements->assertFeature($account, 'webhooks');

        $data = $request->validated();

        if (isset($data['url'])) {
            $this->assertSafe($urlSafety, $data['url']);
        }

        if (isset($data['event_types'])) {
            $data['event_types'] = $this->withoutDuplicates($data['event_types']);
        }

        $wasActive = $webhook->is_active;

        $webhook->fill($data);

        if (($data['is_active'] ?? null) === true && ! $wasActive) {
            $webhook->consecutive_failures = 0;
        }

        $webhook->save();

        return new WebhookEndpointResource($webhook);
    }

    /**
     * DELETE /api/accounts/{account}/webhooks/{webhook} — 204, ingen kropp.
     * Raderar raden på riktigt (Beslut 1): en endpoint som ska bort ska bort,
     * ingen mjukradering och ingen bevarad URL. Idempotent på samma sätt som
     * all DELETE: en redan raderad endpoint har redan gett 404 i bindningen.
     */
    public function destroy(Account $account, WebhookEndpoint $webhook): Response
    {
        Gate::authorize('manageWebhooks', $account);

        $webhook->delete();

        return response()->noContent();
    }

    /**
     * @throws ApiException
     */
    private function assertSafe(UrlSafetyValidator $urlSafety, string $url): void
    {
        try {
            $urlSafety->assertSafe($url);
        } catch (UnsafeUrlException $e) {
            throw ApiException::make('webhook.unsafe_url', ['reason' => $e->reason], 422);
        }
    }

    /**
     * Dubbletter tas bort innan de sparas (Beslut 7) — en endpoint som
     * prenumererar på samma typ två gånger är en rad som kan förvirra.
     *
     * @param  array<int, string>  $eventTypes
     * @return array<int, string>
     */
    private function withoutDuplicates(array $eventTypes): array
    {
        return array_values(array_unique($eventTypes));
    }
}
