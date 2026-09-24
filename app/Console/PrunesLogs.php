<?php

namespace App\Console;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\LegalHold;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gallrar loggarna — se issue 115 (M18) och [[ADR-0043 Tre loggar]] § Beslut.
 * Två steg, och den rättsliga spärren går före båda.
 *
 * HÄNDELSELOGGEN FÖLJER DET DEN HANDLAR OM. En containers rader tas bort tolv
 * månader efter containerns `container.purged`-rad, och rader utan container
 * tolv månader efter kontots `account.deleted` (ADR § Händelseloggen). De två
 * raderna är ankaret, och de är det enda som säger att ett id i loggen en gång
 * var en container eller ett konto. **En levande containers rader rörs aldrig**,
 * hur gamla de än är: utan ett ankare finns ingen frist att räkna från, och
 * användaren behöver historiken så länge det användaren äger finns.
 *
 * SÄKERHETSLOGGEN HAR INGET ANKARE. Den hör inte till en container och
 * överlever inte kontot — raden tas bort tolv månader efter sitt eget
 * `created_at` (ADR § Säkerhetsloggen). Den har ingen rå IP-adress att nolla
 * (issue 113), så det finns inget nittiodagarssteg: raden tas bort hel, eller
 * inte alls.
 *
 * MÄTNINGENS TABELL RÖRS ALDRIG (ADR § Mätningen). `usage_metric` är anonym
 * och sparas för evigt; den gallras inte här och inte någon annanstans.
 *
 * DEN RÄTTSLIGA SPÄRREN GÅR FÖRE BÅDA STEGEN (ADR § Den rättsliga spärren).
 * Rader som hör till ett spärrat konto tas inte bort. Frågan ställs med
 * LegalHold::covers() — den enda formuleringen av "är kontot spärrat" (issue
 * 112) — och aldrig med en egen fråga mot `legal_hold`: en spärr som bara en
 * del av gallringen känner igen är värre än ingen spärr, för den ser ut att
 * gälla. Spärren läses per kandidatkonto och inte i själva urvalet, så en
 * spärr som sätts medan körningen pågår hinner få verkan — samma ordning som
 * App\Console\PurgesExpiredTrash.
 *
 * ETT KONTO SOM INTE LÄNGRE FINNS ÄR ALDRIG SPÄRRAT. Spärren sätts från
 * kommandoraden mot ett konto som slås upp på sin ULID, och
 * App\Console\DeletesDormantAccounts hoppar över ett spärrat konto — ett
 * raderat konto kan därför inte bära en gällande spärr. `account.deleted`-
 * ankaret hör alltid till ett sådant konto, så kontots kontonivårader gallras
 * utan en spärrfråga som inte kan besvaras.
 *
 * FRISTEN RÄKNAS I KALENDERMÅNADER, med Carbon::subMonths() och aldrig
 * subDays() — samma regel som kontolivscykeln (config/konton.php). Tolv månader
 * är ett år, inte 365 dagar. Längden bor i config/loggar.php.
 *
 * IDEMPOTENT: en andra körning samma natt finner inga ankare och rör ingenting.
 *
 * SCHEMALÄGGNINGEN LIGGER EFTER MÄTNINGEN (issue 114): mätningen läser de rader
 * gallringen tar, och det som gallras innan det räknats är borta ur mätningen
 * för alltid. Se routes/console.php och tests/Feature/Drift/KoarbetareTest.php
 * — `drain-queue` ska fortsätta ligga sist. Schemaläggs med
 * `Schedule::call(...)`, aldrig `Schedule::command(...)` — se AGENTS.md
 * § Driftmiljön saknar proc_open. Klassen är medvetet fri från
 * Artisan-beroenden av samma skäl som App\Console\PrunesRegistrationIps, så
 * att ingen av misstag schemalägger den som ett kommando.
 */
class PrunesLogs
{
    /**
     * @return array{audit_log: int, security_log: int} Antal borttagna rader per logg.
     */
    public function handle(): array
    {
        return [
            'audit_log' => $this->prunesAuditLog(),
            'security_log' => $this->prunesSecurityLog(),
        ];
    }

    /**
     * Händelseloggen, i sina två slag: containerns rader och kontots rader.
     * De två slagen rör olika rader — det ena frågar på `container_id`, det
     * andra på `container_id IS NULL` — så de kan inte dubbelräkna varandra.
     *
     * @return int Antal borttagna rader.
     */
    private function prunesAuditLog(): int
    {
        $cutoff = now()->subMonths((int) config('loggar.audit_retention_months'));

        return $this->prunesPurgedContainers($cutoff) + $this->prunesDeletedAccounts($cutoff);
    }

    /**
     * Containerns rader: tolv månader efter `container.purged`. Ankaret skrivs
     * av App\Actions\Trash\PurgeContainer i samma transaktion som containern
     * försvinner (issue 107), och ligger i den container den beskriver — det
     * tas bort med de andra.
     *
     * @return int Antal borttagna rader.
     */
    private function prunesPurgedContainers(Carbon $cutoff): int
    {
        $ankare = DB::table('audit_log')
            ->where('action', AuditLog::ACTION_CONTAINER_PURGED)
            ->whereNotNull('container_id')
            ->where('created_at', '<', $cutoff)
            ->get(['container_id', 'account_id']);

        if ($ankare->isEmpty()) {
            return 0;
        }

        // Spärren prövas mot ankarets konto: containern har exakt en ägare
        // ([[ADR-0002 Konto äger container]]), och varje rad i containern bär
        // samma `account_id` — RecordAuditEvent får alltid containerns konto.
        $spärrade = $this->heldAccounts($ankare->pluck('account_id'));

        $containers = $ankare
            ->reject(fn ($rad): bool => $rad->account_id !== null && isset($spärrade[(int) $rad->account_id]))
            ->pluck('container_id')
            ->unique()
            ->values();

        if ($containers->isEmpty()) {
            return 0;
        }

        return $this->deleteUnheld(
            DB::table('audit_log')->whereIn('container_id', $containers->all()),
            $spärrade,
        );
    }

    /**
     * Kontots rader utan container: tolv månader efter `account.deleted`, som
     * skrivs av App\Actions\Account\DeleteAccount (issue 107). Ankaret är
     * självt en rad utan container och tas bort med de andra.
     *
     * @return int Antal borttagna rader.
     */
    private function prunesDeletedAccounts(Carbon $cutoff): int
    {
        $konton = DB::table('audit_log')
            ->where('action', AuditLog::ACTION_ACCOUNT_DELETED)
            ->where('created_at', '<', $cutoff)
            ->pluck('account_id')
            ->filter()
            ->unique()
            ->values();

        if ($konton->isEmpty()) {
            return 0;
        }

        return $this->deleteUnheld(
            DB::table('audit_log')
                ->whereNull('container_id')
                ->whereIn('account_id', $konton->all()),
            $this->heldAccounts($konton),
        );
    }

    /**
     * Säkerhetsloggen: raden tas bort tolv månader efter sitt eget
     * `created_at`. En rad utan konto — en misslyckad inloggning mot en adress
     * som inte finns — hör inte till någon som kan vara spärrad och följer
     * med.
     *
     * @return int Antal borttagna rader.
     */
    private function prunesSecurityLog(): int
    {
        $cutoff = now()->subMonths((int) config('loggar.security_retention_months'));

        $rader = DB::table('security_log')->where('created_at', '<', $cutoff);

        $spärrade = $this->heldAccounts(
            DB::table('security_log')->where('created_at', '<', $cutoff)->pluck('account_id')
        );

        return $this->deleteUnheld($rader, $spärrade);
    }

    /**
     * Tar bort kandidatraderna, utom de som hör till ett spärrat konto. En rad
     * utan konto kan inte vara spärrad och tas med.
     *
     * @param  Builder  $rader  Kandidaterna; frågan om vad som är en kandidat är redan ställd.
     * @param  array<int, true>  $spärrade
     * @return int Antal borttagna rader.
     */
    private function deleteUnheld(Builder $rader, array $spärrade): int
    {
        if ($spärrade !== []) {
            $rader->where(function (Builder $query) use ($spärrade): void {
                $query->whereNull('account_id')
                    ->orWhereNotIn('account_id', array_keys($spärrade));
            });
        }

        return $rader->delete();
    }

    /**
     * Kontona bland kandidaterna som har en gällande spärr, som en mängd
     * `id => true`.
     *
     * Frågan ställs med LegalHold::covers() och ett konto i taget — den enda
     * formuleringen av "är kontot spärrat" (issue 112). En kandidat utan konto,
     * och ett konto som inte längre finns (se klassdocblocket), är aldrig
     * spärrad och hoppas över utan en fråga.
     *
     * @param  Collection<int, mixed>  $accountIds
     * @return array<int, true>
     */
    private function heldAccounts(Collection $accountIds): array
    {
        $ids = $accountIds
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $spärrade = [];

        foreach (Account::query()->whereIn('id', $ids->all())->get() as $konto) {
            if (LegalHold::covers($konto)) {
                $spärrade[$konto->id] = true;
            }
        }

        return $spärrade;
    }
}
