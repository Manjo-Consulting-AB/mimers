<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /files/{attachment} — nedladdning av en bilagas byten, se issue 19a
 * och [[ADR-0019 Filleverans]]. En rutt på appdomänen, utanför /api: den
 * klickas i en webbläsare och lämnar inga JSON-fel (Beslut 1).
 *
 * Leveransen görs av webbservern via X-LiteSpeed-Location (Beslut 3 och 6)
 * när config('files.internal_redirect') är true. PHP skickar noll bytes;
 * LiteSpeed läser filen med sendfile() och håller ingen PHP-process upptagen
 * under överföringen. Lokalt och i testsviten (config false) strömmar appen
 * filen själv med Storage::response() — samma headers, riktiga bytes.
 *
 * INGEN behörighetslogik bor här: efter att itemet visat sig inte vara
 * mjukraderat anropas bara Gate::authorize('view', ...) mot den befintliga
 * grinden i App\Policies\ContainerPolicy (Beslut 2). Läsning räcker för att
 * ladda ner; det är aldrig update. `{attachment}` binds på bilagans ULID via
 * #[RouteKey('ulid')] — en mjukraderad bilaga syns inte av bindningen och ger
 * 404 (Beslut 7).
 *
 * Sökvägen till bytena är aldrig indata (Beslut 8): rutten tar en ULID, slår
 * upp stored_file-raden och levererar den sökväg som står i databasen.
 */
class AttachmentDownloadController extends Controller
{
    public function __invoke(Request $request, Attachment $attachment): Response
    {
        $attachment->load(['storedFile', 'item.container']);

        // SoftDeletes' globala scope döljer bilagan i bindningen, men ett
        // mjukraderat item måste kontrolleras uttryckligen — en bilaga i ett
        // item som ligger i papperskorgen är inte nedladdningsbar (Beslut 7).
        if ($attachment->item === null || $attachment->item->trashed()) {
            abort(404);
        }

        $container = $attachment->item->container;

        if ($container === null || $container->trashed()) {
            abort(404);
        }

        Gate::authorize('view', $container);

        $storedFile = $attachment->storedFile;
        $storagePath = $storedFile->storage_path;

        $variant = $request->query('variant');

        if ($variant !== null) {
            // Ett okänt värde är 404, inte 422 — det här är en webbrutt utan
            // valideringshölje (Beslut 5). En saknad variant ger också 404,
            // aldrig en tyst återgång till originalet.
            if (! in_array($variant, ['thumb', 'medium'], true)) {
                abort(404);
            }

            $derivative = $storedFile->derivatives()->where('variant', $variant)->first();

            if ($derivative === null) {
                abort(404);
            }

            $storagePath = $derivative->storage_path;
        }

        $headers = [
            'Content-Type' => $storedFile->mime_type,
            'Content-Disposition' => $this->disposition($attachment->filename),
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (config('files.internal_redirect')) {
            // 200 med tom kropp, inte noContent (204): Symfony tar bort
            // Content-Type ur ett 204-svar när det förbereds, och LiteSpeed
            // sätter inte typen själv vid intern omdirigering (Beslut 3) —
            // då hade typen aldrig nått webbservern. Med 200 följer headern
            // med, och LiteSpeed ersätter kroppen med filens byten.
            return response()->make('', 200, $headers + [
                'X-LiteSpeed-Location' => '/_protected/'.$storagePath,
            ]);
        }

        return Storage::disk('files')->response($storagePath, $attachment->filename, $headers);
    }

    /**
     * Content-Disposition-byggs alltid av Symfonys hjälpare (Beslut 4):
     * `$filename` i `filename*=UTF-8''…` och en ASCII-fallback i `filename=`.
     * Ett namn med citattecken, semikolon eller å-ä-ö sätts aldrig ihop för
     * hand — en oescapad rad i en header är en headerinjektion.
     */
    private function disposition(string $filename): string
    {
        return HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $filename,
            str_replace('%', '', Str::ascii($filename)),
        );
    }
}
