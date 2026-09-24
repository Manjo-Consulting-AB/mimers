<?php

namespace App\Actions\Security;

use App\Models\Account;
use App\Models\SecurityLog;
use App\Models\User;
use App\Support\Security\DeviceName;
use App\Support\Security\IpGroup;

/**
 * Den enda vägen IN i säkerhetsloggen — se [[ADR-0043 Tre loggar]]
 * § Säkerhetsloggen och issue 113. Varje uppräknad händelse — inloggningar,
 * magic links, tvåfaktor, återställningskoder, inbjudningar, exporter,
 * webhooks, tömd lagring, nedladdningar ur andras containrar och den
 * rättsliga spärrens två kommandon — anropar den här actionen.
 *
 * **Rådata kommer in och går aldrig ut igen.** Anroparen skickar IP-adressen
 * och webbläsarsträngen som de är, och actionen räknar fram pseudonymen
 * (App\Support\Security\IpGroup) och tolkar enhetsnamnet
 * (App\Support\Security\DeviceName) innan raden skrivs. Att låta anroparen
 * räkna fram dem först vore att lägga formeln på tolv ställen och ge tolv
 * chanser att spara adressen i stället.
 *
 * **Ingen egen transaktion**, av samma skäl som App\Actions\Audit\
 * RecordAuditEvent: en egen `DB::transaction` här vore en nästlad
 * transaktion som ser ut att skydda något den inte skyddar. En rad som
 * skrivs utanför anroparens transaktion kan överleva ett rollback och
 * beskriva en händelse som aldrig hände — därför skriver
 * App\Actions\Invitation\CreateInvitation sin rad inuti transaktionen, och
 * App\Actions\LegalHold\PlaceLegalHold sin utanför (den har ingen).
 *
 * **Aldrig ett lösenord, en kod eller ett token, inte ens hashad**, och
 * aldrig en e-postadress. `$meta` är anroparens ansvar; actionen kan inte
 * kontrollera det, och därför står regeln i varje anropares docblock.
 *
 * `$ip` och `$userAgent` är nullbara: den rättsliga spärren sätts från
 * kommandoraden utan en request, och en klient utan `User-Agent` har ingen
 * enhet att tolka.
 */
class RecordSecurityEvent
{
    /**
     * Skriv en rad. `$action` är ett öppet namnrum — konstanterna bor på
     * App\Models\SecurityLog, så anroparna aldrig stavar en sträng.
     *
     * @param  array<string, mixed>  $meta
     */
    public function handle(
        string $action,
        ?Account $account = null,
        ?User $user = null,
        ?string $ip = null,
        ?string $userAgent = null,
        array $meta = [],
    ): SecurityLog {
        $log = new SecurityLog;
        $log->action = $action;
        $log->account_id = $account?->id;
        $log->user_id = $user?->id;
        $log->ip_group = $ip === null ? null : IpGroup::from($ip);
        $log->device_name = DeviceName::from($userAgent);
        $log->meta = $meta;
        $log->save();

        return $log;
    }
}
