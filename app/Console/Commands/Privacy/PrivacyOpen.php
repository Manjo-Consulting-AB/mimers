<?php

namespace App\Console\Commands\Privacy;

use App\Actions\Privacy\OpenPrivacyRequest;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * `privacy:open` — registrera en begäran från en registrerad och skriv ut
 * referensen och fristen. Se [[ADR-0095 Integritetsbegäranden]] § 1, § 2 och
 * § 5.
 *
 * Kommandot körs för hand över SSH av den som sköter ärendena, och
 * schemaläggs aldrig. Åtkomsten till ärendena är åtkomsten till servern
 * (ADR-0095 § 1): ingen rutt, inget API och ingen vy.
 *
 * **Kommandot letar inte upp en person ur e-postadressen.** Kopplingen till
 * konto och person görs av handläggaren med `--account` och `--user` när
 * identiteten är prövad (ADR-0095 § 2), och en ULID som inte finns är ett
 * fel — inte en ny rad.
 *
 * Logiken bor i App\Actions\Privacy\OpenPrivacyRequest, som `/contact` ska
 * kunna anropa senare; det här är kommandoytan.
 */
class PrivacyOpen extends Command
{
    protected $signature = 'privacy:open
                            {type : Typen av begäran, t.ex. access}
                            {--name= : Den registrerades namn}
                            {--email= : Den registrerades e-postadress}
                            {--description= : Vad personen begär, med egna ord}
                            {--by= : Handläggarens namn}
                            {--received= : Mottagningsdagen i formen Y-m-d, förval i dag}
                            {--on-behalf-of= : Huvudmannen när ett ombud begär}
                            {--account= : Kontots ULID, sätts när identiteten prövats}
                            {--user= : Personens ULID, sätts när identiteten prövats}';

    protected $description = 'Öppna ett ärende om en begäran från en registrerad.';

    public function handle(OpenPrivacyRequest $open): int
    {
        // --by är obligatoriskt på varje privacy-kommando: händelsen bär
        // handläggarens namn, och en händelse utan avsändare är inget bevis
        // (ADR-0095 § 1).
        $by = $this->text('by');

        if ($by === null) {
            $this->error('--by måste anges: handläggarens namn.');

            return self::FAILURE;
        }

        // Bara namn, e-postadress och beskrivning krävs (ADR-0095 § 2).
        $name = $this->text('name');
        $email = $this->text('email');
        $description = $this->text('description');

        if ($name === null || $email === null || $description === null) {
            $this->error('--name, --email och --description måste anges.');

            return self::FAILURE;
        }

        try {
            $received = $this->receivedAt();
        } catch (InvalidFormatException) {
            $this->error('--received måste vara ett datum i formen Y-m-d.');

            return self::FAILURE;
        }

        try {
            $request = $open->handle(
                type: (string) $this->argument('type'),
                requesterName: $name,
                requesterEmail: $email,
                description: $description,
                actor: $by,
                receivedAt: $received,
                onBehalfOf: $this->text('on-behalf-of'),
                accountUlid: $this->text('account'),
                userUlid: $this->text('user'),
            );
        } catch (InvalidArgumentException $e) {
            // Okänd typ och en mottagningsdag i framtiden. Ingenting har
            // skrivits när actionen kastar.
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
        $this->line('Frist: '.$request->deadline_at->format('Y-m-d'));

        return self::SUCCESS;
    }

    /**
     * `--received` som mottagningsdagen, eller null när det inte angetts —
     * actionen förvalar då till i dag.
     *
     * Formatet prövas genom att tolka och skriva tillbaka: PHP accepterar
     * `2026-02-30` som 2 mars och `2026-13-01` som 1 januari nästa år, och
     * en tyst omräkning av mottagningsdagen hade flyttat fristen utan att
     * någon märkte det.
     *
     * @throws InvalidFormatException
     */
    private function receivedAt(): ?Carbon
    {
        $varde = $this->text('received');

        if ($varde === null) {
            return null;
        }

        $datum = Carbon::createFromFormat('Y-m-d', $varde);

        if ($datum->format('Y-m-d') !== $varde) {
            throw new InvalidFormatException("Ogiltigt datum: {$varde}.");
        }

        return $datum;
    }

    /**
     * Ett optionvärde som text, eller null när det inte angetts.
     *
     * `option()` ger `string|array|null` — Laravel tillåter `--flagga=a
     * --flagga=b` — och phpstan vill inte se en array där en sträng ska
     * tolkas. Samma hjälpare som VoucherCreate använder.
     */
    private function text(string $name): ?string
    {
        $varde = $this->option($name);

        return is_string($varde) && $varde !== '' ? $varde : null;
    }
}
