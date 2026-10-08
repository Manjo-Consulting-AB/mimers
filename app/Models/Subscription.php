<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Bär raden sin plan just nu? SANNINGEN om giltighet, formulerad EN gång
     * (beslut 1 i issue 265 · [[ADR-0055 Inbjudningskoder och stängd
     * registrering]] § Konsekvenser):
     *
     *   bär sin plan = status `active` och `current_period_end > now()`
     *               ELLER status `past_due`, oavsett datum
     *
     * `past_due` lämnas med flit: en utebliven betalning hanteras av
     * nedgraderingen och dess frist ([[Planer och kvoter]] § Nedgradering),
     * inte av slutdatumet. `cancelled` bär aldrig sin plan. Gränsen är
     * strikt — en rad vars slut är *nu* bär inte sin plan.
     *
     * Giltigheten avgörs när planen LÄSES. Ingen kolumn skrivs om och inget
     * jobb sätter en utgången rad till `cancelled`: en Pro-period löper ut
     * genom den här beräkningen och ingenting annat. Varje ställe som tolkar
     * en prenumeration — `Account::currentPlan()`, `PlanResource::planFor()`,
     * missbruksrapporten och de två livscykeljobben — går genom den här
     * metoden eller `scopeCurrent()`; en andra formulering kan glida isär.
     */
    public function isCurrent(): bool
    {
        if ($this->status === 'past_due') {
            return true;
        }

        return $this->status === 'active' && $this->current_period_end->isFuture();
    }

    /**
     * Rader som bär sin plan just nu — `isCurrent()` som fråga, med samma
     * regel och samma strikta gräns (`current_period_end > now()`).
     *
     * Livscykeljobben använder den för att undanta konton: ett konto vars Pro
     * löpt ut undantas inte och kan alltså stängas och raderas som vilket
     * gratiskonto som helst (beslut 2 i issue 265).
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->where('status', 'past_due')
                ->orWhere(function (Builder $q): void {
                    $q->where('status', 'active')
                        ->where('current_period_end', '>', now());
                });
        });
    }
}
