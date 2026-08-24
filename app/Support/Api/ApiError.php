<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;
use stdClass;

/**
 * Höljet för varje felsvar på `/api`, se AGENTS.md § Felformat i API:et och
 * issue 7: `{ "error": { "code", "data" } }`. `code` är alltid en
 * punktseparerad, stabil sträng. `data` finns alltid, även tom — och tom
 * `data` ska serialiseras som `{}`, inte `[]`, så en klient som gör
 * `JSON.parse(...).error.data` aldrig behöver hantera två typer. Ingen
 * `message`-nyckel, inte ens som bekvämlighet — klienten översätter koden.
 */
final class ApiError
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function response(string $code, array $data = [], int $status = 422): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'data' => self::asJsonObject($data),
            ],
        ], $status);
    }

    /**
     * `[]` kodas som JSON-array av `json_encode`/`response()->json()`.
     * Tvingar tomma associativa arrayer till `stdClass` så de i stället blir
     * `{}` — se klassdokumentationen ovan.
     *
     * @param  array<string, mixed>  $data
     */
    public static function asJsonObject(array $data): array|stdClass
    {
        return $data === [] ? new stdClass : $data;
    }
}
