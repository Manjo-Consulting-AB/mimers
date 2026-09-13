<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\TotpRecoveryCode;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Säkerhetssidan — ytan där tvåfaktorn slås på, stängs av och förses med
 * återställningskoder, se issue 53b.
 *
 * **Bara läsning.** Kontrollern renderar vyn och svarar med tre frågor:
 * har kontot en bekräftad TOTP, när bekräftades den, och hur många
 * oförbrukade återställningskoder finns kvar. Den skriver ingenting, och
 * den har ingen affärslogik — allt som ändrar tillstånd görs av
 * App\Support\Auth\TotpBroker och App\Support\Auth\RecoveryCodeBroker,
 * bakom de fyra rutter som redan finns sedan issue 6a–6c
 * ([[ADR-0021 Frontendteknik]], [[ADR-0024 Tunna controllers och actions]]).
 * Formulären i vyn postar dit: POST /totp, POST /totp/confirm,
 * DELETE /totp, POST /totp/recovery-codes.
 *
 * **De två engångshemligheterna, issue 53b § Beslut 3.** `totp_uri` och
 * `recovery_codes` ligger i sessionens *flash* — App\Http\Controllers\Auth\TotpController
 * och App\Http\Controllers\Auth\RecoveryCodeController lägger dem där med
 * `back()->with(...)` — och läses här ur sessionen. Laravel tömmer flash
 * efter nästa request, så en omladdning av sidan visar dem inte igen:
 * `TotpBroker::generate()` och `RecoveryCodeBroker::generate()` returnerar
 * sina hemligheter en enda gång, och databasen har bara bcrypt-hashar.
 *
 * De är sidprops och aldrig delade props. En hemlighet i
 * HandleInertiaRequests::share() hamnar i HTML-källan på varje sida
 * användaren besöker under samma request-cykel — se issue 51 § Beslut 5.
 * `session()->keep()` eller `reflash()` är fel svar av samma skäl: att
 * förlänga en flash är att göra engångsvisningen tvågångs.
 *
 * Rutten bakom `auth` (routes/web.php) — en utloggad besökare skickas till
 * /login av middlewaren och når aldrig den här metoden.
 */
class SecurityController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Settings/Security', [
            'totpEnabled' => $user->totp_confirmed_at !== null,
            'totpConfirmedAt' => $user->totp_confirmed_at?->toDateString(),

            // Oförbrukade rader, inte antalet utfärdade: en kod som använts
            // vid inloggning är kvar i tabellen men räknas inte — se
            // App\Support\Auth\RecoveryCodeBroker::consume().
            'recoveryCodesRemaining' => TotpRecoveryCode::query()
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->count(),

            'totpUri' => $request->session()->get('totp_uri'),
            'recoveryCodes' => $request->session()->get('recovery_codes'),
        ]);
    }
}
