<?php

namespace App\Console\Commands;

use App\Models\Voucher;
use Illuminate\Console\Command;

/**
 * `voucher:revoke {code}` — återkalla en inbjudningskod, se [[ADR-0055
 * Inbjudningskoder och stängd registrering]] § 7.
 *
 * Återkallandet sätter `revoked_at`; raden raderas aldrig. Koden går
 * därefter inte att lösa in (Voucher::isRedeemable()) och inte att använda
 * för att registrera sig, men en redan skapad inlösen står kvar — historiken
 * över vem som kom in på den ändras inte av att koden dras tillbaka. Det är
 * skillnaden mot att koden förbrukats: samma spärr, olika berättelse.
 *
 * Koden läses ur argumentet och hashas för uppslaget; den skrivs aldrig ut.
 * Ett återkallande av en redan återkallad kod är inte ett fel — kommandot
 * säger till och avslutar grönt, så en upprepad körning i en skriptad
 * sekvens inte ser ut som ett haveri.
 */
class VoucherRevoke extends Command
{
    protected $signature = 'voucher:revoke {code : Koden som ska återkallas}';

    protected $description = 'Återkalla en inbjudningskod.';

    public function handle(): int
    {
        $code = $this->argumentText('code');

        $voucher = $code === null ? null : Voucher::findByCode($code);

        if ($voucher === null) {
            $this->error('Ingen voucher med den koden.');

            return self::FAILURE;
        }

        if ($voucher->revoked_at !== null) {
            $this->line('Koden är redan återkallad.');

            return self::SUCCESS;
        }

        $voucher->revoked_at = now();
        $voucher->save();

        $this->line('Koden är återkallad.');

        return self::SUCCESS;
    }

    /**
     * Argumentet som text, eller null.
     *
     * `argument()` ger `array|string|float|int|bool|null` — Laravel tillåter
     * listargument — och ett listvärde på det här argumentet är ett skrivfel,
     * inte en kod. Samma grepp som VoucherCreate::text(): nyckeln är en
     * variabel, så unionen står kvar och `(string)` på en array blir aldrig
     * en tyst "Array".
     */
    private function argumentText(string $name): ?string
    {
        $varde = $this->argument($name);

        return is_string($varde) ? $varde : null;
    }
}
