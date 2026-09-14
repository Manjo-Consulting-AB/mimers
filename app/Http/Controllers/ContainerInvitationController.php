<?php

namespace App\Http\Controllers;

use App\Actions\Invitation\CreateInvitation;
use App\Actions\Invitation\RevokeInvitation;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Invitation\StoreInvitationRequest;
use App\Models\Container;
use App\Models\Invitation;
use App\Models\Item;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Webbens avsändaryta för inbjudningar — bjud in och dra tillbaka, se issue
 * 55b § Beslut 5, 6, 7 och 8.
 *
 * **Skrivningarna är utbrutna och delas med `/api`** (§ Beslut 7):
 * App\Actions\Invitation\CreateInvitation och
 * App\Actions\Invitation\RevokeInvitation bär kropparna, och
 * StoreInvitationRequest bär reglerna. Ingen regel formuleras här, och ett
 * fält som avvisas i webben avvisas av samma FormRequest på `/api`.
 *
 * **Kvotgränserna översätts i stället för att renderas som JSON** (§ Beslut
 * 6). `ApiException` implementerar `Responsable` och svarar
 * `{"error":{"code":…}}` var den än kastas, också från en Inertia-kontroller
 * — se App\Support\Frontend\ApiErrorTranslator och issue 54 § Beslut 4.
 * Uppdelningen är issue 54 § Beslut 4:s: `already_pending` är ett fältfel på
 * `email` (det är adressen användaren skrev som redan har en inbjudan), de
 * två kvotfelen hör till formulärnyckeln `quota`.
 *
 * **Ordningen mellan kontrollerna kastas inte om** (§ Beslut 6):
 * duplikatspärren före delningstaket före kontotaket. Ordningen bor i
 * App\Actions\Invitation\CreateInvitation och rörs inte härifrån.
 *
 * `{invitation}` nästlas under `{container}` med `->scopeBindings()` i
 * routes/web.php, av exakt samma skäl som `routes/api.php` gör det (issue 9b
 * § Beslut 1): utan det går en inbjudan i pärm B att dra tillbaka via pärm
 * A:s rutt. En ULID från en annan pärm blir 404.
 *
 * **Ingen behörighetslogik bor här.** Båda metoderna anropar bara
 * `Gate::authorize()` och litar på App\Policies\ContainerPolicy — samma
 * grind `manageAccess()` som `/api`:s två rutter och som 55a:s
 * åtkomstskrivningar. Ett `read_only`-ägarkonto får alltså 403 på både POST
 * och DELETE: att bjuda in är att hantera åtkomster, och regel 4 undantar
 * bara återkallandet av en BEFINTLIG åtkomst.
 *
 * Rutterna ligger bakom `auth` (routes/web.php) — en utloggad besökare
 * skickas till /login av middlewaren och når aldrig de här metoderna.
 */
class ContainerInvitationController extends Controller
{
    /**
     * POST /containers/{container}/invitations — 302 tillbaka till
     * delningssidan.
     *
     * `item` slås upp INOM den container rutten bär, precis som
     * `/api`:s `store()` gör: StoreInvitationRequest har redan bevisat att
     * ULID:en finns där och är levande, så `withTrashed()` behövs inte.
     */
    public function store(
        StoreInvitationRequest $request,
        Container $container,
        CreateInvitation $createInvitation,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('manageAccess', $container);

        $item = $request->validated('item') === null
            ? null
            : Item::where('ulid', $request->validated('item'))->firstOrFail();

        try {
            $createInvitation->handle(
                $request->user(),
                $container,
                $request->validated('email'),
                $request->validated('level'),
                $item,
            );
        } catch (ApiException $e) {
            throw ValidationException::withMessages([$this->field($e->errorCode()) => $translator->message($e)]);
        }

        return back()->with('status', 'invitation-sent');
    }

    /**
     * DELETE /containers/{container}/invitations/{invitation} — 302 tillbaka
     * till delningssidan.
     *
     * Ett svar som inte går att dra tillbaka — en redan accepterad, avvisad
     * eller tillbakadragen rad — är `invitation.not_pending` (422) och blir
     * ett formulärfel på nyckeln `invitation`, inte en rå felkod på skärmen.
     * Vyn renderar den ovanför listan: raden felet gäller är redan besvarad
     * och bär ingen egen yta att sätta felet på.
     */
    public function destroy(
        Container $container,
        Invitation $invitation,
        RevokeInvitation $revokeInvitation,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('manageAccess', $container);

        try {
            $revokeInvitation->handle($container, $invitation);
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['invitation' => $translator->message($e)]);
        }

        return back()->with('status', 'invitation-revoked');
    }

    /**
     * Vilken formulärnyckel felet hör till (issue 55b § Beslut 6, samma
     * uppdelning som issue 54 § Beslut 4 satte): `already_pending` handlar om
     * adressen användaren skrev och hör på fältet `email`; de två kvotfelen
     * handlar om kontots gränser och hör till rutan `quota` ovanför
     * formuläret.
     */
    private function field(string $code): string
    {
        return $code === 'invitation.already_pending' ? 'email' : 'quota';
    }
}
