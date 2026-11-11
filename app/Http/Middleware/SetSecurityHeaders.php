<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issue 1043 · Säkerhetshuvuden på varje svar i webb- och API-gruppen.
 *
 * Ett huvud som svaret redan bär lämnas orört: den som satte det har
 * bestämt. X-Frame-Options sätts inte på ett svar med egen
 * Content-Security-Policy — App\Support\Files\AttachmentDelivery låter
 * appens egen origin rama in en PDF via frame-ancestors, och DENY hade
 * brutit det. HSTS sätts bara över https, utan includeSubDomains och
 * preload (driftbeslut). Ingen CSP för appens sidor här.
 */
class SetSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $this->setIfMissing($response, 'X-Content-Type-Options', 'nosniff');
        $this->setIfMissing($response, 'Referrer-Policy', 'strict-origin-when-cross-origin');

        if (! $response->headers->has('Content-Security-Policy')) {
            $this->setIfMissing($response, 'X-Frame-Options', 'DENY');
        }

        if ($request->isSecure()) {
            $this->setIfMissing($response, 'Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }

    private function setIfMissing(Response $response, string $name, string $value): void
    {
        if (! $response->headers->has($name)) {
            $response->headers->set($name, $value);
        }
    }
}
