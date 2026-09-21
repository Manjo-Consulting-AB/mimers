<?php

namespace App\Http\Controllers;

use App\Exceptions\Api\ApiException;
use App\Http\Requests\StoreWebhookEndpointRequest;
use App\Http\Requests\UpdateWebhookEndpointRequest;
use App\Http\Resources\WebhookEndpointResource;
use App\Models\Account;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Support\Frontend\ApiErrorTranslator;
use App\Support\Notification\UnsafeUrlException;
use App\Support\Notification\UrlSafetyValidator;
use App\Support\Plan\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kontots webhook-endpoints — den utgång ur produkten som gör att Telegram,
 * Slack och Home Assistant aldrig behöver byggas ([[ADR-0010
 * Notisarkitektur]]), se issue 65b § Beslut 1, 3, 5, 6, 7 och 8.
 *
 * **Webhookarna bor i INSTÄLLNINGARNA och inte i containern.** De hör till
 * KONTOT — kontot äger URL:en, betalar för funktionen och är det vars plan
 * grinden läser — och sidan får därför en egen rad i
 * resources/js/layouts/settingsSections.js (Beslut 1).
 *
 * **Kontot kommer ur ett FÄLT, aldrig ur en gissning.** `/api` tar kontot ur
 * rutten (`/accounts/{account}/webhooks`); webbens fyra rutter har ingen
 * sådan parameter, så sidan väljer konto i en väljare och skickar det som
 * `account` i kroppen (POST/PATCH) respektive querysträngen (GET/DELETE).
 * Grinden prövas sedan mot DET kontot — aldrig mot "användarens första
 * konto", som vore en behörighetskontroll mot en slump (Beslut 1).
 *
 * **`{webhook}` binds på ULID och prövas sedan mot kontot.** Rutten
 * `/settings/webhooks/{webhook}` har ingen `{account}`-parameter, så
 * `scopeBindings()` kan inte göra det `/api` gör; i stället jämförs
 * `account_id` mot det valda kontot och en endpoint från ett annat konto ger
 * 404, precis som App\Models\Account::webhooks() ger på `/api` (37a § Beslut
 * 3). Utan den kontrollen kunde en förvaltare av konto A ändra konto B:s
 * endpoint genom att skicka sitt eget konto-ULID.
 *
 * **Ingen behörighetslogik bor här.** Varje metod börjar med
 * `Gate::authorize('manageWebhooks', $account)` och litar på
 * App\Policies\AccountPolicy::manageWebhooks(), som kräver `owner` eller
 * `admin` — samma grind på alla fyra rutterna, läsning inräknad, för listan
 * bär vilka URL:er kontot skickar till (37a § Beslut 4). Ett nekat svar
 * kastar `AuthorizationException`, som bootstrap/app.php renderar som 403.
 *
 * **Plangrinden sitter på POST och PATCH, inte på GET och DELETE** (Beslut 5).
 * Ett konto som nedgraderats ska kunna SE och TA BORT det den har, annars är
 * nedgraderingen en fälla. Ordningen är bindande (27a § Beslut 3):
 * `Gate::authorize()` först, `Entitlements::assertFeature()` sedan — en
 * användare som inte får förvalta kontots webhooks ska få 403, inte veta
 * vilken plan kontot ligger på.
 *
 * **Planfelet blir ett fältfel och aldrig en JSON-kropp** (Beslut 5).
 * `Entitlements::assertFeature()` är byggd för `/api` och kastar en
 * `ApiException` som svarar `{"error":{…}}` var den än kastas — i en
 * webbläsare vore det en rå kropp på skärmen. App\Support\Frontend\
 * ApiErrorTranslator gör koden till en mening ([[ADR-0013 Språk och i18n]]
 * § Felkoden rörs inte), och meningen hamnar på formulärnyckeln `plan`, inte
 * på ett fält: felet handlar inte om vad användaren skrev. Vyn renderar
 * `errors.plan` som en ruta. När kontot saknar funktionen står SAMMA mening
 * som förklaring på sidan — `index()` formulerar den med `featureNotice()`
 * och skickar den som prop, så de två kan inte säga olika saker (Beslut 5).
 *
 * **URL:ens SSRF-kontroll är serverns och ingen annans** (Beslut 7). Sidan
 * prövar varken privata intervall, `localhost` eller metadatatjänster — den
 * här kontrollern anropar samma App\Support\Notification\UrlSafetyValidator
 * som `/api`, och serverns `webhook.unsafe_url` blir ett fältfel på `url`. En
 * klientkontroll som svarade något annat än servern vore en bugg som ser ut
 * som ett fel hos användaren.
 *
 * **Ingen leveranshistorik och ingen omleveransknapp** (omfångsrutan):
 * `webhook_delivery` har ingen listningsrutt i `/api`, och en vy utan en rutt
 * är en ny API-yta.
 */
class WebhookEndpointController extends Controller
{
    /**
     * Klartexthemlighetens längd — samma 64 tecken som
     * App\Http\Controllers\Api\WebhookEndpointController::SECRET_LENGTH.
     * Två konstanter och inte en delad: hemligheten krypteras och signeras,
     * den har inget format de två vägarna måste vara ense om, och den delade
     * klassen ligger utanför den här issuen.
     */
    private const SECRET_LENGTH = 64;

    /**
     * Sessionsnyckeln hemligheten flashas under. Stavas bara här —
     * `store()` lägger den och `index()` läser den.
     */
    private const SECRET_SESSION_KEY = 'webhook_secret';

    /**
     * GET /settings/webhooks — kontots endpoints.
     *
     * Kontot kommer ur `?account=` (ett ULID) och faller tillbaka på det
     * första konto användaren får förvalta. **Väljaren listar ALLA hennes
     * konton**: att utelämna ett konto ur listan vore att dölja en knapp, och
     * behörighetskontroller görs i policies (M10 § ingressen). Att välja ett
     * förval är inte samma sak — det avgör bara vilken sida hon landar på.
     *
     * `endpoints` byggs ur App\Http\Resources\WebhookEndpointResource med EN
     * rad lagd bredvid: `disabledBySystem`. Fältet hör inte i `/api` — det är
     * webbens förklaring till varför en rad är avstängd — så det läggs i
     * kontrollern, samma mönster som `can` i
     * App\Http\Controllers\ContainerController::index() (Beslut 8).
     *
     * `secret` är klartexten ur redirectens flash, och är `null` vid varje
     * annan visning än den direkt efter ett skapande (Beslut 3).
     *
     * `eventTypes` skickas som PROP, samma teknik som `kinds` i
     * ContainerController::create(): listan finns i
     * App\Models\WebhookEndpoint::EVENT_TYPES och ska inte skrivas av i
     * JavaScript. Två listor blir två sanningar.
     *
     * `planNotice` är svaret på samma fråga som POST ställer — ställd till
     * `Entitlements` genom samma `featureNotice()` och formulerad ur samma
     * nyckel — så att meningen på sidan och fältfelet vid POST aldrig kan
     * säga olika saker (Beslut 5). `null` betyder att funktionen är öppen.
     */
    public function index(Request $request, Entitlements $entitlements, ApiErrorTranslator $translator): Response
    {
        $user = $request->user();

        $accounts = $user->accounts()->orderBy('name')->get();

        abort_if($accounts->isEmpty(), 404);

        $account = $this->selectedAccount($request, $user, $accounts);

        Gate::authorize('manageWebhooks', $account);

        $endpoints = $account->webhooks()->orderByDesc('created_at')->get();
        $deactivateAfter = $this->deactivateAfterFailures();

        return Inertia::render('Settings/Webhooks', [
            'accounts' => $accounts->map(fn (Account $konto): array => [
                'ulid' => $konto->ulid,
                'name' => $konto->name,
            ])->all(),

            'account' => [
                'ulid' => $account->ulid,
                'name' => $account->name,
            ],

            'endpoints' => $endpoints->map(fn (WebhookEndpoint $endpoint): array => [
                ...WebhookEndpointResource::make($endpoint)->resolve($request),

                // Systemet stänger av en endpoint när `consecutive_failures`
                // nått taket i config/notiser.php (37b § Beslut 8). Tröskeln
                // och inte `> 0`: en endpoint som är AKTIV kan aldrig ha nått
                // taket (jobbet stänger av den i samma skrivning som den
                // passerar det), så `>=` betyder "systemet gjorde det" medan
                // `> 0` hade påstått det om en rad användaren själv stängt av
                // efter ett par misslyckanden.
                'disabledBySystem' => ! $endpoint->is_active
                    && $endpoint->consecutive_failures >= $deactivateAfter,
            ])->all(),

            'eventTypes' => WebhookEndpoint::EVENT_TYPES,

            'planNotice' => $this->featureNotice($entitlements, $account, $translator),

            'secret' => $request->session()->get(self::SECRET_SESSION_KEY),
        ]);
    }

    /**
     * POST /settings/webhooks — registrerar en endpoint och skickar
     * hemligheten vidare i flashen.
     *
     * Klartexten genereras här, sätts explicit och krypteras av modellens
     * `encrypted`-cast vid sparning (Beslut 3). Den finns i svaret EN gång;
     * tappas den bort skapar man en ny endpoint, för ingen rotationsyta
     * byggs i MVP ([[Notiser]] § webhook_endpoint).
     *
     * Ingen ny FormRequest (omfångsrutan): `StoreWebhookEndpointRequest`
     * delas rakt av med `/api`, och fältet `account` valideras inte av den —
     * det är en ruttfråga, inte ett formulärfält, och läses därför utanför
     * `validated()`.
     */
    public function store(
        StoreWebhookEndpointRequest $request,
        UrlSafetyValidator $urlSafety,
        Entitlements $entitlements,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        $account = $this->accountFrom($request);

        Gate::authorize('manageWebhooks', $account);

        $this->assertFeature($entitlements, $account, $translator);

        $url = $request->validated('url');

        $this->assertSafeUrl($urlSafety, $url, $translator);

        $rawSecret = Str::random(self::SECRET_LENGTH);

        $endpoint = new WebhookEndpoint;
        $endpoint->account_id = $account->id;
        $endpoint->url = $url;
        $endpoint->secret = $rawSecret;
        $endpoint->event_types = $this->withoutDuplicates($request->validated('event_types'));
        // Sätts explicit, inte via databasens default: modellinstansen ska
        // spegla raden redan i svaret (en ny endpoint är alltid aktiv), samma
        // rad som API-kontrollern.
        $endpoint->is_active = true;
        $endpoint->save();

        return redirect()
            ->route('settings.webhooks', ['account' => $account->ulid])
            ->with(self::SECRET_SESSION_KEY, $rawSecret);
    }

    /**
     * PATCH /settings/webhooks/{webhook} — ändrar `url`, `event_types`
     * och/eller `is_active`.
     *
     * Samma tre fält och samma två undantag som
     * App\Http\Controllers\Api\WebhookEndpointController::update():
     * `secret` ändras inte — en ny hemlighet är en ny endpoint — och
     * `consecutive_failures` skrivs bara i ett fall, en ÅTERAKTIVERING, som
     * nollställer räknaren. Utan nollställningen inaktiveras endpointen igen
     * efter ett enda fel (37a § Beslut 3).
     */
    public function update(
        UpdateWebhookEndpointRequest $request,
        WebhookEndpoint $webhook,
        UrlSafetyValidator $urlSafety,
        Entitlements $entitlements,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        $account = $this->accountFrom($request);

        Gate::authorize('manageWebhooks', $account);

        $this->assertWebhookBelongsToAccount($webhook, $account);

        $this->assertFeature($entitlements, $account, $translator);

        $data = $request->validated();

        if (isset($data['url'])) {
            $this->assertSafeUrl($urlSafety, $data['url'], $translator);
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

        return back()->with('status', 'webhook-updated');
    }

    /**
     * DELETE /settings/webhooks/{webhook} — 302 tillbaka till listan.
     *
     * Raden raderas på riktigt (37a § Beslut 1): en endpoint som ska bort ska
     * bort. Ingen plangrind — ett konto som nedgraderats ska kunna städa
     * (Beslut 5).
     *
     * Leveransraderna städas före endpointen, av exakt samma skäl som i
     * API-kontrollern: `webhook_delivery` pekar på endpointen med ON DELETE
     * RESTRICT, så en endpoint som någon gång tagit emot en händelse hade
     * annars gett 500. Kön tillhör endpointen, och utan en mottagare kvar
     * finns det ingen att försöka mot.
     */
    public function destroy(Request $request, WebhookEndpoint $webhook): RedirectResponse
    {
        $account = $this->accountFrom($request);

        Gate::authorize('manageWebhooks', $account);

        $this->assertWebhookBelongsToAccount($webhook, $account);

        DB::table('webhook_delivery')->where('webhook_endpoint_id', $webhook->getKey())->delete();

        $webhook->delete();

        return back()->with('status', 'webhook-destroyed');
    }

    /**
     * Kontot ur `account`-fältet. Fältet är en ruttfråga på webben — `/api`
     * tar samma uppgift ur `{account}` i sökvägen — och ett ULID som inte
     * finns ger 404, medan ett konto användaren inte får förvalta ger 403 i
     * `Gate::authorize()` hos anroparen.
     */
    private function accountFrom(Request $request): Account
    {
        return Account::query()
            ->where('ulid', (string) $request->input('account'))
            ->firstOrFail();
    }

    /**
     * Sidans konto: `?account=` om det pekar på ett konto användaren är med
     * i, annars det första hon får förvalta — och, om hon inte får förvalta
     * något alls, det första. Valet är ett förval och inte en grind; grinden
     * prövas av `index()` mot det valda kontot, och en `member` får därför
     * 403 precis som på `/api` (Beslut 1).
     *
     * Ett `?account=` som pekar på ett konto hon INTE är med i faller
     * tillbaka på förvalet i stället för att läcka att kontot finns.
     *
     * @param  Collection<int, Account>  $accounts
     */
    private function selectedAccount(Request $request, User $user, Collection $accounts): Account
    {
        $requested = $accounts->firstWhere('ulid', (string) $request->query('account', ''));

        if ($requested !== null) {
            return $requested;
        }

        return $accounts->first(
            fn (Account $account): bool => Gate::forUser($user)->allows('manageWebhooks', $account),
        ) ?? $accounts->first();
    }

    /**
     * Endpointen måste höra till det valda kontot. Det här är webbens
     * motsvarighet till `/api`:s `scopeBindings()`: en ULID från ett annat
     * konto är inte "nekad", den finns inte här — 404, samma svar som
     * App\Models\Account::webhooks() ger på `/api` (37a § Beslut 3).
     *
     * Utan den vore `{webhook}` en ULID utan ägare, och kontot i kroppen en
     * fribiljett: en `admin` i konto A kunde skicka sitt eget ULID och ändra
     * konto B:s endpoint.
     */
    private function assertWebhookBelongsToAccount(WebhookEndpoint $webhook, Account $account): void
    {
        abort_unless($webhook->account_id === $account->getKey(), 404);
    }

    /**
     * Plangrinden som ett formulärfel. Ordningen mot `Gate::authorize()` är
     * anroparens ansvar (27a § Beslut 3), och nyckeln är `plan` och inte ett
     * fältnamn: felet handlar om kontots plan, inte om vad användaren skrev —
     * samma val som `quota` i App\Http\Controllers\ContainerController::store().
     *
     * @throws ValidationException
     */
    private function assertFeature(Entitlements $entitlements, Account $account, ApiErrorTranslator $translator): void
    {
        $notice = $this->featureNotice($entitlements, $account, $translator);

        if ($notice !== null) {
            throw ValidationException::withMessages(['plan' => $notice]);
        }
    }

    /**
     * Meningen för funktionsgrinden, eller `null` om kontots plan har den.
     *
     * Frågan ställs till SAMMA metod som POST grindar med, och ett undantag
     * betyder nej — att i stället läsa `$account->planLimit('webhooks') !==
     * false` hade varit en andra formulering av samma regel, och de två hade
     * glidit isär den dag `assertFeature()` ändras.
     *
     * `index()` skickar svaret som prop och `assertFeature()` ovan som
     * fältfel, så sidan och servern visar samma mening (Beslut 5).
     */
    private function featureNotice(Entitlements $entitlements, Account $account, ApiErrorTranslator $translator): ?string
    {
        try {
            $entitlements->assertFeature($account, 'webhooks');
        } catch (ApiException $e) {
            return $this->planMessage($e, $translator);
        }

        return null;
    }

    /**
     * `plan.feature_unavailable` som mening. `data.feature` är en KOD
     * (`webhooks`) och inte ett namn, så den översätts i två steg — först till
     * ett namn, sedan in i meningen — precis som
     * App\Http\Controllers\ItemLinkController::errorMessage() gör med
     * `item_link.pair_exists`. Ett meddelande som slänger bort `data` är sämre
     * än felkoden det ersatte.
     *
     * Felet byggs om i stället för att skickas rakt in i `message()`:
     * App\Support\Frontend\ApiErrorTranslator läser `data` som ersättningar
     * och översätter dem inte själv.
     */
    private function planMessage(ApiException $exception, ApiErrorTranslator $translator): string
    {
        $feature = (string) ($exception->data()['feature'] ?? '');

        return $translator->message(ApiException::make($exception->errorCode(), [
            'feature' => (string) trans('ui.error.plan.feature_name.'.$feature),
        ]));
    }

    /**
     * URL:en prövas av App\Support\Notification\UrlSafetyValidator — samma
     * klass, samma regler och samma ordning som
     * App\Http\Controllers\Api\WebhookEndpointController::assertSafe()
     * (Beslut 7). Koden mappas till samma `webhook.unsafe_url` med samma
     * `reason`-data som `/api` svarar med, och översätts sedan till en mening
     * på fältet `url`.
     *
     * Orsaken är en KOD (`reserved_ip`, `invalid_scheme`, …) och inte en
     * mening — samma uppdelning som [[ADR-0013 Språk och i18n]] gör för
     * API:et — så den översätts i två steg, precis som
     * App\Http\Controllers\ItemLinkController::errorMessage() gör med
     * `item_link.pair_exists`: först koden till ett led, sedan ledet in i
     * meningen. Ett meddelande som slänger bort `data` är sämre än felkoden
     * det ersatte.
     *
     * @throws ValidationException
     */
    private function assertSafeUrl(UrlSafetyValidator $urlSafety, string $url, ApiErrorTranslator $translator): void
    {
        try {
            $urlSafety->assertSafe($url);
        } catch (UnsafeUrlException $e) {
            $error = ApiException::make('webhook.unsafe_url', [
                'reason' => (string) trans('ui.error.webhook.unsafe_reason.'.$e->reason),
            ], 422);

            throw ValidationException::withMessages(['url' => $translator->message($error)]);
        }
    }

    /**
     * Tröskeln där 37b stänger av en endpoint, ur samma configvärde som
     * App\Console\DeliversWebhooks::registerEndpointFailure() läser. Två
     * läsare och ett värde: att hårdkoda taket här hade varit en andra sanning
     * om när en endpoint ger upp.
     */
    private function deactivateAfterFailures(): int
    {
        return (int) config('notiser.webhook.deactivate_after_failures', 20);
    }

    /**
     * Dubbletter tas bort innan de sparas — en endpoint som prenumererar på
     * samma typ två gånger är en rad som kan förvirra. Samma rad som i
     * API-kontrollern (37a § Beslut 7).
     *
     * @param  array<int, string>  $eventTypes
     * @return array<int, string>
     */
    private function withoutDuplicates(array $eventTypes): array
    {
        return array_values(array_unique($eventTypes));
    }
}
