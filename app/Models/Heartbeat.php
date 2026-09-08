<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * En rad per schemapost: namnet (postens `->name(...)` i routes/console.php)
 * och tidpunkten för senaste lyckade körning, se issue 43 § Beslut 2.
 *
 * Tabellen är dead man's switch-ens minne. Raden skrivs bara av
 * App\Listeners\RecordsScheduleHeartbeat, som lyssnar på
 * ScheduledTaskFinished — och bara när `exitCode` är 0 (Beslut 5). Ytan
 * GET /drift/heartbeat läser raderna; vakten på VPS:en (deploy/drift/vakt.sh)
 * läser dem i sin tur därifrån.
 *
 * Inget `ulid` (raden syns aldrig i API:et) och inget `deleted_at`
 * (drifttillstånd, inte användarskapat innehåll) — se migrationen. En rad
 * per namn, uppdaterad på plats via uniknyckeln; tabellen växer aldrig.
 * `last_success_at` måste vara fillable: lyssnaren skriver raden med
 * `updateOrCreate`, alltså massilldelning.
 */
#[Fillable(['name', 'last_success_at'])]
class Heartbeat extends Model
{
    /**
     * Tabellen heter `heartbeat`, inte Eloquents standardplural.
     */
    protected $table = 'heartbeat';

    /**
     * Get the attributes that should be cast.
     *
     * `last_success_at` är en TIMESTAMP i UTC. Casten till datetime ger en
     * Carbon — i sqlite (testsviten) ligger tidsstämpeln som text, och utan
     * casten vore jämförelserna fel (issue 30 § Att se upp med).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_success_at' => 'datetime',
        ];
    }
}
