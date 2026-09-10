<?php

namespace App\Actions\Auth;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Registrering skapar alltid ett konto, inte bara en användare — se
 * [[ADR-0002 Konto äger container]] och issue #17 § Att se upp med: "Ett
 * konto per registrering... Den som registrerar sig behöver alltså ett
 * `account` med `type = 'personal'` och en `account_user`-rad med
 * `role = 'owner'`, annars äger den nya användaren ingenting."
 *
 * Delas av webbens och API:ets registreringskontroller så att kontot
 * skapas på exakt samma sätt oavsett yta.
 */
class CreatesUserWithPersonalAccount
{
    /**
     * @param  string  $name  Personens namn, som hon skrev det — sätts på
     *                        både `user.name` och `account.name`, se issue
     *                        3b (#51) § Beslut 4.
     * @param  string  $password  Klartext — får INTE hashas här. `password_hash`
     *                            har redan en `hashed`-cast på modellen (se
     *                            App\Models\User), en andra Hash::make() skulle
     *                            dubbelhasha lösenordet.
     * @param  string|null  $registrationIp  Requestens IP, hämtad av controllern
     *                                       med `$request->ip()` (Beslut 4) — actionen läser
     *                                       aldrig `request()` själv. Obligatorisk och utan
     *                                       defaultvärde (Beslut 3): en tredje anropare som
     *                                       glömmer den ska få ett fel, inte tyst skriva
     *                                       null. `?string` för att värdet FÅR vara null,
     *                                       inte för att det får utelämnas — en request utan
     *                                       pålitlig IP ska inte hindra en registrering.
     */
    public function handle(string $name, string $email, string $password, ?string $registrationIp): User
    {
        return DB::transaction(function () use ($name, $email, $password, $registrationIp): User {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password_hash' => $password,
            ]);

            // account.locale, timezone och unit_system samlas inte in vid
            // registrering (formuläret tar bara namn, e-post och lösenord).
            // `name` sätts till användarens namn, inte e-postadressen — ett
            // personkonto ÄR den personen, se issue 3b (#51) § Beslut 4.
            // Befintliga konton döps INTE om här; det sker i kontovyerna
            // (M10), eftersom vi inte kan skilja en outnyttjad default från
            // ett namn ägaren redan valt. locale/timezone/unit_system är
            // dokumenterade defaultvärden för ett nyregistrerat
            // personkonto, se granskningen av #17.
            $account = Account::query()->create([
                'type' => 'personal',
                'name' => $name,
                'locale' => 'sv_SE',
                'timezone' => 'Europe/Stockholm',
                'unit_system' => 'metric',
                'status' => 'active',
                // Skrivs i samma transaktion som kontot skapas (Beslut 5) —
                // ingen andra skrivning, ingen ->update() efteråt. Enda
                // stället i app/ där ett konto skapas, se Beslut 3.
                'registration_ip' => $registrationIp,
            ]);

            $account->users()->attach($user, ['role' => 'owner']);

            return $user;
        });
    }
}
