<?php

namespace App\Actions\Account;

use App\Actions\Security\RecordSecurityEvent;
use App\Models\EmailChange;
use App\Models\MagicLinkToken;
use App\Models\SecurityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Genomför ett begärt adressbyte, se [[M20 Kontot]] § 130 och
 * [[Konton och åtkomst]] § email_change. Anropas när länken i mejlet till
 * den NYA adressen öppnas — det är först här `user.email` ändras.
 *
 * **Fyra saker gör att länken inte gäller, och de ger alla samma svar.**
 * Tokenet finns inte, det hör till en annan användare, det har gått ut, eller
 * det är redan förbrukat. Skillnaden mellan dem får inte synas: en gissad
 * tokensträng ska inte kunna skiljas från en utgången, och en annan inloggad
 * användare ska inte kunna avgöra om länken var någon annans (issuens
 * flödespunkt 4). Alla fyra blir `404`, och det är därför ingen av dem får en
 * egen felväg.
 *
 * **Kontrollen är två steg, och det andra är det som räknas.** Den här
 * metoden läser raden och kontrollerar tillståndet — men det som faktiskt
 * förbrukar tokenet är en VILLKORLIG UPDATE (`whereNull('confirmed_at')` och
 * `expires_at` i framtiden), och den ligger inuti transaktionen. Två samtidiga
 * klick på samma länk kan därför aldrig båda lyckas: databasen serialiserar
 * UPDATE-satser mot samma rad, och den andra får noll träffar och ett 404.
 * Samma spärr gäller mot en ny begäran som ogiltigförklarar raden genom att
 * sätta `expires_at` till nu — även den committar in i samma villkor, så
 * utgång och förbrukning avgörs i en och samma sats. Samma spärr som
 * App\Support\Auth\MagicLinkBroker § Beslut 3 och
 * App\Actions\Invitation\AcceptInvitation.
 *
 * **En adress som tagits under tiden är ett annat fel än ett ogiltigt
 * token** — länken ÄR giltig, det är adressen som inte längre är ledig — och
 * det blir ett valideringsfel på `new_email` i stället för 404. Bytet görs
 * aldrig, och transaktionen rullas tillbaka: ingenting halvvägs.
 * Omdirigeringen sätts explicit till profilsidan, för `back()` hade gått dit
 * webbläsarens `Referer` pekade — och den är mejlklienten.
 *
 * **Allt skrivs i en transaktion**, som issuen kräver: den nya adressen,
 * `email_verified_at`, `confirmed_at`, borttagningen av de obegagnade
 * magic link-tokenen och säkerhetsloggens rad. Faller något mitt i — eller
 * faller den andra unikhetskontrollen — står kontot kvar som det var.
 *
 * **Magic link-token för den gamla adressen tas bort.** De är obrukbara
 * redan av sig själva (tokenet är bundet till adressen, och den finns inte
 * kvar på kontot), men ett obegagnat token som pekar på en adress ingen har
 * är skräp som ska bort medan vi vet varför det finns.
 *
 * **Väntande inbjudningar följer adressen och inte användaren**, och det är
 * avsiktligt: en inbjudan till den gamla adressen går inte längre att
 * acceptera efter bytet, för accepten jämför inbjudans adress med kontots
 * ([[Konton och åtkomst]] § invitation). Ingen kod här rör dem — det är
 * samma sak som gör att en inbjudan till en adress ingen längre har ska sluta
 * gälla, och att "städa" dem vore att koppla inbjudningar till personer i
 * stället för till adresser.
 */
class ConfirmEmailChange
{
    public function __construct(
        private readonly RecordSecurityEvent $recordSecurityEvent,
    ) {}

    /**
     * @param  string  $rawToken  Klartexten ur länken. Hashen är det enda
     *                            som finns i databasen.
     *
     * @throws ModelNotFoundException Tokenet är okänt, utgånget, förbrukat,
     *                                eller hör till en annan användare. Blir
     *                                `404` och ingenting annat.
     * @throws ValidationException Adressen togs under tiden. Fel på
     *                             `new_email`, och ingenting skrivs.
     */
    public function handle(User $user, string $rawToken, ?string $ip = null, ?string $userAgent = null): void
    {
        $change = EmailChange::query()
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();

        if (! $change instanceof EmailChange
            || $change->user_id !== $user->id
            || $change->isConfirmed()
            || $change->isExpired()) {
            $this->notFound();
        }

        DB::transaction(function () use ($change, $user, $ip, $userAgent): void {
            // Förbrukningen: noll träffar betyder att en annan request hann
            // först, och svaret ska vara detsamma som för ett okänt token.
            // `expires_at` prövas HÄR och inte bara i läsningen ovan: en ny
            // begäran ogiltigförklarar den här raden genom att sätta
            // `expires_at` till nu (RequestEmailChange), och hinner den
            // commit:a mellan läsningen och den här satsen vore länken
            // annars fortfarande lösbar. Bägge villkoren ligger i samma
            // UPDATE, så utgång och förbrukning avgörs atomärt.
            $claimed = EmailChange::query()
                ->whereKey($change->getKey())
                ->whereNull('confirmed_at')
                ->where('expires_at', '>', now())
                ->update(['confirmed_at' => now()]);

            if ($claimed !== 1) {
                $this->notFound();
            }

            // Unikheten prövas om, se klassdocblocket. Kontot självt undantas
            // — att byta till sin egen adress är inte en kollision.
            $taken = User::query()
                ->where('email', $change->new_email)
                ->whereKeyNot($user->getKey())
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages([
                    'new_email' => __('ui.settings.profile.email_change.taken'),
                ])->redirectTo(route('settings.profile'));
            }

            $oldEmail = $user->email;

            // Explicit sättning och inte update(): `email` är fillable men
            // `email_verified_at` är det inte — den ligger utanför
            // `#[Fillable]` på App\Models\User som resten av tidsstämplarna,
            // och en update() hade tyst tappat den.
            $user->email = $change->new_email;
            $user->email_verified_at = now();
            $user->save();

            // Obegagnade token för den gamla adressen. Kvarvarande, använda
            // rader rörs inte: de är förbrukade och därmed ofarliga.
            MagicLinkToken::query()
                ->where('email', $oldEmail)
                ->whereNull('used_at')
                ->delete();

            $this->recordSecurityEvent->handle(
                action: SecurityLog::ACTION_EMAIL_CHANGED,
                user: $user,
                ip: $ip,
                userAgent: $userAgent,
                meta: [],
            );
        });
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
        throw (new ModelNotFoundException)->setModel(EmailChange::class);
    }
}
