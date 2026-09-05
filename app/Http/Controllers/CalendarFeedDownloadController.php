<?php

namespace App\Http\Controllers;

use App\Models\CalendarFeed;
use App\Models\Container;
use App\Models\ScheduleOccurrence;
use App\Support\Notification\IcsDocument;
use App\Support\Notification\LocaleResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /kalender/{token}.ics — ICS-kalenderfeeden, issue 36b och
 * [[Notiser]] § ICS-kalenderfeed. En läsendpoint som genererar en textfil
 * åt Apple Calendar och Google Calendar, som hämtar själva i bakgrunden.
 * Rutten ligger utanför `auth`-gruppen med flit: kalenderklienten har varken
 * session eller cookie — tokenet i URL:en ÄR autentiseringen (Beslut 1).
 * Risken är ett datautlämnande utan spår, så skillnaderna nedan är noga
 * valda:
 *
 * - En återkallad, en okänd och en aldrig existerande token ger ALLA 404.
 *   Uppslaget hashar inkommande klartext och slår upp på hashen (aldrig
 *   tvärtom) med `whereNull('revoked_at')` i SAMMA fråga — en kontroll
 *   efteråt är en rad någon tar bort vid en refaktorering (Beslut 2).
 * - En återkallad ÅTKOMST ger en tom kalender, inte 404: tokenet lever,
 *   innehållet gör det inte. Åtkomstvillkoret kontrolleras vid VARJE
 *   hämtning, inte bara när feeden skapades — en gäst vars åtkomst dragits
 *   in ska sluta se innehållet även om hon har kvar länken (Beslut 3).
 *   Villkoret formuleras med Container::scopeAccessibleBy() genom
 *   relationskedjan förekomst → schema → item → container, aldrig som en
 *   `whereIn('container_id', ...)`-lista mot löpnummer.
 * - `visible_from` filtreras INTE: en kalender visar framtiden, så en
 *   förekomst som förfaller om fyra månader ska stå i kalendern. Därför
 *   används inte scopeTodoFor(), som också filtrerar på blockerande
 *   beroenden (Beslut 3).
 */
class CalendarFeedDownloadController extends Controller
{
    public function __construct(
        private readonly LocaleResolver $locales,
    ) {}

    public function __invoke(Request $request, string $token): Response
    {
        $feed = CalendarFeed::query()
            ->with(['container', 'user.accounts'])
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->first();

        // En mjukraderad container ger null här — containern är borta för
        // alla (även innehållet är oåtkomligt genom SoftDeletes' globala
        // scope i frågan nedan), och en feed utan namn kan inte rendera en
        // kalender. 404 är samma svar som efter purgen, då raden är borta.
        abort_if($feed === null || $feed->container === null, 404);

        $accountIds = $feed->user->accounts->pluck('id')->values()->all();

        $occurrences = ScheduleOccurrence::query()
            ->where('status', ScheduleOccurrence::STATUS_OPEN)
            ->whereHas('schedule', function (Builder $query) use ($feed, $accountIds): void {
                $query->where('schedule.is_active', true)
                    ->whereHas('item', function (Builder $query) use ($feed, $accountIds): void {
                        $query->where('item.container_id', $feed->container_id)
                            ->whereHas('container', function (Builder $query) use ($feed, $accountIds): void {
                                /** @var Builder<Container> $query */
                                $query->accessibleBy($feed->user, $accountIds);
                            });
                    });
            })
            ->with(['schedule.item'])
            ->orderBy('due_at')
            ->orderBy('ulid')
            ->get();

        // Serverrenderat innehåll väljer språk från mottagarens locale, inte
        // från requestens Accept-Language (AGENTS.md § Felformat). Språket
        // återställs i finally — en kvarglömd locale vore en bugg som smittar
        // allt som renderas efter den här begäran (issue 36b § Beslut 6).
        $tidigare = App::getLocale();

        try {
            App::setLocale($this->locales->forUser($feed->user));

            $ics = (new IcsDocument(
                trans('notiser.calendar.name', ['container' => $feed->container->name]),
                trans('notiser.calendar.overdue_prefix'),
                $occurrences,
            ))->render();
        } finally {
            App::setLocale($tidigare);
        }

        // En query-builder-update, inte modellens save(): en save() skulle
        // röra updated_at och göra varje hämtning till en ändring av raden
        // (Beslut 7). `last_fetched_at` skrivs vid varje lyckad hämtning —
        // även en tom kalender är en lyckad hämtning.
        CalendarFeed::query()->whereKey($feed->getKey())->update([
            'last_fetched_at' => now(),
        ]);

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="mimers.ics"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
