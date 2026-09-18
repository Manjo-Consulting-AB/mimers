<?php

namespace App\Http\Middleware;

use App\Http\Resources\AccountResource;
use App\Http\Resources\AuthUserResource;
use App\Models\Account;
use App\Models\Container;
use App\Support\Frontend\ActiveContainer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

/**
 * De delade propsen — det enda som når varje webbsida, se issue 51
 * § Beslut 2 och 3.
 *
 * Sex nycklar, och ingen av dem byggs för hand: `auth.user` och
 * `auth.accounts` kommer ur samma API Resource-klasser som `/api` använder
 * ([[ADR-0021 Frontendteknik]] § "Inertia-props renderas ur samma API
 * Resource-klasser som /api"), `activeContainer` ur
 * App\Support\Frontend\ActiveContainer och `flash.status` ur sessionen.
 *
 * `locale` och `translations` kom med issue 52: locale sätts av
 * App\Http\Middleware\SetLocale, som ligger FÖRE den här middlewaren i
 * `web`-gruppen, så `App::getLocale()` är redan rätt när `share()` körs.
 * `translations` är `lang/{locale}/ui.php` och ingenting annat — notiser.php
 * och export.php är serverrenderat innehåll (mejl, ICS, PDF) och levereras
 * aldrig som prop.
 *
 * Allt är closures. Inertias middleware anropar share() på varje webbanrop
 * — även POST-rutter som bara svarar med en omdirigering — och löser först
 * senare upp det som faktiskt ska serialiseras, så en closure är skillnaden
 * mellan "frågan ställs när sidan renderas" och "frågan ställs på varje
 * anrop". `auth` är därför också lazy, inte bara `activeContainer`.
 *
 * `errors` delas medvetet INTE här. Inertia lägger redan sessionens
 * valideringsfel i propsen (Inertia\Middleware::share()), och en egen
 * version skuggar den — se issue 51 § Beslut 9.
 *
 * Sedan issue 83 sätter den här middlewaren också den aktiva containern:
 * `activeContainer` är bokföringen över vilken container användaren arbetar
 * i, och bokföringen sköter sig själv — den sätts av att containern ÖPPNAS.
 * Se setActiveContainer().
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(private readonly ActiveContainer $activeContainer) {}

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Navigationen sätter containerkontexten (issue 83).
     *
     * Anropet ligger FÖRE `$next`, och ordningen är bindande på två sätt.
     * Dels hinner kontexten sättas för den sida som öppnade den: `share()`
     * bygger sina props som closures (se ovan), och `activeContainer` läses
     * först när kontrollern svarat och sidan renderas. Dels ligger
     * åtkomstkontrollen före skrivningen — samma grind som
     * App\Http\Controllers\ItemController::index() ställer med
     * `Gate::authorize()` — så en container användaren inte når rör aldrig
     * sessionen.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->setActiveContainer($request);

        return parent::handle($request, $next);
    }

    /**
     * Gör containern i URL:en till sessionens kontext, när det är en
     * container som ÖPPNAS.
     *
     * Bara `containers.show` — containerns egen sida, itemlistan — räknas som
     * att öppna den. Att redigera ett item eller tömma papperskorgen i en
     * container är inte samma handling, och `ActiveContainer::set()` glömmer
     * nyckeln när åtkomsten saknas: den får därför bara anropas efter att
     * grinden sagt ja, annars hade ett nekat anrop tömt en kontext användaren
     * redan hade.
     *
     * Ingen egen rutt och ingen knapp gör det här för hand —
     * `PUT /containers/{container}/active` togs bort i samma issue.
     */
    private function setActiveContainer(Request $request): void
    {
        $user = $request->user();
        $route = $request->route();

        if ($user === null || $route?->getName() !== 'containers.show') {
            return;
        }

        $container = $route->parameter('container');

        if (! $container instanceof Container) {
            return;
        }

        if (! Gate::forUser($user)->allows('view', $container)) {
            return;
        }

        $this->activeContainer->set($user, $container);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => fn (): array => $this->auth($request),
            'activeContainer' => fn (): ?string => $this->activeContainer->forUser($request->user()),
            'locale' => fn (): string => App::getLocale(),
            'translations' => fn (): array => Lang::get('ui'),
            'flash' => [
                'status' => fn (): ?string => $request->session()->get('status'),
            ],
        ];
    }

    /**
     * Den inloggade användaren och hennes konton, eller tomt för en gäst.
     *
     * ETT villkor högst upp, inte en `?->`-kedja per fält: ett fält som
     * glöms blir en null-krasch i en komponent, och ett som glöms i den
     * andra riktningen blir ett läckage. En gäst får `user: null` och
     * `accounts: []` — samma form som en inloggad får, så en komponent
     * aldrig behöver två avpackningsvägar.
     *
     * `accounts.subscription.plan` laddas i förväg. Utan det kostar varje
     * konto egna frågor och en sida med tre konton blir dyrare än en med
     * ett; med det är frågekostnaden konstant i antalet konton. Planen
     * själv kommer ur PlanResource::forAccount(), som äger sitt eget
     * memoiserade free-uppslag.
     */
    private function auth(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [
                'user' => null,
                'accounts' => [],
            ];
        }

        $user->loadMissing('accounts.subscription.plan');

        return [
            'user' => AuthUserResource::make($user)->resolve($request),
            'accounts' => $user->accounts
                ->map(fn (Account $account): array => AccountResource::make($account)->resolve($request))
                ->all(),
        ];
    }
}
