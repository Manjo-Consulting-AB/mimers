<?php

namespace App\Console\Commands;

use App\Models\Voucher;
use Illuminate\Console\Command;

/**
 * `voucher:list` — lista vouchrarna, se [[ADR-0055 Inbjudningskoder och
 * stängd registrering]] § 7.
 *
 * **Koden visas aldrig.** Den finns bara som `code_hash` i databasen, och
 * den här listan visar `ulid` i stället — det är raden man återkallar eller
 * letar rätt på, inte hemligheten. En lista som skrev ut koden skulle göra
 * en terminalhistorik eller en loggad utskrift till en uppsättning
 * inlösbara koder.
 *
 * Kolumnerna är de ADR-0055 § 7 räknar upp: etikett, plan, dagar, uttag och
 * utgång — plus om koden är återkallad, eftersom `revoked_at` inte syns i
 * någon av de andra.
 */
class VoucherList extends Command
{
    protected $signature = 'voucher:list';

    protected $description = 'Lista inbjudningskoder. Koden visas aldrig.';

    public function handle(): int
    {
        $vouchers = Voucher::query()->with('plan')->orderBy('id')->get();

        if ($vouchers->isEmpty()) {
            $this->line('Inga vouchrar.');

            return self::SUCCESS;
        }

        $this->table(
            ['ulid', 'etikett', 'plan', 'dagar', 'uttag', 'utgår', 'återkallad'],
            $vouchers->map(fn (Voucher $voucher): array => [
                $voucher->ulid,
                $voucher->label ?? '—',
                $voucher->plan->code,
                (string) $voucher->duration_days,
                $voucher->used_count.'/'.$voucher->max_uses,
                $voucher->expires_at?->toDateString() ?? '—',
                $voucher->revoked_at === null ? 'nej' : 'ja',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
