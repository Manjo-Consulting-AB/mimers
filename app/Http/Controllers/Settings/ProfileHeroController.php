<?php

namespace App\Http\Controllers\Settings;

use App\Actions\User\RemoveUserHero;
use App\Actions\User\SetUserHero;
use App\Actions\User\SetUserHeroCrop;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UserHeroCropRequest;
use App\Http\Requests\Settings\UserHeroRequest;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Webbens yta för personens hjältebild — uppladdningen, borttagningen och
 * utsnittet, se [[ADR-0090 Dashboardens hjälte]] § 2, [[ADR-0076
 * Profilbilden]] § 2, 3 och 7 samt issue 434.
 *
 * **Byggd som App\Http\Controllers\Settings\ProfileAvatarController, och
 * ingenting av datamodellen eller kvoten byggs här.**
 * App\Actions\User\SetUserHero härleder personkontot, prövar kvoten, laddar
 * upp genom StoreAttachment och rensar den förra bilden;
 * App\Actions\User\RemoveUserHero nollställer pekaren och rensar bilagan;
 * App\Actions\User\SetUserHeroCrop skriver fokuspunkt och zoom. Den här
 * kontrollern anropar dem, den skriver inte om dem.
 *
 * **Objektet är anroparen själv.** Ingen ruttparameter och ingen policy: raden
 * som skrivs är `$request->user()`. Den som vill ändra någon ANNANS hjältebild
 * har ingen väg in här, för det finns ingen parameter att peka med.
 *
 * **Kvot- och storleksfel blir formulärfel, aldrig en JSON-kropp.**
 * App\Support\Plan\Entitlements och StoreAttachment kastar
 * App\Exceptions\Api\ApiException, som svarar `{"error":{"code":…}}` var den
 * än kastas — också mitt i en Inertia-sida. App\Support\Frontend\
 * ApiErrorTranslator gör koden till en mening, och fångsten lägger den på
 * fältet `file`. Samma väg som profilbilden tar.
 *
 * **Alla tre svarar `back()` med en flash-kod**: `hero-updated`,
 * `hero-removed` och `hero-cropped` står i lang/en/ui.php. Formuläret står på
 * `/settings/profile`, så `back()` är samma sida med färska props.
 */
class ProfileHeroController extends Controller
{
    /**
     * POST /settings/profile/hero — sätter eller byter bilden, 302 tillbaka
     * till profilsidan.
     */
    public function store(
        UserHeroRequest $request,
        SetUserHero $setUserHero,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        $file = $request->file('file');
        assert($file instanceof UploadedFile); // krävd och storleksvaliderad i requesten

        try {
            $setUserHero->handle($request->user(), $file);
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['file' => $translator->message($e)]);
        }

        return back()->with('status', 'hero-updated');
    }

    /**
     * DELETE /settings/profile/hero — tar bort bilden, 302 tillbaka.
     *
     * En person UTAN bild är inget fel: actionen är en no-op och svarar
     * false, och sidan ritas som förut med den inbyggda standardbilden i
     * dashboarden. Att svara 404 hade gjort ett tillstånd utan bild till ett
     * fel — samma val som för profilbilden.
     */
    public function destroy(
        Request $request,
        RemoveUserHero $removeUserHero,
    ): RedirectResponse {
        $removeUserHero->handle($request->user());

        return back()->with('status', 'hero-removed');
    }

    /**
     * PATCH /settings/profile/hero/crop — sparar fokuspunkt och zoom, 302
     * tillbaka till profilsidan med flash-koden `hero-cropped`.
     *
     * **404 när personen saknar bild.** App\Actions\User\SetUserHeroCrop
     * returnerar `false` — ett utsnitt på en bild som inte finns är ett
     * anrop mot ett objekt som inte finns, till skillnad från en borttagning
     * där frånvaron är ett giltigt tillstånd.
     */
    public function crop(
        UserHeroCropRequest $request,
        SetUserHeroCrop $setUserHeroCrop,
    ): RedirectResponse {
        if (! $setUserHeroCrop->handle(
            $request->user(),
            $request->integer('x'),
            $request->integer('y'),
            $request->integer('zoom'),
        )) {
            abort(404);
        }

        return back()->with('status', 'hero-cropped');
    }
}
