<?php

namespace App\Support\Mcp;

use App\Exceptions\Api\ApiException;
use App\Support\Api\ApiError;
use App\Support\Api\ValidationErrorMapper;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Throwable;

/**
 * Felformatet i MCP, se [[ADR-0087 MCP-server för agenter]] Riktning 18 och
 * AGENTS.md § Felformat i API:et: `{"error": {"code", "data", "hint"}}` som
 * verktygsresultat med `isError: true`. `data` är `{}` när den är tom, och
 * `hint` utelämnas när koden saknas i App\Support\Mcp\Hints.
 *
 * `server.error` bär aldrig undantagstext — undantaget rapporteras, och
 * agenten får bara koden.
 */
final class ToolError
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function response(string $code, array $data = [], ?string $hint = null): Response
    {
        $error = [
            'code' => $code,
            'data' => ApiError::asJsonObject($data),
        ];

        $hint ??= Hints::for($code);

        if ($hint !== null) {
            $error['hint'] = $hint;
        }

        return Response::error((string) json_encode(['error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function fromThrowable(Throwable $e): Response
    {
        if ($e instanceof McpToolException) {
            return self::response($e->errorCode, $e->data, $e->hint);
        }

        if ($e instanceof ApiException) {
            return self::response($e->errorCode(), $e->data());
        }

        if ($e instanceof ValidationException) {
            return self::response('validation.failed', ['fields' => ValidationErrorMapper::fields($e)]);
        }

        report($e);

        return self::response('server.error');
    }
}
