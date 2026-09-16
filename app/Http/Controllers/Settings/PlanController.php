<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Plan\ReadPlanUsage;
use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plansidan — det valda kontots plan, förbrukning mot gränserna och vad en
 * nedgradering skulle innebära, se issue 66a § Beslut 1, 2 och 3.
 *
 * **Ett konto i taget, och kontot kommer ur ett FÄLT.** Rutten
 * `/settings/plan` har ingen `{account}`-parameter: sidan väljer konto i en
 * väljare och byter med `?account=`, precis som
 * App\Http\Controllers\WebhookEndpointController (65b § Beslut 1). En sida
 * som byter konto ska inte behöva byta URL.
 *
 * **Grinden prövas mot DET kontot, aldrig mot "användarens första"** (Beslut
 * 2). En användare som är medlem i tre konton ser tre olika svar, och ett
 * konto hon inte är medlem i ger 403 — inte en tom sida med någon annans
 * siffror. Grinden är App\Policies\AccountPolicy::viewStorage(), som betyder
 * exakt "får se kontots förbrukning"; ingen ny policymetod behövs.
 *
 * **Läsning kräver ingenting utöver medlemskap**, till skillnad från
 * webhookarnas `manageWebhooks`: siffrorna är kontots egna och en `member` ska
 * se dem. Därför har väljaren inget förval att välja — det första kontot är
 * ett lika gott förval som något annat, och `$user->accounts()` bär bara
 * konton hon redan är med i.
 *
 * **Ingen behörighetslogik och ingen sifferlogik bor här** ([[ADR-0021
 * Frontendteknik]], [[ADR-0024 Tunna controllers och actions]]): allt utom
 * kontovalet och `Gate::authorize()` kommer ur
 * App\Actions\Plan\ReadPlanUsage, som är ren läsning. Ett nekat svar kastar
 * `AuthorizationException`, som bootstrap/app.php renderar som 403.
 *
 * **Ordningen är bindande:** grinden står före `handle()`. En medlem i ett
 * annat konto ska få 403 utan att servern först har räknat ihop kontots
 * förbrukning.
 */
class PlanController extends Controller
{
    /**
     * GET /settings/plan — planen och förbrukningen för det valda kontot.
     *
     * `accounts` är ALLA konton användaren är med i, sorterade på namn. Att
     * utelämna ett konto ur listan vore att dölja en knapp, och
     * behörighetskontroller görs i policies (M10 § ingressen) — en medlem som
     * väljer ett konto hon inte är med i kan inte välja det, för listan är
     * hennes egen.
     *
     * `account` är det valda kontot, så att sidan kan säga vilket den visar
     * (Beslut 1) — oavsett om väljaren ritas eller inte.
     */
    public function index(Request $request, ReadPlanUsage $readPlanUsage): Response
    {
        $user = $request->user();

        $accounts = $user->accounts()->orderBy('name')->get();

        abort_if($accounts->isEmpty(), 404);

        $account = $this->selectedAccount($request, $accounts);

        Gate::authorize('viewStorage', $account);

        return Inertia::render('Settings/Plan', [
            'accounts' => $accounts->map(fn (Account $konto): array => [
                'ulid' => $konto->ulid,
                'name' => $konto->name,
            ])->all(),

            'account' => [
                'ulid' => $account->ulid,
                'name' => $account->name,
            ],

            ...$readPlanUsage->handle($account),
        ]);
    }

    /**
     * Sidans konto: `?account=` om det är ett konto användaren är med i,
     * annars hennes första.
     *
     * **Den sista grenen är skillnaden mot webhookarnas väljare.** Där faller
     * en ULID användaren inte får förvalta tillbaka på ett förval, eftersom
     * väljaren bara är ett förval och grinden prövas separat. Här är kontot
     * sidans hela innehåll: en ULID som pekar på någon annans konto får därför
     * inte tyst bli hennes eget — den går vidare till `Gate::authorize()` i
     * index() och blir 403 (Beslut 2), aldrig en tom sida och aldrig ett annat
     * kontos förbrukning. Ett ULID som inte finns alls är 404, samma svar som
     * varje annan rutt med en `{account}`-parameter ger.
     *
     * Urvalet är fortfarande INTE en grind — det är `Gate::authorize()` som
     * nekar — och en medlem i tre konton kommer alltid förbi här.
     *
     * @param  Collection<int, Account>  $accounts
     */
    private function selectedAccount(Request $request, Collection $accounts): Account
    {
        $ulid = (string) $request->query('account', '');

        $valt = $accounts->firstWhere('ulid', $ulid);

        if ($valt !== null) {
            return $valt;
        }

        if ($ulid === '') {
            return $accounts->first();
        }

        return Account::query()->where('ulid', $ulid)->firstOrFail();
    }
}
