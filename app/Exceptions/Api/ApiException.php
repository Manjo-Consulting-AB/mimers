<?php

namespace App\Exceptions\Api;

use App\Support\Api\ApiError;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Ett enda, toppnivåkodat API-fel — se AGENTS.md § Felformat i API:et och
 * issue 7 § Beslut som redan är fattade punkt 2. Kastas bara från kod som
 * redan vet att den körs på `/api` (t.ex. en API-kontroller som fångar ett
 * webb-orienterat undantag och översätter det, se
 * App\Http\Controllers\Api\Auth\AuthenticatedTokenController) — inte
 * registrerad som en generell mappning i bootstrap/app.php, för den skulle
 * annars behöva gissa om ett godtyckligt RuntimeException hör hemma i
 * höljet eller inte.
 *
 * Implementerar `Responsable` i stället för att registreras som en
 * `$exceptions->render()`-closure i bootstrap/app.php — Laravels
 * `Handler::render()` känner av `Responsable` och anropar `toResponse()`
 * direkt, före den vanliga undantagsrenderingen.
 */
final class ApiException extends RuntimeException implements Responsable
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        private readonly string $errorCode,
        private readonly array $data = [],
        private readonly int $status = 422,
    ) {
        parent::__construct($errorCode);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function make(string $code, array $data = [], int $status = 422): self
    {
        return new self($code, $data, $status);
    }

    public function toResponse($request): JsonResponse
    {
        return ApiError::response($this->errorCode, $this->data, $this->status);
    }
}
