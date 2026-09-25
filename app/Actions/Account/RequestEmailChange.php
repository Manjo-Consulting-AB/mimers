<?php

namespace App\Actions\Account;

use App\Actions\Security\RecordSecurityEvent;
use App\Models\EmailChange;
use App\Models\SecurityLog;
use App\Models\User;
use App\Notifications\EmailChangeConfirmationNotification;
use App\Notifications\EmailChangeRequestedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Begär ett adressbyte, se [[M20 Kontot]] § 130 och
 * [[Konton och åtkomst]] § email_change. Skriver raden och skickar de två
 * mejlen — men **rör aldrig `user.email`**. Adressen byts först när länken i
 * mejlet öppnas, av App\Actions\Account\ConfirmEmailChange, och det är hela
 * poängen med flödet: en kapad session ska inte kunna flytta kontot i ett
 * steg.
 *
 * **Förkunskaperna är redan prövade när den här metoden anropas.** Att
 * användaren HAR ett lösenord, att lösenordet stämmer och att tvåfaktorn —
 * om den är på — gav en giltig kod, prövas av
 * App\Http\Requests\Settings\RequestEmailChangeRequest. Att den nya adressen
 * är ledig prövas där också (`unique:user,email`), och **prövas en gång
 * till** i ConfirmEmailChange: en adress som tas under timmen mellan begäran
 * och bekräftelse är ett eget fel, och kontrollen hör till det tillfälle
 * skrivningen faktiskt sker.
 *
 * **En ny begäran ogiltigförklarar en tidigare obekräftad rad** genom att
 * sätta dess `expires_at` till nu i stället för att radera den. Den gamla
 * länken slutar alltså fungera direkt, och raden ligger kvar som bevis på
 * att begäran gjordes — samma resonemang som gör `invitation` oåterkallelig
 * i stället för borttagen ([[Konton och åtkomst]] § invitation). Bara
 * OBekräftade rader rörs: en bekräftad rad är historik och får inte skrivas
 * om.
 *
 * **De två mejlen går till var sin adress.** Bekräftelselänken till den NYA
 * adressen — det är den som ska bevisa att hon når den — och ett meddelande
 * till den GAMLA, som är den enda varning en kapad session inte kan hindra:
 * den som sitter i sessionen ser formuläret, men bara innehavaren av den
 * gamla brevlådan får veta att ett byte är på väg. Det meddelandet har ingen
 * länk och nämner inte den nya adressen
 * (App\Notifications\EmailChangeRequestedNotification).
 *
 * **Mejlen skickas efter commit och utanför transaktionen.** Ett mejl som
 * gick ut för en begäran som sedan rullades tillbaka vore ett löfte systemet
 * inte höll — samma ordning som App\Http\Controllers\Settings\PasswordController.
 */
class RequestEmailChange
{
    /**
     * Länkens livslängd, en timme. Kort nog att ett läckt mejl (en delad
     * inkorg, en skärmdump, ett spamfilter som förhandsvisar länkar) inte
     * förblir en giltig omflyttning av kontot särskilt länge, och lång nog
     * att hinna växla till e-postklienten. Jämför
     * App\Support\Auth\MagicLinkBroker::TTL_MINUTES — den länken loggar in,
     * den här flyttar kontot, och en timme är den yttre gränsen för vad en
     * engångslänk i ett mejl får gälla.
     */
    public const TTL_MINUTES = 60;

    /**
     * Längden på den slump som skickas i länken (tecken, inte bytes), samma
     * storlek och samma källa som MagicLinkBroker: `Str::random()` hämtar
     * sin entropi från `random_bytes()`, och 64 tecken ur ett
     * 62-teckensalfabet gör hashen omöjlig att gissa.
     */
    private const TOKEN_LENGTH = 64;

    public function __construct(
        private readonly RecordSecurityEvent $recordSecurityEvent,
    ) {}

    /**
     * Skriv raden, ogiltigförklara den föregående, och skicka de två mejlen.
     *
     * `$ip` och `$userAgent` går rakt in i säkerhetsloggen — de tolkas till
     * en pseudonym respektive ett enhetsnamn av actionen, och `meta` är tom:
     * **ingen adress finns i raden**, varken den gamla eller den nya
     * ([[ADR-0043 Tre loggar]] § Säkerhetsloggen).
     */
    public function handle(User $user, string $newEmail, ?string $ip = null, ?string $userAgent = null): void
    {
        $raw = Str::random(self::TOKEN_LENGTH);

        DB::transaction(function () use ($user, $newEmail, $raw, $ip, $userAgent): void {
            // Den villkorliga uppdateringen är hela ogiltigförklaringen: en
            // tidigare obekräftad rad får `expires_at` i dåtid och kan
            // därmed inte lösas in, medan raden finns kvar.
            EmailChange::query()
                ->where('user_id', $user->id)
                ->whereNull('confirmed_at')
                ->update(['expires_at' => now()]);

            EmailChange::query()->create([
                'user_id' => $user->id,
                'new_email' => $newEmail,
                'token_hash' => self::hash($raw),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);

            // Inuti transaktionen, som App\Actions\Invitation\CreateInvitation
            // gör och av samma skäl: en rad som skrivs utanför kan överleva
            // ett rollback och beskriva en begäran som aldrig hände.
            $this->recordSecurityEvent->handle(
                action: SecurityLog::ACTION_EMAIL_CHANGE_REQUESTED,
                user: $user,
                ip: $ip,
                userAgent: $userAgent,
                meta: [],
            );
        });

        // Efter commit: den nya adressen får länken, den gamla får varningen.
        // `$user->notify()` går till `user.email`, som fortfarande är den
        // gamla adressen — det är den som ska varnas.
        Notification::route('mail', $newEmail)
            ->notify(new EmailChangeConfirmationNotification(self::url($raw)));

        $user->notify(new EmailChangeRequestedNotification);
    }

    /**
     * Absolut länk till bekräftelserutten. Samma skäl som
     * MagicLinkBroker::url(): `route()` respekterar redan `APP_URL` och den
     * åtstramade `trustProxies` (bootstrap/app.php, issue 4) — ingen egen
     * värdnamnshantering byggs här.
     */
    private static function url(string $raw): string
    {
        return URL::route('settings.profile.email.confirm', ['token' => $raw]);
    }

    /**
     * SHA-256 av slumpen. Klartexten lagras aldrig — se
     * app/Models/EmailChange.php och MagicLinkBroker § Beslut 1.
     */
    private static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
