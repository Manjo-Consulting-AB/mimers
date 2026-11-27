<?php

namespace App\Http\Middleware;

use App\Exceptions\Api\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use League\OAuth2\Server\Exception\OAuthServerException;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\UserDataBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Issue 387 · Det som skickas till Sentry, se [[ADR-0079 Övervakning]] § 1.
 *
 * Ligger i app/Http/Middleware/ för att issuens ruta säger så, trots att det
 * inte är en middleware: `beforeSend` är config/sentry.php:s `before_send`
 * som strängcallable (config:cache kan inte serialisera en closure), och
 * `isClientError` samlar listan över undantag som aldrig rapporteras på ETT
 * ställe, så att bootstrap/app.php och before_send inte kan glida isär.
 *
 * SDK:ns egen inloggningslyssnare lägger id, e-post, användarnamn och
 * IP-adress på användaren även när send_default_pii är av. Därför ersätts
 * användaren här i stället för att kompletteras.
 */
final class ScrubSentryEvent
{
    /**
     * Undantag som är klientens fel och aldrig ska rapporteras. 403 kommer
     * som AuthorizationException eller AccessDeniedHttpException, 404 som
     * NotFoundHttpException eller ModelNotFoundException, 419 som
     * TokenMismatchException.
     *
     * @var list<class-string<Throwable>>
     */
    public const CLIENT_ERRORS = [
        ValidationException::class,
        NotFoundHttpException::class,
        ModelNotFoundException::class,
        AuthorizationException::class,
        AccessDeniedHttpException::class,
        TokenMismatchException::class,
        AuthenticationException::class,
    ];

    /** Rubriker som aldrig lämnar servern. */
    private const SENSITIVE_HEADERS = ['cookie', 'authorization', 'x-xsrf-token', 'x-csrf-token'];

    /** Brödsmulenivåer som behålls: warning och uppåt. */
    private const BREADCRUMB_LEVELS = [
        Breadcrumb::LEVEL_WARNING,
        Breadcrumb::LEVEL_ERROR,
        Breadcrumb::LEVEL_FATAL,
    ];

    /**
     * Sant för undantag som inte ska rapporteras: klientfelen ovan och ett
     * ApiException med en statuskod under 500.
     */
    public static function isClientError(Throwable $e): bool
    {
        foreach (self::CLIENT_ERRORS as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        // Passports TokenGuard rapporterar Leagues undantag via report($e) när
        // en bearer-token är ogiltig eller utgången (401), innan gaten svarar
        // oinloggad. Det är klientens fel.
        if ($e instanceof OAuthServerException) {
            return $e->getHttpStatusCode() < 500;
        }

        // ApiException bär statusen privat och har ingen accessor; svaret
        // är det enda publika sättet att läsa den.
        return $e instanceof ApiException && $e->toResponse(request())->getStatusCode() < 500;
    }

    /**
     * De 16 första tecknen av en HMAC över ULID:n med appens nyckel: samma
     * person ger samma id, men ULID:n kan inte läsas ut ur det.
     */
    public static function pseudonym(string $ulid): string
    {
        return substr(hash_hmac('sha256', $ulid, (string) config('app.key')), 0, 16);
    }

    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        // En bakstopp utöver dontReport: också ett undantag som loggats via
        // Log::error('...', ['exception' => $e]) ska respektera listan.
        $exception = $hint?->exception;

        if ($exception !== null && self::isClientError($exception)) {
            return null;
        }

        $event->setRelease((string) config('app.version'));
        $event->setUser(self::user());

        $request = $event->getRequest();
        unset($request['data'], $request['cookies'], $request['env']['REMOTE_ADDR']);

        if (isset($request['headers']) && is_array($request['headers'])) {
            $request['headers'] = array_filter(
                $request['headers'],
                fn ($name) => ! in_array(strtolower((string) $name), self::SENSITIVE_HEADERS, true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        $event->setRequest($request);

        $event->setBreadcrumb(array_values(array_filter(
            $event->getBreadcrumbs(),
            fn (Breadcrumb $crumb) => in_array($crumb->getLevel(), self::BREADCRUMB_LEVELS, true),
        )));

        return $event;
    }

    private static function user(): ?UserDataBag
    {
        try {
            $ulid = auth()->user()?->ulid;
        } catch (Throwable) {
            return null;
        }

        return is_string($ulid) && $ulid !== '' ? new UserDataBag(self::pseudonym($ulid)) : null;
    }
}
