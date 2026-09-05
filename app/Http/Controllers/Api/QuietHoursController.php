<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateQuietHoursRequest;
use Illuminate\Http\JsonResponse;

/**
 * Tysta timmar och tidszon på den inloggade användaren, issue 31b § Beslut 5.
 * En rutt under `/me` (Beslut 1), samma toppnivåval som preferensytan: bara
 * den inloggade användarens egen rad, härledd ur `$request->user()`.
 *
 * Tidszonen hör hit fast den ser ut som en profilinställning — [[Notiser]] §
 * Tysta timmar och tidszon binder ihop dem. `locale` och `unit_system`
 * ändras inte här (Beslut 5, Out of scope).
 */
class QuietHoursController extends Controller
{
    /**
     * PATCH /api/me/quiet-hours — 200 med de tre fälten. Sparar fönstret
     * (start/end som `H:i` eller `null`, båda tillsammans) och tidszonen.
     *
     * Kolumnen är MySQL `TIME`, som lagrar `22:00:00` medan klienten skickar
     * `22:00` (issue 31b § Att se upp med) — svaret normaliseras därför till
     * `H:i` så en tur och retur ger tillbaka samma sträng som skickades in.
     */
    public function update(UpdateQuietHoursRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update($request->validated());

        return response()->json([
            'quiet_hours_start' => self::timeToApi($user->quiet_hours_start),
            'quiet_hours_end' => self::timeToApi($user->quiet_hours_end),
            'timezone' => $user->timezone,
        ]);
    }

    /**
     * Ett klockslag från TIME-kolumnen (`'22:00:00'`, i sqlite ibland
     * `'22:00'`) till API:ets `H:i`.
     */
    private static function timeToApi(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }
}
