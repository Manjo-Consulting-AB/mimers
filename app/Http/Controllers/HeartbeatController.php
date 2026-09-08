<?php

namespace App\Http\Controllers;

use App\Models\Heartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /drift/heartbeat — dead man's switch-ytan, issue 43 § Beslut 6. Lämnar
 * ut namnen och tidsstämplarna i `heartbeat`-tabellen till vakten på
 * utvecklings-VPS:en (deploy/drift/vakt.sh), som hämtar varje timme. Rutten
 * ligger medvetet utanför `auth`-gruppen: vakten är ingen användare och har
 * ingen session — den delade hemligheten i X-Drift-Token är autentiseringen.
 *
 * Avslaget är alltid 404, aldrig 401: en yta som inte ska existera för den
 * som inte har token. Tre fall avvisas — header saknas, token stämmer inte,
 * eller config('drift.token') är tomt/osatt. Ett tomt konfigvärde får aldrig
 * göra ytan öppen: samma fälla som config/notiser.php § Mailgun-webhooken
 * beskriver för en tom HMAC-nyckel (Beslut 6). Jämförelsen går genom
 * hash_equals, aldrig `===`.
 *
 * Svaret bär bara `now` (produktionens klocka, som vakten räknar åldrar mot
 * för att slippa klockskev mot VPS:en) och `jobs` (namn → senaste lyckade
 * körning). Alla tidsstämplar i UTC med `Z`. Inget annat: ingen version,
 * ingen kölängd, inga radantal. Felhöljet i AGENTS.md § Felformat gäller
 * /api och alltså inte den här rutten.
 */
class HeartbeatController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->header('X-Drift-Token');
        $konfigurerad = config('drift.token');

        if (! is_string($token)
            || ! is_string($konfigurerad)
            || $konfigurerad === ''
            || ! hash_equals($konfigurerad, $token)
        ) {
            abort(404);
        }

        $jobb = Heartbeat::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Heartbeat $h) => [
                $h->name => $h->last_success_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ])
            ->all();

        return response()->json([
            'now' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            // (object) så att en tom tabell ger {} — inte [] — och jobs alltid
            // är ett objekt nycklat på postnamn.
            'jobs' => (object) $jobb,
        ]);
    }
}
