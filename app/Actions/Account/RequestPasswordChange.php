<?php

namespace App\Actions\Account;

use App\Actions\Security\RecordSecurityEvent;
use App\Models\PasswordChange;
use App\Models\SecurityLog;
use App\Models\User;
use App\Notifications\PasswordChangeConfirmationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Begär ett lösenordsbyte, se [[M20 Kontot]] § 140 och
 * [[Konton och åtkomst]] § password_change. Skriver raden och skickar mejlet —
 * men **rör aldrig `user.password_hash`**. Lösenordet byts först när länken i
 * mejlet öppnas, av App\Actions\Account\ConfirmPasswordChange, och det är
 * hela poängen med flödet: den som sitter i en kapad session kan begära
 * bytet, men bara den som når brevlådan kan genomföra det
 * ([[ADR-0011 Autentisering]] § Uppföljning 2026-09-26).
 *
 * **Återautentiseringen har redan skett när den här metoden anropas.** Att
 * kontot har en giltig tvåfaktorkod — om tvåfaktorn är på — prövas av
 * App\Http\Requests\Settings\UpdatePasswordRequest::authenticate(), som
 * kontrollern anropar först. Det nuvarande lösenordet krävs INTE längre: det
 * är just det kravet issue 140 tar bort, för den som glömt sitt lösenord kan
 * logga in med magic link men hade annars ingen väg att byta det. Beviset
 * flyttas till mejlet i stället.
 *
 * **En ny begäran ogiltigförklarar en tidigare obekräftad rad** genom att
 * sätta dess `expires_at` till nu i stället för att radera den. Den gamla
 * länken slutar alltså fungera direkt, och raden ligger kvar som bevis på
 * att begäran gjordes — samma resonemang som
 * App\Actions\Account\RequestEmailChange för och som gör `invitation`
 * oåterkallelig i stället för borttagen ([[Konton och åtkomst]]
 * § invitation). Bara OBekräftade rader rörs: en bekräftad rad är historik
 * och får inte skrivas om.
 *
 * **Lösenordet hashas innan det lämnar requesten.** `Hash::make()` här, och
 * klartexten finns därefter bara i den här metodens `$newPassword`-parameter.
 * Raden bär hashen (app/Models/PasswordChange.php), och det är samma hash som
 * senare skrivs rakt igenom till `user.password_hash`.
 *
 * **Mejlet skickas efter commit och utanför transaktionen.** Ett mejl som
 * gick ut för en begäran som sedan rullades tillbaka vore ett löfte systemet
 * inte höll — samma ordning som App\Http\Controllers\Settings\
 * PasswordController och App\Actions\Account\RequestEmailChange.
 */
class RequestPasswordChange
{
    /**
     * Länkens livslängd, en timme. Kort nog att ett läckt mejl (en delad
     * inkorg, en skärmdump, ett spamfilter som förhandsvisar länkar) inte
     * förblir en giltig nyckel till kontot särskilt länge, och lång nog att
     * hinna växla till e-postklienten. Samma timme som
     * App\Actions\Account\RequestEmailChange::TTL_MINUTES och
     * App\Support\Auth\MagicLinkBroker::TTL_MINUTES — en timme är den yttre
     * gränsen för vad en engångslänk i ett mejl får gälla.
     */
    public const TTL_MINUTES = 60;

    /**
     * Längden på den slump som skickas i länken (tecken, inte bytes), samma
     * storlek och samma källa som RequestEmailChange och MagicLinkBroker:
     * `Str::random()` hämtar sin entropi från `random_bytes()`, och 64 tecken
     * ur ett 62-teckensalfabet gör hashen omöjlig att gissa.
     */
    private const TOKEN_LENGTH = 64;

    public function __construct(
        private readonly RecordSecurityEvent $recordSecurityEvent,
    ) {}

    /**
     * Skriv raden, ogiltigförklara den föregående, och skicka mejlet.
     *
     * `$ip` och `$userAgent` går rakt in i säkerhetsloggen — de tolkas till
     * en pseudonym respektive ett enhetsnamn av actionen, och `meta` bär bara
     * `had_password`: **varken lösenordet, hashen, koden eller tokenet finns
     * i raden** ([[ADR-0043 Tre loggar]] § Säkerhetsloggen).
     */
    public function handle(User $user, string $newPassword, ?string $ip = null, ?string $userAgent = null): void
    {
        $raw = Str::random(self::TOKEN_LENGTH);

        // Före skrivningen: `meta` säger om ett lösenord fanns förut, och
        // det är den enda upplysning raden får bära. Samma form som
        // App\Http\Controllers\Settings\PasswordController gjorde för
        // `auth.password_changed` före issue 140.
        $hadPassword = $user->password_hash !== null;

        DB::transaction(function () use ($user, $newPassword, $raw, $hadPassword, $ip, $userAgent): void {
            // Den villkorliga uppdateringen är hela ogiltigförklaringen: en
            // tidigare obekräftad rad får `expires_at` i dåtid och kan
            // därmed inte lösas in, medan raden finns kvar.
            PasswordChange::query()
                ->where('user_id', $user->id)
                ->whereNull('confirmed_at')
                ->update(['expires_at' => now()]);

            PasswordChange::query()->create([
                'user_id' => $user->id,
                'password_hash' => Hash::make($newPassword),
                'token_hash' => self::hash($raw),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);

            // Inuti transaktionen, som App\Actions\Account\RequestEmailChange
            // gör och av samma skäl: en rad som skrivs utanför kan överleva
            // ett rollback och beskriva en begäran som aldrig hände.
            $this->recordSecurityEvent->handle(
                action: SecurityLog::ACTION_PASSWORD_CHANGE_REQUESTED,
                user: $user,
                ip: $ip,
                userAgent: $userAgent,
                meta: ['had_password' => $hadPassword],
            );
        });

        // Efter commit: länken går till kontots egen adress — det är den som
        // ska bevisa att hon når brevlådan. `$user->notify()` går till
        // `user.email`.
        $user->notify(new PasswordChangeConfirmationNotification(self::url($raw)));
    }

    /**
     * Absolut länk till bekräftelserutten. Samma skäl som
     * RequestEmailChange::url(): `route()` respekterar redan `APP_URL` och den
     * åtstramade `trustProxies` (bootstrap/app.php, issue 4) — ingen egen
     * värdnamnshantering byggs här.
     */
    private static function url(string $raw): string
    {
        return URL::route('settings.security.password.confirm', ['token' => $raw]);
    }

    /**
     * SHA-256 av slumpen. Klartexten lagras aldrig — se
     * app/Models/PasswordChange.php och MagicLinkBroker § Beslut 1.
     */
    private static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
