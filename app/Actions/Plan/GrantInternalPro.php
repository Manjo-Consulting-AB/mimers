<?php

namespace App\Actions\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Ger ett internt konto Pro utan betalning — issue 656 och [[M24
 * Desktopdesignen]] § Internt Pro.
 *
 * Actionen är sömmen och har två anropare: registreringen
 * (App\Actions\Auth\CreatesUserWithPersonalAccount) för den som registrerar
 * sig senare, och datamigrationen …_grant_internal_pro för dem som redan
 * finns. Båda går genom samma metod, så regeln formulerats en gång.
 *
 * Pro ges som DATA, inte som ett undantag i planlogiken (Beslut 1): kontot
 * får en vanlig `subscription`-rad med plan `pro`, status `active`,
 * `current_period_end` = 2037-12-31 och `external_ref` = `internal`. Då läser
 * Account::currentPlan(), PlanResource::planFor() och
 * ReportsAbuseSignals::freeAccountsQuery() samma rad som alla andra konton,
 * utan att någon av de tre ändras — de bär samma regel med flit, och ett
 * undantag på ett ställe hade fått dem att glida isär.
 *
 * Vilka adresser som är interna står i config('konton.internal_pro_emails')
 * (Beslut 2); jämförelsen är skiftlägesokänslig. Står användarens e-post inte
 * i listan händer ingenting, och saknar hon ett konto där hon är `owner`
 * händer ingenting heller — actionen gissar aldrig ett konto.
 *
 * Idempotent (Beslut 3): `firstOrNew` hittar den befintliga raden och skriver
 * om den i stället för att lägga en andra — `account_id` är unikt, se
 * migrationen för `subscription`.
 *
 * `account_id`, `plan_id` och `external_ref` sätts explicit och inte via
 * massildelning: de står utanför Subscription::#[Fillable] med flit, se
 * modellens docblock, och samma grepp som
 * App\Actions\OwnershipTransfer\AcceptOwnershipTransfer::grantProTime().
 *
 * `grace_until` nollställs med flit: har kontot nedgraderats tidigare ligger
 * fristen kvar på raden (StartDowngrade sätter den), och ReadPlanUsage räknar
 * fram graceDaysLeft ur den. Utan nollställningen visar ett Pro-konto en
 * nedräkning av en frist som inte gäller. Samma grepp och samma skäl som
 * AcceptOwnershipTransfer::grantProTime().
 */
class GrantInternalPro
{
    /**
     * Datumet ett internt Pro löper till. Långt fram med flit: raden ska
     * aldrig gå ut, och något förnyelseflöde finns inte. Före 2038 med flit
     * också: `current_period_end` är en MariaDB-`timestamp`, som avvisar
     * senare datum (fel 1292) — SQLite i testerna gör det inte.
     */
    private const PERIOD_END = '2037-12-31 00:00:00';

    public function handle(User $user): void
    {
        if (! $this->isInternal($user->email)) {
            return;
        }

        $account = $user->accounts()->wherePivot('role', 'owner')->first();

        if ($account === null) {
            return;
        }

        $plan = Plan::query()->where('code', 'pro')->firstOrFail();

        $subscription = $account->subscription()->firstOrNew([]);

        $subscription->account_id = $account->getKey();
        $subscription->plan_id = $plan->id;
        $subscription->status = 'active';
        $subscription->current_period_end = Carbon::parse(self::PERIOD_END);
        $subscription->external_ref = 'internal';
        $subscription->grace_until = null;

        $subscription->save();
    }

    /**
     * Står adressen i config('konton.internal_pro_emails')? Skiftlägesokänsligt
     * — `Mia@Manjo.ME` är samma adress som `mia@manjo.me`.
     */
    private function isInternal(string $email): bool
    {
        $adresser = array_map(
            static fn (string $adress): string => Str::lower($adress),
            (array) config('konton.internal_pro_emails'),
        );

        return in_array(Str::lower($email), $adresser, true);
    }
}
