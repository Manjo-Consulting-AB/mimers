<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\SecurityLog;
use App\Models\TotpRecoveryCode;
use App\Models\User;
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
 * **Inloggningshistoriken, issue 117.** Sedan issue 113 skriver
 * App\Http\Controllers\Auth\AuthenticatedSessionController,
 * MagicLinkLoginController och App\Http\Requests\Auth\LoginRequest varsin rad
 * i `security_log`, och den här sidan är den ENDA ytan där användaren får
 * läsa ur den: sina egna inloggningar, de tjugo senaste, med tid, enhetsnamn
 * och utfall ([[ADR-0043 Tre loggar]] § Säkerhetsloggen — *det bästa skyddet
 * mot ett kapat konto: användaren upptäcker det själv*). Resten av
 * säkerhetsloggen är vår.
 *
 * Tre saker om den listan. **Raderna kommer färdiga och vyn tolkar
 * ingenting**: `device_name` är enhetsnamnet App\Support\Security\DeviceName
 * tolkade när raden skrevs, och `succeeded` är avgjort här — vyn väljer bara
 * ordet. **`ip_group` följer aldrig med**: pseudonymen är vår, och issue 117
 * visar den inte, varken i vyn eller i proparna. **Urvalet är
 * inloggningshändelserna**, inte varje `auth.*`: tvåfaktor på och av och nya
 * återställningskoder är kontohändelser och inte inloggningar, och de hör
 * till resten.
 *
 * Rutten bakom `auth` (routes/web.php) — en utloggad besökare skickas till
 * /login av middlewaren och når aldrig den här metoden.
 */
class SecurityController extends Controller
{
    /**
     * Inloggningshändelserna ur säkerhetsloggen — de enda rader användaren
     * får läsa (issue 117 · [[ADR-0043 Tre loggar]] § Säkerhetsloggen).
     *
     * `auth.magic_link` hör hit och inte till resten: ett inlöst magic link ÄR
     * en genomförd inloggning — ett konto utan bekräftad tvåfaktor loggar in
     * i steg ett, ett med i steg två — och utan den raden hade en användare
     * som bara loggar in med länk mötts av en tom historik, precis det skyddet
     * ADR:en finns för att ge.
     */
    private const LOGIN_ACTIONS = [
        SecurityLog::ACTION_LOGIN,
        SecurityLog::ACTION_LOGIN_FAILED,
        SecurityLog::ACTION_MAGIC_LINK,
    ];

    /**
     * De tjugo senaste (issue 117). Fler än så är ingen överblick; är det ett
     * kap syns det i de första raderna.
     */
    private const LOGIN_LIMIT = 20;

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

            'logins' => $this->logins($user),
        ]);
    }

    /**
     * Användarens tjugo senaste inloggningar, nyast först.
     *
     * `user_id` är hela urvalet: raderna är användarens EGNA, och en rad utan
     * användare — en misslyckad inloggning mot en adress som inte finns —
     * tillhör ingen och kan därför aldrig synas här.
     *
     * `succeeded` är utfall och inte handling: en misslyckad inloggning är den
     * enda som inte lyckades, och vyn behöver bara veta det — vilken av
     * handlingarna raden bar är vår sak.
     *
     * Sekundordningen är `id` och inte en andra tidsstämpel: två försök inom
     * samma sekund är det vanliga vid ett intrångsförsök, och utan den hade
     * deras inbördes ordning varit databasens.
     *
     * @return list<array{created_at: string, device_name: string|null, succeeded: bool}>
     */
    private function logins(User $user): array
    {
        return SecurityLog::query()
            ->where('user_id', $user->id)
            ->whereIn('action', self::LOGIN_ACTIONS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LOGIN_LIMIT)
            ->get()
            ->map(fn (SecurityLog $log): array => [
                'created_at' => $log->created_at->toIso8601String(),
                'device_name' => $log->device_name,
                'succeeded' => $log->action !== SecurityLog::ACTION_LOGIN_FAILED,
            ])
            ->all();
    }
}
