<?php

namespace App\Console\Commands;

use App\Actions\Voucher\GenerateVoucher;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        // Värdena prövas innan något skrivs. Ett `(int)` på `--days=abc` blir
        // 0, och en voucher som aldrig går att lösa in (eller ger noll dagar)
        // är ett värre svar än ett felmeddelande: koden skrivs ju ut och
        // lämnas till en testare. Negativa tal avvisas av kolumnens
        // unsignedInteger med ett databasundantag i stället.
        $days = $this->positiveInteger('days');

        if ($days === null) {
            $this->error('--days måste vara ett heltal större än 0.');

            return self::FAILURE;
        }

        $uses = $this->positiveInteger('uses');

        if ($uses === null) {
            $this->error('--uses måste vara ett heltal större än 0.');

            return self::FAILURE;
        }

        try {
            $expires = $this->expiresAt();
        } catch (InvalidFormatException) {
            $this->error('--expires måste vara ett datum i formen Y-m-d.');

            return self::FAILURE;
        }

        try {
            $resultat = $generate->handle(
                $this->text('plan') ?? 'pro',
                $days,
                $uses,
                ! $this->option('no-registration'),
                $this->text('label'),
                $expires,
            );
        } catch (ModelNotFoundException) {
            // GenerateVoucher slår upp planen med firstOrFail(); en felstavad
            // --plan ska bli ett felmeddelande, inte ett stacktrace.
            $this->error('Ingen plan med den koden.');

            return self::FAILURE;
        }

        $this->line($resultat['code']);

        return self::SUCCESS;
    }

    /**
     * `--expires` som dagens slut, eller null när det inte angetts. Kastar
     * InvalidFormatException på ett datum som inte går att tolka.
     */
    private function expiresAt(): ?Carbon
    {
        $expires = $this->text('expires');

        return $expires === null ? null : Carbon::parse($expires)->endOfDay();
    }

    /**
     * Ett optionvärde som ett heltal >= 1, eller null när det inte är ett
     * sådant. `ctype_digit` och inte `(int)`: "abc" och "-5" ska ge null, och
     * "0" ska falla på gränsen, inte tolkas som ett tomt värde.
     */
    private function positiveInteger(string $name): ?int
    {
        $varde = $this->text($name);

        if ($varde === null || ! ctype_digit($varde)) {
            return null;
        }

        $tal = (int) $varde;

        return $tal >= 1 ? $tal : null;
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
