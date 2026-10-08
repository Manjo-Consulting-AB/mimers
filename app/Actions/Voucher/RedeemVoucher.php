<?php

namespace App\Actions\Voucher;

use App\Models\Account;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use Illuminate\Validation\ValidationException;

/**
 * Lös in en voucher: räkna upp den, skriv inlösen och ge kontot planen — se
 * [[ADR-0055 Inbjudningskoder och stängd registrering]] § 6.
 *
 * **Anropas inuti anroparens transaktion.** Actionen öppnar ingen egen, med
 * flit: inlösen sker i samma transaktion som kontot skapas
 * (App\Actions\Auth\CreatesUserWithPersonalAccount), alltså antingen blir
 * både kontot och inlösen till eller inget av dem. Ett konto som skapats utan
 * att koden förbrukats vore ett konto som kommit in gratis.
 *
 * Gången är ADR-0055 § 6, i ordning:
 *
 * 1. Lås vouchern med `lockForUpdate`. Låset hindrar två samtidiga
 *    registreringar från att båda se ett uttag kvar och båda räkna upp det.
 * 2. Pröva att den inte är återkallad, inte har gått ut och har uttag kvar —
 *    och att kontot inte redan löst in just den här koden. Formuleringen bor
 *    i Voucher::isRedeemable(); det sista villkoret hör hit, för det gäller
 *    paret (voucher, konto) och inte vouchern. Det unika indexet i
 *    migrationen är backstoppet; prövningen här gör felet till ett
 *    valideringsfel i stället för en databasdom.
 * 3. Räkna upp `used_count` och skriv `voucher_redemption`.
 * 4. Ge planen som data, som i App\Actions\Plan\GrantInternalPro.
 *
 * Är vouchern inte inlösbar kastas `ValidationException` på `voucher_code`.
 * Meddelandenyckeln är `validation.redeemable_voucher`, samma svar som en
 * ogiltig kod ger — svaret skiljer inte fallen åt, så det går inte att pröva
 * sig fram till vilka koder som finns (ADR-0055 § 8). Koden blir
 * `validation.invalid` i API-höljet, eftersom undantaget inte bär någon
 * valideringsregel, och det är avsiktligt: det här är kapplöpningen där
 * valideringen redan godkänt koden (ADR-0055 § Konsekvenser).
 *
 * Planen ges med `external_ref = 'voucher'` (ADR-0055 § 6). Har kontot redan
 * en AKTIV rad med samma plan och ett SENARE slutdatum — ett internt Pro —
 * lämnas den orörd: koden förbrukas, men ett redan längre Pro kortas inte av
 * ett kortare. `grace_until` nollställs av samma skäl som i
 * GrantInternalPro::handle(): en kvarliggande frist från en tidigare
 * nedgradering ska inte visas på ett Pro-konto.
 */
class RedeemVoucher
{
    /**
     * @throws ValidationException på `voucher_code` när vouchern inte är
     *                             inlösbar för det här kontot.
     */
    public function handle(Voucher $voucher, Account $account, User $user): void
    {
        $locked = Voucher::query()->whereKey($voucher->getKey())->lockForUpdate()->firstOrFail();

        if (! $locked->isRedeemable() || $this->alreadyRedeemed($locked, $account)) {
            throw ValidationException::withMessages([
                'voucher_code' => __('validation.redeemable_voucher'),
            ]);
        }

        $locked->used_count = $locked->used_count + 1;
        $locked->save();

        $redemption = new VoucherRedemption;
        $redemption->voucher_id = $locked->getKey();
        $redemption->account_id = $account->getKey();
        $redemption->user_id = $user->getKey();
        $redemption->save();

        $this->grantPlan($locked, $account);
    }

    /**
     * Har det här kontot redan löst in just den här koden? En kod med
     * `max_uses` > 1 betyder flera KONTON, inte flera inlösen på samma konto
     * — se det unika indexet i migrationen för `voucher_redemption`.
     */
    private function alreadyRedeemed(Voucher $voucher, Account $account): bool
    {
        return VoucherRedemption::query()
            ->where('voucher_id', $voucher->getKey())
            ->where('account_id', $account->getKey())
            ->exists();
    }

    /**
     * Ge kontot kodens plan, i samma form som GrantInternalPro: en vanlig
     * `subscription`-rad, inte ett undantag i planlogiken. Då läser
     * Account::currentPlan() och allt annat som tolkar en plan samma rad som
     * för alla andra konton.
     *
     * `account_id`, `plan_id` och `external_ref` sätts explicit — de står
     * utanför Subscription::#[Fillable] med flit.
     */
    private function grantPlan(Voucher $voucher, Account $account): void
    {
        $plan = $voucher->plan()->firstOrFail();

        $periodEnd = now()->addDays($voucher->duration_days);

        $subscription = $account->subscription()->firstOrNew([]);

        if (
            $subscription->exists
            && $subscription->status === 'active'
            && (int) $subscription->plan_id === (int) $plan->getKey()
            // Kolumnen är NOT NULL, så en befintlig rad har alltid ett slut.
            && $subscription->current_period_end->gt($periodEnd)
        ) {
            return;
        }

        $subscription->account_id = $account->getKey();
        $subscription->plan_id = $plan->getKey();
        $subscription->status = 'active';
        $subscription->current_period_end = $periodEnd;
        $subscription->external_ref = 'voucher';
        $subscription->grace_until = null;

        $subscription->save();
    }
}
