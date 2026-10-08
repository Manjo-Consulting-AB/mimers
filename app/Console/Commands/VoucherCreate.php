<?php

namespace App\Console\Commands;

use App\Actions\Voucher\GenerateVoucher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * `voucher:create` — skapa en inbjudningskod och skriv ut den, se
 * [[ADR-0055 Inbjudningskoder och stängd registrering]] § 7.
 *
 * Kommandot körs för hand över SSH av Tony när en testare ska bjudas in, och
 * schemaläggs aldrig: det är därför det inte berörs av att `proc_open`
 * saknas hos inleed (AGENTS.md § Driftmiljön saknar proc_open).
 *
 * Koden skrivs ut EN gång. Den finns bara som hash i databasen efteråt
 * (ADR-0055 § 4 och § 5), så en tappad kod går inte att läsa fram ur
 * `voucher:list` — då skapas en ny i stället.
 *
 * Förvalen är betans (ADR-0055 § 7): Pro, 365 dagar, ett uttag och
 * `grants_registration`. `--no-registration` tar bort det sista: koden ger
 * Pro men släpper inte in någon ny i betan.
 *
 * Logiken bor i App\Actions\Voucher\GenerateVoucher, testad direkt; det här
 * är kommandoytan.
 */
class VoucherCreate extends Command
{
    protected $signature = 'voucher:create
                            {--label= : Vem eller vad koden ges till}
                            {--plan=pro : Planens kod}
                            {--days=365 : Hur länge planen gäller från inlösen}
                            {--uses=1 : Hur många konton som får lösa in koden}
                            {--expires= : Sista dag koden kan lösas in (Y-m-d)}
                            {--no-registration : Koden släpper inte in i läget invite_only}';

    protected $description = 'Skapa en inbjudningskod. Koden visas en gång.';

    public function handle(GenerateVoucher $generate): int
    {
        $expires = $this->text('expires');

        $resultat = $generate->handle(
            $this->text('plan') ?? 'pro',
            (int) $this->text('days'),
            (int) $this->text('uses'),
            ! $this->option('no-registration'),
            $this->text('label'),
            $expires === null ? null : Carbon::parse($expires)->endOfDay(),
        );

        $this->line($resultat['code']);

        return self::SUCCESS;
    }

    /**
     * Ett optionvärde som text, eller null när det inte angetts.
     *
     * `option()` ger `string|array|null` — Laravel tillåter `--flagga=a
     * --flagga=b` — och phpstan vill inte se en array där en sträng ska
     * tolkas. Ett listvärde på ett av de här valen är ett skrivfel, och
     * `(string)` på en array vore en tyst "Array".
     */
    private function text(string $name): ?string
    {
        $varde = $this->option($name);

        return is_string($varde) && $varde !== '' ? $varde : null;
    }
}
