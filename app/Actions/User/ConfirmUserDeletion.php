<?php

namespace App\Actions\User;

use App\Models\User;
use App\Models\UserDeletion;
use App\Support\User\UserDeletionBlocked;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Personraderingen bakom länken i mejlet, se [[M22 Redo för testare]] § 145,
 * [[ADR-0045 Radering av konto och person]] § Uppföljning 2026-09-28 och
 * [[Konton och åtkomst]] § user_deletion.
 *
 * **Tokenet är beviset, och det räcker.** Sedan Tonys beslut 2026-09-28
 * (ADR-0045 § Uppföljning 2026-09-28, beslut 1) kräver länken ingen
 * inloggning: den som når brevlådan får genomföra raderingen. Är någon ANNAN
 * inloggad i webbläsaren spelar det ingen roll — tokenet avgör vem som raderas,
 * och den inloggade förblir inloggad. Före beslutet jämfördes tokenet mot
 * `$request->user()`, och en testare som öppnade länken i mejlappens inbyggda
 * webbläsare möttes av `/login` och fick aldrig sin radering (bugg #577).
 *
 * **Två ingångar, och bara den ena rör raden.** `pending()` svarar på vem
 * länken gäller och läser raden utan att skriva någonting — den bär `GET`-sidan,
 * som visar vad en radering skulle göra och ingenting mer. `handle()` förbrukar
 * tokenet och raderar. Mellansteget är inte bara artigt: en mejlskanner som
 * förhandshämtar länkar gör en `GET`, och en `GET` som raderade hade låtit en
 * skanner radera ett konto.
 *
 * **Fyra saker gör att länken inte gäller, och de ger alla samma svar.**
 * Tokenet finns inte, det har gått ut, det är redan förbrukat, eller personen
 * bakom det finns inte längre. Skillnaden mellan dem får inte synas: en gissad
 * tokensträng ska inte kunna skiljas från en utgången, och en okänd länk ska
 * inte avslöja vems den var. Alla blir `404` — se
 * App\Http\Controllers\Settings\UserDeletionController, som renderar samma
 * sida för samtliga. Samma form som App\Actions\Account\ConfirmPasswordChange.
 *
 * **Kontrollen är två steg, och det andra är det som räknas.** `pending()`
 * läser raden och kontrollerar tillståndet — men det som faktiskt förbrukar
 * tokenet är en VILLKORLIG UPDATE (`whereNull('confirmed_at')` och
 * `expires_at` i framtiden), och den ligger inuti transaktionen. Två samtidiga
 * klick på samma länk kan därför aldrig båda lyckas: databasen serialiserar
 * UPDATE-satser mot samma rad, och den andra får noll träffar och ett 404.
 *
 * **Spärrarna prövas av `DeleteUser`, inte här.** Det är samma enda
 * formulering som ytorna läste innan länken öppnades, och den ligger i
 * `DeleteUser::handle()` — en kontroll som förlitar sig på att anroparen
 * gjorde rätt är ingen kontroll. Skillnaden mot begäran är att den nu är
 * auktoritativ: en spärr som uppstått mellan begäran och länken stoppar
 * raderingen.
 *
 * **Spärren rullar tillbaka förbrukningen också.** `DeleteUser` kastar
 * App\Support\User\UserDeletionBlocked inifrån samma transaktion, och
 * därmed går både raderingen och `confirmed_at` tillbaka: ingenting har
 * hänt, och **länken är fortfarande lösbar**. Det är avsiktligt — spärren kan
 * hävas inom timmen (det är därför `RequestUserDeletion` skickar mejlet även
 * när något spärrar), och den som då öppnar länken igen ska inte mötas av ett
 * 404 för att hon försökte en gång för mycket. Anroparen fångar undantaget
 * och visar spärren.
 *
 * **`DeleteUser` äger hela raderingen.** Ordningen, kontona, författarkolumnerna
 * och loggraden `user.deleted` står i dess docblock och rörs inte här; den
 * här actionen är bara den villkorliga förbrukningen framför den.
 *
 * **Den raderade modellen returneras.** `handle()` ger `user`-raden som den
 * såg ut, så att anroparen kan avgöra om den aktuella sessionen var den
 * raderades — och bara då logga ut den (ADR-0045 § Uppföljning 2026-09-28,
 * beslut 3).
 */
class ConfirmUserDeletion
{
    public function __construct(
        private readonly DeleteUser $deleteUser,
    ) {}

    /**
     * Personen bakom ett giltigt, obekräftat och ej utgånget token — utan att
     * röra raden.
     *
     * `null` för alla fallen i docblocket ovan, och det är anroparens `404`.
     * Ingen jämförelse mot en inloggad användare: tokenet är hela beviset.
     */
    public function pending(string $rawToken): ?User
    {
        return $this->validDeletion($rawToken)?->user;
    }

    /**
     * Förbruka tokenet och radera personen.
     *
     * @param  string  $rawToken  Klartexten ur länken. Hashen är det enda
     *                            som finns i databasen.
     * @return User Den raderade modellen. Raden är borta; modellen bär
     *              fortfarande sitt id, så anroparen kan jämföra den med
     *              `$request->user()`.
     *
     * @throws ModelNotFoundException Tokenet är okänt, utgånget eller
     *                                förbrukat. Blir `404` och ingenting
     *                                annat.
     * @throws UserDeletionBlocked En spärr står i vägen. Ingenting har
     *                             raderats, och länken är fortfarande
     *                             lösbar.
     */
    public function handle(string $rawToken): User
    {
        $deletion = $this->validDeletion($rawToken);

        if (! $deletion instanceof UserDeletion) {
            $this->notFound();
        }

        $user = $deletion->user;

        DB::transaction(function () use ($deletion, $user): void {
            // Förbrukningen: noll träffar betyder att en annan request hann
            // först, och svaret ska vara detsamma som för ett okänt token.
            // `expires_at` prövas HÄR och inte bara i läsningen ovan: en ny
            // begäran ogiltigförklarar den här raden genom att sätta
            // `expires_at` till nu (RequestUserDeletion), och hinner den
            // commit:a mellan läsningen och den här satsen vore länken annars
            // fortfarande lösbar. Bägge villkoren ligger i samma UPDATE, så
            // utgång och förbrukning avgörs atomärt.
            $claimed = UserDeletion::query()
                ->whereKey($deletion->getKey())
                ->whereNull('confirmed_at')
                ->where('expires_at', '>', now())
                ->update(['confirmed_at' => now()]);

            if ($claimed !== 1) {
                $this->notFound();
            }

            // Kastar UserDeletionBlocked om något spärrar, och fäller då hela
            // transaktionen — förbrukningen ovan med den.
            $this->deleteUser->handle($user);
        });

        return $user;
    }

    /**
     * Raden bakom tokenet, om den gäller. `null` för okänt, utgånget och
     * förbrukat — de tre får inte gå att skilja åt, och `pending()` och
     * `handle()` ställer därför samma fråga.
     *
     * Ingen `User`-parameter: personen följer med raden, och det är
     * skillnaden mot flödet före 2026-09-28.
     */
    private function validDeletion(string $rawToken): ?UserDeletion
    {
        $deletion = UserDeletion::query()
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();

        if (! $deletion instanceof UserDeletion
            || $deletion->isConfirmed()
            || $deletion->isExpired()) {
            return null;
        }

        return $deletion;
    }

    /**
     * Samma svar för alla fall, och samma klass som ramverket självt kastar
     * när en modell inte hittas — Laravels undantagshanterare gör `404` av
     * den, i både webb och API, utan en egen felsida.
     *
     * Inget id följer med modellnamnet: undantagets mening hamnar i loggen,
     * och varken tokenet eller dess hash har där att göra.
     *
     * @throws ModelNotFoundException
     */
    private function notFound(): never
    {
        throw (new ModelNotFoundException)->setModel(UserDeletion::class);
    }
}
