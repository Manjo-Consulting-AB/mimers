<?php

namespace App\Http\Controllers;

use App\Actions\Container\RemoveContainerCover;
use App\Actions\Container\SetContainerCover;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Container\ContainerCoverRequest;
use App\Models\Account;
use App\Models\Container;
use App\Support\Frontend\ApiErrorTranslator;
use App\Support\Plan\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Webbens yta för containerns bild — uppladdningen och borttagningen, se
 * [[ADR-0047 Containerns bild]] § Beslut och [[M23 Mobilen och kartan]] § 159.
 *
 * **Ingenting av datamodellen eller grinden byggs här.** Issue 158 äger båda:
 * App\Actions\Container\SetContainerCover laddar upp genom StoreAttachment med
 * `container_id`, kräver `kind = image` och rensar den förra bilden, och
 * App\Actions\Container\RemoveContainerCover nollställer pekaren och rensar
 * bilagan. Den här kontrollern anropar dem, den skriver inte om dem.
 *
 * **Kontot som betalar är det UPPLADDANDE kontot, och det kommer ur kroppen.**
 * `billed_account_id` är det uppladdande kontot, och bytena räknas mot dess
 * kvot ([[ADR-0047 Containerns bild]] § Beslut) — inte containerns ägarkonto.
 * En container-bred `write`-mottagare som är främmande för ägarkontot belastar
 * sitt EGET konto; annars vore varje mottagares uppladdning en väg in i
 * ägarkontots kvot, vilket är exakt den vektor [[ADR-0017 Missbruksvektorer]]
 * stänger. Kontot kommer i fältet `account` ur
 * App\Http\Requests\Container\ContainerCoverRequest — samma fält, samma regel
 * och samma medlemsprövning som webbens itembilaga i
 * App\Http\Controllers\AttachmentController: en ULID som inte finns är ett
 * valideringsfel, ett konto användaren inte är medlem i är 403. Arket
 * (resources/js/components/ContainerCoverSheet.vue) skickar samma förval som
 * itemets uppladdning, dolt; det har fortfarande tre rader och ingen
 * kontoväljare (ADR-0047 § Beslut).
 *
 * **Fyra grindar, och den första är behörigheten.** `ContainerPolicy::update`
 * — samma pinne som att byta containerns namn — prövas först, så en användare
 * som inte får ändra containern får 403 och aldrig veta något om kontots
 * kvot (samma ordning som App\Http\Controllers\AttachmentController § Beslut
 * 4 och 5). Sedan medlemsprövningen på det anropade kontot. Actionen prövar
 * samma container-grind en gång till; den ligger där för att den är ytan för
 * varje anropare, och en andra prövning av samma policy är billigare än två
 * formuleringar av samma regel.
 *
 * **Kvot- och storleksfel blir formulärfel, aldrig en JSON-kropp** (Beslut 5).
 * App\Support\Plan\Entitlements kastar App\Exceptions\Api\ApiException, som
 * svarar `{"error":{"code":…}}` var den än kastas — också mitt i en
 * Inertia-sida. App\Support\Frontend\ApiErrorTranslator gör koden till en
 * mening med gränsen och värdet i läsbar form, och fångsten lägger den på
 * fältet `file`. Det gäller BÅDA kontrollerna, precis som för en itembilaga:
 * den billiga avvisningen här och den auktoritativa inne i StoreAttachments
 * transaktion, som kastar samma kod. Ett dokument avvisas av actionen med
 * `attachment.not_image`, som översätts på samma väg.
 *
 * Två rutter och ingen sida: bilden kommer med containerns egen prop ur
 * App\Http\Resources\ContainerResource (se `cover` där), och en andra väg till
 * samma läsning hade varit en andra sanning om vilken bild som är satt.
 *
 * Båda svarar `back()` med en flash-kod — mönstret från issue 51 § Beslut 5,
 * `status` och ingenting annat. Arket står på containerns sida (eller i
 * skalet, som följer med varje containerns sida), så `back()` är samma sida
 * med färska props och den nya bilden i toppraden.
 */
class ContainerCoverController extends Controller
{
    /**
     * POST /containers/{container}/cover — sätter eller byter bilden, 302
     * tillbaka till sidan arket öppnades från.
     */
    public function store(
        ContainerCoverRequest $request,
        Container $container,
        SetContainerCover $setContainerCover,
        Entitlements $entitlements,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('update', $container);

        // Kontot som betalar är det uppladdande kontot (ADR-0047 § Beslut).
        // Uppslagsformen och medlemsprövningen är
        // App\Http\Controllers\AttachmentController:s, ordagrant: samma
        // fält, samma 404-fria validering och samma 403.
        $account = Account::where('ulid', $request->validated('account'))->firstOrFail();

        if (! $account->users()->whereKey($request->user()->id)->exists()) {
            abort(403);
        }

        $file = $request->file('file');
        assert($file instanceof UploadedFile); // krävd och storleksvaliderad i requesten ovan

        $byteSize = $file->getSize();
        if ($byteSize === false) {
            throw new RuntimeException('Den mottagna filen kunde inte läsas.');
        }

        try {
            // Styckstorleken prövas bara här; totalkvoten prövas här som en
            // billig avvisning och en gång till, auktoritativt, inne i
            // StoreAttachments transaktion (issue 27b § Beslut 4).
            $entitlements->assertFileWithinLimit($account, $byteSize);
            $entitlements->assertStorageWithinLimit($account, $byteSize);

            $setContainerCover->handle($container, $file, $request->user(), $account);
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['file' => $translator->message($e)]);
        }

        return back()->with('status', 'container-cover-updated');
    }

    /**
     * DELETE /containers/{container}/cover — tar bort bilden, 302 tillbaka.
     *
     * Grinden är `ContainerPolicy::update`, samma pinne som uppladdningen, och
     * den ligger i App\Actions\Container\RemoveContainerCover::handle() — den
     * här metoden har ingen egen auktorisering av samma skäl som
     * ContainerController::destroy() lånar sin av TrashContainer: actionen är
     * ytan för varje anropare.
     *
     * En container UTAN bild är inget fel: actionen är en no-op och svarar
     * false, och sidan ritas som förut med den neutrala ytan. Att svara 404
     * hade varit att göra ett tillstånd utan bild till ett fel.
     */
    public function destroy(
        Container $container,
        RemoveContainerCover $removeContainerCover,
    ): RedirectResponse {
        $removeContainerCover->handle($container);

        return back()->with('status', 'container-cover-removed');
    }
}
