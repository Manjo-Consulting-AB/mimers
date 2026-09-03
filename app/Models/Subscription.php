<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kontots prenumeration på en plan — se [[Planer och kvoter]] § subscription.
 *
 * Ett konto har högst en rad här (`account_id` är unikt); statusen bär
 * livscykeln (`active` | `past_due` | `cancelled`), issue 25 § Beslut 5.
 * `grace_until` sätts först vid nedgraderingen (issue 28) och `external_ref`
 * skrivs av betalflödet som inte finns i MVP.
 *
 * `ulid` eftersom prenumerationen hör till ett konto och kan visas i en
 * kontovy — aldrig ett löpnummer utåt (issue 25 § Beslut 1).
 *
 * `account_id`, `plan_id` och `external_ref` är medvetet UTESLUTNA ur
 * `#[Fillable]`: `account_id` binds vid skapandet och är unikt (ett konto,
 * högst en prenumeration — issue 25 § Beslut 5), `plan_id` byts bara genom
 * nedgraderingen i issue 28, och `external_ref` skrivs av det betalflöde som
 * inte finns i MVP. Inget av dem får sättas via massildelning från en
 * request; kod som skapar eller ändrar en prenumeration sätter dem explicit.
 */
#[Fillable(['status', 'current_period_end', 'grace_until'])]
#[RouteKey('ulid')]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory, HasUlid;

    /**
     * Tabellen heter `subscription`, inte Eloquents standardplural.
     */
    protected $table = 'subscription';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_period_end' => 'datetime',
            'grace_until' => 'datetime',
        ];
    }

    /**
     * Kontot prenumerationen gäller.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Planen kontot prenumererar på.
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
