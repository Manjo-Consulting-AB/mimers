<?php

namespace App\Models;

use App\Actions\Access\ResolveItemScope;
use App\Models\Concerns\HasUlid;
use Database\Factories\ScheduleOccurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Den ENSKILDA GÅNGEN av ett schema — se [[Scheman och uppgifter]] §
 * schedule_occurrence och [[ADR-0005 Schema och förekomst]]. Schemat är
 * regeln ("var tolfte månad"); förekomsten är den som förfaller 5 maj 2027.
 * Endast den öppna förekomsten plus historiken lagras; ingen serie genereras
 * i förväg.
 *
 * En förekomst skapas ALDRIG av en klient — den enda vägen in är
 * App\Actions\Schedule\OpenNextOccurrence, som anropas av
 * App\Http\Controllers\Api\ScheduleController (när ett aktivt schema skapas
 * eller ett pausat aktiveras) och av avslutsflödet (issue 22b). Alla kolumner
 * är därför UTESLUTNA ur `#[Fillable]` — de sätts explicit av Actionen,
 * aldrig via massildelning, samma resonemang som App\Models\ItemLink.
 *
 * Ingen `deleted_at` (issue 22 § Beslut 2): raderingen av ett schema är mjuk
 * (SoftDeletes på App\Models\Schedule) och förekomsterna följer med genom
 * relationen. `status` har ingen cast — det är en av
 * ['open', 'completed', 'skipped'] och läses/skrivs som sträng.
 *
 * `overdue` är INTE en kolumn utan härleds vid läsning av
 * App\Http\Resources\ScheduleOccurrenceResource: `status = 'open' AND
 * due_at < idag`. `idag` är ANVÄNDARENS kalenderdatum sedan issue 135 —
 * `User::today()` — och inte serverns; se den metoden för varför datumet
 * byggs om till appens tidszon. App\Support\Item\ItemStatus räknar fortfarande
 * i serverns datum och rörs inte av issue 135.
 */
#[Fillable([])]
#[RouteKey('ulid')]
class ScheduleOccurrence extends Model
{
    /** @use HasFactory<ScheduleOccurrenceFactory> */
    use HasFactory, HasUlid;

    /**
     * Tabellen heter `schedule_occurrence`, inte Eloquents standardplural
     * `schedule_occurrences`.
     */
    protected $table = 'schedule_occurrence';

    /**
     * De tre statusvärdena, var för sig — avslutsflödet (issue 22b) jämför
     * och sätter dem och ska aldrig behöva stava strängarna.
     */
    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SKIPPED = 'skipped';

    /**
     * De giltiga värdena för `status`, se migrationens CHECK-villkor.
     *
     * @var list<string>
     */
    public const STATUSES = [self::STATUS_OPEN, self::STATUS_COMPLETED, self::STATUS_SKIPPED];

    /**
     * Get the attributes that should be cast.
     *
     * `visible_from` och `due_at` är DATE-kolumner, castade till datum och
     * serialiserade med `toDateString()` — ett förfallodatum har ingen
     * tidszon (issue 22 § Beslut 8). `completed_at` är en tidsstämpel.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visible_from' => 'date',
            'due_at' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Schemat förekomsten hör till — exakt ett, och förekomsten byter aldrig
     * schema.
     *
     * @return BelongsTo<Schedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    /**
     * Användaren som stängde förekomsten — skrivs bara av avslutsflödet
     * (issue 22b). Kolumnen finns för revisionsloggen (M6); attributionen
     * utåt är kontot, aldrig användaren (issue 22 § Beslut 8).
     *
     * @return BelongsTo<User, $this>
     */
    public function completedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /**
     * Kontot som förekomsten tillskrivs när den stängs — varvet, inte den
     * anställde (dokumentet § schedule_occurrence). Läses av
     * App\Http\Resources\ScheduleOccurrenceResource, som bär kontots ULID
     * och namn i svaret.
     *
     * @return BelongsTo<Account, $this>
     */
    public function completedByAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'completed_by_account_id');
    }

    /**
     * Förekomster den här förekomsten BEROR PÅ — de som måste vara stängda
     * innan den här kan stängas, se [[Scheman och uppgifter]] §
     * occurrence_dependency och issue 23b. Riktningen är den lagrade: raden
     * i `occurrence_dependency` har `occurrence_id` = den här förekomsten
     * och `depends_on_occurrence_id` = motparten (§ Beslut 1). Relationerna
     * läggs här för 23b (spärren i avslutsflödet läser dem); själva
     * cykelkontrollen i App\Actions\Schedule\DependOccurrence läser raderna
     * direkt via App\Models\OccurrenceDependency.
     *
     * @return BelongsToMany<ScheduleOccurrence, $this>
     */
    public function dependsOn(): BelongsToMany
    {
        return $this->belongsToMany(ScheduleOccurrence::class, 'occurrence_dependency', 'occurrence_id', 'depends_on_occurrence_id');
    }

    /**
     * Förekomster som BEROR PÅ den här förekomsten — omvänt mot dependsOn().
     *
     * @return BelongsToMany<ScheduleOccurrence, $this>
     */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(ScheduleOccurrence::class, 'occurrence_dependency', 'depends_on_occurrence_id', 'occurrence_id');
    }

    /**
     * Begränsar till de förekomster som hör hemma i todo-listan (issue 24) —
     * dokumentets fyra villkor plus de som följer av att raden hänger under
     * något ([[Scheman och uppgifter]] § Todo-listan, issue 24 § Beslut 3):
     *
     * - `status = 'open'`
     * - `visible_from <= idag`, där idag är ANVÄNDARENS kalenderdatum och
     *   inte serverns (issue 135): `User::today()` ger hennes datum, och
     *   `toDateString()` gör jämförelsen till en datumjämförelse. `whereDate()`,
     *   aldrig en rå kolumnjämförelse: i sqlite lagras DATE-kolumner med en
     *   tidskomponent, och ett datum ska inte bero på klockslaget när frågan
     *   körs (issue 24 § Att se upp med). Skickas `today()` rakt in hade
     *   datumet följt med som ett ögonblick i appens tidszon — rätt här, men
     *   strängen gör det omöjligt att läsa fel.
     * - containern är åtkomlig för användaren. Villkoret ligger på
     *   Container-modellen (`scopeAccessibleBy`) och appliceras som `whereHas`
     *   genom relationskedjan förekomst → schema → item → container — aldrig
     *   som en `whereIn('container_id', ...)`-lista, se samma resonemang som
     *   App\Http\Controllers\Api\ItemSearchController. SoftDeletes' globala
     *   scope gäller automatiskt i underfrågorna, så ett mjukraderat schema,
     *   item eller container faller ut här. `is_active` har inget globalt
     *   scope utan skrivs ut explicit: ett pausat schema behåller sin öppna
     *   förekomst (22a § Beslut 3), och den ska inte synas i listan.
     * - **itemet ligger inom användarens omfång** (issue 74 § Beslut 7). En
     *   container-bred åtkomst räcker inte: sedan ADR-0028 kan en mottagare
     *   ha `read` på ett enskilt item i en container hon i övrigt inte ser,
     *   och todo-listan är en TOPPNIVÅvy som annars namnger varje annat items
     *   uppgifter i containern.
     * - inga öppna beroenden — samma villkor som spärren i 23b § Beslut 4.
     *   En förekomst vars motpart har status `open` går inte att stänga och
     *   ska inte stå bland det man kan göra nu. Ett öppet beroende vars
     *   motpart ligger under ett mjukraderat schema eller item räknas inte:
     *   motparten "existerar inte" där, i GET-listan eller i cykelkontrollen
     *   (23b § Att se upp med). Villkoret är en enda `whereDoesntHave`-
     *   underfråga, aldrig en fråga per rad (Beslut 4).
     *
     *   Asymmetrin mot `is_active`-villkoret två rader ovan är avsiktlig,
     *   inte en inkonsekvens: en mjukraderad motpart blockerar INTE (raden
     *   är oåtkomlig genom hela rutt-kedjan — ingen kan bocka av eller hoppa
     *   över den, så beroendet vore permanent och osynligt trasigt), medan
     *   en PAUSAD motpart blockerar FORTFARANDE (paus är reversibelt och
     *   synligt — schemat kan återupptas, förekomsten bockas av eller
     *   hoppas över — så det är rätt att A väntar). Villkoret nedan prövar
     *   därför bara `status` och att schema/item finns (SoftDeletes' globala
     *   scope), aldrig motpartens `is_active`.
     *
     * Omfånget (issue 74 § Beslut 7 och 10) löses upp HÄR, en gång per
     * anrop, och inte i anroparen: scopet är delat mellan TodoController och
     * App\Console\GeneratesTaskNotifications, och filtret hör hemma i scopet
     * så att listan och notiserna aldrig kan säga olika saker om vad
     * mottagaren ser. Genereraren rörs därför inte av issue 74 — den får
     * filtret genom det här anropet, och följdverkan verifieras i issue 75.
     *
     * `forContainers()` och inte `handle()` per container: genereraren kör
     * scopet en gång per användare i en `chunkById`-loop över hela
     * användartabellen, och en upplösning per container hade blivit ett
     * nattjobb som växer med kundstocken. Upplösningen är konstant — en
     * ResolveItemScope ställer tre frågor, fyra när någon container har en
     * itemgrant — och de två frågorna här (containrarna och omfånget)
     * ersätter den `whereHas('container')`-kedja som stod i villkoret
     * tidigare. Antalet frågor beror alltså inte på antalet containers eller
     * förekomster.
     *
     * Upplösningen sker på en FÄRSK instans (`build()`) och inte på den
     * `scoped`-bundna. Memon på den senare finns för ItemPolicy, som frågar
     * en gång per rad i en listning; scopet frågar en gång per anrop, och för
     * det skulle memon bara göra frågekostnaden beroende av vad samma
     * PHP-process råkade ha löst upp tidigare — ett mått som inte hör till
     * anropet.
     *
     * Filtret formuleras som två grenar på item-nivån: items i de containers
     * där omfånget är OMFATTANDE, plus de enskilda items ett BEGRÄNSAT
     * omfång når. Är båda tomma (användaren når ingenting) ger `whereIn` mot
     * en tom lista `0 = 1` i båda grenarna, alltså inga rader — en tom lista
     * är alltid "når ingenting", aldrig "når allt" (ItemScope).
     *
     * @param  Builder<ScheduleOccurrence>  $query
     * @param  list<int>  $accountIds  löpnumren för kontona $user är medlem i
     * @return Builder<ScheduleOccurrence>
     */
    public function scopeTodoFor(Builder $query, User $user, array $accountIds): Builder
    {
        $containerIds = Container::query()
            ->accessibleBy($user, $accountIds)
            ->pluck('id')
            ->all();

        $scopes = app()->build(ResolveItemScope::class)->forContainers($user, $containerIds);

        $unrestrictedContainers = [];
        $scopedItemIds = [];

        foreach ($scopes as $containerId => $scope) {
            if ($scope->isUnrestricted()) {
                $unrestrictedContainers[] = $containerId;

                continue;
            }

            $scopedItemIds = array_merge($scopedItemIds, $scope->itemIds() ?? []);
        }

        return $query
            ->where('status', self::STATUS_OPEN)
            ->whereDate('visible_from', '<=', $user->today()->toDateString())
            ->whereHas('schedule', function (Builder $query) use ($unrestrictedContainers, $scopedItemIds): void {
                $query->where('schedule.is_active', true)
                    ->whereHas('item', function (Builder $query) use ($unrestrictedContainers, $scopedItemIds): void {
                        $query->whereIn('item.container_id', $unrestrictedContainers)
                            ->orWhereIn('item.id', $scopedItemIds);
                    });
            })
            ->whereDoesntHave('dependsOn', function (Builder $query): void {
                $query
                    ->where('status', self::STATUS_OPEN)
                    ->whereHas('schedule.item');
            });
    }

    /**
     * Begränsar till det som är AKTUELLT NU — försenat och i dag (issue 134).
     *
     * **Växeln `user.show_upcoming_tasks` avgör om det här villkoret alls
     * ställs**, och det formuleras här och inte i anroparen: precis som
     * `scopeTodoFor()` är det ett urval och ingen presentation, och en
     * filtrering i App\Actions\Schedule\ListTodo hade varit den andra
     * sanningen om vad listan visar. Är flaggan sann — standardvärdet, dagens
     * beteende — läggs ingen scope på alls.
     *
     * **`whereDate()` och inte en rå kolumnjämförelse**, av exakt samma skäl
     * som `scopeTodoFor()` väljer det: `due_at` är en DATE-kolumn, men värdet
     * lagras med en tidsdel — `2026-06-15 00:00:00`. MariaDB klipper den till
     * kolumnens typ, sqlite gör det inte, så `due_at <= '2026-06-15'` hade
     * räknat in samma dag i sviten och inte i drift.
     *
     * **Idag är användarens dag**, `User::today()` — samma klocka som
     * `overdue`, grupperingen och `scopeTodoFor` räknar mot sedan issue 135
     * ([[ADR-0044 Användarens dag]]). En förekomst som förfaller i morgon enligt
     * UTC men i dag enligt hennes tidszon hör till "i dag" och stannar kvar i
     * listan.
     *
     * @param  Builder<ScheduleOccurrence>  $query
     * @return Builder<ScheduleOccurrence>
     */
    public function scopeDueTodayOrEarlier(Builder $query, User $user): Builder
    {
        return $query->whereDate('due_at', '<=', $user->today()->toDateString());
    }
}
