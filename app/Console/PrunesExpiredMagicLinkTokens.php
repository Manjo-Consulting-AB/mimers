<?php

namespace App\Console;

use App\Models\MagicLinkToken;
use Illuminate\Database\Eloquent\Builder;

/**
 * Gallrar magic_link_token — se issue 5 / PR #34, uppföljning efter
 * granskning: tabellen växer obegränsat annars, en rad i taget för varje
 * begärd länk.
 *
 * Tar bort rader som är förbrukade (`used_at` satt) ELLER utgångna
 * (`expires_at` passerad). Ingen historik behålls — en sådan rad har inget
 * värde efter förbrukning eller förfall, se App\Support\Auth\MagicLinkBroker
 * för hela livscykeln.
 *
 * Schemaläggs i routes/console.php med `Schedule::call(...)`, aldrig
 * `Schedule::command(...)` — se AGENTS.md § Driftmiljön saknar proc_open.
 * Klassen är medvetet fri från Artisan-beroenden (inget `Command`, inget
 * `Schedulable`-interface) just för att den ska gå att köra som en ren
 * closure-kallbar utan att öppna dörren för att någon av misstag
 * schemalägger den som ett kommando i stället.
 */
class PrunesExpiredMagicLinkTokens
{
    /**
     * @return int Antal borttagna rader.
     */
    public function handle(): int
    {
        return MagicLinkToken::query()
            ->where(function (Builder $query) {
                $query->whereNotNull('used_at')
                    ->orWhere('expires_at', '<', now());
            })
            ->delete();
    }
}
