<?php

namespace App\Http\Controllers;

use App\Actions\Access\RevokeContainerAccess;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\ContainerAccess\UpdateContainerAccessRequest;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\User;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Webbens två skrivningar mot en åtkomst: ändra nivå och återkalla, se issue
 * 55a § Beslut 1 och 9.
 *
 * **Två metoder, och ingen `store()`.** Webben beviljar aldrig en åtkomst
 * direkt — den bjuder in, se ContainerSharingController § Beslut 2.
 * `StoreContainerAccessRequest` används därför inte här, medan
 * `UpdateContainerAccessRequest` delas rakt av med `/api` (§ Beslut 2 i
 * issue 55a:s omfång: ingen ny FormRequest). Att `kind`, `item`,
 * `grantee` och `grantee_type` är `prohibited` i den gäller alltså webben
 * också, utan en rad kod här.
 *
 * **Skrivningarna svarar som webben, inte som API:et** (§ Beslut 9). Ingen
 * JSON-kropp når någonsin en webbläsare: `back()` med en flash-kod
 * (`status` och ingenting annat, issue 51 § Beslut 5), och ett tillståndsfel
 * som ett formulärfel i stället för en felkod på skärmen.
 *
 * `{access}` nästlas under `{container}` med `->scopeBindings()` i
 * routes/web.php, av exakt samma skäl som `routes/api.php` gör det (issue 9b
 * § Beslut 1): utan det går en åtkomst i pärm B att återkalla via pärm A:s
 * rutt. En ULID från en annan pärm blir 404.
 *
 * **Ingen behörighetslogik bor här.** Båda metoderna anropar bara
 * `Gate::authorize()` och litar på App\Policies\ContainerPolicy. Skillnaden
 * mellan de två grindarna är avsiktlig och hela poängen med den
 * `read_only`-rad vyn skriver ut: `manageAccess()` har en kontostatuskontroll,
 * `revokeAccess()` har det inte.
 */
class ContainerAccessController extends Controller
{
    /**
     * PATCH /containers/{container}/accesses/{access} — 302 tillbaka till
     * delningssidan.
     *
     * Kroppen får bära `level` och `expires_at` och ingenting annat —
     * `UpdateContainerAccessRequest` nekar allt övrigt med 422. Vyn skickar
     * bara `level`: `expires_at` redovisas på radan men redigeras inte här.
     *
     * `manageAccess()` auktoriserar, samma grind som `/api`:s `PATCH` och
     * samma grind som att bevilja — att ändra en åtkomst ÄR att hantera
     * åtkomster ([[Konton och åtkomst]] § Behörighetsregler regel 3). Ett
     * `read_only`-ägarkonto får 403 här och lyckas med `DELETE` nedan.
     *
     * **En död rad blir ett formulärfel, inte en rå felkod.** `ApiException`
     * implementerar `Responsable` och svarar `{"error":{"code":…}}` var den
     * än kastas — också från en Inertia-kontroller, precis som kvotfelet i
     * issue 54 § Beslut 4. Kontrollen nedan är samma tillståndsfel som
     * `/api`:s `PATCH` prövar, och koden är densamma
     * (`container_access.revoked`); det som skiljer är svaret, och
     * App\Support\Frontend\ApiErrorTranslator formulerar meningen ur
     * `lang/`. Nyckeln är `level` och inte ett eget fältnamn: felet hör
     * till nivåformuläret, och det är där användaren ska se det.
     *
     * Att höja nivån på en återkallad eller utgången rad är antingen ett
     * misstag eller en väg runt återkallandet, och båda ska nekas.
     */
    public function update(
        UpdateContainerAccessRequest $request,
        Container $container,
        ContainerAccess $access,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('manageAccess', $container);

        try {
            $this->assertEditable($access);
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['level' => $translator->message($e)]);
        }

        $access->fill($request->safe()->only(['level', 'expires_at']));
        $access->save();

        return back()->with('status', 'access-updated');
    }

    /**
     * DELETE /containers/{container}/accesses/{access} — 302 tillbaka till
     * delningssidan.
     *
     * `revokeAccess()` auktoriserar — regel 1 bara, UTAN
     * `read_only`-kontroll, se issue 9b § Beslut 2 och [[Konton och åtkomst]]
     * § Behörighetsregler regel 4. Återkallandet är den enda skrivningen på
     * den här sidan som ett fruset ägarkonto får göra: det minskar
     * exponeringen i stället för att öka den.
     *
     * Själva skrivningen — `revoked_at`, och `access.revoked` i `audit_log` i
     * samma transaktion — bor i App\Actions\Access\RevokeContainerAccess,
     * som `/api`:s `DELETE` anropar. En andra återkallning rör varken
     * tidsstämpeln eller loggen, och svarar ändå utan fel (issue 9b
     * § Beslut 9).
     */
    public function destroy(
        Request $request,
        Container $container,
        ContainerAccess $access,
        RevokeContainerAccess $revokeContainerAccess,
    ): RedirectResponse {
        Gate::authorize('revokeAccess', $container);

        /** @var User $actor */
        $actor = $request->user();

        $revokeContainerAccess->handle($actor, $container, $access);

        return back()->with('status', 'access-revoked');
    }

    /**
     * Är raden levande nog att ändra? Kastar `ApiException` med samma kod som
     * `/api`:s `PATCH` — anroparen översätter den till sitt svar.
     *
     * Villkoret är detsamma som App\Http\Controllers\Api\ContainerAccessController::update()
     * prövar. Det bor i två kontroller och inte i en delad Action därför att
     * issue 55a § Beslut 8 räknar upp exakt tre utbrytningar — ingen av dem
     * en uppdatering — och för att den delade delen här är FELKODEN och
     * översättningen, inte formuleringen: `/api` svarar med koden,
     * webben med en mening.
     *
     * @throws ApiException
     */
    private function assertEditable(ContainerAccess $access): void
    {
        if ($access->revoked_at !== null || ($access->expires_at !== null && $access->expires_at->isPast())) {
            throw ApiException::make('container_access.revoked', ['access' => $access->ulid], 422);
        }
    }
}
