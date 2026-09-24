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
 * för samma dag, källa och plan. Når den gruppen fem skrivs den på sin egen
 * plan. Gör den inte det slås den ihop över planerna, och den sammanslagna
 * raden skrivs med planen `mixed` — den hör inte till någon enskild plan.
 * `unknown` betyder något annat: att kontot inte längre finns (se
 * UsageMetric::PLAN_UNKNOWN). "Planen är okänd" och "flera planer i
 * samma rad" är två olika fakta, och blandas de blir andelen aktivitet från
 * raderade konton omätbar.
 *
 * TRÖSKELN GÄLLER VARJE RAD SOM SKRIVS, också den sammanslagna. Når planerna
 * inte fem tillsammans kastas raden i stället för att skrivas — annars vore en
 * liten sammanslagen grupp vägen runt tröskeln. Att upp till fyra händelser per
 * dag och källa går förlorade är en medveten kostnad; sammanslagningen räddar
 * de fall där flera små planers `other` tillsammans når fem. Tröskeln är en
 * konstant: den får höjas men aldrig sänkas utan en ny ADR.
 *
 * DÄRFÖR RÄKNAS DYGNET OM, INTE BARA DET SOM SAKNAS. En dag som gav noll rader
 * — allt under tröskeln — går inte att skilja från en dag som aldrig räknats
 * om man ser till tabellens innehåll. Jobbet håller i stället en
 * HÖGVATTENMÄRKESNIVÅ per källa: det räknar varje dag från max(date) + 1 fram
 * till och med gårdagen. Är tabellen tom för källan börjar det på loggens
 * äldsta dag. En dag som gav noll rader ligger därmed under märket så fort en
 * senare dag skrivits och räknas inte om; blir gårdagen tom räknas den om
 * nästa natt, vilket är ofarligt eftersom omräkningen är idempotent. Det
 * behövs ingen markör och ingen extra tabell.
 *
 * EN MISSAD NATT RÄKNAS I EFTERHAND — högvattenmärket går från den senast
 * skrivna dagen, inte från i går — så länge raderna finns kvar. Det är därför
 * jobbet måste köra före gallringen i issue 115: det som gallras innan det
 * räknats är borta ur mätningen för alltid. Schemaläggs i routes/console.php,
 * före `drain-queue`.
 *
 * IDEMPOTENT. Varje dag räknas om från grunden: jobbet raderar dagens rader för
 * källan och skriver dem på nytt i samma transaktion, i stället för att lägga
 * till. Två körningar för samma dag ger därför samma rader, inte dubbla, och
 * det unika indexet på (date, source, action, plan) är skyddsnätet under det.
 * Enheten är paret (dag, källa), inte dagen: de två källorna skrivs i var sin
 * transaktion, och en körning som faller mellan dem får inte lämna den ena
 * källan oräknad för alltid — nästa körning ser att just den källan står kvar
 * under märket och räknar om den, utan att röra den andra källans rader.
 *
 * DAGENS RADER RÄKNAS ALDRIG: dygnet ska vara slut först. Frågan mot loggen
 * läser då bara den dag som ska räknas, via indexet på `created_at`
 * (migreringen 2026_09_24_020000).
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
     * Planen för en `other`-grupp som slagits ihop över planerna: raden hör
     * inte till någon enskild plan. Den får en egen konstant och inte
     * `unknown`, som betyder att kontot inte längre finns — se
     * klassdocblocket.
     */
    public const PLAN_MIXED = 'mixed';

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

        foreach (self::SOURCES as $källa => $tabell) {
            foreach ($this->daysToCount($källa, $tabell) as $dag) {
                $skrivna += $this->write($dag, $källa, $this->groups($tabell, $dag));
            }
        }

        return $skrivna;
    }

    /**
     * Dygnen som ska räknas för en källa: från dagen efter den senast skrivna
     * fram till och med gårdagen. Per källa, eftersom de två skrivs i var sin
     * transaktion. En dag utan rader räknas också — den skriver noll rader, och
     * det är så en dag som föll under tröskeln skiljs från en oräknad dag.
     *
     * @return list<string>
     */
    private function daysToCount(string $källa, string $tabell): array
    {
        $idag = now()->startOfDay();
        $dagar = [];

        for ($dag = $this->firstUncountedDay($källa, $tabell); $dag->lt($idag); $dag->addDay()) {
            $dagar[] = $dag->toDateString();
        }

        return $dagar;
    }

    /**
     * Högvattenmärket: dagen efter den senast skrivna raden för källan, eller
     * loggens äldsta dag när `usage_metric` är tom för den. Se
     * klassdocblocket om varför märket, och inte tabellens innehåll, avgör
     * vilka dygn som räknas.
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
     * Tröskeln, i två steg. Grupper under MIN_GROUP_SIZE hamnar i `other` på
     * sin egen plan; når den gruppen fem skrivs den. Gör den inte det slås
     * planernas `other`-hinkar ihop, och den sammanslagna raden skrivs med
     * PLAN_MIXED — men bara om den når MIN_GROUP_SIZE. Är den fortfarande
     * under fem kastas den: tröskeln gäller varje rad som skrivs. En
     * `unknown`-plans hink ingår i sammanslagningen som vilken plan som helst.
     * Se klassdocblocket.
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
        $mixed = 0;

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

            $mixed += $antal;
        }

        if ($mixed >= self::MIN_GROUP_SIZE) {
            $ut[UsageMetric::ACTION_OTHER][self::PLAN_MIXED] =
                ($ut[UsageMetric::ACTION_OTHER][self::PLAN_MIXED] ?? 0) + $mixed;
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
