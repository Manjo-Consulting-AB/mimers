<?php

namespace App\Http\Controllers;

use App\Actions\Access\ListContainerAccesses;
use App\Actions\Access\ListParticipants;
use App\Http\Resources\ContainerAccessResource;
use App\Http\Resources\ContainerResource;
use App\Http\Resources\ParticipantResource;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Support\Access\AccessLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webbens delningsyta — deltagarlistan och förvaltningen av åtkomsterna, se
 * issue 55a § Beslut 1, 3 och 6.
 *
 * **Sidan bär två sektioner med olika publik, och den ena datan skickas
 * inte alls** (§ Beslut 3). Deltagarlistan har grinden `view()` — varje
 * deltagare ser den, också en `read`-guest. Åtkomsterna har grinden
 * `viewAccesses()`, alltså medlemskap i ägarkontot. Skiljelinjen är
 * [[Konton och åtkomst]] § Behörighetsregler, sista stycket: den som läser
 * något känsligt ska veta vem mer som kan, men inte vem som beviljade vad,
 * vilka adresser som haft åtkomst eller historiken av återkallanden.
 *
 * **`accesses` är `null` när svaret är nej** — inte en tom lista, och inte
 * en fylld lista som vyn låter bli att rendera. En prop som ligger i sidans
 * HTML är utlämnad oavsett vad Vue gör med den, och det är just den sortens
 * läckage som inte syns i någon vy. Samma sak gäller `itemNames`: det är
 * förvaltningsdata om åtkomsterna och följer dem.
 *
 * **Ingen `POST`.** Webben beviljar aldrig en åtkomst direkt — all ny delning
 * går genom en inbjudan, se § Beslut 2. `POST
 * /api/containers/{container}/accesses` tar en mottagar-ULID, och vägen från
 * en e-postadress till en ULID är ett uppslag "har adressen ett konto?", som
 * är en kontoenumerering. Inbjudningsformuläret och acceptflödet är 55b, och
 * den här sidan får sin tredje sektion där.
 *
 * **Ingen behörighetslogik bor här.** Metoden anropar `Gate::authorize()`
 * respektive `Gate::allows()` och litar på App\Policies\ContainerPolicy,
 * precis som API-kontrollern gör. Ett nekat svar kastar
 * `AuthorizationException`, som bootstrap/app.php renderar som 403-sidan.
 *
 * Rutten ligger bakom `auth` (routes/web.php) — en utloggad besökare skickas
 * till /login av middlewaren och når aldrig den här metoden.
 */
class ContainerSharingController extends Controller
{
    /**
     * GET /containers/{container}/sharing.
     *
     * `can.manage` och `can.revoke` är de två grindarna för sidans två
     * skrivningar, och de är INTE samma fråga: `manageAccess()` har en
     * `read_only`-kontroll som `revokeAccess()` saknar, för regel 4 undantar
     * uttryckligen återkallandet — det minskar exponeringen i stället för att
     * öka den. En `read_only`-ägare ser därför en sida där *Återkalla*
     * fungerar och *Spara nivå* ger 403, och vyn skriver ut det med en rad i
     * stället för att låta användaren upptäcka det som ett fel (§ Beslut 9).
     * Flaggan är presentation; grinden är policyn, och PATCH/DELETE
     * auktoriserar med `Gate::authorize()` oavsett vad sidan visade.
     *
     * `levels` skickas som prop ur `AccessLevel::LADDER` — samma teknik som
     * `Container::KINDS` i issue 54 § Beslut 8, och av samma skäl: laddern är
     * fyra kolumnvärden med en ordning, och en avskrift i JavaScript blir en
     * andra sanning om vilka nivåer som finns.
     *
     * `itemNames` är ett eget uppslag och INTE ett fält i
     * `ContainerAccessResource` (§ Beslut 6): itemets namn behövs för att
     * läsaren ska se att "motorn" betyder fyra items, men `/api` har inte
     * bett om namnet och resursen är delad. EN fråga med `withTrashed()` —
     * en grant på ett mjukraderat item ska redovisas med sitt namn, precis
     * som resursen redan redovisar dess ULID.
     *
     * Uppslaget castas till ett objekt och skickas aldrig som en tom PHP-lista:
     * `[]` blir `[]` i JSON, och `{}` blir `{}`. Vyn slår upp på ULID, och en
     * prop vars form skiftar med innehållet är en form som måste prövas två
     * gånger på klientsidan.
     */
    public function show(
        Request $request,
        Container $container,
        ListContainerAccesses $listAccesses,
        ListParticipants $listParticipants,
    ): Response {
        Gate::authorize('view', $container);

        $user = $request->user();

        // Ladda ägarkontot uttryckligen så ContainerResource aldrig kör en
        // oplanerad lazy-load — samma resonemang som ContainerController.
        $container->loadMissing('account');

        $mayViewAccesses = Gate::allows('viewAccesses', $container);

        $accesses = $mayViewAccesses ? $listAccesses->handle($container) : null;

        return Inertia::render('Containers/Sharing', [
            'container' => ContainerResource::make($container)->resolve($request),
            'participants' => ParticipantResource::collection($listParticipants->handle($container))->resolve($request),
            'accesses' => $accesses === null
                ? null
                : ContainerAccessResource::collection($accesses)->resolve($request),
            'itemNames' => (object) ($accesses === null ? [] : $this->itemNames($accesses)),
            'levels' => AccessLevel::LADDER,
            'can' => [
                'manage' => Gate::forUser($user)->allows('manageAccess', $container),
                'revoke' => Gate::forUser($user)->allows('revokeAccess', $container),
            ],
        ]);
    }

    /**
     * Item-ULID → itemets namn för de åtkomstrader som har ett item. EN
     * fråga, oavsett antal rader, och `withTrashed()` av skälet i
     * klassens docblock.
     *
     * Nyckeln är ULID och inte löpnummer: det är ULID:n resursen bär i
     * `item`, och vyn slår upp på den utan att känna till något löpnummer.
     *
     * @param  Collection<int, ContainerAccess>  $accesses
     * @return array<string, string>
     */
    private function itemNames(Collection $accesses): array
    {
        $itemIds = $accesses->pluck('item_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($itemIds === []) {
            return [];
        }

        return Item::withTrashed()
            ->whereIn('id', $itemIds)
            ->pluck('name', 'ulid')
            ->all();
    }
}
