<?php

namespace App\Models;

use Database\Factories\ScheduleDependencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * En regel på schemanivå: "schemat `schedule_id` väntar på schemat
 * `depends_on_schedule_id`", se [[Scheman och uppgifter]] § occurrence_dependency
 * och issue 23. Riktningen är inte symmetrisk (§ Beslut 2): B beror på A
 * skrivs `schedule_id` = B, `depends_on_schedule_id` = A, och inget härleds
 * vid läsning — till skillnad från `item_link` (issue 14 § Beslut 4) finns
 * ingen kanonisk form att vända.
 *
 * Ingen ULID (§ Beslut 1) och därför inget #[RouteKey]: paret identifierar
 * raden, och det finns ingen rutt som pekar ut en enskild rad. Ingen
 * `deleted_at` (§ Beslut 7): raderingen är hård, och ett mjukraderat schema
 * drar inte med sig sina beroenden — SoftDeletes är en UPDATE, raderna ligger
 * kvar och blir aktuella igen om schemat återställs. Schemat självt filtreras
 * bort av SoftDeletes globala scope vid läsning och i cykelkontrollen.
 *
 * Alla kolumner är medvetet UTESLUTNA ur #[Fillable] — de sätts explicit av
 * App\Actions\Schedule\DependSchedule, aldrig via massildelning, samma
 * resonemang som App\Models\ItemLink.
 */
#[Fillable([])]
class ScheduleDependency extends Model
{
    /** @use HasFactory<ScheduleDependencyFactory> */
    use HasFactory;

    /**
     * Tabellen heter `schedule_dependency`, inte Eloquents standardplural
     * `schedule_dependencies`.
     */
    protected $table = 'schedule_dependency';
}
