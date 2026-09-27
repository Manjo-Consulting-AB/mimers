<?php

namespace App\Actions\User;

use App\Actions\Security\RecordSecurityEvent;
use App\Models\SecurityLog;
use App\Models\User;
use App\Models\UserDeletion;
use App\Notifications\UserDeletionConfirmationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Begär att personen själv raderas, se [[M22 Redo för testare]] § 145 och
 * [[Konton och åtkomst]] § user_deletion. Skriver raden och skickar mejlet —
 * men **raderar ingenting**. Raderingen sker först när länken i mejlet öppnas,
 * av App\Actions\User\ConfirmUserDeletion, och det är hela poängen med
 * flödet: den som sitter i en kapad session kan begära raderingen, men bara
 * den som når brevlådan kan genomföra den ([[ADR-0045 Radering av konto och
 * person]] § Beslut 3, [[ADR-0011 Autentisering]] § Uppföljning 2026-09-26).
 * En personradering har ingen ångerfrist, så det är den enda spärren mot ett
 * misstag — eller mot någon annan.
 *
 * **Återautentiseringen har redan skett när den här metoden anropas.** Att
 * kontot har en giltig tvåfaktorkod — om tvåfaktorn är på — prövas av
 * App\Http\Requests\Settings\RequestUserDeletionRequest::authenticate(), som
 * kontrollern anropar först. Mönstret är
 * App\Actions\Account\RequestPasswordChange:s, och TwoFactorChallenge ändras
 * inte.
 *
 * **En ny begäran ogiltigförklarar en tidigare obekräftad rad** genom att
 * sätta dess `expires_at` till nu i stället för att radera den. Den gamla
 * länken slutar alltså fungera direkt, och raden ligger kvar som bevis på att
 * begäran gjordes — samma resonemang som
 * App\Actions\Account\RequestPasswordChange för. Bara OBekräftade rader rörs:
 * en bekräftad rad är historik, och i det här flödet finns dessutom personen
 * inte längre när den skrevs.
 *
 * **Mejlet skickas efter commit och utanför transaktionen.** Ett mejl som
 * gick ut för en begäran som sedan rullades tillbaka vore ett löfte systemet
 * inte höll — och i det här flödet ett löfte om att en person snart raderas.
 *
 * **Kontona prövas inte här.** Den som begär en radering får sin länk även om
 * en spärr står i vägen: spärren kan hävas inom timmen, och ytan har redan
 * visat den innan knappen trycktes. Den auktoritativa prövningen görs när
 * länken öppnas, av App\Actions\User\DeleteUser.
 */
class RequestUserDeletion
{
    /**
     * Länkens livslängd, en timme. Samma timme som
     * App\Actions\Account\RequestPasswordChange::TTL_MINUTES och
     * App\Actions\Account\RequestEmailChange::TTL_MINUTES — en timme är den
     * yttre gränsen för vad en engångslänk i ett mejl får gälla. Här väger
     * skälet tyngre än någon annanstans: länken raderar en person, och ett
     * läckt mejl (en delad inkorg, en skärmdump, ett spamfilter som
     * förhandsvisar länkar) är en nyckel till hela kontot.
     */
    public const TTL_MINUTES = 60;

    /**
     * Längden på den slump som skickas i länken (tecken, inte bytes), samma
     * storlek och samma källa som RequestPasswordChange och
     * App\Support\Auth\MagicLinkBroker: `Str::random()` hämtar sin entropi
     * från `random_bytes()`, och 64 tecken ur ett 62-teckensalfabet gör
     * hashen omöjlig att gissa.
     */
    private const TOKEN_LENGTH = 64;

    public function __construct(
        private readonly RecordSecurityEvent $recordSecurityEvent,
    ) {}

    /**
     * Skriv raden, ogiltigförklara den föregående, och skicka mejlet.
     *
     * `$ip` och `$userAgent` går rakt in i säkerhetsloggen — de tolkas till
     * en pseudonym respektive ett enhetsnamn av actionen. **Meta är tom**:
     * raden bär `user_id` i kolumnen, och varken tokenet, hashen eller något
     * om personens konton har där att göra ([[ADR-0043 Tre loggar]]
     * § Säkerhetsloggen).
     */
    public function handle(User $user, ?string $ip = null, ?string $userAgent = null): void
    {
        $raw = Str::random(self::TOKEN_LENGTH);

        DB::transaction(function () use ($user, $raw, $ip, $userAgent): void {
            // Den villkorliga uppdateringen är hela ogiltigförklaringen: en
            // tidigare obekräftad rad får `expires_at` i dåtid och kan
            // därmed inte lösas in, medan raden finns kvar.
            UserDeletion::query()
                ->where('user_id', $user->id)
                ->whereNull('confirmed_at')
                ->update(['expires_at' => now()]);

            UserDeletion::query()->create([
                'user_id' => $user->id,
                'token_hash' => self::hash($raw),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);

            // Inuti transaktionen, som RequestPasswordChange gör och av
            // samma skäl: en rad som skrivs utanför kan överleva ett rollback
            // och beskriva en begäran som aldrig hände.
            $this->recordSecurityEvent->handle(
                action: SecurityLog::ACTION_USER_DELETION_REQUESTED,
                user: $user,
                ip: $ip,
                userAgent: $userAgent,
            );
        });

        // Efter commit: länken går till kontots egen adress — det är den som
        // ska bevisa att hon når brevlådan. `$user->notify()` går till
        // `user.email`.
        $user->notify(new UserDeletionConfirmationNotification(self::url($raw)));
    }

    /**
     * Absolut länk till bekräftelserutten. Samma skäl som
     * RequestPasswordChange::url(): `route()` respekterar redan `APP_URL` och
     * den åtstramade `trustProxies` (bootstrap/app.php, issue 4) — ingen egen
     * värdnamnshantering byggs här.
     */
    private static function url(string $raw): string
    {
        return URL::route('settings.delete-user.confirm', ['token' => $raw]);
    }

    /**
     * SHA-256 av slumpen. Klartexten lagras aldrig — se
     * app/Models/UserDeletion.php och MagicLinkBroker § Beslut 1.
     */
    private static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
