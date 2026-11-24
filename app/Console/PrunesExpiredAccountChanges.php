<?php

namespace App\Console;

use App\Models\EmailChange;
use App\Models\PasswordChange;
use Illuminate\Database\Eloquent\Builder;

/**
 * Gallrar `email_change` och `password_change` — M83 · issue 473, se
 * [[Registerförteckning]] och [[Konton och åtkomst]] § email_change och
 * § password_change.
 *
 * Tar bort rader som är bekräftade (`confirmed_at` satt) ELLER utgångna
 * (`expires_at` passerad). Ingen historik behålls: en sådan rad har inget
 * värde efter förbrukning eller förfall, och `email_change` bär den nya
 * adressen i klartext medan raden annars ligger kvar till personen raderas
 * av DeleteUser.
 *
 * **Att raden saknas ändrar inget svar.** `ConfirmEmailChange` och
 * `ConfirmPasswordChange` svarar `404` på en utgången eller bekräftad rad,
 * precis som på ett okänt token — se deras klassdocblock. Samma sak gäller
 * en rad som `RequestEmailChange` eller `RequestPasswordChange`
 * ogiltigförklarat genom att sätta `expires_at` till nu: den är utgången när
 * jobbet kör och tas bort här.
 *
 * Schemaläggs i routes/console.php med `Schedule::call(...)` under namnet
 * `prune-account-changes`, aldrig `Schedule::command(...)` — se AGENTS.md
 * § Subprocesser i driftmiljön. Klassen är medvetet fri från
 * Artisan-beroenden (inget `Command`), i samma form som
 * App\Console\PrunesExpiredMagicLinkTokens, just för att den ska gå att köra
 * som en ren closure-kallbar utan att öppna dörren för att någon av misstag
 * schemalägger den som ett kommando i stället.
 */
class PrunesExpiredAccountChanges
{
    /**
     * @return int Antal borttagna rader ur båda tabellerna.
     */
    public function handle(): int
    {
        return $this->prune(EmailChange::query())
            + $this->prune(PasswordChange::query());
    }

    /**
     * Ett och samma villkor för båda tabellerna, så att en ändring av
     * fristen inte kan glida isär mellan dem.
     *
     * @param  Builder<EmailChange>|Builder<PasswordChange>  $query
     */
    private function prune(Builder $query): int
    {
        return $query
            ->where(function (Builder $query) {
                $query->whereNotNull('confirmed_at')
                    ->orWhere('expires_at', '<', now());
            })
            ->delete();
    }
}
