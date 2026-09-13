<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateAccountSettingsRequest;
use App\Models\Account;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kontosidan — ett kort per konto den inloggade användaren är medlem i, se
 * issue 53c § Beslut 1, 5 och 9.
 *
 * Den andra av issuen två skrivande ytor, och den första som skriver `account`
 * utanför registreringen. Det är därför riskklassen är `elevated`: en policy
 * som släpper igenom en `member`, eller en kontroller som binder kontot ur
 * kroppen i stället för ur rutten, låter vem som helst döpa om någon annans
 * varvskonto.
 *
 * **Objektet kommer ur rutten, aldrig ur kroppen.** `{account}` binds på
 * kontots ULID via `#[RouteKey('ulid')]` på App\Models\Account (Beslut 1).
 * Ett konto som identifieras i kroppen är en rutt utan objekt att auktorisera
 * mot — det finns ingenting att fråga `Gate::authorize()` om, och då skriver
 * kontrollern det kroppen pekade ut.
 *
 * **Ingen behörighetslogik bor här** ([[ADR-0021 Frontendteknik]],
 * [[ADR-0024 Tunna controllers och actions]]): varje metod som rör ett
 * specifikt konto anropar bara `Gate::authorize()` respektive `Gate::allows()`
 * och litar på App\Policies\AccountPolicy::update(), precis som
 * App\Http\Controllers\Api\ContainerController gör mot ContainerPolicy. Ett
 * nekat svar kastar `AuthorizationException`, som bootstrap/app.php renderar
 * som felsidan för 403 på webben.
 *
 * **Läsning kräver ingenting utöver medlemskap.** Listan är konton ur
 * `$request->user()->accounts` — användaren är per konstruktion medlem i varje
 * rad — så det finns ingen fråga att neka. Vilka som får ÄNDRA ett kort
 * avgörs per konto av policyn och följer med som `canUpdate` (Beslut 9):
 * vyn visar ett kort antingen med formulär eller med läsbara värden, och
 * aldrig med utgråa fält.
 */
class AccountSettingsController extends Controller
{
    /**
     * GET /settings/accounts — ett kort per konto användaren är medlem i,
     * sorterade på namn.
     *
     * `role` är medlemskapets roll ur pivoten (`account_user.role`), samma
     * fält som App\Http\Resources\AccountResource läser. Den behövs för att
     * vyn ska kunna visa VILKEN roll användaren har på kortet; vad rollen
     * tillåter avgörs av policyn, inte av vyn.
     *
     * `canUpdate` frågar policyn en gång per konto. Det är en fråga per kort
     * på en sida med ett fåtal kort, och alternativet — att härleda svaret ur
     * `$account->pivot->role` och `$account->status` — hade varit en andra
     * kopia av policyns regel i en kontroller, vilket är precis vad den här
     * klassen inte får innehålla.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $accounts = $user->accounts()->orderBy('name')->get();

        return Inertia::render('Settings/Accounts', [
            'accounts' => $accounts->map(fn (Account $account): array => [
                'ulid' => $account->ulid,
                'name' => $account->name,
                'role' => (string) $account->getRelation('pivot')->getAttribute('role'),
                'locale' => $account->locale,
                'timezone' => $account->timezone,
                'unitSystem' => $account->unit_system,
                'canUpdate' => Gate::allows('update', $account),
            ])->all(),

            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    /**
     * PATCH /settings/accounts/{account} — skriver kontots fyra fält.
     *
     * Auktoriseringen är första raden, före varje skrivning: är svaret nej
     * kastas `AuthorizationException` och `update()` nås aldrig. Ett
     * `read_only`- eller `closed`-konto nekas därför även för sin `owner` —
     * regel 4 undantar bara att återkalla en åtkomst och att rensa lagring,
     * och ett namnbyte är ingendera. Se [[Konton och åtkomst]]
     * § Behörighetsregler regel 4 och AccountPolicy::update().
     */
    public function update(UpdateAccountSettingsRequest $request, Account $account): RedirectResponse
    {
        Gate::authorize('update', $account);

        $account->update($request->validated());

        return redirect()
            ->route('settings.accounts')
            ->with('status', 'account-updated');
    }
}
