<?php

namespace App\Console\Commands\Privacy;

use App\Models\PrivacyRequest;
use Illuminate\Console\Command;

/**
 * `privacy:list` — de öppna ärendena med den närmaste fristen först. Se
 * [[ADR-0095 Integritetsbegäranden]] § 1, § 5 och § 6.
 *
 * **Listan är påminnelsen.** Det finns inget nattjobb, inget mejl och ingen
 * loggrad om fristen; den som sköter ärendena tittar här (ADR-0095 § 6).
 * Därför sorteras de öppna ärendena på frist och visar dagar kvar, eller
 * *overdue* när fristen passerat.
 *
 * **Listan visar aldrig namn, e-postadress eller beskrivning.** Den ska
 * kunna köras utan att något om den registrerade hamnar på skärmen; den som
 * behöver läsa ärendet gör det med `privacy:show`, och det är läsningen som
 * loggas (ADR-0095 § 3). Listan skriver därför ingen händelse.
 *
 * `--all` tar med de avslutade ärendena, och `--status=` visar bara en
 * status — också en avslutad sådan, utan att `--all` behövs.
 */
class PrivacyList extends Command
{
    protected $signature = 'privacy:list
                            {--all : Ta med de avslutade ärendena}
                            {--status= : Visa bara ärenden med den statusen}';

    protected $description = 'Visa integritetsärendena med den närmaste fristen först.';

    public function handle(): int
    {
        $status = $this->text('status');

        if ($status !== null && ! in_array($status, (array) config('privacy.statuses'), true)) {
            $this->error("Okänd status: {$status}.");

            return self::FAILURE;
        }

        $query = PrivacyRequest::query();

        if ($status !== null) {
            // En uttrycklig status går före --all: den som frågar efter
            // `completed` vill se dem, oavsett om de räknas som avslutade.
            $query->where('status', $status);
        } elseif (! $this->option('all')) {
            $query->whereIn('status', (array) config('privacy.open_statuses'));
        }

        $ärenden = $query
            ->orderBy('deadline_at')
            ->orderBy('id')
            ->get();

        if ($ärenden->isEmpty()) {
            $this->line('Inga ärenden.');

            return self::SUCCESS;
        }

        $this->table(
            ['Referens', 'Typ', 'Status', 'Prioritet', 'Handläggare', 'Frist', 'Dagar kvar'],
            $ärenden->map(fn (PrivacyRequest $ärende): array => [
                $ärende->reference,
                $ärende->type,
                $ärende->status,
                $ärende->priority,
                $ärende->assignee ?? '—',
                $ärende->deadline_at->format('Y-m-d'),
                $this->daysLeft($ärende),
            ])->all(),
        );

        return self::SUCCESS;
    }

    /**
     * Dagar kvar till fristen, eller `overdue N days` när den passerat.
     * Kalenderdagar: fristen är en dag, inte en tidpunkt, och en frist i dag
     * ger 0.
     */
    private function daysLeft(PrivacyRequest $ärende): string
    {
        $dagar = (int) today()->diffInDays($ärende->deadline_at->copy()->startOfDay(), false);

        return $dagar < 0
            ? 'overdue '.abs($dagar).' days'
            : (string) $dagar;
    }

    /**
     * Ett optionvärde som text, eller null när det inte angetts. Samma
     * hjälpare som de andra privacy-kommandona använder.
     */
    private function text(string $name): ?string
    {
        $varde = $this->option($name);

        return is_string($varde) && $varde !== '' ? $varde : null;
    }
}
