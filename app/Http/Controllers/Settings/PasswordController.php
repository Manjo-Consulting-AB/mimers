<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Security\RecordSecurityEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdatePasswordRequest;
use App\Models\SecurityLog;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Lösenordsbytet — den skrivande halvan av säkerhetssidan, se
 * [[M20 Kontot]] § 129. Den läsande halvan är
 * App\Http\Controllers\Settings\SecurityController, som renderar sidan och
 * säger om kontot har ett lösenord alls.
 *
 * **Ett formulär, två ärenden.** Att byta sitt lösenord och att sätta ett
 * första är samma skrivning mot samma kolumn — skillnaden är bara om
 * `password_hash` var NULL — och de delar därför rutt, validering och
 * återautentisering. Ett eget flöde för "sätt ett lösenord" hade varit en
 * andra väg till samma kolumn, med en andra chans att glömma tvåfaktorn.
 *
 * **Objektet är anroparen själv.** Ingen ruttparameter och ingen policy:
 * raden som skrivs är `$request->user()`, precis som i
 * App\Http\Controllers\Settings\ProfileController. Den som vill byta någon
 * ANNANS lösenord har ingen väg in här, för det finns ingen parameter att
 * peka med.
 *
 * **Återautentiseringen ligger i App\Http\Requests\Settings\
 * UpdatePasswordRequest** — det nuvarande lösenordet som en
 * valideringsregel, engångskoden genom App\Support\Auth\TwoFactorChallenge.
 * Kontrollern anropar den och gör ingenting själv: regeln om vad som krävs
 * för att byta ett lösenord hör till requesten, och en kontroller som
 * prövade den igen vore en andra plats att glömma den på
 * ([[ADR-0024 Tunna controllers och actions]]).
 *
 * **Efter bytet städas kontots andra vägar in** (issue 129). Det är hela
 * poängen med att byta ett lösenord man misstänker är röjt: den som sitter i
 * en gammal session eller med ett gammalt token ska inte överleva bytet.
 *
 * Rutten bakom `auth` (routes/web.php) — en utloggad besökare skickas till
 * /login av middlewaren och når aldrig den här metoden.
 */
class PasswordController extends Controller
{
    public function update(
        UpdatePasswordRequest $request,
        RecordSecurityEvent $recordSecurityEvent,
    ): RedirectResponse {
        $user = $request->user();

        // Före skrivningen: `meta` säger om ett lösenord fanns förut, och
        // efter update() går det inte att se.
        $hadPassword = $user->password_hash !== null;

        // Kastar ValidationException vid fel kod — och körs först efter att
        // valideringen redan prövat det nuvarande lösenordet, se requestens
        // klassdocblock. Ingenting har skrivits när den kastar.
        $request->authenticate();

        // **De fyra skrivningarna är en kedja och hör i samma transaktion.**
        // Fallerar ett anrop mitt i — mellan update() och tokens()->delete()
        // — blir resultatet exakt det tillståndet bytet finns för att
        // förhindra: lösenordet är nytt, men en gammal session eller ett
        // gammalt token lever kvar. RecordSecurityEvent skriver själv ingen
        // transaktion och säger i sitt docblock att anroparen äger den;
        // App\Actions\Invitation\CreateInvitation gör precis så här.
        DB::transaction(function () use ($request, $user, $hadPassword, $recordSecurityEvent): void {
            $user->update([
                'password_hash' => $request->string('password')->toString(),
            ]);

            $this->logoutOtherSessions($request, $user);

            // Alla personal access tokens. Ett token är en väg in som inte går
            // genom ett lösenord alls, och den som byter lösenord efter ett
            // intrång menar att varje sådan väg ska stängas.
            $user->tokens()->delete();

            // Issue 113 och [[ADR-0043 Tre loggar]] § Säkerhetsloggen: raden
            // skrivs av actionen, och `meta` bär bara om ett lösenord fanns före
            // — aldrig ett lösenord eller en kod.
            $recordSecurityEvent->handle(
                action: SecurityLog::ACTION_PASSWORD_CHANGED,
                user: $user,
                ip: $request->ip(),
                userAgent: $request->userAgent(),
                meta: ['had_password' => $hadPassword],
            );
        });

        // Transaktionellt mejl och ingen notisrad: användaren får veta att
        // bytet hände, och klockan i sidhuvudet får ingenting — hon gjorde
        // det själv, se App\Notifications\PasswordChangedNotification.
        // **Efter commit**, utanför closuren: ett mejl som gick ut för ett
        // byte som sedan rullades tillbaka vore ett löfte systemet inte höll.
        $user->notify(new PasswordChangedNotification);

        return back()->with('status', 'password-changed');
    }

    /**
     * Loggar ut kontots övriga webbsessioner och ger den egna ett nytt id.
     *
     * **Sessionerna ligger i databasen** (`SESSION_DRIVER=database`, se
     * config/session.php) och `sessions.user_id` är den koppling som gör
     * städningen möjlig: en webbsession är en rad, och kontots andra rader är
     * kontots andra enheter. Laravels egen `logoutOtherDevices()` hade
     * krävt ett lösenord att jämföra med, och den finns inte för ett konto
     * som bara använt magic link — den vägen är alltså stängd av samma skäl
     * som issuen skriver ut.
     *
     * **Ordningen är avsiktlig.** `regenerate()` ger den HÄR sessionen ett
     * nytt id först — skyddet mot sessionsfixering, samma anrop som
     * App\Http\Controllers\Auth\RegisteredUserController gör vid inloggning
     * — och raderingen tar sedan varje rad som inte är det nya id:et. Den
     * egna gamla raden försvinner med de andras, och den nya skrivs när
     * requesten avslutas. Raden som gör bytet är alltså kvar, inloggad, medan
     * varje annan webbläsare möts av en tom session nästa gång.
     *
     * Ingen `session()->invalidate()`: den tömmer sessionen, och då hade
     * flashkoden tillbaka till formuläret försvunnit med den.
     */
    private function logoutOtherSessions(UpdatePasswordRequest $request, User $user): void
    {
        $session = $request->session();

        $session->regenerate();

        DB::table(config('session.table'))
            ->where('user_id', $user->id)
            ->where('id', '!=', $session->getId())
            ->delete();
    }
}
