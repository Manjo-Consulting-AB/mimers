<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * `help:feedback` — visar fritexterna från *Was this helpful?* som en tabell,
 * M82 · issue 472 och [[ADR-0094 Hjälpcentret]] § 9. Kommandot är rapportens
 * andra halva: den nattliga rapporten (App\Console\ReportsHelpUsage) räknar
 * talen, det här visar kommentarerna. Det finns ingen adminsida, så Tony kör
 * kommandot över ssh. Det är inte schemalagt.
 *
 * **Ingen e-post, inget namn och inget användar-id.** Frågan väljer bara
 * `created_at`, `article_id`, `reason` och `comment`; raden kan ägas av en
 * användare (`user_id`), men ägaren lämnas utanför. Kommentaren är frivillig
 * och bara en inloggad kan skriva den, så en rad utan kommentar visas inte
 * alls — det är fritexterna kommandot finns för.
 *
 * `--days` är fönstret bakåt och `--article` begränsar till en artikel.
 * Kommandot ÄNDRAR INGENTING: det finns ingen INSERT, UPDATE eller DELETE
 * här.
 */
class HelpFeedbackCommand extends Command
{
    protected $signature = 'help:feedback
        {--days=7 : Antal dygn bakåt, räknat från nu}
        {--article= : Endast den här artikelns kommentarer}';

    protected $description = 'Visa kommentarerna från hjälpens Was this helpful? — ändrar ingenting.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $article = $this->option('article');
        $since = Carbon::now()->subDays($days);

        $fraga = DB::table('help_feedback')
            ->where('created_at', '>=', $since)
            ->whereNotNull('comment')
            ->where('comment', '<>', '')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (is_string($article) && $article !== '') {
            $fraga->where('article_id', $article);
        }

        $rader = $fraga->get(['created_at', 'article_id', 'reason', 'comment']);

        if ($rader->isEmpty()) {
            $this->line("Inga kommentarer under de senaste {$days} dygnen.");

            return self::SUCCESS;
        }

        $tabell = $rader->map(fn ($rad): array => [
            Carbon::parse((string) $rad->created_at)->toDateString(),
            (string) $rad->article_id,
            $rad->reason === null ? '—' : (string) $rad->reason,
            (string) $rad->comment,
        ])->all();

        $this->table(['datum', 'artikel', 'orsak', 'kommentar'], $tabell);

        return self::SUCCESS;
    }
}
