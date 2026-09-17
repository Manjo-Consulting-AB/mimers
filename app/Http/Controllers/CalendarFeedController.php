<?php

namespace App\Http\Controllers;

use App\Http\Resources\CalendarFeedResource;
use App\Http\Resources\ContainerResource;
use App\Models\CalendarFeed;
use App\Models\Container;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Containerns kalenderlänk — den hemliga ICS-adressen en kalenderapp hämtar
 * själv, se issue 65b § Beslut 1, 2 och 4.
 *
 * **Feeden bor i CONTAINERN och inte i inställningarna.** Den visar containerns
 * uppgifter för den inloggade användaren, och den som ska skapa en ny är
 * redan i containern (Beslut 1). Sidan får därför en egen rad i
 * resources/js/layouts/containerSections.js, och den ritas under
 * App\Http\Resources\ContainerResource som varje annan sida under
 * ContainerLayout.
 *
 * **Klartexten visas EN gång och lagras aldrig.** App\Models\CalendarFeed
 * sparar bara `token_hash`, och App\Http\Resources\CalendarFeedResource bär
 * varken hashen eller tokenet — exakt som
 * App\Http\Controllers\Api\CalendarFeedController § Beslut 5. Den här
 * kontrollern gör därför samma sak som API-kontrollern: tokenet genereras i
 * `store()`, sätts in i URL:en och lämnar processen med svaret. Skillnaden är
 * bara transporten — `/api` lägger den i svarets `url`, webben i redirectens
 * flash, som `index()` lyfter in i sidans prop `url`.
 *
 * **URL:en är i praktiken ett lösenord** ([[Notiser]] § ICS-kalenderfeed), och
 * den får därför aldrig hamna i en adressrad, en `<a href>` eller en logg.
 * Den renderas som text i vyn
 * (resources/js/components/SecretOnce.vue) — och den som tappat bort den
 * återkallar feeden och skapar en ny: att visa den igen vore att göra en
 * engångshemlighet beständig.
 *
 * **Sökvägen `/kalender/{token}.ics` rörs inte.** Den är kontraktet från 36a
 * § Beslut 6 och ligger i någons kalenderapp; URL:en byggs här med samma
 * `config('app.url')`-uttryck som API-kontrollern, för rutten ägs av
 * App\Http\Controllers\CalendarFeedDownloadController och av ingen annan.
 *
 * **Ingen behörighetslogik bor här.** Alla tre metoderna anropar bara
 * `Gate::authorize('view', $container)` och litar på
 * App\Policies\ContainerPolicy::view() — samma grind som API-kontrollern och
 * av samma skäl: den som får läsa containern får prenumerera på dess kalender,
 * för feeden visar per definition inget hon inte redan kan se (36a § Beslut
 * 4). Att återkalla är att MINSKA exponeringen, och den som får skapa en feed
 * får klippa den.
 *
 * **Återkallandet är idempotent och rader raderas aldrig** (Beslut 4): en
 * redan återkallad feed svarar som en färsk, och `revoked_at` skrivs inte om —
 * den ursprungliga tidsstämpeln är historien, och vyn visar en återkallad
 * feed som återkallad.
 */
class CalendarFeedController extends Controller
{
    /**
     * Klartextens längd — samma 64 tecken ur Str::random()s 62-teckens
     * alfabet som App\Http\Controllers\Api\CalendarFeedController::TOKEN_LENGTH.
     * Två konstanter och inte en delad: den här är entropin i ett token som
     * hashas, inte ett format de två vägarna måste vara ense om, och den
     * delade klassen ligger utanför den här issuen.
     */
    private const TOKEN_LENGTH = 64;

    /**
     * Sessionsnyckeln URL:en flashas under. Stavas bara här — `store()`
     * lägger den och `index()` läser den, så en omladdning av nyckeln är en
     * rad och inte en jakt.
     */
    private const URL_SESSION_KEY = 'calendar_feed_url';

    /**
     * GET /containers/{container}/calendar — containerns kalenderlänkar.
     *
     * Listan är den INLOGGADE användarens egna feeds, precis som
     * API-kontrollerns index(): en feed visar bara det den användaren får se,
     * så en annan medlems länkar är varken hennes eller något hon ska se.
     *
     * `url` är klartexten ur redirectens flash och är `null` vid varje annan
     * visning än den direkt efter ett skapande — den visas en gång (Beslut 2).
     * Fältet ligger i prop-svaret och inte i resursen: resursen får aldrig bära
     * tokenet.
     */
    public function index(Request $request, Container $container): Response
    {
        Gate::authorize('view', $container);

        $container->loadMissing('account');

        $feeds = $container->calendarFeeds()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Containers/CalendarFeed', [
            'container' => ContainerResource::make($container)->resolve($request),
            'feeds' => CalendarFeedResource::collection($feeds)->resolve($request),
            'url' => $request->session()->get(self::URL_SESSION_KEY),
        ]);
    }

    /**
     * POST /containers/{container}/calendar — skapar en feed och skickar
     * tillbaka till listan med URL:en i flashen.
     *
     * Ingen request-body och ingen FormRequest (Beslut 2): feeden är bara
     * (container, användare, token), och `/api` har redan samma form. Ingen
     * duplikatspärr heller — en användare får ha flera feeder till samma
     * container, det är hela poängen med `revoked_at` (36a § Beslut 2).
     *
     * `container_id`, `user_id` och `token_hash` sätts explicit på
     * modellinstansen, aldrig via massildelning:
     * App\Models\CalendarFeed har `#[Fillable([])]`.
     *
     * Skapandet är den enda vägen till klartexten, och därför den enda vägen
     * till `url`-propen i `index()` ovan.
     */
    public function store(Request $request, Container $container): RedirectResponse
    {
        Gate::authorize('view', $container);

        $rawToken = Str::random(self::TOKEN_LENGTH);

        $feed = new CalendarFeed;
        $feed->container_id = $container->id;
        $feed->user_id = $request->user()->id;
        $feed->token_hash = hash('sha256', $rawToken);
        $feed->save();

        $url = rtrim((string) config('app.url'), '/').'/kalender/'.$rawToken.'.ics';

        return redirect()
            ->route('containers.calendar', $container)
            ->with(self::URL_SESSION_KEY, $url);
    }

    /**
     * DELETE /containers/{container}/calendar/{calendar_feed} — återkallar
     * feeden och går tillbaka till listan.
     *
     * `{calendar_feed}` binds inom `{container}` av routes/web.php:s
     * `scopeBindings()` genom App\Models\Container::calendarFeeds(): en ULID
     * från en annan container löser aldrig upp här, och en okänd ULID blir
     * felsidan för 404.
     *
     * Raden raderas aldrig och `revoked_at` skrivs bara om den är null —
     * ett andra anrop är en no-op som ändå svarar som ett första (Beslut 4).
     * App\Models\CalendarFeed använder inte SoftDeletes.
     */
    public function destroy(Container $container, CalendarFeed $calendarFeed): RedirectResponse
    {
        Gate::authorize('view', $container);

        if ($calendarFeed->revoked_at === null) {
            $calendarFeed->revoked_at = now();
            $calendarFeed->save();
        }

        return back()->with('status', 'calendar-feed-revoked');
    }
}
