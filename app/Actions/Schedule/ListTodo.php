<?php

namespace App\Actions\Schedule;

use App\Actions\Access\ResolveItemScope;
use App\Http\Resources\TodoEntryResource;
use App\Models\Container;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Todo-urvalet — "vad ska jag göra?", se issue 64, issue 122 och issue 123.
 *
 * **Frågan formulerades i App\Http\Controllers\TodoController och bröts ut hit
 * när todo-vyn flyttade till `/tasks`** (issue 122). Dashboardens
 * uppgiftspanel läser samma urval, och två sidor som formulerade samma fråga
 * var förr eller senare två sanningar om den: panelen skulle ha glömt omfånget
 * eller `visible_from`, och ingen rad hade gett ett fel.
 *
 * **Urvalet är `ScheduleOccurrence::scopeTodoFor()` och ingenting annat**
 * (Beslut 2). De fyra villkoren — `status = open`, `visible_from <= idag`,
 * inga öppna beroenden, containern åtkomlig och itemet inom omfånget —
 * formuleras EN gång, i modellen (issue 24 § Beslut 2, issue 74 § Beslut 7).
 * Den här klassen formulerar inget eget `where`, och ingen av vyerna
 * filtrerar: två filtreringar är två sanningar, och den ena är alltid den som
 * glömmer omfånget.
 *
 * **Ordningen och grupperingen räknas på servern** (Beslut 3). `due_at`
 * stigande med `ulid` stigande — samma deterministiska ordning som
 * `Api\TodoController::index()` — och raden hamnar i `overdue`, `today` eller
 * `upcoming` efter en jämförelse mot SERVERNS datum. Klienten får tre listor
 * och ritar dem i den ordning de kommer; den räknar aldrig en grupp själv.
 * Samma regel som `overdue` i 63b § Beslut 3: en klient med fel klocka ska
 * inte kunna flytta en uppgift till fel hög.
 *
 * **`/tasks` är paginerad, dashboarden är det inte** (issue 123). `page()`
 * nedan ger en sida om högst `PER_PAGE` rader med en markör över
 * `(due_at, ulid)` i querysträngen; `handle()` ger hela listan, ogrupperad i
 * `rows`, och det är den dashboardens uppgiftspanel läser och klipper sina fem
 * ur. Panelen pagineras alltså inte, och frågan är den samma för båda.
 *
 * **Pagineringen omprövade ett äldre beslut, och det hör hit.** Issue 64
 * § Beslut 3 och [[ADR-0005 Schema och förekomst]] motiverade den opaginerade
 * listan med att *"i april förfaller allt samtidigt"* — en premis om
 * båtpärmen och inte om produkten, se [[ADR-0033 Produktens omfång]].
 * ADR-0005 står kvar som historik enligt [[ADR-0032 Produktens ord]].
 *
 * **`rows` är samma rader i samma ordning, ogrupperade** — för panelen, som
 * tar de fem första. Gruppordningen (försenat, idag, kommande) ÄR den ordning
 * `due_at` ger, så de två fälten kan inte glida ifrån varandra: den som
 * behöver en grupprubrik tar `groups`, den som bara ska visa en rad tar `rows`.
 *
 * **Flaggorna är presentation** (Beslut 4). `can.update` räknas per rad med
 * `ItemPolicy::update()` — samma grind som avbockningsrutten (63b) och
 * itemets skrivytor (issue 71) — och vyn ritar knappen bara när den är sann.
 * Rutten auktoriserar ändå; en postad avbockning utan rätt blir 403.
 *
 * **Kontots förval räknas här** (Beslut 4, 63b § Beslut 4).
 * `CompleteOccurrenceRequest` kräver `account`, och regeln är 63b:s: containerns
 * ägarkonto när användaren är medlem i det, annars hennes första konto. Vyn
 * skickar bara tillbaka det ULID den fick — den väljer inget själv, och en
 * mottagare utanför ägarkontot får sitt eget konto och inte containerns.
 *
 * **Frågekostnaden är konstant** (Beslut 8). Förekomsterna hämtas med
 * `with(['schedule.item.container.account'])` — samma eager load som `/api`,
 * plus `account`, som `ItemPolicy::update()` läser för kontospärren (regel 4)
 * och som annars hade blivit ett uppslag per rad. Omfånget värms i ETT anrop
 * för de containers listan faktiskt bär: `ResolveItemScope` memoiserar per
 * `{user, container}`, så utan värmningen hade `can`-flaggan kostat en
 * upplösning per container och listan vuxit i frågor med antalet containers i
 * stället för att vara konstant (issue 70 § Beslut 2, samma grepp som
 * `App\Actions\Item\SearchAccessibleItems`). Sidan bär samma kostnad som hela
 * listan gjorde: den hämtar en rad mer än den visar och ingenting per rad.
 *
 * **`hasContainers` skiljer de två ärliga tomma lägena åt** (Beslut 6): den
 * som inte har någon container alls får en länk till att skapa en, den som har
 * containers utan öppna uppgifter får "inget att göra just nu". Ingen av
 * meningarna vet om något filtrerats bort, och ingen bär ett tal. Både
 * `/tasks` och `/dashboard` frågar efter samma flagga, och den kommer ur
 * samma fråga här — ingen av sidorna räknar containrar själv.
 */
class ListTodo
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
     * Antalet rader per sida på `/tasks` (issue 123).
     */
    public const PER_PAGE = 50;

    /**
     * Markörens två namn i querysträngen (issue 123).
     *
     * `after` pekar på raden FÖRE sidan och är exklusiv. `before` pekar på
     * sidans SISTA rad och är inklusiv — den är därför alltid en rad som
     * finns, också när en sida blivit tom därför att uppgifterna hann bli
     * avbockade innan länken klickades. En exklusiv markör hade pekat på en
     * rad som försvunnit och lämnat besökaren utan väg tillbaka.
     *
     * Namnen bor här och inte i kontrollern: den som läser dem och den som
     * skriver dem ska läsa samma konstant.
     */
    public const CURSOR_AFTER = 'after';

    /**
     * Se `CURSOR_AFTER`.
     */
    public const CURSOR_BEFORE = 'before';

    public function __construct(private readonly ResolveItemScope $resolveItemScope) {}

    /**
     * Användarens öppna uppgifter, grupperade och ogrupperade — HELA listan.
     *
     * Dashboardens uppgiftspanel läser den här formen och klipper sina fem i
     * PHP (issue 122): panelen pagineras inte (issue 123), och frågan är den
     * samma som `/tasks` ställer.
     *
     * @return array{
     *     groups: array<string, list<array<string, mixed>>>,
     *     rows: list<array<string, mixed>>,
     *     hasContainers: bool
     * }
     */
    public function handle(User $user, Request $request): array
    {
        $accountIds = $user->accounts->pluck('id')->values()->all();

        // Containerna användaren når, i EN fråga — underlaget för `hasContainers`
        // och ingenting annat. Urvalet av rader ställs inte här: `todoFor()`
        // nedan formulerar åtkomsten själv, och en andra lista hade varit en
        // andra sanning om omfånget (Beslut 2).
        $containerIds = Container::query()
            ->accessibleBy($user, $accountIds)
            ->pluck('id')
            ->all();

        $occurrences = $this->occurrences($user, $accountIds)->get();

        return [
            ...$this->present($user, $request, $occurrences),
            'hasContainers' => $containerIds !== [],
        ];
    }

    /**
     * En sida av todo-listan — högst `PER_PAGE` rader, och markörerna till
     * nästa och föregående sida.
     *
     * **Markören är `(due_at, ulid)` och står i querysträngen** som `after`
     * eller `before`. Två rader som delar `due_at` skiljs av `ulid`, så en
     * sidgräns kan aldrig hamna mitt i ett dött lopp: nästa sida börjar på
     * raden efter den förra sidans sista, vilket är hela skälet till den
     * andra nyckeln.
     *
     * **Grupperingen räknas per rad, på den här sidans rader** — `due_at` mot
     * serverns datum och ingenting annat. En sida som börjar mitt i en grupp
     * får därför gruppens rubrik en gång till, och en rad hamnar i samma grupp
     * vilken sida den än står på. Klienten räknar aldrig en grupp själv, och
     * servern minns ingen föregående sida: det finns inget att minnas, för
     * gruppen följer av raden.
     *
     * **Frågekostnaden är konstant** (Beslut 8). Sidan hämtar `PER_PAGE + 1`
     * rader i EN fråga, och den extra raden är svaret på "finns det mer" — den
     * visas aldrig. Omfånget, de ivriga laddningarna och `can`-flaggorna
     * kostar vad de kostade för hela listan, oavsett hur många rader sidan bär.
     *
     * Bär adressen båda markörerna vinner `before`: den är inklusiv och pekar
     * på en rad som finns, och en sida som slutar där är alltid ett svar.
     * Går markören inte att läsa alls förbigås den — en klistrad adress ska ge
     * första sidan, inte ett fel.
     *
     * @return array{
     *     groups: array<string, list<array<string, mixed>>>,
     *     hasContainers: bool,
     *     previous: string|null,
     *     next: string|null
     * }
     */
    public function page(User $user, Request $request): array
    {
        $accountIds = $user->accounts->pluck('id')->values()->all();

        $containerIds = Container::query()
            ->accessibleBy($user, $accountIds)
            ->pluck('id')
            ->all();

        $before = $this->cursor($request->query(self::CURSOR_BEFORE));
        $after = $this->cursor($request->query(self::CURSOR_AFTER));

        // Baklänges när `before` styr: raden markören pekar på är sidans sista,
        // och frågan hämtar de femtio som slutar där.
        //
        // `whereDate()` och inte en rå kolumnjämförelse, av samma skäl som
        // `scopeTodoFor()` väljer det: `due_at` är en DATE-kolumn, men värdet
        // lagras med en tidsdel — `2026-06-15 00:00:00`. MariaDB klipper den
        // till kolumnens typ, sqlite gör det inte, så `due_at > '2026-06-15'`
        // hade räknat in samma dag i sviten och inte i drift.
        $backwards = $before !== null;

        $query = $this->occurrences($user, $accountIds);

        if ($backwards) {
            $query->where(fn (Builder $query) => $query
                ->whereDate('due_at', '<', $before['due_at'])
                ->orWhere(fn (Builder $query) => $query
                    ->whereDate('due_at', '=', $before['due_at'])
                    ->where('ulid', '<=', $before['ulid'])));
        } elseif ($after !== null) {
            $query->where(fn (Builder $query) => $query
                ->whereDate('due_at', '>', $after['due_at'])
                ->orWhere(fn (Builder $query) => $query
                    ->whereDate('due_at', '=', $after['due_at'])
                    ->where('ulid', '>', $after['ulid'])));
        }

        $occurrences = $query
            ->orderBy('due_at', $backwards ? 'desc' : 'asc')
            ->orderBy('ulid', $backwards ? 'desc' : 'asc')
            ->limit(self::PER_PAGE + 1)
            ->get();

        // Den extra raden är bara till för det här svaret. Bakåt ligger den
        // först i svaret och är raden före sidan; framåt ligger den sist och är
        // raden efter den.
        $hasMore = $occurrences->count() > self::PER_PAGE;

        $page = $occurrences->take(self::PER_PAGE);

        if ($backwards) {
            $page = $page->reverse()->values();
        }

        return [
            ...$this->present($user, $request, $page),
            'hasContainers' => $containerIds !== [],
            // Föregående sida slutar på raden före den här sidans första rad.
            // Framåt är det markören vi kom in med; bakåt är det den
            // femtioförsta raden, den vi hämtade men inte visar.
            'previous' => $backwards
                ? ($hasMore ? $this->mark($occurrences->last()) : null)
                : ($after === null ? null : $this->cursorString($after['due_at'], $after['ulid'])),
            // Nästa sida börjar efter den här sidans sista rad. Bakåt är det
            // markören vi kom in med — den ÄR sidans sista rad.
            'next' => $backwards
                ? $this->cursorString($before['due_at'], $before['ulid'])
                : ($hasMore ? $this->mark($page->last()) : null),
        ];
    }

    /**
     * Förekomstfrågan: urvalet, de ivriga laddningarna och den ordning
     * markören vilar på. Ordningen ställs av anroparen, för sidan vänder på
     * den.
     *
     * @param  list<int>  $accountIds
     * @return Builder<ScheduleOccurrence>
     */
    private function occurrences(User $user, array $accountIds): Builder
    {
        return ScheduleOccurrence::query()
            ->todoFor($user, $accountIds)
            ->with(['schedule.item.container.account']);
    }

    /**
     * Raderna ur förekomsterna, grupperade och ogrupperade.
     *
     * Grupperingen räknas per rad mot serverns datum (Beslut 3). En sida kan
     * därför börja mitt i en grupp, och rubriken upprepas på nästa sida — den
     * följer av raderna på just den sidan och aldrig av en räknare som minns
     * föregående sida.
     *
     * @param  Collection<int, ScheduleOccurrence>  $occurrences
     * @return array{
     *     groups: array<string, list<array<string, mixed>>>,
     *     rows: list<array<string, mixed>>
     * }
     */
    private function present(User $user, Request $request, Collection $occurrences): array
    {
        // Värm omfånget för de containers listan bär, i ETT anrop. `forContainers`
        // med en tom lista ställer inga frågor alls, så en tom todo-vy kostar
        // ingenting extra.
        $this->resolveItemScope->forContainers(
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

        $rows = [];

        foreach ($occurrences as $occurrence) {
            $item = $occurrence->schedule->item;

            $row = [
                // Resursen, med allt den bär, och de tre nycklarna BREDVID
                // den — samma mönster som App\Http\Controllers\
                // SearchController lägger containern bredvid ItemResource: ett
                // fält som bara webben behöver hör inte inuti `/api`:s svar.
                ...TodoEntryResource::make($occurrence)->resolve($request),
                'account' => $this->account($item->container->account->ulid, $accountUlids),
                'can' => [
                    'update' => Gate::forUser($user)->allows('update', $item),
                ],
            ];

            $groups[$this->group($occurrence->due_at, $today)][] = $row;
            $rows[] = $row;
        }

        return [
            'groups' => $groups,
            'rows' => $rows,
        ];
    }

    /**
     * Markören ur querysträngen, eller null när den saknas eller inte går att
     * läsa. En trasig markör ger första sidan i stället för ett fel: den
     * kommer ur ett adressfält någon klistrat i, och ett 500 hade varit ett
     * svar på fel fråga.
     *
     * Formen är `due_at` och `ulid` med ett understreck emellan —
     * `2026-06-15_01HZ…`. Understrecket finns inte i någon av delarna:
     * datumet bär bindestreck och ULID:n är Crockfords alfabet.
     *
     * @return array{due_at: string, ulid: string}|null
     */
    private function cursor(mixed $value): ?array
    {
        if (! is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})_([0-9A-Za-z]{26})$/', $value, $träffar) !== 1) {
            return null;
        }

        return ['due_at' => $träffar[1], 'ulid' => $träffar[2]];
    }

    /**
     * Markören för en rad.
     */
    private function mark(ScheduleOccurrence $occurrence): string
    {
        return $this->cursorString($occurrence->due_at->toDateString(), $occurrence->ulid);
    }

    /**
     * Markören som den står i querysträngen — `cursor()`:s motpart.
     */
    private function cursorString(string $dueAt, string $ulid): string
    {
        return $dueAt.'_'.$ulid;
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
     * containerns ägarkonto när hon är medlem i det, annars hennes första konto —
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
