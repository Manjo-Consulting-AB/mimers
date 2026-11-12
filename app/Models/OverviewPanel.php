<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En panel på containerns översikt i personens ordning och bredd, se
 * [[ADR-0073 Översiktens ordning]]. Pivot utan `ulid` och `deleted_at`;
 * raderna ersätts som helhet av App\Actions\User\SaveOverviewPanels.
 */
#[Fillable([])]
class OverviewPanel extends Model
{
    protected $table = 'overview_panel';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
