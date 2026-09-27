<?php

namespace App\Actions\User;

use App\Models\User;
use App\Models\UserDeletion;
use App\Support\User\UserDeletionBlocked;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Genomför en begärd personradering, se [[M22 Redo för testare]] § 145 och
 * [[Konton och åtkomst]] § user_deletion. Anropas när länken i mejlet till
 * `user.email` öppnas — det är först här någonting raderas.
 *
 * **Fyra saker gör att länken inte gäller, och de ger alla samma svar.**
 * Tokenet finns inte, det hör till en annan användare, det har gått ut, eller
 * det är redan förbrukat. Skillnaden mellan dem får inte synas: en gissad
 * tokensträng ska inte kunna skiljas från en utgången, och en annan inloggad
 * användare ska inte kunna avgöra om länken var någon annans (issuens
 * flödespunkt 3). Alla fyra blir `404`, och det är därför ingen av dem får en
 * egen felväg. Samma form som App\Actions\Account\ConfirmPasswordChange.
 *
 * **Kontrollen är två steg, och det andra är det som räknas.** Den här
 * metoden läser raden och kontrollerar tillståndet — men det som faktiskt
 * förbrukar tokenet är en VILLKORLIG UPDATE (`whereNull('confirmed_at')` och
 * `expires_at` i framtiden), och den ligger inuti transaktionen. Två samtidiga
 * klick på samma länk kan därför aldrig båda lyckas: databasen serialiserar
 * UPDATE-satser mot samma rad, och den andra får noll träffar och ett 404.
 *
 * **Spärrarna prövas av `DeleteUser`, inte här.** Det är samma enda
 * formulering som ytan läste innan begäran gjordes, och den ligger i
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
 */
class ConfirmUserDeletion
{
    public function __construct(
        private readonly DeleteUser $deleteUser,
    ) {}

    /**
     * @param  string  $rawToken  Klartexten ur länken. Hashen är det enda
     *                            som finns i databasen.
     *
     * @throws ModelNotFoundException Tokenet är okänt, utgånget, förbrukat,
     *                                eller hör till en annan användare. Blir
     *                                `404` och ingenting annat.
     * @throws UserDeletionBlocked En spärr står i vägen. Ingenting har
     *                             raderats, och länken är fortfarande
     *                             lösbar.
     */
    public function handle(User $user, string $rawToken): void
    {
        $deletion = UserDeletion::query()
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();

        if (! $deletion instanceof UserDeletion
            || $deletion->user_id !== $user->id
            || $deletion->isConfirmed()
            || $deletion->isExpired()) {
            $this->notFound();
        }

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
        throw (new ModelNotFoundException)->setModel(UserDeletion::class);
    }
}
