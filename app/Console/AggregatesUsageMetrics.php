<?php

namespace App\Console;

use App\Models\Account;
use App\Models\UsageMetric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mätningen — se issue 114 (M18) och [[ADR-0043 Tre loggar]] § Mätningen.
 * Jobbet räknar ihop loggarnas rader till anonyma summor i `usage_metric`:
 * antal per dag, källa, handling och plan. Det läser de två andra loggarna och
 * skriver bara i sin egen tabell — den rör aldrig en loggrad.
 *
 * VARFÖR EN EGEN TABELL OCH INTE EN RAPPORT. App\Console\ReportsAbuseSignals
 * är skrivskyddad med flit (issue 50b § Beslut 12): den läser tabeller sex
 * milstolpar äger och skriver ingenting, så att den kan köras mot
 * produktionsdata utan att lämna spår. Mätningen är motsatsen — den finns för
 * att svaret ska gå att ställa EFTER att loggen gallrats, och en summa som
 * bara skrivs till applikationsloggen försvinner med loggfilens rotation.
 *
 * EN GRUPP UNDER FEM SKRIVS ALDRIG (ADR § Mätningen). En rad som säger att en
 * enda användare på en viss plan gjorde en viss sak en viss dag pekar ut en
 * person. Grupper under MIN_GROUP_SIZE slås därför ihop med handlingen `other`
 * för samma dag, källa och plan. Är även den gruppen under fem slås den ihop
 * över planerna och skrivs med planen `unknown` — den hör då inte till någon
 * enskild plan, och en summa utan plan och utan handling kan inte peka ut
 * någon. Tröskeln är en konstant: den får höjas men aldrig sänkas utan en ny
 * ADR.
 *
 * IDEMPOTENT. Jobbet räknar bara det som saknas i `usage_metric`, och skriver
 * om hela dagen och källan i en transaktion (delete + insert) i stället för
 * att lägga till. Två körningar för samma dag ger därför samma rader, inte
 * dubbla, och det unika indexet på (date, source, action, plan) är skyddsnätet
 * under det. Enheten är paret (dag, källa), inte dagen: de två källorna skrivs
 * i var sin transaktion, och en körning som faller mellan dem får inte lämna
 * den ena källan oräknad för alltid. Nästa körning ser att just den källan
 * saknar dagen och räknar om den — den andra källans rader rörs inte.
 *
 * EN MISSAD NATT RÄKNAS I EFTERHAND. Jobbet letar upp varje dag som har
 * loggrader men ingen rad i `usage_metric`, inte bara gårdagen — så länge
 * raderna finns kvar. Det är också därför jobbet måste köra före gallringen i
 * issue 115: det som gallras innan det räknats är borta ur mätningen för
 * alltid. Schemaläggs i routes/console.php, före `drain-queue`.
 *
 * SÖKNINGEN BÖRJAR DAGEN EFTER DEN SENAST RÄKNADE DAGEN för källan, inte i
 * loggens början. En loggrad skrivs alltid med "nu", och en dag som en gång
 * räknats kan inte få fler rader — alltså kan ingenting oräknat ligga under
 * gränsen, och varje natt läser frågan svansen i stället för hela tabellen.
 * Bara första körningen, när `usage_metric` är tom för källan, söker från
 * loggens äldsta rad. Indexet på `created_at` (migreringen
 * 2026_09_24_020000) bär gränsen.
 *
 * PLANEN ÄR KONTOTS PLANKOD NÄR JOBBET KÖR, genom Account::currentPlan()->code
 * — aldrig en egen SQL-formulering av regeln. Att skriva av regeln i ett
 * CASE-uttryck vore en andra sanning om vad ett gratiskonto är (samma
 * invändning som issue 50b § Beslut 4 reser mot rapportens kopia), och den
 * skulle glida isär vid nästa ändring av prenumerationslogiken. En natt rör
 * ett fåtal konton, så uppslaget per konto kostar ingenting här.
 *
 * FRÅGORNA VÄXER MED ANTALET DAGAR, INTE MED ANTALET LOGGRADER (samma regel
 * som 26b § Beslut 5 och 50b § Beslut 13): en GROUP BY per källa och dag,
 * aldrig en fråga per rad.
 *
 * Schemaläggs med `Schedule::call(...)`, aldrig `Schedule::command(...)` — se
 * AGENTS.md § Driftmiljön saknar proc_open. Klassen är medvetet fri från
 * Artisan-beroenden av samma skäl som App\Console\PrunesRegistrationIps, så
 * att ingen av misstag schemalägger den som ett kommando.
 */
class AggregatesUsageMetrics
{
    /**
     * Tröskeln för en grupp. Under fem rader skrivs gruppen aldrig som sig
     * själv — se klassdocblocket. Får höjas men aldrig sänkas utan en ny ADR
     * ([[ADR-0043 Tre loggar]] § Mätningen).
     */
    public const MIN_GROUP_SIZE = 5;

    /**
     * Källan och tabellen den räknas ur. Ordningen är källornas.
     *
     * @var array<string, string>
     */
    private const SOURCES = [
        UsageMetric::SOURCE_AUDIT => 'audit_log',
        UsageMetric::SOURCE_SECURITY => 'security_log',
    ];

    /**
     * @return int Antal skrivna rader.
     */
    public function handle(): int
    {
        $skrivna = 0;

        foreach ($this->pendingSources() as [$dag, $källa]) {
            $grupper = $this->groups(self::SOURCES[$källa], $dag);

            if ($grupper === []) {
                continue;
            }

            $skrivna += $this->write($dag, $källa, $grupper);
        }

        return $skrivna;
    }

    /**
     * Det som väntar på att räknas: varje dag som har loggrader men ingen rad
     * i `usage_metric` — per källa, eftersom de två skrivs i var sin
     * transaktion. Gårdagen i normalfallet, och varje natt som missats
     * dessförinnan. Dagens rader räknas aldrig: dygnet ska vara slut först.
     *
     * @return list<array{0: string, 1: string}> [dag, källa] i stigande ordning
     */
    private function pendingSources(): array
    {
        $idag = now()->startOfDay();
        $väntande = [];

        foreach (self::SOURCES as $källa => $tabell) {
            $från = $this->firstUncountedDay($källa, $tabell);

            if ($från->gte($idag)) {
                continue;
            }

            $räknade = DB::table('usage_metric')
                ->where('source', $källa)
                ->pluck('date')
                ->mapWithKeys(fn ($dag): array => [Carbon::parse((string) $dag)->toDateString() => true])
                ->all();

            foreach ($this->loggedDays($tabell, $från) as $dag) {
                if (isset($räknade[$dag]) || ! Carbon::parse($dag)->lt($idag)) {
                    continue;
                }

                $väntande[] = [$dag, $källa];
            }
        }

        usort($väntande, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $väntande;
    }

    /**
     * Den första dag som kan behöva räknas för en källa: dagen efter den
     * senast räknade, eller loggens äldsta dag när ingenting räknats än. Se
     * klassdocblocket om varför gränsen är säker.
     */
    private function firstUncountedDay(string $källa, string $tabell): Carbon
    {
        $senastRäknad = DB::table('usage_metric')->where('source', $källa)->max('date');

        if ($senastRäknad !== null) {
            return Carbon::parse((string) $senastRäknad)->addDay()->startOfDay();
        }

        $äldsta = DB::table($tabell)->min('created_at');

        return $äldsta === null
            ? now()->startOfDay()
            : Carbon::parse((string) $äldsta)->startOfDay();
    }

    /**
     * De dygn som loggen har rader på, från och med $från — ett datum per rad,
     * aldrig ett dygn som bara passerat. Gränsen gör att frågan läser svansen
     * i stället för hela tabellen, via indexet på `created_at`.
     *
     * @return list<string>
     */
    private function loggedDays(string $tabell, Carbon $från): array
    {
        return DB::table($tabell)
            ->where('created_at', '>=', $från)
            ->selectRaw('DATE(created_at) as dag')
            ->distinct()
            ->pluck('dag')
            ->map(fn ($dag): string => Carbon::parse((string) $dag)->toDateString())
            ->all();
    }

    /**
     * Dagens rader i en logg, grupperade på handling och konto — och därefter
     * på kontots plan. Konton slås upp i en fråga för hela dagen, inte en per
     * rad.
     *
     * @return array<string, array<string, int>> action => plan => antal
     */
    private function groups(string $tabell, string $dag): array
    {
        $rader = DB::table($tabell)
            ->whereBetween('created_at', [$dag.' 00:00:00', $dag.' 23:59:59'])
            ->groupBy('action', 'account_id')
            ->selectRaw('action, account_id, COUNT(*) as antal')
            ->get();

        if ($rader->isEmpty()) {
            return [];
        }

        $planer = $this->plansByAccount(
            $rader->pluck('account_id')
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all()
        );

        $grupper = [];

        foreach ($rader as $rad) {
            $action = (string) $rad->action;
            $antal = (int) $rad->antal;
            $konto = $rad->account_id === null ? null : (int) $rad->account_id;

            // Raden saknar konto, eller kontot är raderat sedan länge: planen
            // går inte att slå upp och raden räknas som `unknown`.
            $plan = $konto === null
                ? UsageMetric::PLAN_UNKNOWN
                : ($planer[$konto] ?? UsageMetric::PLAN_UNKNOWN);

            $grupper[$action][$plan] = ($grupper[$action][$plan] ?? 0) + $antal;
        }

        return $grupper;
    }

    /**
     * Kontonas gällande plankod, via modellen och aldrig via en egen regel —
     * se klassdocblocket.
     *
     * @param  list<int>  $konton
     * @return array<int, string>
     */
    private function plansByAccount(array $konton): array
    {
        if ($konton === []) {
            return [];
        }

        return Account::query()
            ->with('subscription.plan')
            ->whereIn('id', $konton)
            ->get()
            ->mapWithKeys(fn (Account $konto): array => [$konto->id => $konto->currentPlan()->code])
            ->all();
    }

    /**
     * Tröskeln: grupper under MIN_GROUP_SIZE hamnar i `other`, och en
     * `other`-grupp som själv ligger under tröskeln hamnar i `unknown` över
     * planerna. Se klassdocblocket.
     *
     * `other` är ett öppet namnrum — en riktig handling kan heta `other` — så
     * varje skrivning till hinken SUMMERAR. Skulle en riktig `other`-grupp
     * redan stå på samma plan skrivs den inte över, den får de hopslagna
     * raderna tillagda.
     *
     * @param  array<string, array<string, int>>  $grupper
     * @return array<string, array<string, int>>
     */
    private function mergeSmallGroups(array $grupper): array
    {
        $ut = [];
        $other = [];

        foreach ($grupper as $action => $planer) {
            foreach ($planer as $plan => $antal) {
                if ($antal >= self::MIN_GROUP_SIZE) {
                    $ut[$action][$plan] = ($ut[$action][$plan] ?? 0) + $antal;

                    continue;
                }

                $other[$plan] = ($other[$plan] ?? 0) + $antal;
            }
        }

        foreach ($other as $plan => $antal) {
            if ($antal >= self::MIN_GROUP_SIZE) {
                $ut[UsageMetric::ACTION_OTHER][$plan] =
                    ($ut[UsageMetric::ACTION_OTHER][$plan] ?? 0) + $antal;

                continue;
            }

            $ut[UsageMetric::ACTION_OTHER][UsageMetric::PLAN_UNKNOWN] =
                ($ut[UsageMetric::ACTION_OTHER][UsageMetric::PLAN_UNKNOWN] ?? 0) + $antal;
        }

        return $ut;
    }

    /**
     * Skriver dagens mängd för en källa. Hela mängden byts i en transaktion —
     * en halvskriven dag vore en dag som varken är räknad eller oräknad.
     *
     * @param  array<string, array<string, int>>  $grupper
     * @return int Antal skrivna rader.
     */
    private function write(string $dag, string $källa, array $grupper): int
    {
        $nu = now();
        $rader = [];

        foreach ($this->mergeSmallGroups($grupper) as $action => $planer) {
            foreach ($planer as $plan => $antal) {
                $rader[] = [
                    'date' => $dag,
                    'source' => $källa,
                    'action' => $action,
                    'plan' => $plan,
                    'count' => $antal,
                    'created_at' => $nu,
                    'updated_at' => $nu,
                ];
            }
        }

        DB::transaction(function () use ($dag, $källa, $rader): void {
            DB::table('usage_metric')
                ->where('date', $dag)
                ->where('source', $källa)
                ->delete();

            DB::table('usage_metric')->insert($rader);
        });

        return count($rader);
    }
}
