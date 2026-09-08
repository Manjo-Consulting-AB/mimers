<?php

namespace App\Http\Controllers;

use App\Models\Export;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /exports/{export}/download — nedladdning av en färdig export, se issue
 * 41b och [[ADR-0019 Filleverans]]. En rutt på appdomänen, utanför /api
 * (Beslut 1): den klickas i en webbläsare och lämnar inga JSON-fel.
 *
 * Leveransen görs av webbservern via X-LiteSpeed-Location (Beslut 4) när
 * config('files.internal_redirect') är true — samma mekanism som
 * AttachmentDownloadController. Lokalt och i testsviten (config false)
 * strömmar appen filen själv med Storage::response(). Båda grenarna sätter
 * samma headers: en miljöskillnad får aldrig bli en säkerhetsskillnad (issue
 * 19a § Beslut 6).
 *
 * INGEN behörighetslogik bor här (Beslut 2): efter att exporten visat sig
 * vara `ready` anropas bara Gate::authorize('view', ...) mot den befintliga
 * grinden i App\Policies\ContainerPolicy. Läsning räcker för att ladda ner —
 * en export bär exakt det innehåll en view-innehavare redan kan hämta bilaga
 * för bilaga. `{export}` binds på exportens ULID via #[RouteKey('ulid')].
 *
 * Bara en `ready`-export levereras; alla andra statusar ger 404, liksom en
 * mjukraderad container och en artefakt som saknas på disken (Beslut 3).
 * Sökvägen till bytena är aldrig indata (Beslut 5): rutten tar en ULID, slår
 * upp raden och levererar den storage_path som står i databasen.
 */
class ExportDownloadController extends Controller
{
    public function __invoke(Export $export): Response
    {
        $export->load('container');

        // SoftDeletes' globala scope gäller genom relationen: en mjukraderad
        // container ger null här och 404, av samma anledning som i
        // AttachmentDownloadController (Beslut 3).
        if ($export->container === null) {
            abort(404);
        }

        if ($export->status !== Export::STATUS_READY || $export->storage_path === null) {
            abort(404);
        }

        Gate::authorize('view', $export->container);

        // En artefakt som redan är borta från disken är 404, inte ett
        // serverfel — Storage::response() kastar annars FileNotFound.
        if (! Storage::disk('files')->exists($export->storage_path)) {
            abort(404);
        }

        $filename = $export->container->name.'-export-'.$export->created_at->toDateString().'.zip';

        $headers = [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => $this->disposition($filename),
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (config('files.internal_redirect')) {
            // 200 med tom kropp, inte noContent (204): Symfony tar bort
            // Content-Type ur ett 204-svar när det förbereds, och LiteSpeed
            // sätter inte typen själv vid intern omdirigering — se
            // AttachmentDownloadController. Med 200 följer headern med.
            return response()->make('', 200, $headers + [
                'X-LiteSpeed-Location' => '/_protected/'.$export->storage_path,
            ]);
        }

        return Storage::disk('files')->response($export->storage_path, $filename, $headers);
    }

    /**
     * Content-Disposition byggs alltid av Symfonys hjälpare (Beslut 4):
     * `$filename` i `filename*=UTF-8''…` och en ASCII-fallback i `filename=`.
     * Containerns namn är användarinput och en oescapad rad i en header är en
     * headerinjektion. Samma hjälpare och samma fallback-logik som
     * AttachmentDownloadController::disposition().
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
