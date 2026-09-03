<?php

namespace App\Models;

use Database\Factories\OccurrenceDependencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * En regel på förekomstnivå: "förekomsten `occurrence_id` väntar på
 * förekomsten `depends_on_occurrence_id`", se [[Scheman och uppgifter]] §
 * occurrence_dependency och issue 23b. Riktningen är inte symmetrisk (§
 * Beslut 1): B väntar på A skrivs `occurrence_id` = B,
 * `depends_on_occurrence_id` = A.
 *
 * Samma form som App\Models\ScheduleDependency (issue 23a): ingen ULID och
 * därför inget #[RouteKey] (paret identifierar raden, ingen rutt pekar ut en
 * enskild rad), ingen `deleted_at` (raderingen är hård; historiken ligger
 * kvar, § Beslut 5).
 *
 * Alla kolumner är medvetet UTESLUTNA ur #[Fillable] — raderna skrivs av
 * App\Actions\Schedule\DependOccurrence (direkta beroenden), av
 * App\Actions\Schedule\OpenNextOccurrence (arvet) och av testerna, aldrig
 * via massildelning, samma resonemang som App\Models\ItemLink.
 */
#[Fillable([])]
class OccurrenceDependency extends Model
{
    /** @use HasFactory<OccurrenceDependencyFactory> */
    use HasFactory;

    /**
     * Tabellen heter `occurrence_dependency`, inte Eloquents standardplural
     * `occurrence_dependencies`.
     */
    protected $table = 'occurrence_dependency';
}
