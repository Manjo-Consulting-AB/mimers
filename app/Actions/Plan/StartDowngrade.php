<?php

namespace App\Actions\Plan;

use App\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * Nedgraderingen av ett konto till `read_only` — issue 28, steg 1 och 2. Se
 * [[Planer och kvoter]] § Nedgradering och [[ADR-0009 Kvoter och livscykel]].
 *
 * Actionen är sömmen i hela nedgraderingen och ingenting anropar den
 * automatiskt i MVP (Beslut 1): betalflödet finns inte, så det är den
 * webhook-issue som en dag tar emot en utebliven betalning som anropar det
 * här förloppet. Den byggs och testas direkt, precis som assertFeature() i
 * 27a.
 *
 * Stegen i en transaktion (Beslut 1):
 *
 * 1. `account.status = 'read_only'` och `account.read_only_reason = $reason`.
 * 2. Finns en subscription sätts `grace_until = now()->addMonths(3)`.
 * 3. Idempotent: ett konto som REDAN är `read_only` får ingen ny frist.
 *    Annars förlänger varje omkörning fristen och steg 4 (den automatiska
 *    raderingen, issue 28b) inträffar aldrig.
 *
 * Tre månader är fristen — talet räknas ut HÄR och bara här; 28b läser
 * `grace_until` som den rad nedgraderingen satte och räknar inte om det. Ett
 * konto utan prenumeration har ingen `grace_until`-rad att sätta — det är
 * inte ett fel (28b hanterar det fallet), och ingen prenumeration skapas åt
 * kontot här.
 *
 * $reason är ett av `payment_failed` och `over_quota` — de värden
 * `account.read_only_reason` redan tillåter enligt sitt CHECK-villkor
 * (`inactivity` hör till 29a). Inga varningar skickas här (Beslut 7): M5:s
 * notisjobb räknar bakåt från `grace_until`.
 */
class StartDowngrade
{
    public function handle(Account $account, string $reason): void
    {
        DB::transaction(function () use ($account, $reason): void {
            // Raden läses om under radlåset — en current read, samma mönster
            // som PurgeAttachment. Två samtidiga nedgraderingar av samma
            // konto ska inte båda passera idempotenskontrollen och flytta
            // fristen framåt två gånger.
            $rad = Account::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->first();

            if ($rad === null) {
                return;
            }

            if ($rad->status === 'read_only') {
                return;
            }

            $rad->status = 'read_only';
            $rad->read_only_reason = $reason;
            $rad->save();

            $subscription = $rad->subscription;

            if ($subscription !== null) {
                $subscription->grace_until = now()->addMonths(3);
                $subscription->save();
            }
        });
    }
}
