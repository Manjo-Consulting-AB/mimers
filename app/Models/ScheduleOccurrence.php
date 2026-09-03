<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\ScheduleOccurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

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
 * due_at < CURDATE()` (dokumentet § schedule_occurrence).
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
     * - `visible_from <= idag`. `whereDate()`, aldrig en rå kolumnjämförelse:
     *   i sqlite lagras DATE-kolumner med en tidskomponent, och ett datum
     *   ska inte bero på klockslaget när frågan körs (issue 24 § Att se upp
     *   med).
     * - containern är åtkomlig för användaren. Villkoret ligger på
     *   Container-modellen (`scopeAccessibleBy`) och appliceras som `whereHas`
     *   genom relationskedjan förekomst → schema → item → container — aldrig
     *   som en `whereIn('container_id', ...)`-lista, se samma resonemang som
     *   App\Http\Controllers\Api\ItemSearchController. SoftDeletes' globala
     *   scope gäller automatiskt i underfrågorna, så ett mjukraderat schema,
     *   item eller container faller ut här. `is_active` har inget globalt
     *   scope utan skrivs ut explicit: ett pausat schema behåller sin öppna
     *   förekomst (22a § Beslut 3), och den ska inte synas i listan.
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
     * @param  Builder<ScheduleOccurrence>  $query
     * @param  list<int>  $accountIds  löpnumren för kontona $user är medlem i
     * @return Builder<ScheduleOccurrence>
     */
    public function scopeTodoFor(Builder $query, User $user, array $accountIds): Builder
    {
        return $query
            ->where('status', self::STATUS_OPEN)
            ->whereDate('visible_from', '<=', Carbon::today())
            ->whereHas('schedule', function (Builder $query) use ($user, $accountIds): void {
                $query->where('schedule.is_active', true)
                    ->whereHas('item', function (Builder $query) use ($user, $accountIds): void {
                        $query->whereHas('container', function (Builder $query) use ($user, $accountIds): void {
                            /** @var Builder<Container> $query */
                            $query->accessibleBy($user, $accountIds);
                        });
                    });
            })
            ->whereDoesntHave('dependsOn', function (Builder $query): void {
                $query
                    ->where('status', self::STATUS_OPEN)
                    ->whereHas('schedule.item');
            });
    }
}
