<?php

namespace App\Console;

use App\Mail\PerformanceSummaryMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Veckosammanställningen av prestandaloggen — issue 390,
 * [[ADR-0079 Övervakning]] § 3 och 5.
 *
 * Läser veckans rader i loggkanalen `performance` (en fil per dag,
 * `performance-ÅÅÅÅ-MM-DD.log`, en JSON-rad per händelse) och mejlar till
 * `monitoring.ops_email`: de tio långsammaste rutterna efter median och
 * 95:e percentil, de tio vanligaste långsamma frågorna och antalet
 * förfrågningar över tröskeln jämfört med veckan före. Utan adress, eller
 * utan rader den här veckan, skickas inget.
 *
 * Percentilen är "nearest rank". Schemaläggs i routes/console.php med
 * `Schedule::call`, aldrig `Schedule::command` — se AGENTS.md § Subprocesser
 * i driftmiljön.
 */
class SummarizesPerformance
{
    public const TOP = 10;

    /**
     * @return bool Sant om ett mejl skickades.
     */
    public function handle(?string $directory = null): bool
    {
        $to = config('monitoring.ops_email');

        if (! is_string($to) || trim($to) === '') {
            return false;
        }

        $now = now();
        $weekStart = $now->copy()->subDays(7);
        $previousStart = $now->copy()->subDays(14);

        $routes = [];
        $queries = [];
        $requestsThisWeek = 0;
        $requestsPreviousWeek = 0;
        $rows = 0;

        foreach ($this->records($directory ?? storage_path('logs'), $previousStart) as $record) {
            $context = $record['context'] ?? [];
            $at = Carbon::parse($record['datetime'] ?? 'now');
            $thisWeek = $at->gte($weekStart);
            $type = $context['type'] ?? null;

            if ($type === 'request') {
                if ($thisWeek) {
                    $requestsThisWeek++;
                    $routes[(string) ($context['route'] ?? 'unnamed')][] = (float) ($context['ms'] ?? 0);
                } else {
                    $requestsPreviousWeek++;
                }
            } elseif ($type === 'query' && $thisWeek) {
                $queries[(string) ($context['sql'] ?? '')][] = (float) ($context['ms'] ?? 0);
            }

            if ($thisWeek) {
                $rows++;
            }
        }

        if ($rows === 0) {
            return false;
        }

        Mail::to($to)->send(new PerformanceSummaryMail(
            routes: $this->slowestRoutes($routes),
            queries: $this->commonestQueries($queries),
            requestsThisWeek: $requestsThisWeek,
            requestsPreviousWeek: $requestsPreviousWeek,
        ));

        return true;
    }

    /**
     * @param  array<string, list<float>>  $routes
     * @return list<array{route: string, count: int, median: float, p95: float}>
     */
    public function slowestRoutes(array $routes): array
    {
        $stats = [];

        foreach ($routes as $route => $times) {
            sort($times);
            $stats[] = [
                'route' => (string) $route,
                'count' => count($times),
                'median' => self::percentile($times, 50),
                'p95' => self::percentile($times, 95),
            ];
        }

        usort($stats, fn ($a, $b) => [$b['median'], $b['p95']] <=> [$a['median'], $a['p95']]);

        return array_slice($stats, 0, self::TOP);
    }

    /**
     * @param  array<string, list<float>>  $queries
     * @return list<array{sql: string, count: int, max: float}>
     */
    private function commonestQueries(array $queries): array
    {
        $stats = [];

        foreach ($queries as $sql => $times) {
            $stats[] = ['sql' => (string) $sql, 'count' => count($times), 'max' => max($times)];
        }

        usort($stats, fn ($a, $b) => [$b['count'], $b['max']] <=> [$a['count'], $a['max']]);

        return array_slice($stats, 0, self::TOP);
    }

    /**
     * Nearest rank över en redan sorterad lista.
     *
     * @param  list<float>  $sorted
     */
    public static function percentile(array $sorted, int $percent): float
    {
        $n = count($sorted);

        if ($n === 0) {
            return 0.0;
        }

        if ($percent === 50) {
            $mid = intdiv($n, 2);

            return $n % 2 === 1 ? $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2;
        }

        return $sorted[max(0, intdiv($percent * $n + 99, 100) - 1)];
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function records(string $directory, Carbon $since): \Generator
    {
        $files = glob(rtrim($directory, '/').'/performance-*.log') ?: [];
        $firstDay = $since->copy()->startOfDay()->format('Y-m-d');

        foreach ($files as $file) {
            if (! preg_match('/performance-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m) || $m[1] < $firstDay) {
                continue;
            }

            $handle = fopen($file, 'r');

            if ($handle === false) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $record = json_decode($line, true);

                if (is_array($record) && Carbon::parse($record['datetime'] ?? 'now')->gte($since)) {
                    yield $record;
                }
            }

            fclose($handle);
        }
    }
}
