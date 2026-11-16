<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Råtabellen för sidvisningar och funktioner — [[ADR-0079 Övervakning]] § 4,
 * issue 391. Mellanlagring: nattjobbet flyttar raderna till `usage_metric` och
 * tömmer dem. Skrivs enbart av CountRouteView och CountFeatureUse, via en
 * upsert; ingen kolumn pekar på en person, ett konto eller en IP-adress.
 */
#[Fillable([])]
class UsageEventDaily extends Model
{
    protected $table = 'usage_event_daily';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'count' => 'integer',
        ];
    }
}
