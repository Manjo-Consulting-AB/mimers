<?php

namespace App\Http\Controllers;

use App\Actions\Item\SearchAccessibleItems;
use App\Http\Resources\ItemResource;
use App\Models\Item;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webbens globala sökning, `GET /search?q=...` — issue 59b § Beslut 1–8.
 *
 * **Frågan är global med flit.** Sidan ligger på toppnivå och inte under en
 * pärm: "var la jag den där?" är en fråga över allt användaren har åtkomst
 * till, inte inom en pärm hon redan valt (issue 15b § Beslut 5). Den är
 * syster till `GET /api/items?q=...` och frågar med SAMMA villkor — urvalet
 * är App\Actions\Item\SearchAccessibleItems, som `Api\ItemSearchController::
 * index()` också anropar (Beslut 2). Ingen andra formulering av åtkomstfiltret
 * bor här: en andra formulering är en andra chans att glömma ett villkor, och
 * den som glöms läcker ([[ADR-0012 Sök]] § Konsekvenser).
 *
 * **Ingen ny FormRequest och ingen ändrad regel** (Beslut 4).
 * `IndexItemRequest` äger `q` på sina två rutter, och `/api`s kontrakt — `q`
 * är obligatorisk, en global lista över allt användaren äger är inte en
 * sökning — står orört. Här lånas regelns FORM (`string`, `max:255`); den
 * enda skillnaden är att tomt är tillåtet, för på webben betyder `/search`
 * utan `q` att någon klickat på sökfältet: sidan renderas i UTGÅNGSLÄGET och
 * kör ingen fråga alls. Varken 422 eller redirect.
 *
 * En `q` över 255 tecken klipps inte tyst — den ger ett vanligt
 * valideringsfel vid fältet, som varje annat webbformulär. `q` trimmas som
 * `IndexItemRequest::prepareForValidation()` gör, och en enbart blank `q` är
 * samma sak som ingen.
 *
 * **Varje träff säger vilken pärm den ligger i** (Beslut 3). `ItemResource`
 * bär ingen `container`-nyckel med flit — rutten bär den redan — och den får
 * den inte heller: `/api` har inte bett om pärmnamnet
 * ([[ADR-0021 Frontendteknik]] § Konsekvenser om drift mellan webbens behov
 * och API:ets kontrakt). Pärmens ULID, namn och `kind` läggs därför BREDVID
 * resursen, i samma form som `can` läggs bredvid `ContainerResource` i
 * App\Http\Controllers\ContainerController::index(). Actionen laddar INTE
 * containern — `/api` bad aldrig om den — så sidan laddar den själv, riktat,
 * efter anropet: en enda extra fråga, konstant över antalet träffar.
 *
 * **Tomt resultat säger vad som söktes — ingenting annat** (Beslut 6). Sidan
 * spänner över flera pärmar med olika omfång i var och en, och texten nämner
 * sökordet och slutar där: aldrig ett tal om hur många rader som fanns,
 * aldrig en antydan om att det kan finnas mer, aldrig en uppräkning av vilka
 * pärmar som genomsöktes. En användare utan åtkomst till någonting alls får
 * därför ordagrant samma tomma svar som en vars sökord inte matchar; ingen av
 * texterna vet om omfånget.
 *
 * Rutten ligger bakom `auth` (routes/web.php) — en utloggad besökare skickas
 * till /login av middlewaren och når aldrig den här metoden.
 */
class SearchController extends Controller
{
    /**
     * GET /search?q=... — 200, alltid. Utan `q` (eller med en blank) är
     * svaret utgångsläget: sökrutan och en rad om vad man kan söka på.
     */
    public function index(Request $request, SearchAccessibleItems $searchAccessibleItems): Response
    {
        // Trimning sker FÖRE validering, som IndexItemRequest::
        // prepareForValidation() gör: annars prövas `max:255` mot det råa
        // värdet och en q som är kort nog efter trim nekas ändå.
        if (is_string($request->query('q'))) {
            $request->merge(['q' => trim($request->query('q'))]);
        }

        // Lånad form av IndexItemRequest:s regel, utan dess `required` — se
        // klassens docblock. `nullable` gör ett saknat fält till ett giltigt
        // värde; `q` är ett fält i ett formulär och ett fel hamnar vid det.
        $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $q = $this->query($request);

        if ($q === null) {
            return Inertia::render('Search', [
                'q' => null,
                'results' => [],
            ]);
        }

        $items = $searchAccessibleItems->handle($request->user(), $q);

        // Riktad eager load HÄR, inte i actionens delade with([...]): /api
        // bad aldrig om pärmen och ska svara med samma antal frågor som förut
        // (Beslut 2, Klart när). Webbsidan får sin pärm i en enda extra fråga,
        // konstant över antalet träffar.
        $items->loadMissing('container');

        return Inertia::render('Search', [
            'q' => $q,
            'results' => $items
                ->map(fn (Item $item): array => [
                    ...ItemResource::make($item)->resolve($request),
                    // BREDVID resursen, aldrig inuti den — se klassens
                    // docblock. Ingen fråga: `container` laddades ovan.
                    'container' => [
                        'ulid' => $item->container->ulid,
                        'name' => $item->container->name,
                        'kind' => $item->container->kind,
                    ],
                ])
                ->all(),
        ]);
    }

    /**
     * Sökordet ur querysträngen, trimmat — eller null när inget finns.
     *
     * Samma normalisering som `IndexItemRequest::prepareForValidation()` gör
     * för `/api`: en enbart blank `q` är samma sak som ingen `q` (Beslut 4).
     * Ett värde av annan typ än en sträng kan inte nå hit — `validate()` ovan
     * har redan nekat `?q[]=…` som ett vanligt valideringsfel.
     */
    private function query(Request $request): ?string
    {
        $q = $request->query('q');
        $q = is_string($q) ? trim($q) : null;

        return $q === '' ? null : $q;
    }
}
