<?php

namespace App\Actions\Invitation;

use App\Exceptions\Api\ApiException;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Accepterar en inbjudan: inbjudan blir `accepted` och mottagaren får en
 * `container_access`-rad, allt i EN transaktion — se issue 10b § Beslut 8.
 *
 * Skrivningen bär fem regler värda egna tester (verifieringskravet,
 * engångsspärren, dubblettspärren, radens värden och det frusna
 * ägarkontot), vilket är precis tröskeln i
 * [[ADR-0024 Tunna controllers och actions]] för när en Action skrivs i
 * stället för kod i kontrollern. ADR:ns gissning att issue 10 inte skulle
 * behöva någon gäller 10a, som mycket riktigt klarade sig utan.
 *
 * Formen är instansklass med `handle()`, injicerad av containern, som
 * förlagan App\Actions\Auth\CreatesUserWithPersonalAccount.
 *
 * Anropas efter App\Http\Requests\Invitation\InvitationTokenRequest::invitation(),
 * som redan bevisat att tokenet hör till den inloggade adressen, att
 * containern finns och att inbjudan är `pending` och inte utgången.
 */
class AcceptInvitation
{
    /**
     * @param  Invitation  $invitation  Redan uppslagen och kontrollerad, med
     *                                  `container`-relationen laddad.
     * @param  User  $user  Den inloggade mottagaren.
     * @return Container Containern mottagaren nyss fick åtkomst till — se
     *                   § Beslut 10: klienten kände inte till den innan.
     *
     * @throws ApiException 403 `invitation.email_not_verified`, 422
     *                      `invitation.not_pending`.
     */
    public function handle(Invitation $invitation, User $user): Container
    {
        // § Beslut 6: verifieringskravet ([[ADR-0003 Åtkomstmodell]] —
        // "mottagaren måste skapa konto och verifiera sin e-post") kollas
        // i kod, inte med `verified`-middlewaret. Middlewaret svarar
        // `auth.forbidden` utan att säga varför, och då kan klienten inte
        // skicka användaren vidare till "verifiera din e-post".
        if (! $user->hasVerifiedEmail()) {
            throw ApiException::make('invitation.email_not_verified', [], 403);
        }

        return DB::transaction(function () use ($invitation, $user): Container {
            // Villkorad UPDATE, aldrig en läsning följd av en skrivning:
            // databasen serialiserar UPDATE-satser mot samma rad, så två
            // samtidiga accept-anrop kan aldrig båda lyckas. Samma spärr
            // som App\Support\Auth\MagicLinkBroker § Beslut 3.
            $accepted = Invitation::query()
                ->whereKey($invitation->getKey())
                ->where('status', 'pending')
                ->update(['status' => 'accepted']);

            if ($accepted !== 1) {
                throw ApiException::make('invitation.not_pending', [], 422);
            }

            $container = $invitation->container;

            // § Beslut 8 punkt 2: finns redan en GILTIG åtkomst — samma
            // villkor som App\Policies\ContainerPolicy prövar, via
            // ContainerAccess::scopeValidFor() så de två aldrig glider
            // isär — skapas ingen andra rad. Inbjudan är ändå accepterad
            // och svaret är detsamma.
            //
            // `item_id`-villkoret (issue 72 § Beslut 7) är samma tillägg som
            // dubblettspärren i App\Http\Controllers\Api\ContainerAccessController::store()
            // fick, av samma skäl: utan det kan en itemsinbjudan inte
            // accepteras av någon som redan har en container-bred rad — och
            // det är just kombinationen ADR-0028 § Beslut regel 4 finns till
            // för ("read på containern, write på motorn").
            $harRedanAtkomst = ContainerAccess::query()
                ->where('container_id', $container->id)
                ->where('item_id', $invitation->item_id)
                ->validFor($user, $user->accounts->pluck('id')->values()->all())
                ->exists();

            if (! $harRedanAtkomst) {
                $this->createAccess($invitation, $user);
            }

            // § Beslut 11: ett `read_only` ägarkonto hindrar INTE accept.
            // Regel 4 spärrar skrivande i containern och det gör policyn
            // redan; att låsa accept vore en återvändsgränd — inbjudan
            // skulle gå ut utan att kunna besvaras.
            return $container;
        });
    }

    /**
     * Raden får exakt värdena i § Beslut 9: `kind = 'member'` (en inbjuden
     * person är permanent och individuell — `managed` är en organisation
     * och `guest` är tidsbegränsad, båda beviljas direkt via 9b:s yta),
     * `expires_at = null` av samma skäl, och `granted_by_user_id` pekar på
     * den som DELEGERADE, inte på den som klickade.
     *
     * `container_id`, `item_id` och `granted_by_user_id` sätts explicit på
     * instansen — de är medvetet uteslutna ur
     * App\Models\ContainerAccess#[Fillable]. `item_id` kopieras rakt av från
     * inbjudan (issue 72 § Beslut 7): en itemsinbjudan ger en itemrad, en
     * container-bred inbjudan ger en container-bred rad, och nivån följer med
     * oförändrad.
     */
    private function createAccess(Invitation $invitation, User $user): void
    {
        $access = new ContainerAccess([
            'grantee_type' => 'user',
            'grantee_id' => $user->id,
            'level' => $invitation->level,
            'kind' => 'member',
            'expires_at' => null,
        ]);
        $access->container_id = $invitation->container_id;
        $access->item_id = $invitation->item_id;
        $access->granted_by_user_id = $invitation->invited_by_user_id;
        $access->save();
    }
}
