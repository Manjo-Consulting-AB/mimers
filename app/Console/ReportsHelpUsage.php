<?php

namespace App\Console;

use App\Actions\Help\CountHelpEvent;
use App\Models\HelpFeedback;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Den nattliga hjälprapporten — M82 · issue 472,
 * [[ADR-0094 Hjälpcentret]] § 9, [[ADR-0079 Övervakning]] § 4 och
 * [[ADR-0017 Missbruksvektorer]] § Beslut. Jobbet läser hjälpens anonyma
 * mätning och skriver en enda loggrad, `help.report`, på nivån `info`.
 *
 * **Ingen adminsida.** Det finns ingen adminroll (ADR-0094 § 9), så rapporten
 * är loggen och `php artisan help:feedback` är fritexterna. Det här jobbet
 * larmar inte, spärrar ingenting och skriver ingenting i databasen. Precis
 * som App\Console\ReportsAbuseSignals läser det tabeller andra milstolpar
 * äger och rör dem aldrig: en INSERT, UPDATE eller DELETE här vore en andra
 * avstämning med en egen uppfattning om sanningen. Rapportens enda skrivyta
 * är loggen.
 *
 * **Sökfrågor syns först vid fem.** Tröskeln är ADR-0079 § 4:s och
 * ADR-0094 § 9:s och står i config/help.php § report_search_gap_min. En fråga
 * under tröskeln syns inte alls — inte ens som antal — så rapporten kan inte
 * användas för att läsa ut en enskild sökning som bara gjorts en gång.
 * Sammanhangen är kod och inte användartext och har därför ingen tröskel.
 *
 * **Fönstret är rullande.** `report_window_days` dygn bakåt, samma form som
 * missbruksrapportens fönster: jobbet körs varje natt och talen är rullande.
 * Dagsraderna jämförs på `date`, återkopplingen på `created_at`.
 */
class ReportsHelpUsage
{
    /**
     * Räknar fram rapporten, skriver den till loggen och returnerar den.
     *
     * @return array{
     *     views_total: int,
     *     searches_total: int,
     *     search_clicks_total: int,
     *     click_through: float|null,
     *     top_articles: list<array{id: string, views: int}>,
     *     search_gaps: list<array{query: string, count: int}>,
     *     unhelpful: list<array{id: string, answers: int, unhelpful: int, share: float}>,
     *     reasons: array<string, int>,
     *     contexts: array<string, int>,
     *     unknown_contexts: array<string, int>,
     *     new_comments: int,
     * }
     */
    public function handle(): array
    {
        $windowDays = (int) config('help.report_window_days');
        $since = Carbon::now()->subDays($windowDays);
        $sinceDate = $since->toDateString();

        $views = $this->eventTotal(CountHelpEvent::ARTICLE_VIEW, $sinceDate);
        $searches = $this->eventTotal(CountHelpEvent::SEARCH, $sinceDate);
        $clicks = $this->eventTotal(CountHelpEvent::SEARCH_CLICK, $sinceDate);

        $rapport = [
            'views_total' => $views,
            'searches_total' => $searches,
            'search_clicks_total' => $clicks,
            'click_through' => $searches > 0 ? round($clicks / $searches, 2) : null,
            'top_articles' => $this->topArticles($sinceDate),
            'search_gaps' => $this->searchGaps($sinceDate),
            'unhelpful' => $this->unhelpful($since),
            'reasons' => $this->reasons($since),
            'contexts' => $this->contexts(CountHelpEvent::CONTEXT_OPEN, $sinceDate),
            'unknown_contexts' => $this->contexts(CountHelpEvent::CONTEXT_UNKNOWN, $sinceDate),
            'new_comments' => $this->newComments($since),
        ];

        Log::info('help.report', $rapport);

        return $rapport;
    }

    /** Summan av en händelse i `help_event_daily` inom fönstret. */
    private function eventTotal(string $event, string $sinceDate): int
    {
        return (int) DB::table('help_event_daily')
            ->where('date', '>=', $sinceDate)
            ->where('event', $event)
            ->sum('count');
    }

    /**
     * De mest visade artiklarna, fallande. `views` är summan av dagens
     * räknare, inte antalet rader.
     *
     * @return list<array{id: string, views: int}>
     */
    private function topArticles(string $sinceDate): array
    {
        return DB::table('help_event_daily')
            ->where('date', '>=', $sinceDate)
            ->where('event', CountHelpEvent::ARTICLE_VIEW)
            ->where('article_id', '<>', '')
            ->selectRaw('article_id, SUM(count) as views')
            ->groupBy('article_id')
            ->orderByDesc('views')
            ->orderBy('article_id')
            ->limit((int) config('help.report_top_articles'))
            ->get()
            ->map(fn ($rad): array => [
                'id' => (string) $rad->article_id,
                'views' => (int) $rad->views,
            ])
            ->all();
    }

    /**
     * Sökfrågorna utan träff som nått tröskeln, fallande. Frågor under
     * tröskeln utelämnas helt — inte som nollställd rad.
     *
     * @return list<array{query: string, count: int}>
     */
    private function searchGaps(string $sinceDate): array
    {
        return DB::table('help_search_gap_daily')
            ->where('date', '>=', $sinceDate)
            ->selectRaw('query, SUM(count) as total')
            ->groupBy('query')
            ->havingRaw('SUM(count) >= ?', [(int) config('help.report_search_gap_min')])
            ->orderByDesc('total')
            ->orderBy('query')
            ->limit((int) config('help.report_search_gaps_limit'))
            ->get()
            ->map(fn ($rad): array => [
                'query' => (string) $rad->query,
                'count' => (int) $rad->total,
            ])
            ->all();
    }

    /**
     * Artiklarna med flest Nej, fallande andel. Andelen räknas mot artikelns
     * samtliga svar i fönstret, och en artikel med färre än
     * `report_unhelpful_min` svar utelämnas. Delningen tvingas till flyttal
     * med `* 1.0` så att sqlite och mysql sorterar likadant — annars hade
     * sqlite gjort heltalsdivision.
     *
     * @return list<array{id: string, answers: int, unhelpful: int, share: float}>
     */
    private function unhelpful(Carbon $since): array
    {
        return DB::table('help_feedback')
            ->where('created_at', '>=', $since)
            ->selectRaw('article_id, COUNT(*) as answers, SUM(CASE WHEN helpful = 0 THEN 1 ELSE 0 END) as unhelpful')
            ->groupBy('article_id')
            ->havingRaw('COUNT(*) >= ?', [(int) config('help.report_unhelpful_min')])
            ->orderByRaw('SUM(CASE WHEN helpful = 0 THEN 1 ELSE 0 END) * 1.0 / COUNT(*) DESC')
            ->orderByDesc('answers')
            ->orderBy('article_id')
            ->limit((int) config('help.report_unhelpful_limit'))
            ->get()
            ->map(fn ($rad): array => [
                'id' => (string) $rad->article_id,
                'answers' => (int) $rad->answers,
                'unhelpful' => (int) $rad->unhelpful,
                'share' => round((int) $rad->unhelpful / (int) $rad->answers, 2),
            ])
            ->all();
    }

    /**
     * Antal Nej per orsak, med alla orsaker i HelpFeedback::REASONS med —
     * noll när ingen valt den. En fast form gör raden lätt att jämföra mellan
     * nätter.
     *
     * @return array<string, int>
     */
    private function reasons(Carbon $since): array
    {
        $antal = array_fill_keys(HelpFeedback::REASONS, 0);

        $rader = DB::table('help_feedback')
            ->where('created_at', '>=', $since)
            ->whereNotNull('reason')
            ->selectRaw('reason, COUNT(*) as total')
            ->groupBy('reason')
            ->get();

        foreach ($rader as $rad) {
            $antal[(string) $rad->reason] = (int) $rad->total;
        }

        return $antal;
    }

    /**
     * Antal per sammanhang för en händelsetyp. Ingen tröskel och inget tak:
     * sammanhanget är en rutt eller en kod, inte användartext.
     *
     * @return array<string, int>
     */
    private function contexts(string $event, string $sinceDate): array
    {
        $rader = DB::table('help_event_daily')
            ->where('date', '>=', $sinceDate)
            ->where('event', $event)
            ->where('context', '<>', '')
            ->selectRaw('context, SUM(count) as total')
            ->groupBy('context')
            ->orderByDesc('total')
            ->orderBy('context')
            ->get();

        $antal = [];

        foreach ($rader as $rad) {
            $antal[(string) $rad->context] = (int) $rad->total;
        }

        return $antal;
    }

    /** Antalet nya kommentarer — en rad med fritext, inte varje Nej. */
    private function newComments(Carbon $since): int
    {
        return DB::table('help_feedback')
            ->where('created_at', '>=', $since)
            ->whereNotNull('comment')
            ->where('comment', '<>', '')
            ->count();
    }
}
