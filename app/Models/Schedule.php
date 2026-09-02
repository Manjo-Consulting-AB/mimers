<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\ScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Regeln för återkommande underhåll — se [[Scheman och uppgifter]] §
 * schedule och [[ADR-0005 Schema och förekomst]]. Ett item har noll eller
 * flera scheman; förekomsten (den enskilda gången) är en rad i
 * `schedule_occurrence` (issue 22a).
 *
 * `item_id` är medvetet UTESLUTEN ur `#[Fillable]` — sätts explicit på
 * modellinstansen i App\Http\Controllers\Api\ScheduleController efter att
 * itemet lästs från rutten, aldrig via massildelning. Samma mönster som
 * Item gör med `container_id`.
 *
 * Inga förekomster skapas här — den enda vägen in är
 * App\Actions\Schedule\OpenNextOccurrence (issue 22 § Beslut 3).
 */
#[Fillable(['title', 'notes', 'recurrence_type', 'interval_unit', 'interval_count', 'anchor_date', 'lead_days', 'is_active'])]
#[RouteKey('ulid')]
class Schedule extends Model
{
    /** @use HasFactory<ScheduleFactory> */
    use HasFactory, HasUlid, SoftDeletes;

    /**
     * De giltiga värdena för `recurrence_type`, se migrationens CHECK-villkor
     * och [[Scheman och uppgifter]] § De två återkommandetyperna. Delas
     * mellan FormRequests (App\Http\Requests\Schedule) och ScheduleFactory
     * så listan bara underhålls på ett ställe — samma mönster som
     * Container::KINDS.
     *
     * @var list<string>
     */
    public const RECURRENCE_TYPES = ['none', 'fixed', 'interval'];

    /**
     * De giltiga värdena för `interval_unit`, se migrationens CHECK-villkor.
     *
     * @var list<string>
     */
    public const INTERVAL_UNITS = ['day', 'week', 'month', 'year'];

    /**
     * Tabellen heter `schedule`, inte Eloquents standardplural `schedules`.
     */
    protected $table = 'schedule';

    /**
     * Modellens standardvärden, speglar kolumnernas DEFAULT i migrationen.
     * En ny modell som skapas utan `lead_days`/`is_active` i kroppen bär dem
     * direkt — annars läser ScheduleResource null ur den osparade modellen
     * trots att databasen skulle ha satt defaultvärdena.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'lead_days' => 0,
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * `anchor_date` är en DATE-kolumn, castad till datum och serialiserad med
     * `toDateString()` ("2027-05-05") — aldrig `toIso8601String()`, ett
     * förfallodatum har ingen tidszon (issue 21 § Beslut 8).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'anchor_date' => 'date',
            'interval_count' => 'integer',
            'lead_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Itemet schemat hör till — exakt ett, schemat sätts alltid via
     * App\Models\Item::schedules() (eller direkt på item_id) och byter aldrig
     * item. Itemet i sin tur byter aldrig container (issue 21 § Beslut 3),
     * så containern härleds alltid ur itemet.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Schemats förekomster — den öppna plus historiken (issue 22a). Det är
     * DEN relationen listningen i
     * App\Http\Controllers\Api\ScheduleOccurrenceController::index() går
     * genom, sorterad `due_at` fallande där. En förekomst skapas aldrig här:
     * den enda vägen in är App\Actions\Schedule\OpenNextOccurrence.
     *
     * @return HasMany<ScheduleOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(ScheduleOccurrence::class);
    }

    /**
     * Schemats öppna förekomst — den som förfaller härnäst, om schemat har
     * en. Invarianterna (issue 22 § Beslut 7) garanterar högst en; är det
     * ingen alls returnerar relationen null. Läsningen "saknar schemat en
     * öppen förekomst?" i App\Http\Controllers\Api\ScheduleController går
     * genom relationens existens.
     *
     * @return HasOne<ScheduleOccurrence, $this>
     */
    public function openOccurrence(): HasOne
    {
        return $this->hasOne(ScheduleOccurrence::class)->where('status', 'open');
    }
}
