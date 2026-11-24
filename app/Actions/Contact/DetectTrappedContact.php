<?php

namespace App\Actions\Contact;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Skräpskyddet på kontaktformuläret — M89 ·
 * [[ADR-0096 Kontaktformuläret]] § 6.
 *
 * **En träff ger samma svar som ett riktigt ärende.** Kontrollern skickar
 * aldrig mejlet när `handle()` svarar med ett skäl, men svarar ändå med
 * bekräftelsen — en bot ska inte kunna skilja sin egen träff från en
 * levererad inskickning.
 *
 * Tre träffar räknas:
 *
 *  - **Honeypot.** Fältet `website` ska vara tomt. Människor ser det inte,
 *    men en bot som fyller varje fält gör det.
 *  - **Trasig tidsstämpel.** `rendered_at` kommer från servern, krypterad
 *    med `Crypt::encryptString`, och ett värde som inte går att dekryptera
 *    är antingen förfalskat eller stulet ur en gammal sida.
 *  - **För snabb inskickning.** Mindre än tre sekunder efter att sidan
 *    ritades är snabbare än någon som läser formuläret hinner.
 *
 * Skälet returneras som en kort kod (`honeypot`, `rendered_at`, `too_fast`)
 * och hamnar i loggraden `contact.trapped`. Meddelandet, namnet och adressen
 * loggas aldrig — de hör till ärendet och inte till skräpdetekteringen.
 */
final class DetectTrappedContact
{
    /** Antal sekunder mellan sidritning och tidigast godkända inskickning. */
    public const MIN_SECONDS = 3;

    /** Skälet till att inskickningen räknas som skräp, eller `null`. */
    public function handle(Request $request): ?string
    {
        if (filled($request->input('website'))) {
            return 'honeypot';
        }

        $renderedAt = $request->input('rendered_at');

        if (! is_string($renderedAt) || $renderedAt === '') {
            return 'rendered_at';
        }

        try {
            $renderedAt = Carbon::parse(Crypt::decryptString($renderedAt));
        } catch (Throwable) {
            return 'rendered_at';
        }

        if (now()->lt($renderedAt->copy()->addSeconds(self::MIN_SECONDS))) {
            return 'too_fast';
        }

        return null;
    }
}
