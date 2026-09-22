<?php

namespace App\Http\Middleware;

use App\Actions\Item\ListFavorites;
use App\Http\Resources\AccountResource;
use App\Http\Resources\AuthUserResource;
use App\Models\Account;
use App\Models\Item;
use App\Support\Frontend\ActiveContainer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Inertia\Middleware;

/**
 * De delade propsen — det enda som når varje webbsida, se issue 51
 * § Beslut 2 och 3.
 *
 * Sju nycklar, och ingen av dem byggs för hand: `auth.user` och
 * `auth.accounts` kommer ur samma API Resource-klasser som `/api` använder
 * ([[ADR-0021 Frontendteknik]] § "Inertia-props renderas ur samma API
 * Resource-klasser som /api"), `activeContainer` ur
 * App\Support\Frontend\ActiveContainer och `flash.status` ur sessionen.
 *
 * **`favorites` kom med issue 106** — sidopanelens `FAVORITER`-sektion,
 * se [[M17 Designsystemet]] § 106 och [[ADR-0042 Designsystemet]]
 * § Konsekvenser. Listan byggs av App\Actions\Item\ListFavorites, som
 * filtrerar den genom ResolveItemScope som varje annan listning: en favorit
 * användaren förlorat åtkomsten till försvinner i stället för att bli en
 * trasig länk, och ingenting i svaret berättar hur många som föll bort
 * (issue 73 § Beslut 6).
 *
 * Raden bär `name` och `url` och ingenting mer. Adressen byggs HÄR och inte i
 * JavaScript: den innehåller två ULID:n som bara servern känner, och skalets
 * egen regel är att en sådan URL skickas som prop (issue 51 § Beslut 7).
 * Ingen `ItemResource`: sektionen visar ett namn och en länk, och resurserna
 * under `app/Http/Resources/` rörs inte av den här issuen.
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
 * Middlewaren bara LÄSER kontexten och delar ut den (issue 83). Den som gör
 * en container till kontext är den kontroller som ÖPPNAR den —
 * App\Http\Controllers\ItemController::index(). Att skriva sessionstillstånd
 * här hade gjort utdelningen ordningsberoende och tvingat skalet att känna
 * igen ett ruttnamn, och då hade en framtida rutt in i containern satt
 * kontexten tyst utan att något test i närheten blev rött.
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

    public function __construct(
        private readonly ActiveContainer $activeContainer,
        private readonly ListFavorites $listFavorites,
    ) {}

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
            'favorites' => fn (): array => $this->favorites($request),
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

    /**
     * Användarens favoriter som raddata åt skalet, eller tomt för en gäst —
     * se klassens docblock.
     *
     * Ett tidigt `return []` och inte en tom lista ur actionen: en gäst har
     * ingen att fråga för, och `ListFavorites` tar en `User`. Formen på
     * svaret är densamma som för en inloggad utan favoriter, så skalet aldrig
     * behöver två avpackningsvägar — samma regel som `auth()` ovan.
     *
     * `route(..., false)` ger en relativ adress, samma form skalet skriver
     * för hand i sina egna `<Link href="/dashboard">`.
     *
     * @return list<array{name: string, url: string}>
     */
    private function favorites(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        return $this->listFavorites->handle($user)
            ->map(fn (Item $item): array => [
                'name' => $item->name,
                'url' => route('containers.items.show', [$item->container, $item], false),
            ])
            ->values()
            ->all();
    }
}
