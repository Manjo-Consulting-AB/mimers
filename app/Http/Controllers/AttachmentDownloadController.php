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
 * mjukraderat anropas bara Gate::authorize('view', ...) — sedan issue 71 mot
 * BILAGANS ITEM, App\Policies\ItemPolicy::view() (Beslut 5). Läsning räcker
 * för att ladda ner; det är aldrig `update` och aldrig containern. Det här är
 * filleverans, och en containergrind där itemets skulle stått är exakt det fel
 * [[ADR-0028 Åtkomst på itemnivå]] § Konsekvenser räknar upp: en
 * omfångsbegränsad mottagare hade kunnat hämta en bilaga på ett item hon inte
 * ser. Rutten är i dag inte signerad eller tidsbegränsad — bara auth:sanctum.
 * Skulle en signatur läggas till senare ändrar det ingenting här: den skulle
 * säga vem som bad om länken, inte vad hon får se nu.
 *
 * `{attachment}` binds på bilagans ULID via #[RouteKey('ulid')] — en
 * mjukraderad bilaga syns inte av bindningen och ger 404 (Beslut 7).
 *
 * Sökvägen till bytena är aldrig indata (Beslut 8): rutten tar en ULID, slår
 * upp stored_file-raden och levererar den sökväg som står i databasen.
 */
class AttachmentDownloadController extends Controller
{
    public function __invoke(Request $request, Attachment $attachment): Response
    {
        $attachment->load(['storedFile', 'item.container']);

        // SoftDeletes' globala scope gäller även genom relationerna: item()
        // är en belongsTo mot en mjukraderingsmodell, så ett raderat item ger
        // null här och 404 redan i === null-grenen. Detsamma gäller containern,
        // som laddas via itemets container-relation. trashed()-anropen är
        // bälte-och-hängslen om någon senare lägger withTrashed() i bindningen
        // — de är inte det som skyddar i dag (Beslut 7).
        if ($attachment->item === null || $attachment->item->trashed()) {
            abort(404);
        }

        $container = $attachment->item->container;

        if ($container === null || $container->trashed()) {
            abort(404);
        }

        Gate::authorize('view', $attachment->item);

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
     *
     * Blir fallbacken tom (namn helt utan ASCII-tecken, t.ex. `写真`) eller
     * innehåller den tecken utanför det skrivbara ASCII-intervallet kastar
     * Symfonys hjälpare InvalidArgumentException. Den kan inte användas som
     * `filename=`, men `filename*=UTF-8''…` bär fortfarande det riktiga
     * namnet — fallbacken faller tillbaka på `download`, inget går förlorat.
     */
    private function disposition(string $filename): string
    {
        $fallback = str_replace('%', '', Str::ascii($filename));

        if (! preg_match('/^[\x20-\x7e]+$/', $fallback)) {
            $fallback = 'download';
        }

        return HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $filename,
            $fallback,
        );
    }
}
