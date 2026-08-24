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
     * @param  string  $password  Klartext — får INTE hashas här. `password_hash`
     *                            har redan en `hashed`-cast på modellen (se
     *                            App\Models\User), en andra Hash::make() skulle
     *                            dubbelhasha lösenordet.
     */
    public function handle(string $email, string $password): User
    {
        return DB::transaction(function () use ($email, $password): User {
            $user = User::query()->create([
                'email' => $email,
                'password_hash' => $password,
            ]);

            // account.name, locale, timezone och unit_system samlas inte in vid
            // registrering (formuläret tar bara e-post och lösenord).
            // `name` sätts till hela e-postadressen — den är entydig och
            // låtsas inte vara ett valt namn, till skillnad från att hitta
            // på ett genom att klippa av delen före '@'. Användaren döper
            // om kontot när kontovyerna byggs i M10. locale/timezone/
            // unit_system är dokumenterade defaultvärden för ett
            // nyregistrerat personkonto, se granskningen av #17.
            $account = Account::query()->create([
                'type' => 'personal',
                'name' => $email,
                'locale' => 'sv_SE',
                'timezone' => 'Europe/Stockholm',
                'unit_system' => 'metric',
                'status' => 'active',
            ]);

            $account->users()->attach($user, ['role' => 'owner']);

            return $user;
        });
    }
}
