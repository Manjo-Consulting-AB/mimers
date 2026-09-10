<?php

namespace App\Console;

use App\Models\Account;

/**
 * Gallrar registrerings-IP:t — se issue 50a (M9) och [[Registerförteckning]].
 * Kolumnen är personuppgiftsnära och har en gallringsfrist ([[ADR-0017
 * Missbruksvektorer]] § Konsekvenser); det här jobbet verkställer den.
 *
 * Jobbet NOLLAR kolumnen, det raderar ingenting (Beslut 9): kontot, dess
 * ULID, status, medlemmar och containers är efteråt exakt vad de var — det
 * enda som hänt är att `registration_ip` blivit NULL. Konton raderas av
 * App\Console\DeletesDormantAccounts (29b), och de två jobben har ingenting
 * med varandra att göra.
 *
 * Fristen räknas ur `account.created_at`, som redan finns och aldrig
 * ändras — ingen egen `purge_after`-kolumn (Beslut 8). Längden bor i
 * config/konton.php § registration_ip_retention_days.
 *
 * Idempotent i kraft av `WHERE registration_ip IS NOT NULL` (Beslut 9):
 * andra körningen samma natt nollar noll rader.
 *
 * Schemaläggs i routes/console.php med `Schedule::call(...)`, aldrig
 * `Schedule::command(...)` — se AGENTS.md § Driftmiljön saknar proc_open.
 * Klassen är medvetet fri från Artisan-beroenden (inget `Command`, inget
 * `Schedulable`-interface) av samma skäl som
 * App\Console\PrunesExpiredMagicLinkTokens: så att ingen av misstag
 * schemalägger den som ett kommando.
 */
class PrunesRegistrationIps
{
    /**
     * @return int Antal nollade rader.
     */
    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('konton.registration_ip_retention_days'));

        return Account::query()
            ->whereNotNull('registration_ip')
            ->where('created_at', '<', $cutoff)
            ->update(['registration_ip' => null]);
    }
}
