<?php

namespace App\Http\Controllers\Api;

use App\Actions\User\RemoveUserAvatar;
use App\Actions\User\SetUserAvatar;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserAvatarRequest;
use App\Http\Resources\AuthUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;

/**
 * API:ets yta för profilbilden — uppladdningen och borttagningen, se
 * [[ADR-0076 Profilbilden]] § 2, 3 och 6 och [[M59 Profilbilden]] § 380.
 *
 * **Två TOPPNIVÅrutter under `/me`**: bilden är anroparens egen, och en rutt
 * utan identifierare är en rutt som inte går att peka fel — samma val som
 * /api/me/notification-preferences och /api/me/quiet-hours gjorde (issue 31b
 * § Beslut 1). Ingen policy och ingen Gate: objektet är `$request->user()`.
 *
 * **Samma actions som webben.** App\Actions\User\SetUserAvatar härleder
 * personkontot, prövar kvoten, laddar upp genom StoreAttachment och rensar
 * den förra bilden; App\Actions\User\RemoveUserAvatar nollställer pekaren och
 * rensar bilagan. Kontrollern översätter ingenting: på `/api` gäller
 * felkodshöljet (AGENTS.md § Felformat), till skillnad från webbens
 * App\Http\Controllers\Settings\ProfileAvatarController som gör koden till
 * ett fältfel.
 *
 * **201 med personens resurs** efter uppladdningen — samma
 * App\Http\Resources\AuthUserResource som skalet delar, så klienten ser den
 * nya `avatar`-proppen utan en andra läsning. **204 utan kropp** efter
 * borttagningen: det finns ingenting att säga om en bild som inte finns.
 */
class AvatarController extends Controller
{
    /**
     * POST /api/me/avatar — 201 med personens resurs.
     */
    public function store(UserAvatarRequest $request, SetUserAvatar $setUserAvatar): JsonResponse
    {
        $user = $request->user();

        $file = $request->file('file');
        assert($file instanceof UploadedFile); // krävd och storleksvaliderad i requesten

        $setUserAvatar->handle($user, $file);

        // Resursen läser avatarAttachment.storedFile.derivatives; sätt dem
        // direkt så svaret gör ett konstant antal frågor.
        $user->load('avatarAttachment.storedFile.derivatives');

        return AuthUserResource::make($user)->response()->setStatusCode(201);
    }

    /**
     * DELETE /api/me/avatar — 204. En person utan bild är en no-op, inte ett
     * fel: actionen svarar false och anropet lyckas ändå.
     */
    public function destroy(Request $request, RemoveUserAvatar $removeUserAvatar): Response
    {
        $removeUserAvatar->handle($request->user());

        return response()->noContent();
    }
}
