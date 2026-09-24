<?php

namespace App\Http\Controllers;

use App\Actions\Access\RevokeContainerAccess;
use App\Actions\Access\UpdateContainerAccess;
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
 * § Beslut 1): utan det går en åtkomst i container B att återkalla via container A:s
 * rutt. En ULID från en annan container blir 404.
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
     * `level` alltid och `expires_at` bara på en rad som redan har ett: en
     * `member`-rad får ingen "sätt utgång"-yta, och webbytan erbjuder ingen
     * väg att tömma ett befintligt datum (arkitektsvaret § 3). Ett datum i
     * det förflutna fastnar på `after:now` och blir ett vanligt fältfel
     * under `errors.expires_at` — ingen `ApiErrorTranslator`, det är ingen
     * domänfelkod.
     *
     * `manageAccess()` auktoriserar, samma grind som `/api`:s `PATCH` och
     * samma grind som att bevilja — att ändra en åtkomst ÄR att hantera
     * åtkomster ([[Konton och åtkomst]] § Behörighetsregler regel 3). Ett
     * `read_only`-ägarkonto får 403 här och lyckas med `DELETE` nedan.
     *
     * **En död rad blir ett formulärfel, inte en rå felkod.** Villkoret bor
     * i App\Actions\Access\UpdateContainerAccess, som `/api`:s `PATCH`
     * anropar — "en död rad ändras inte" är en domäninvariant och ska inte
     * formuleras två gånger. Det som skiljer ytorna är SVARET:
     * `ApiException` implementerar `Responsable` och svarar
     * `{"error":{"code":…}}` var den än kastas, också från en
     * Inertia-kontroller, precis som kvotfelet i issue 54 § Beslut 4. Här
     * fångas den och App\Support\Frontend\ApiErrorTranslator formulerar
     * meningen ur `lang/`. Nyckeln är `level` och inte ett eget fältnamn:
     * felet hör till nivåformuläret, och det är där användaren ska se det.
     */
    public function update(
        UpdateContainerAccessRequest $request,
        Container $container,
        ContainerAccess $access,
        ApiErrorTranslator $translator,
        UpdateContainerAccess $updateContainerAccess,
    ): RedirectResponse {
        Gate::authorize('manageAccess', $container);

        try {
            $updateContainerAccess->handle($container, $access, $request->safe()->only(['level', 'expires_at']), $request->user());
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['level' => $translator->message($e)]);
        }

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
}
