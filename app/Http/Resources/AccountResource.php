<?php

namespace App\Http\Resources;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ett konto som den inloggade användaren ser det, se issue 51 § Beslut 3.
 *
 * `role` är MEDLEMSKAPETS roll för den inloggade användaren
 * (`account_user.role`), inte en kolumn på kontot. Den läses ur pivoten som
 * User::accounts() redan laddar — ingen ny relation och ingen egen fråga.
 *
 * Ingen e-postadress: identiteten är `name`, samma linje som
 * App\Http\Resources\ParticipantResource drar.
 *
 * `plan` är kontots GÄLLANDE plan ur PlanResource::forAccount(). Det finns
 * inget "aktivt konto" på servern (issue 8 § Beslut 8) — POST bär ägarkontot
 * explicit — så varje konto bär sin egen plan i stället för att en av dem
 * väljs ut.
 *
 * @mixin Account
 */
class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Account $account */
        $account = $this->resource;

        return [
            'ulid' => $account->ulid,
            'name' => $account->name,
            'type' => $account->type,
            'status' => $account->status,
            'role' => $this->membershipRole($account),
            'plan' => PlanResource::forAccount($account)->resolve($request),
        ];
    }

    /**
     * Rollen ur medlemskapsraden. `pivot` sätts av Eloquent när kontot
     * hämtas genom User::accounts() — den enda vägen hit — och läses via
     * getRelation() i stället för det magiska `$account->pivot`, eftersom
     * `pivot` inte är en kolumn på account utan en rad på account_user.
     */
    private function membershipRole(Account $account): string
    {
        $pivot = $account->getRelation('pivot');

        return (string) $pivot->getAttribute('role');
    }
}
