<?php

namespace App\Http\Controllers\Api;

use App\Actions\Audit\RecordAuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\CalendarFeedResource;
use App\Models\AuditLog;
use App\Models\CalendarFeed;
use App\Models\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * API-ytan för ICS-kalenderfeeds, issue 36a. Skapa, lista och återkalla de
 * hemliga prenumerationslänkarna för en container — "URL:en är i praktiken
 * ett lösenord", se [[Notiser]] § ICS-kalenderfeed. INGEN behörighetslogik
 * bor här: varje metod anropar bara `Gate::authorize('view', $container)`
 * och litar på svaret från App\Policies\ContainerPolicy::view() — den
 * befintliga grinden, se issue 36a § Beslut 4. Den som får läsa containern
 * får prenumerera på dess kalender: feeden visar per definition inget hon
 * inte redan kan se.
 *
 * Själva feeden — rutten som svarar med text/calendar, VEVENT-renderingen
 * och uppslaget på token_hash — är issue 36b och rör inte den här klassen.
 *
 * `routes/api.php` nästlar {calendar_feed} under {container} med
 * `->scopeBindings()`, löst genom App\Models\Container::calendarFeeds() —
 * en ULID från en annan container löser aldrig upp här, av exakt samma skäl
 * som 9b § Beslut 1 (issue 36a § Beslut 3 och § Att se upp med).
 *
 * Klartexten genereras i `store()` nedan, används i svarets `url` och lämnar
 * aldrig processen igen — bara hashen sparas, se App\Models\CalendarFeed och
 * issue 36a § Beslut 5 (samma modell som App\Models\Invitation). `strlen` är
 * 64 tecken ur Str::random()s 62-teckens alfabet — långt bortom vad som går
 * att gissa.
 */
class CalendarFeedController extends Controller
{
    private const TOKEN_LENGTH = 64;

    /**
     * GET /api/containers/{container}/calendar-feeds — 200. Visar BARA den
     * inloggade användarens egna feeder, aldrig andras (issue 36a § Beslut
     * 4), sorterat `created_at` fallande — även återkallade rader, som
     * listas med sitt `revoked_at` ifyllt (Beslut 3). En ägare som ser att
     * någon annan har en aktiv länk är en rimlig framtida förvaltningsvy,
     * men den är inte beställd.
     */
    public function index(Request $request, Container $container): JsonResponse
    {
        Gate::authorize('view', $container);

        $feeds = $container->calendarFeeds()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return CalendarFeedResource::collection($feeds)->response();
    }

    /**
     * POST /api/containers/{container}/calendar-feeds — 201. Skapar en feed
     * åt den inloggade användaren och returnerar den fullständiga URL:en
     * INKLUSIVE klartexten — den enda gången klartexten finns någonstans.
     *
     * Ingen request-body och ingen FormRequest: det finns inga fält att
     * validera, feeden är bara (container, användare, token). Ingen
     * duplikatspärr heller — en användare får ha flera feeder till samma
     * container, det är Beslut 2 och hela poängen med `revoked_at` (en i
     * telefonen och en i datorn, så att den ena kan återkallas när telefonen
     * tappas bort).
     *
     * `container_id`, `user_id` och `token_hash` sätts explicit på
     * modellinstansen, aldrig via massildelning — se App\Models\CalendarFeed
     * och § Beslut 5.
     *
     * Sökvägen `/kalender/{token}.ics` är kontraktet 36b ska implementera
     * (§ Beslut 6): svenska i sökvägen därför att det är en URL en människa
     * klistrar in i sin kalenderapp, och `.ics` på slutet därför att flera
     * kalenderklienter vägrar prenumerera på en URL utan filändelse. Rutten
     * byggs i 36b — här byggs bara URL:en. `config('app.url')` och inte
     * `URL::route()`, av samma skäl som
     * App\Http\Controllers\Api\ContainerInvitationController::store():
     * rutten finns inte än.
     *
     * Klartexten läggs i svaret med `additional()` — INTE som ett fält på
     * CalendarFeedResource, som aldrig får bära token (se resursens
     * docblock).
     */
    public function store(Request $request, Container $container, RecordAuditEvent $recordAuditEvent): JsonResponse
    {
        Gate::authorize('view', $container);

        // Klartexten är svarets enda konsument — den skickas i `url` nedan
        // och lagras aldrig, se klassens docblock.
        $rawToken = Str::random(self::TOKEN_LENGTH);

        $feed = DB::transaction(function () use ($request, $container, $rawToken, $recordAuditEvent): CalendarFeed {
            $feed = new CalendarFeed;
            $feed->container_id = $container->id;
            $feed->user_id = $request->user()->id;
            $feed->token_hash = hash('sha256', $rawToken);
            $feed->save();

            // `calendar_feed.created` i samma transaktion (issue 111).
            // **Token följer aldrig med i `meta`** — varken klartexten eller
            // hashen: loggen får inte bli en andra väg till feeden.
            $recordAuditEvent->handle(
                action: AuditLog::ACTION_CALENDAR_FEED_CREATED,
                account: $container->account,
                user: $request->user(),
                container: $container,
                subjectType: 'calendar_feed',
                subjectUlid: $feed->ulid,
            );

            return $feed;
        });

        $url = rtrim((string) config('app.url'), '/').'/kalender/'.$rawToken.'.ics';

        return (new CalendarFeedResource($feed))
            ->additional(['url' => $url])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * DELETE /api/containers/{container}/calendar-feeds/{calendar_feed} —
     * 204, ingen kropp. Återkallar feeden: sätter `revoked_at = now()` om
     * den är null. En redan återkallad feed ger också 204 — idempotent
     * (issue 36a § Beslut 3) — och `revoked_at` skrivs då inte om: den
     * ursprungliga tidsstämpeln är historien. Rader raderas aldrig, och
     * CalendarFeed använder inte SoftDeletes (Beslut 1).
     *
     * Grinden är view() — samma som skapandet. Att återkalla är att minska
     * exponeringen, och den som får skapa en feed får klippa den, se Beslut
     * 4.
     *
     * Raden läses om och låses INNE i transaktionen (`lockForUpdate`):
     * route-modellbindningens instans lästes innan transaktionen öppnades,
     * och två samtidiga anrop mot samma feed skulle annars båda se
     * `revoked_at === null` på sin egen instans och skriva var sin loggrad
     * för samma återkallelse — samma teknik och samma skäl som
     * App\Actions\Access\RevokeContainerAccess.
     */
    public function destroy(Request $request, Container $container, CalendarFeed $calendarFeed, RecordAuditEvent $recordAuditEvent): Response
    {
        Gate::authorize('view', $container);

        DB::transaction(function () use ($request, $container, $calendarFeed, $recordAuditEvent): void {
            $låstFeed = CalendarFeed::query()
                ->whereKey($calendarFeed->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($låstFeed->revoked_at !== null) {
                // En andra återkallelse är ingen handling: den rör varken
                // tidsstämpeln eller loggen (issue 36a § Beslut 3, issue 111).
                return;
            }

            $låstFeed->revoked_at = now();
            $låstFeed->save();

            $recordAuditEvent->handle(
                action: AuditLog::ACTION_CALENDAR_FEED_REVOKED,
                account: $container->account,
                user: $request->user(),
                container: $container,
                subjectType: 'calendar_feed',
                subjectUlid: $låstFeed->ulid,
            );
        });

        return response()->noContent();
    }
}
