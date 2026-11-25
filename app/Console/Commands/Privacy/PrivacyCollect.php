<?php

namespace App\Console\Commands\Privacy;

use App\Actions\Privacy\CollectPrivacyData;
use App\Models\LegalHold;
use App\Models\PrivacyRequest;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `privacy:collect` — sammanställ de uppgifter som binds till personen på
 * ett ärende och lägg sammanställningen som en bilaga. Se [[ADR-0095
 * Integritetsbegäranden]] § 12 och [[Registerförteckning]].
 *
 * **Ingen sammanställning görs innan identiteten är prövad** (ADR-0095
 * § 2): ärendet måste vara kopplat till en person och bära en verifiering
 * med `subject: identity` och `result: verified`, annars vägrar kommandot
 * och ingen bilaga skrivs.
 *
 * **Den rättsliga spärren nämns aldrig i filen** ([[ADR-0043 Tre loggar]]
 * § Den rättsliga spärren). Kommandot varnar handläggaren på skärmen i
 * stället, som `privacy:show` gör — en spärr som syns i det som lämnas ut
 * kunde varna den som utreds.
 *
 * Logiken bor i App\Actions\Privacy\CollectPrivacyData.
 */
class PrivacyCollect extends Command
{
    protected $signature = 'privacy:collect
                            {reference : Ärendets referens, PR-XXXXXXXX}
                            {--by= : Handläggarens namn}';

    protected $description = 'Sammanställ personens uppgifter och lägg dem som en bilaga.';

    public function handle(CollectPrivacyData $collect): int
    {
        // --by är obligatoriskt på varje privacy-kommando: händelsen bär
        // handläggarens namn, och en händelse utan avsändare är inget bevis
        // (ADR-0095 § 1).
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
            $attachment = $collect->handle($request, $by);
        } catch (InvalidArgumentException $e) {
            // Personen saknas, identiteten är inte prövad eller innehållet
            // kunde inte kodas. Ingenting har skrivits när actionen kastar.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Varningen står på skärmen och aldrig i filen. Raden säger att
        // spärren finns, inte vilket ärende eller vilket skäl den gäller.
        if ($request->account !== null && LegalHold::covers($request->account)) {
            $this->line('Legal hold active');
        }

        $this->line("Bilaga: {$attachment->ulid}");
        $this->line("Namn: {$attachment->original_name}");
        $this->line("Storlek: {$attachment->size_bytes} byte");
        $this->line("SHA-256: {$attachment->sha256}");

        return self::SUCCESS;
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
