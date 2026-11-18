<?php

namespace App\Support\Mcp;

use Illuminate\Validation\ValidationException;

/**
 * Markörsidindelning för verktyg som listar, se [[ADR-0087 MCP-server för
 * agenter]] Riktning 16: 25 rader som standard, högst 100, och
 * `next_cursor`. Markören är ogenomskinlig för agenten — en base64-kodad
 * JSON-lista med sorteringsnyckeln för sista raden — och en trasig markör är
 * ett valideringsfel, inte en tom sida.
 */
final class Cursor
{
    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 100;

    /**
     * @param  list<int>  $key
     */
    public static function encode(array $key): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($key)), '+/', '-_'), '=');
    }

    /**
     * @param  int  $length  antal element markören ska ha; en markör från ett annat verktyg har ett annat antal
     * @return list<int>|null null utan markör
     */
    public static function decode(?string $cursor, int $length = 1): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);

        if (! is_array($decoded) || count($decoded) !== $length || array_filter($decoded, fn ($v): bool => ! is_int($v)) !== []) {
            throw ValidationException::withMessages(['cursor' => 'invalid']);
        }

        return array_values($decoded);
    }
}
