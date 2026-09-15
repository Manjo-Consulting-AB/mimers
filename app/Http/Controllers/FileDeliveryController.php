<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Support\Files\AttachmentDelivery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /files/{attachment} på filoriginet — bytena, se issue 61a och
 * [[ADR-0019 Filleverans]] § Uppföljning 2026-09-15.
 *
 * **Den här rutten finns bara när filoriginet finns.** Är `config('files.url')`
 * osatt registreras den inte alls (routes/web.php), och appdomänens rutt
 * levererar bytena själv precis som förut (Beslut 2). Rutten är dessutom bunden
 * till filoriginets värdnamn via `->domain(...)`: samma sökväg finns på
 * appdomänen, där `files.download` äger den.
 *
 * **Signaturen är den enda grinden här** (Beslut 1). Sessionskakan gäller
 * appens värdnamn och följer inte med hit, så `signed`-middlewaren är inte ett
 * lager ovanpå en inloggning — den är autentiseringen. Behörigheten prövades
 * när länken präglades i AttachmentDownloadController; här finns ingen
 * användare att pröva den mot, och därför finns ingen policy, ingen session
 * och ingen användare i den här vägen (Beslut 10).
 *
 * **Länken är bärarbaserad under sin livstid** (Beslut 4): den som har den kan
 * hämta filen. Det är samma egenskap en presignerad S3-URL har, och det är
 * därför livstiden är kort (15 minuter som standard).
 *
 * `{attachment}` binds på bilagans ULID via #[RouteKey('ulid')] — en
 * mjukraderad bilaga syns inte av bindningen och ger 404, precis som på
 * appdomänen.
 */
class FileDeliveryController extends Controller
{
    public function __invoke(Request $request, Attachment $attachment): Response
    {
        $attachment->load('storedFile');

        // `inline: true` — det är här en egen origin finns att rendera i. Vad
        // som får använda den avgörs av tillåt-listan på serverns lagrade
        // MIME-typ, aldrig av URL:en (Beslut 5).
        return AttachmentDelivery::make($attachment, $request->query('variant'), inline: true);
    }
}
