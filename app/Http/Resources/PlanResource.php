<?php

namespace App\Http\Resources;

use App\Models\Account;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RuntimeException;
use WeakMap;

/**
 * Planen som en klient ser den: `code`, `name` och `limits` rakt av, se
 * issue 51 § Beslut 3.
 *
 * Ingen förbrukning räknas här — den ytan är issue 66 och bor där. `limits`
 * är planens JSON oförändrad, så en ny plangräns blir en ny rad i tabellen
 * och aldrig en ny nyckel i den här klassen (issue 25 § Beslut 2).
 *
 * forAccount() är den enda vägen hit från de delade propsen. Den väljer
 * kontots gällande plan ur App\Models\Account::currentPlan() — planen läses
 * aldrig någon annanstans ifrån — och delar free-uppslaget mellan kontona så
 * att frågekostnaden inte växer med antalet konton (issue 51 § Beslut 3).
 *
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    /**
     * Free-planen, memoiserad per anrop.
     *
     * WeakMap:en nycklas på requesten och töms när den collectas. En
     * processbred statisk cache vore fel: testsviten ändrar planrader
     * (sättPlangräns()) i samma PHP-process, och en cachad modell skulle
     * bära en annan tests limits in i nästa.
     *
     * @var WeakMap<object, Plan>|null
     */
    private static ?WeakMap $freePlans = null;

    /**
     * Kontots gällande plan, i resursform.
     */
    public static function forAccount(Account $account): self
    {
        return new self(self::planFor($account));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'limits' => $this->limits ?? [],
        ];
    }

    /**
     * Grenen är App\Models\Account::currentPlan()s, oformulerad (issue 25
     * § Beslut 6): en aktiv eller past_due-prenumeration bär sin plan, allt
     * annat faller på `free`.
     *
     * Den upprepas här av ett enda skäl. currentPlan() slår upp free-planen
     * med en egen fråga varje gång ingen prenumeration bär, alltså en fråga
     * per konto; de delade propsen ska kosta samma antal frågor för tre
     * konton som för ett (issue 51 § Beslut 3), och frågan delas därför
     * här. Ändras reglerna i modellen ska den här metoden följa med — samma
     * upprepning och samma varning som
     * App\Console\ReportsAbuseSignals::freeAccountsQuery() redan bär.
     */
    private static function planFor(Account $account): Plan
    {
        $subscription = $account->subscription;

        if ($subscription !== null
            && in_array($subscription->status, ['active', 'past_due'], true)
            && $subscription->plan !== null) {
            return $subscription->plan;
        }

        $request = request();

        self::$freePlans ??= new WeakMap;

        return self::$freePlans[$request] ??= Plan::query()->where('code', 'free')->first()
            ?? throw new RuntimeException('Grundplanen [free] saknas i tabellen plan.');
    }
}
