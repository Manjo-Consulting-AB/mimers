<?php

namespace App\Support\Auth;

use App\Models\TotpRecoveryCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Genererar och förbrukar TOTP-återställningskoder, se issue 6c och
 * [[ADR-0011 Autentisering]]. Delas av webbens och API:ets kontroller
 * (App\Http\Controllers\Auth\RecoveryCodeController,
 * App\Http\Controllers\Api\Auth\RecoveryCodeController) för utfärdande/
 * omgenerering, och av App\Http\Requests\Auth\LoginRequest::authenticate()
 * för konsumtion vid inloggning — samma delnings-/ansvarsmönster som
 * App\Support\Auth\TotpBroker och App\Support\Auth\MagicLinkBroker.
 *
 * **Beslut 1 — aldrig i klartext.** `generate()` returnerar de nygenererade
 * koderna en enda gång, som anropet svarar med. Databasen får bara
 * `Hash::make()`-hashen (bcrypt, `code_hash`) — se migrationens docblock
 * för varför bcrypt och inte ett rakt sha256 som
 * App\Support\Auth\MagicLinkBroker använder: en återställningskod är kort
 * nog (`CODE_LENGTH` tecken) att en läckt tabell ska kräva samma kostsamma
 * offline-gissning som ett läckt lösenord.
 *
 * **Beslut 2 — förbrukas exakt en gång.** `consume()` matchar koden mot
 * kontots oförbrukade rader och markerar den TRÄFFADE raden med en
 * villkorad UPDATE (`whereNull('used_at')`), samma atomära mönster som
 * App\Support\Auth\MagicLinkBroker::consume() — två samtidiga
 * förbrukningsförsök av samma kod kan aldrig båda lyckas, databasen
 * serialiserar UPDATE-satser mot samma rad.
 *
 * **Beslut 3 — en omgenerering ogiltigförklarar de gamla.** `generate()`
 * raderar HELA kontots radmängd innan de nya koderna skapas, i en
 * transaktion. En bulk-DELETE räcker: tabellen har inget soft delete (se
 * migrationens docblock, "kortlivad säkerhetsartefakt" — samma resonemang
 * som magic_link_token), och det finns inget krav på att kunna se vilka
 * koder som en gång fanns. Efter en omgenerering är varje tidigare kod
 * — förbrukad eller ej — omedelbart och permanent ogiltig, eftersom raden
 * den låg i inte längre finns.
 *
 * **Beslut 4 — kräver bekräftad TOTP.** `generate()` vägrar
 * (TotpNotConfirmedException) om `user.totp_confirmed_at` är NULL — se den
 * klassens docblock. Ingen kodbekräftelse krävs däremot för att generera/
 * omgenerera (till skillnad från App\Support\Auth\TotpBroker::disable(),
 * som kräver en giltig kod som bevis) — samma nivå av bevis som
 * App\Support\Auth\TotpBroker::generate() själv redan nöjer sig med
 * (bara `auth`/`auth:sanctum`-middlewaret). Se issue 6c § Frågor och
 * antaganden för resonemanget: att kräva en TOTP-kod för att omgenerera
 * koder som finns till just för fallet "appen är borta" vore
 * självmotsägande.
 *
 * **Beslut 5 — en avstängd TOTP tar koderna med sig.** Koderna hör till
 * den TOTP-inskrivning de utfärdades under, inte till kontot i största
 * allmänhet. App\Support\Auth\TotpBroker::disable() anropar därför
 * `purge()`. Utan det vore koderna bara vilande, inte döda: `consume()`
 * nås aldrig medan `user.totp_confirmed_at` är NULL (se
 * App\Http\Requests\Auth\LoginRequest::authenticate()), men skriver
 * användaren in TOTP på nytt — ny hemlighet, ny bekräftelse — så skulle de
 * gamla raderna leva upp igen och godkännas mot den nya inskrivningen. Ett
 * kodark som slängdes när TOTP stängdes av vore alltså en väg in efter
 * ominskrivningen. Uppföljning på granskningen av PR #40.
 *
 * **Ingen egen begränsare.** `consume()` anropas bara från
 * App\Http\Requests\Auth\LoginRequest::authenticate(), som körs bakom
 * samma `throttle:login`-middleware (routes/web.php, routes/api.php) som
 * redan begränsar lösenords- och TOTP-kodförsök — se
 * App\Support\Auth\TotpBroker::verifyLoginCode() och issue 6b, samma
 * resonemang här.
 */
final class RecoveryCodeBroker
{
    /**
     * Antal koder som utfärdas per (om)generering. Tio är branschpraxis
     * (Google, GitHub) — tillräckligt att en användare inte tar slut på
     * koder efter ett par inloggningar utan enheten kvar, men litet nog
     * att lista och skriva ner på en gång.
     */
    private const CODE_COUNT = 10;

    /**
     * Tecken per kod, ur `Str::random()`s alfanumeriska alfabet (62 tecken)
     * — ~59,5 bitars entropi per kod. Samma slumpkälla
     * (`random_bytes()` under huven) som App\Support\Auth\MagicLinkBroker
     * redan använder för sina token, bara kortare eftersom en
     * återställningskod ska gå att skriva av för hand.
     */
    private const CODE_LENGTH = 10;

    /**
     * Genererar `CODE_COUNT` nya koder, raderar alla kontots tidigare
     * (se Beslut 3) och returnerar de nya i klartext — den enda gången de
     * någonsin syns utanför den här metoden.
     *
     * @return list<string>
     *
     * @throws TotpNotConfirmedException Kontot har ingen bekräftad TOTP —
     *                                   se Beslut 4.
     */
    public static function generate(User $user): array
    {
        if ($user->totp_confirmed_at === null) {
            throw new TotpNotConfirmedException;
        }

        $codes = [];

        DB::transaction(function () use ($user, &$codes): void {
            self::purge($user);

            for ($i = 0; $i < self::CODE_COUNT; $i++) {
                $code = Str::random(self::CODE_LENGTH);
                $codes[] = $code;

                TotpRecoveryCode::query()->create([
                    'user_id' => $user->id,
                    'code_hash' => Hash::make($code),
                ]);
            }
        });

        return $codes;
    }

    /**
     * Raderar hela kontots uppsättning återställningskoder, förbrukade som
     * oförbrukade. Anropas dels av `generate()` (Beslut 3, omgenerering),
     * dels av App\Support\Auth\TotpBroker::disable() — se Beslut 5.
     */
    public static function purge(User $user): void
    {
        TotpRecoveryCode::query()->where('user_id', $user->id)->delete();
    }

    /**
     * Försöker förbruka `$code` som en återställningskod för `$user`.
     * Returnerar `true` och markerar raden förbrukad vid en träff, `false`
     * annars (fel kod, redan förbrukad, eller inga koder alls) — den här
     * metoden kastar medvetet inget eget undantag, se
     * App\Http\Requests\Auth\LoginRequest::authenticate(), den enda
     * anroparen: ett `false` gör att den redan fångade
     * App\Support\Auth\TotpInvalidException från den misslyckade
     * TOTP-kontrollen får bubbla vidare oförändrad i stället, så
     * inloggningssvaret är identiskt oavsett om koden var en fel TOTP-kod
     * eller en fel/förbrukad återställningskod — se Beslut 2.
     */
    public static function consume(User $user, string $code): bool
    {
        if ($code === '') {
            return false;
        }

        $candidates = TotpRecoveryCode::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->get();

        foreach ($candidates as $candidate) {
            if (! Hash::check($code, $candidate->code_hash)) {
                continue;
            }

            // Villkorad UPDATE, se klassens docblock Beslut 2 — förhindrar
            // att två samtidiga förfrågningar med samma kod båda lyckas.
            $consumed = TotpRecoveryCode::query()
                ->whereKey($candidate->getKey())
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            return $consumed === 1;
        }

        return false;
    }
}
