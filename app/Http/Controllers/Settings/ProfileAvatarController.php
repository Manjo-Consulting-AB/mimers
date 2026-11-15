<?php

namespace App\Http\Controllers\Settings;

use App\Actions\User\RemoveUserAvatar;
use App\Actions\User\SetUserAvatar;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserAvatarRequest;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Webbens yta för profilbilden — uppladdningen och borttagningen, se
 * [[ADR-0076 Profilbilden]] § 2, 3 och 6 och [[M59 Profilbilden]] § 380.
 *
 * **Ingenting av datamodellen eller kvoten byggs här.**
 * App\Actions\User\SetUserAvatar härleder personkontot, prövar kvoten, laddar
 * upp genom StoreAttachment och rensar den förra bilden;
 * App\Actions\User\RemoveUserAvatar nollställer pekaren och rensar bilagan.
 * Den här kontrollern anropar dem, den skriver inte om dem — samma uppdelning
 * som App\Http\Controllers\ContainerCoverController gör för containerns bild.
 *
 * **Objektet är anroparen själv.** Ingen ruttparameter och ingen policy:
 * raden som skrivs är `$request->user()`, och det finns inget annat objekt att
 * auktorisera mot. Den som vill ändra någon ANNANS bild har ingen väg in här,
 * för det finns ingen parameter att peka med — samma form som
 * App\Http\Controllers\Settings\ProfileController.
 *
 * **Kvot- och storleksfel blir formulärfel, aldrig en JSON-kropp.**
 * App\Support\Plan\Entitlements och StoreAttachment kastar
 * App\Exceptions\Api\ApiException, som svarar `{"error":{"code":…}}` var den
 * än kastas — också mitt i en Inertia-sida.
 * App\Support\Frontend\ApiErrorTranslator gör koden till en mening med
 * gränsen och värdet i läsbar form, och fångsten lägger den på fältet
 * `file`. Samma väg som containerns bild tar (issue 54 § Beslut 4 och issue
 * 159), och samma för `avatar.no_personal_account` och
 * `attachment.not_image`.
 *
 * **Båda svarar `back()` med en flash-kod** — mönstret från issue 51
 * § Beslut 5, `status` och ingenting annat. Formuläret står på
 * `/settings/profile`, så `back()` är samma sida med färska props.
 */
class ProfileAvatarController extends Controller
{
    /**
     * POST /settings/profile/avatar — sätter eller byter bilden, 302 tillbaka
     * till profilsidan.
     */
    public function store(
        UserAvatarRequest $request,
        SetUserAvatar $setUserAvatar,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        $file = $request->file('file');
        assert($file instanceof UploadedFile); // krävd och storleksvaliderad i requesten

        try {
            $setUserAvatar->handle($request->user(), $file);
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['file' => $translator->message($e)]);
        }

        return back()->with('status', 'avatar-updated');
    }

    /**
     * DELETE /settings/profile/avatar — tar bort bilden, 302 tillbaka.
     *
     * En person UTAN bild är inget fel: actionen är en no-op och svarar
     * false, och sidan ritas som förut med initialerna. Att svara 404 hade
     * gjort ett tillstånd utan bild till ett fel — samma val som för
     * containerns bild.
     */
    public function destroy(
        Request $request,
        RemoveUserAvatar $removeUserAvatar,
    ): RedirectResponse {
        $removeUserAvatar->handle($request->user());

        return back()->with('status', 'avatar-removed');
    }
}
