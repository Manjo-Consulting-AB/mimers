<?php

namespace App\Actions\Account;

use App\Actions\Security\RecordSecurityEvent;
use App\Models\PasswordChange;
use App\Models\SecurityLog;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Genomför ett begärt lösenordsbyte, se [[M20 Kontot]] § 140 och
 * [[Konton och åtkomst]] § password_change. Anropas när länken i mejlet till
 * `user.email` öppnas — det är först här `password_hash` skrivs.
 *
 * **Fyra saker gör att länken inte gäller, och de ger alla samma svar.**
 * Tokenet finns inte, det hör till en annan användare, det har gått ut, eller
 * det är redan förbrukat. Skillnaden mellan dem får inte synas: en gissad
 * tokensträng ska inte kunna skiljas från en utgången, och en annan inloggad
 * användare ska inte kunna avgöra om länken var någon annans (issuens
 * flödespunkt 4). Alla fyra blir `404`, och det är därför ingen av dem får en
 * egen felväg. Samma form som App\Actions\Account\ConfirmEmailChange.
 *
 * **Kontrollen är två steg, och det andra är det som räknas.** Den här
 * metoden läser raden och kontrollerar tillståndet — men det som faktiskt
 * förbrukar tokenet är en VILLKORLIG UPDATE (`whereNull('confirmed_at')` och
 * `expires_at` i framtiden), och den ligger inuti transaktionen. Två samtidiga
 * klick på samma länk kan därför aldrig båda lyckas: databasen serialiserar
 * UPDATE-satser mot samma rad, och den andra får noll träffar och ett 404.
 * Samma spärr gäller mot en ny begäran som ogiltigförklarar raden genom att
 * sätta `expires_at` till nu — även den committar in i samma villkor, så
 * utgång och förbrukning avgörs i en och samma sats.
 *
 * **129:s kedja flyttar hit och körs oförändrad.** `password_hash` skrivs från
 * raden, den egna sessionen får ett nytt id, kontots övriga webbsessioner
 * raderas, alla Sanctum-token tas bort och `auth.password_changed` skrivs.
 * Allt i EN transaktion: fallerar ett anrop mitt i — mellan skrivningen och
 * `tokens()->delete()` — blir resultatet exakt det tillstånd bytet finns för
 * att förhindra, ett nytt lösenord med en gammal session eller ett gammalt
 * token kvar i livet. `RecordSecurityEvent` skriver själv ingen transaktion
 * och säger i sitt docblock att anroparen äger den.
 *
 * **Hashen skrivs som hash.** `user.password_hash` har en `hashed`-cast
 * (app/Models/User.php), och det castet hashar inte om ett värde som redan är
 * en hash — det ser `Hash::isHashed()` och lämnar värdet orört. Radens
 * `password_hash` är alltså det som hamnar i kolumnen, och klartexten har
 * aldrig funnits i den här processen.
 *
 * **`had_password` räknas vid bekräftelsen**, inte när begäran togs: raden i
 * säkerhetsloggen beskriver bytet, och frågan är om ett lösenord fanns
 * omedelbart före det. Samma fält och samma betydelse som issue 129 gav
 * `auth.password_changed`.
 *
 * **Sessionen kommer in som ett argument och hämtas inte med `session()`.**
 * En action tar emot vad den behöver och anropar inte request-hjälpare själv
 * (se App\Actions\Auth\CreatesUserWithPersonalAccount), och kontrollern har
 * redan requesten. Sessionerna ligger i databasen (`SESSION_DRIVER=database`)
 * och `sessions.user_id` är kopplingen som gör städningen möjlig — se
 * logoutOtherSessions().
 */
class ConfirmPasswordChange
{
    public function __construct(
        private readonly RecordSecurityEvent $recordSecurityEvent,
    ) {}

    /**
     * @param  string  $rawToken  Klartexten ur länken. Hashen är det enda
     *                            som finns i databasen.
     * @param  Session  $session  Den egna sessionen, som får ett nytt id och
     *                            vars gamla rad raderas med de andra.
     *
     * @throws ModelNotFoundException Tokenet är okänt, utgånget, förbrukat,
     *                                eller hör till en annan användare. Blir
     *                                `404` och ingenting annat.
     */
    public function handle(
        User $user,
        string $rawToken,
        Session $session,
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        $change = PasswordChange::query()
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();

        if (! $change instanceof PasswordChange
            || $change->user_id !== $user->id
            || $change->isConfirmed()
            || $change->isExpired()) {
            $this->notFound();
        }

        DB::transaction(function () use ($change, $user, $session, $ip, $userAgent): void {
            // Förbrukningen: noll träffar betyder att en annan request hann
            // först, och svaret ska vara detsamma som för ett okänt token.
            // `expires_at` prövas HÄR och inte bara i läsningen ovan: en ny
            // begäran ogiltigförklarar den här raden genom att sätta
            // `expires_at` till nu (RequestPasswordChange), och hinner den
            // commit:a mellan läsningen och den här satsen vore länken annars
            // fortfarande lösbar. Bägge villkoren ligger i samma UPDATE, så
            // utgång och förbrukning avgörs atomärt.
            $claimed = PasswordChange::query()
                ->whereKey($change->getKey())
                ->whereNull('confirmed_at')
                ->where('expires_at', '>', now())
                ->update(['confirmed_at' => now()]);

            if ($claimed !== 1) {
                $this->notFound();
            }

            // Före skrivningen: `meta` säger om ett lösenord fanns förut, och
            // efter update() går det inte att se.
            $hadPassword = $user->password_hash !== null;

            // Hashen rakt igenom: `hashed`-castet lämnar en färdig hash orörd.
            $user->update(['password_hash' => $change->password_hash]);

            $this->logoutOtherSessions($session, $user);

            // Alla personal access tokens. Ett token är en väg in som inte går
            // genom ett lösenord alls, och den som byter lösenord efter ett
            // intrång menar att varje sådan väg ska stängas.
            $user->tokens()->delete();

            // [[ADR-0043 Tre loggar]] § Säkerhetsloggen: raden bär bara om ett
            // lösenord fanns före — aldrig ett lösenord, en hash, en kod eller
            // ett token.
            $this->recordSecurityEvent->handle(
                action: SecurityLog::ACTION_PASSWORD_CHANGED,
                user: $user,
                ip: $ip,
                userAgent: $userAgent,
                meta: ['had_password' => $hadPassword],
            );
        });

        // Transaktionellt mejl och ingen notisrad: användaren får veta att
        // bytet hände, och klockan i sidhuvudet får ingenting — hon gjorde det
        // själv, se App\Notifications\PasswordChangedNotification.
        // **Efter commit**, utanför closuren: ett mejl som gick ut för ett byte
        // som sedan rullades tillbaka vore ett löfte systemet inte höll.
        $user->notify(new PasswordChangedNotification);
    }

    /**
     * Loggar ut kontots övriga webbsessioner och ger den egna ett nytt id.
     * Oförändrad sedan issue 129, där den låg i PasswordController.
     *
     * **Sessionerna ligger i databasen** (`SESSION_DRIVER=database`, se
     * config/session.php) och `sessions.user_id` är den koppling som gör
     * städningen möjlig: en webbsession är en rad, och kontots andra rader är
     * kontots andra enheter. Laravels egen `logoutOtherDevices()` hade krävt
     * ett lösenord att jämföra med, och den finns inte för ett konto som bara
     * använt magic link — den vägen är alltså stängd av samma skäl som issuen
     * skriver ut.
     *
     * **Ordningen är avsiktlig.** `regenerate()` ger den HÄR sessionen ett
     * nytt id först — skyddet mot sessionsfixering, samma anrop som
     * App\Http\Controllers\Auth\RegisteredUserController gör vid inloggning —
     * och raderingen tar sedan varje rad som inte är det nya id:et. Den egna
     * gamla raden försvinner med de andras, och den nya skrivs när requesten
     * avslutas. Raden som gör bytet är alltså kvar, inloggad, medan varje
     * annan webbläsare möts av en tom session nästa gång.
     *
     * Ingen `session()->invalidate()`: den tömmer sessionen, och då hade
     * flashkoden tillbaka till säkerhetssidan försvunnit med den.
     */
    private function logoutOtherSessions(Session $session, User $user): void
    {
        $session->regenerate();

        DB::table(config('session.table'))
            ->where('user_id', $user->id)
            ->where('id', '!=', $session->getId())
            ->delete();
    }

    /**
     * Samma svar för alla fyra fallen, och samma klass som ramverket självt
     * kastar när en modell inte hittas — Laravels undantagshanterare gör
     * `404` av den, i både webb och API, utan en egen felsida.
     *
     * Inget id följer med modellnamnet: undantagets mening hamnar i loggen,
     * och varken tokenet eller dess hash har där att göra.
     *
     * @throws ModelNotFoundException
     */
    private function notFound(): never
    {
        throw (new ModelNotFoundException)->setModel(PasswordChange::class);
    }
}
