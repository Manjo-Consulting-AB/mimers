<?php

namespace App\Http\Controllers;

use App\Actions\Access\ResolveItemScope;
use App\Http\Resources\TodoEntryResource;
use App\Models\Container;
use App\Models\ScheduleOccurrence;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webbens startsida efter inloggning — todo-vyn, `GET /dashboard`, issue 64.
 *
 * **Sidan är produktens andra huvudfråga** — "vad ska jag göra?" vid sidan av
 * "var la jag den där?" — och den öppnas oftare än någon annan i M10. Den
 * ligger på `/dashboard` och inte på en egen URL (Beslut 1): rutten finns,
 * ramverket skickar en nyinloggad användare dit, och `Dashboard.vue` är redan
 * komponenten som renderas. Closuren i routes/web.php blev den här metoden.
 *
 * **Urvalet är `ScheduleOccurrence::scopeTodoFor()` och ingenting annat**
 * (Beslut 2). De fyra villkoren — `status = open`, `visible_from <= idag`,
 * inga öppna beroenden, containern åtkomlig och itemet inom omfånget —
 * formuleras EN gång, i modellen (issue 24 § Beslut 2, issue 74 § Beslut 7).
 * Den här kontrollern formulerar inget eget `where`, och vyn filtrerar
 * ingenting: två filtreringar är två sanningar, och den ena är alltid den som
 * glömmer omfånget.
 *
 * **Ordningen och grupperingen räknas också här** (Beslut 3). `due_at`
 * stigande med `ulid` stigande — samma deterministiska ordning som
 * `Api\TodoController::index()` — och raden hamnar i `overdue`, `today` eller
 * `upcoming` efter en jämförelse mot SERVERNS datum. Klienten får tre listor
 * och ritar dem i den ordning de kommer; den räknar aldrig en grupp själv.
 * Samma regel som `overdue` i 63b § Beslut 3: en klient med fel klocka ska
 * inte kunna flytta en uppgift till fel hög.
 *
 * **Flaggorna är presentation** (Beslut 4). `can.update` räknas per rad med
 * `ItemPolicy::update()` — samma grind som avbockningsrutten (63b) och
 * itemets skrivytor (issue 71) — och vyn ritar knappen bara när den är sann.
 * Rutten auktoriserar ändå; en postad avbockning utan rätt blir 403.
 *
 * **Kontots förval räknas här** (Beslut 4, 63b § Beslut 4).
 * `CompleteOccurrenceRequest` kräver `account`, och regeln är 63b:s: pärmens
 * ägarkonto när användaren är medlem i det, annars hennes första konto. Vyn
 * skickar bara tillbaka det ULID den fick — den väljer inget själv, och en
 * mottagare utanför ägarkontot får sitt eget konto och inte pärmens.
 *
 * **Frågekostnaden är konstant** (Beslut 8). Förekomsterna hämtas med
 * `with(['schedule.item.container.account'])` — samma eager load som `/api`,
 * plus `account`, som `ItemPolicy::update()` läser för kontospärren (regel 4)
 * och som annars hade blivit ett uppslag per rad. Omfånget värms i ETT anrop
 * för de pärmar listan faktiskt bär: `ResolveItemScope` memoiserar per
 * `{user, container}`, så utan värmningen hade `can`-flaggan kostat en
 * upplösning per pärm och listan vuxit i frågor med antalet pärmar i stället
 * för att vara konstant (issue 70 § Beslut 2, samma grepp som
 * `App\Actions\Item\SearchAccessibleItems`).
 */
class TodoController extends Controller
{
    /**
     * Raden är försenad — `due_at` ligger före serverns idag.
     */
    public const GROUP_OVERDUE = 'overdue';

    /**
     * Raden förfaller idag.
     */
    public const GROUP_TODAY = 'today';

    /**
     * Raden förfaller framåt i tiden.
     */
    public const GROUP_UPCOMING = 'upcoming';

    /**
     * GET /dashboard — 200. De öppna förekomster användaren når, i `due_at`-
     * ordning, grupperade i försenat, idag och kommande.
     *
     * Ingen paginering och ingen sorteringsväljare (Beslut 3): systemet är
     * kraftigt säsongsbetonat och i april förfaller allt samtidigt
     * ([[ADR-0005 Schema och förekomst]] § Konsekvenser), så sidan ska tåla
     * hundra rader i stället för att tyst klippa listan.
     *
     * `hasContainers` skiljer de två ärliga tomma lägena åt (Beslut 6): den
     * som inte har någon pärm alls får en länk till att skapa en, den som har
     * pärmar utan öppna uppgifter får "inget att göra just nu". Ingen av
     * meningarna vet om något filtrerats bort, och ingen bär ett tal.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $accountIds = $user->accounts->pluck('id')->values()->all();

        // Pärmarna användaren når, i EN fråga — underlaget för `hasContainers`
        // och ingenting annat. Urvalet av rader ställs inte här: `todoFor()`
        // nedan formulerar åtkomsten själv, och en andra lista hade varit en
        // andra sanning om omfånget (Beslut 2).
        $containerIds = Container::query()
            ->accessibleBy($user, $accountIds)
            ->pluck('id')
            ->all();

        $occurrences = ScheduleOccurrence::query()
            ->todoFor($user, $accountIds)
            ->with(['schedule.item.container.account'])
            ->orderBy('due_at')
            ->orderBy('ulid')
            ->get();

        // Värm omfånget för de pärmar listan bär, i ETT anrop. `forContainers`
        // med en tom lista ställer inga frågor alls, så en tom todo-vy kostar
        // ingenting extra.
        app(ResolveItemScope::class)->forContainers(
            $user,
            $occurrences->pluck('schedule.item.container.id')->unique()->values()->all(),
        );

        $today = Carbon::today();
        $accountUlids = $user->accounts->pluck('ulid')->all();

        $groups = [
            self::GROUP_OVERDUE => [],
            self::GROUP_TODAY => [],
            self::GROUP_UPCOMING => [],
        ];

        foreach ($occurrences as $occurrence) {
            $item = $occurrence->schedule->item;

            $groups[$this->group($occurrence->due_at, $today)][] = [
                // Resursen, med allt den bär, och de tre nycklarna BREDVID
                // den — samma mönster som App\Http\Controllers\
                // SearchController lägger pärmen bredvid ItemResource: ett
                // fält som bara webben behöver hör inte inuti `/api`:s svar.
                ...TodoEntryResource::make($occurrence)->resolve($request),
                'account' => $this->account($item->container->account->ulid, $accountUlids),
                'can' => [
                    'update' => Gate::forUser($user)->allows('update', $item),
                ],
            ];
        }

        return Inertia::render('Dashboard', [
            'groups' => $groups,
            'hasContainers' => $containerIds !== [],
        ]);
    }

    /**
     * Radens grupp. Jämförelsen görs mot serverns datum — den enda klocka som
     * får avgöra vad som är försenat (Beslut 3).
     */
    private function group(CarbonInterface $dueAt, CarbonInterface $today): string
    {
        if ($dueAt->lessThan($today)) {
            return self::GROUP_OVERDUE;
        }

        return $dueAt->equalTo($today) ? self::GROUP_TODAY : self::GROUP_UPCOMING;
    }

    /**
     * Kontot avbockningen tillskrivs om användaren klickar direkt i listan:
     * pärmens ägarkonto när hon är medlem i det, annars hennes första konto —
     * 63b § Beslut 4, samma förval som formuläret på schemats sida.
     *
     * @param  list<string>  $accountUlids
     */
    private function account(string $containerAccountUlid, array $accountUlids): ?string
    {
        return in_array($containerAccountUlid, $accountUlids, true)
            ? $containerAccountUlid
            : ($accountUlids[0] ?? null);
    }
}
