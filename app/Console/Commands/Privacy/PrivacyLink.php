<?php

namespace App\Console\Commands\Privacy;

use App\Actions\Privacy\LinkPrivacyRequest;
use App\Models\Account;
use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

/**
 * `privacy:link` — koppla ett ärende till ett konto och en person. Se
 * [[ADR-0095 Integritetsbegäranden]] § 1 och § 2.
 *
 * Kopplingen görs först när identiteten är prövad med `privacy:verify`, och
 * den skrivs som en händelse `linked` med de två ULID:erna. En ULID som inte
 * finns är ett fel — inte en ny rad — och ingenting skrivs då.
 *
 * Logiken bor i App\Actions\Privacy\LinkPrivacyRequest.
 */
class PrivacyLink extends Command
{
    protected $signature = 'privacy:link
                            {reference : Ärendets referens, PR-XXXXXXXX}
                            {--account= : Kontots ULID, sätts när identiteten prövats}
                            {--user= : Personens ULID, sätts när identiteten prövats}
                            {--by= : Handläggarens namn}';

    protected $description = 'Koppla ett integritetsärende till ett konto och en person.';

    public function handle(LinkPrivacyRequest $link): int
    {
        $by = $this->text('by');

        if ($by === null) {
            $this->error('--by måste anges: handläggarens namn.');

            return self::FAILURE;
        }

        $reference = (string) $this->argument('reference');

        $request = PrivacyRequest::query()
            ->where('reference', $reference)
            ->first();

        if ($request === null) {
            $this->error("Inget ärende med referensen {$reference}.");

            return self::FAILURE;
        }

        try {
            $link->handle(
                request: $request,
                accountUlid: $this->text('account'),
                userUlid: $this->text('user'),
                actor: $by,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (ModelNotFoundException) {
            // Actionen slår upp ULID:erna med firstOrFail(); en felstavad
            // eller okänd identifierare ska bli ett felmeddelande, inte ett
            // stacktrace — och ingenting har skrivits.
            $this->error('--account och --user måste vara ULID:er som finns.');

            return self::FAILURE;
        }

        $this->line("Referens: {$request->reference}");

        // ULID:erna och inte de interna id:na: det är de som står i den
        // `linked`-händelse som just skrevs. Uppslaget kan ge null när
        // kontot eller personen raderats sedan kopplingen gjordes —
        // kolumnerna saknar främmande nycklar med flit.
        $konto = $request->account_id === null
            ? null
            : Account::query()->find($request->account_id);

        $person = $request->user_id === null
            ? null
            : User::query()->find($request->user_id);

        $this->line('Konto: '.($konto->ulid ?? '—'));
        $this->line('Person: '.($person->ulid ?? '—'));

        return self::SUCCESS;
    }

    /**
     * Ett optionvärde som text, eller null när det inte angetts. Samma
     * hjälpare som PrivacyOpen och PrivacyShow använder.
     */
    private function text(string $name): ?string
    {
        $varde = $this->option($name);

        return is_string($varde) && $varde !== '' ? $varde : null;
    }
}
