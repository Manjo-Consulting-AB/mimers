<?php

namespace App\Console\Commands;

use App\Support\Transaction\Currencies;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `costs:report-invalid-currencies` — visa vilka valutakoder som står i
 * databasen men inte i Currencies::codes(), se [[ADR-0078 Valutor ur ISO
 * 4217]] § 3 och issue 1252.
 *
 * Kommandot ÄNDRAR INGENTING. Det finns ingen INSERT, UPDATE eller DELETE
 * här, inte ens för de rader rapporten pekar ut — raden är sanningen
 * ([[ADR-0037 Valutans arv]]), och vad som ska göras med en ogiltig kod
 * beslutas när rapporten finns. Det är därför mjukraderade rader räknas med
 * och redovisas i en egen kolumn: de finns kvar i databasen och kan komma
 * tillbaka.
 *
 * Rapporten läser tre kolumner och skriver en tabell per kolumn som har
 * träffar:
 *
 * - `item_transaction.currency` — antal rader, mjukraderade och summan av `amount`
 *   per kod. `amount` lagras redan i minsta enhet
 *   (App\Support\Transaction\MinorUnits), så summan är i minsta enhet och aldrig i
 *   huvudenhet.
 * - `container.currency` — antal rader och mjukraderade. Kolumnen är NULLBAR;
 *   `NULL` är ingen kod och varken giltig eller ogiltig, så de raderna
 *   utelämnas.
 * - `account.currency` — antal rader. `account` har ingen `deleted_at`
 *   (kontots livscykel går via `status`), så kolumnen för mjukraderade är
 *   alltid noll men står med för att tabellerna ska ha samma form.
 *
 * Utan träffar skriver kommandot "All currency codes are valid." — meningen
 * är engelsk med flit, den är rapportens fasta svar och inte prosa.
 *
 * Grupperingen sker BINÄRT, inte enligt kolumnens kollation. Kolumnerna är
 * `char(3)` och kopplingens kollation är `utf8mb4_unicode_ci`
 * (config/database.php), alltså skiftlägesokänslig: `GROUP BY currency` slår
 * annars ihop `sek` och `SEK` till en grupp och MariaDB returnerar ett
 * godtyckligt värde ur den. Blir det `SEK` filtreras gruppen bort som giltig
 * och de små bokstäverna syns aldrig i rapporten — fast den nuvarande
 * valideringen (`alpha|size:3`) tillåter dem, så raderna kan finnas.
 * `BINARY currency` gör jämförelsen tecken för tecken och räcker också mot
 * `PAD SPACE`-kollationer, där `'AB '` annars jämställs med `'AB'`.
 * SQLite jämför redan skiftlägeskänsligt, så uttrycket lämnas orört där;
 * drivrutinsglappet är dokumenterat i provet.
 */
class ReportInvalidCurrencies extends Command
{
    protected $signature = 'costs:report-invalid-currencies';

    protected $description = 'Rapportera valutakoder som inte är aktiva ISO 4217-koder. Ändrar ingenting.';

    public function handle(): int
    {
        $valid = Currencies::codes();
        $found = false;

        $costEntries = $this->invalidCostEntries($valid);

        if ($costEntries !== []) {
            $found = true;
            $this->line('item_transaction.currency');
            $this->table(['kod', 'rader', 'mjukraderade', 'summa (minsta enhet)'], $costEntries);
        }

        $containers = $this->invalidContainers($valid);

        if ($containers !== []) {
            $found = true;
            $this->line('container.currency');
            $this->table(['kod', 'rader', 'mjukraderade'], $containers);
        }

        $accounts = $this->invalidAccounts($valid);

        if ($accounts !== []) {
            $found = true;
            $this->line('account.currency');
            $this->table(['kod', 'rader', 'mjukraderade'], $accounts);
        }

        if (! $found) {
            $this->line('All currency codes are valid.');
        }

        return self::SUCCESS;
    }

    /**
     * Uttrycket som grupperar och sorterar valutakoden binärt.
     *
     * MySQL och MariaDB ärver kollationen `utf8mb4_unicode_ci` och måste säga
     * `BINARY currency` för att `sek` och `SEK` ska bli två grupper. SQLite
     * jämför strängar skiftlägeskänsligt redan som det är och känner inte
     * igen `BINARY` som operator, så där står kolumnen orörd. Jämförelsen mot
     * Currencies::codes() sker sedan i PHP med `in_array(..., true)` och är
     * binär på alla drivrutiner.
     */
    private function binaryCurrencyExpression(): string
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? 'BINARY currency'
            : 'currency';
    }

    /**
     * Ogiltiga koder i `item_transaction`, med antal, mjukraderade och summa i
     * minsta enhet. Frågan går via DB::table och inte ItemTransaction::query(),
     * eftersom modellens SoftDeletes-scope annars hade tystat de
     * mjukraderade raderna — och de ska räknas.
     *
     * @param  list<string>  $valid
     * @return list<array{string, int, int, string}>
     */
    private function invalidCostEntries(array $valid): array
    {
        $currency = $this->binaryCurrencyExpression();

        $rows = DB::table('item_transaction')
            ->selectRaw($currency.' as currency, COUNT(*) as row_count, SUM(CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END) as deleted_count, SUM(amount) as amount_sum')
            ->groupByRaw($currency)
            ->orderByRaw($currency)
            ->get();

        $result = [];

        foreach ($rows as $row) {
            if (in_array($row->currency, $valid, true)) {
                continue;
            }

            $result[] = [
                (string) $row->currency,
                (int) $row->row_count,
                (int) $row->deleted_count,
                (string) $row->amount_sum,
            ];
        }

        return $result;
    }

    /**
     * Ogiltiga koder i `container`, med antal och mjukraderade. NULL är
     * ingen kod och utelämnas.
     *
     * @param  list<string>  $valid
     * @return list<array{string, int, int}>
     */
    private function invalidContainers(array $valid): array
    {
        $currency = $this->binaryCurrencyExpression();

        $rows = DB::table('container')
            ->whereNotNull('currency')
            ->selectRaw($currency.' as currency, COUNT(*) as row_count, SUM(CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END) as deleted_count')
            ->groupByRaw($currency)
            ->orderByRaw($currency)
            ->get();

        $result = [];

        foreach ($rows as $row) {
            if (in_array($row->currency, $valid, true)) {
                continue;
            }

            $result[] = [
                (string) $row->currency,
                (int) $row->row_count,
                (int) $row->deleted_count,
            ];
        }

        return $result;
    }

    /**
     * Ogiltiga koder i `account`, med antal. `account` mjukraderas aldrig,
     * så mjukraderade-kolumnen är alltid noll.
     *
     * @param  list<string>  $valid
     * @return list<array{string, int, int}>
     */
    private function invalidAccounts(array $valid): array
    {
        $currency = $this->binaryCurrencyExpression();

        $rows = DB::table('account')
            ->selectRaw($currency.' as currency, COUNT(*) as row_count')
            ->groupByRaw($currency)
            ->orderByRaw($currency)
            ->get();

        $result = [];

        foreach ($rows as $row) {
            if (in_array($row->currency, $valid, true)) {
                continue;
            }

            $result[] = [
                (string) $row->currency,
                (int) $row->row_count,
                0,
            ];
        }

        return $result;
    }
}
