<?php

namespace App\Actions\Invitation;

use App\Exceptions\Api\ApiException;
use App\Models\Container;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Support\Plan\Entitlements;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Skapar en inbjudan och skickar mejlet med länken — se issue 55b § Beslut 7.
 *
 * Kroppen är oförändrad sedan issue 10a: den flyttades hit från
 * App\Http\Controllers\Api\ContainerInvitationController::store() när webben
 * fick sin egen väg in och två kopior av duplikatspärren, kvotordningen och
 * tokenhanteringen hade varit två formuleringar av samma svar — samma
 * tröskel och samma skäl som App\Actions\Invitation\AcceptInvitation
 * ([[ADR-0024 Tunna controllers och actions]]).
 *
 * **Ordningen är kontraktet.** Duplikatspärren före delningstaket före
 * kontotaket (issue 10a § Beslut 12, 27 § Beslut 5, 48 § Beslut 8): att bjuda
 * in någon som redan har en obesvarad inbjudan ska svara `already_pending` och
 * inte avslöja var taket ligger. Ändra inte ordningen utan att ändra den i
 * App\Support\Plan\Entitlements.
 *
 * **Klartexttokenet lämnar aldrig den här klassen.** Det skickas i mejlets
 * länk och lagras aldrig — inte i raden, inte i en retur, inte i en `data`
 * och inte i en logg (issue 10a § Beslut 5). Returvärdet är raden, och den bär
 * bara hashen.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen, precis som
 * i App\Actions\Access\ListContainerAccesses och
 * App\Actions\Container\CreateContainer — se issue 54 § Beslut 3 och issue
 * 55a § Beslut 8. Kvotkontrollerna ligger däremot KVAR här: de hör till
 * skrivningen och inte till behörigheten, och båda ingångarna ska pröva dem.
 */
class CreateInvitation
{
    /**
     * Längden på den slump som ska skickas i mejlets länk (tecken, inte
     * bytes), samma som App\Support\Auth\MagicLinkBroker::TOKEN_LENGTH.
     * `Str::random()` hämtar sin entropi från `random_bytes()`. Konstanten
     * flyttade med hit från API-kontrollern i issue 55b.
     */
    private const TOKEN_LENGTH = 64;

    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * @param  User  $inviter  Den som bjuder in. Blir `invited_by_user_id`.
     * @param  Container  $container  Containern inbjudan gäller.
     * @param  string  $email  Adressen som den kom in i kroppen. Normaliseras
     *                         här, så duplikatspärren och den lagrade raden
     *                         garanterat jämför samma sträng.
     * @param  string  $level  Ett steg i AccessLevel::LADDER.
     * @param  Item|null  $item  Itemet inbjudan avgränsas till, eller `null`
     *                           för hela containern. Anroparen har redan bevisat
     *                           att det finns i DEN HÄR containern och är
     *                           levande (`StoreInvitationRequest`).
     *
     * @throws ApiException 422 `invitation.already_pending`, 403
     *                      `quota.shared_users_exceeded`, 403
     *                      `quota.pending_invitations_exceeded`.
     */
    public function handle(User $inviter, Container $container, string $email, string $level, ?Item $item): Invitation
    {
        $email = mb_strtolower($email);

        $existing = $container->invitations()
            ->where('email', $email)
            ->outstanding()
            ->first();

        if ($existing instanceof Invitation) {
            throw ApiException::make('invitation.already_pending', ['invitation' => $existing->ulid], 422);
        }

        // Kvotkontrollen kommer efter duplikatspärren: att bjuda in någon som
        // redan har en pending inbjudan är inte en ny delning, så den ska svara
        // already_pending, inte avslöja taket. Delningstaket följer ägarkontots
        // plan och räknar även den här inbjudan när den ligger pending
        // (issue 27 § Beslut 5).
        $this->entitlements->assertCanShareContainer($container);

        // Kontotaket kommer sist (issue 48 § Beslut 8): delningstaket är den
        // gräns användaren kan göra något åt, och först när den är passerad är
        // frågan om utskicksvolymen. Undantaget kastas före Str::random(), före
        // save() och före Notification::route() — ett nekande lämnar inga spår,
        // varken rad, token eller mejl (issue 48 § Beslut 9).
        $this->entitlements->assertPendingInvitationsWithinLimit($container->account);

        // Klartexten är mejlets enda konsument — den skickas i länken nedan och
        // lagras aldrig, se klassens docblock.
        $rawToken = Str::random(self::TOKEN_LENGTH);

        $invitation = new Invitation([
            'email' => $email,
            'level' => $level,
        ]);
        $invitation->container_id = $container->id;
        // Kolumnen är medvetet inte #[Fillable] — den sätts explicit, som
        // container_id och invited_by_user_id.
        $invitation->item_id = $item?->id;
        $invitation->token_hash = hash('sha256', $rawToken);
        $invitation->status = 'pending';
        $invitation->expires_at = now()->addDays(Invitation::TTL_DAYS);
        $invitation->invited_by_user_id = $inviter->id;
        $invitation->save();

        // Issue 10b § Beslut 2 och 3: mejlet skickas härifrån, direkt efter att
        // raden skapats, med den klartext-token som genererades ovan.
        // Mottagaren har inget konto och därmed ingen `User` att notifiera —
        // on-demand-notifikation. Länken pekar på frontendens landningssida
        // (issue 55, M10); URL:en byggs ur `config('app.url')` och inte med
        // `URL::route()`, för accept kräver en inloggad, verifierad användare
        // och en sida som kan be henne registrera sig först. Sökvägen
        // `/invitations/{token}` är kontraktet issue 55 implementerar.
        $url = rtrim((string) config('app.url'), '/').'/invitations/'.$rawToken;

        Notification::route('mail', $invitation->email)->notify(new InvitationNotification($url, $container));

        return $invitation;
    }
}
