<?php

namespace App\Support\Frontend;

use App\Models\Container;
use App\Models\User;

/**
 * Den aktiva containern — ett sessionsbegrepp, se issue 51 § Beslut 4.
 *
 * Klassen äger sessionsnyckeln och är det enda stället den stavas: den som
 * gör en container till kontext anropar set(), allt annat läser forUser().
 * Sedan issue 83 sätts kontexten av att containern ÖPPNAS — se
 * App\Http\Middleware\HandleInertiaRequests::setActiveContainer() — och det
 * finns ingen rutt och ingen knapp som gör det för hand. Kvar som egna
 * anropare står de tre tillfällen då användaren just FÅTT en container:
 * skapandet (App\Http\Controllers\ContainerController::store()), en antagen
 * inbjudan och ett mottaget ägarbyte.
 * Servern har inget "aktivt konto" (issue 8 § Beslut 8) och följaktligen
 * ingen aktiv container utanför sessionen — nyckeln är hela tillståndet.
 *
 * forUser() prövar åtkomsten på nytt vid varje läsning, med
 * Container::scopeAccessibleBy() — samma fråga som
 * App\Http\Controllers\Api\ContainerController::index() ställer. En
 * återkallad delning får aldrig lämna kvar en aktiv container i någons
 * session, så en ULID som inte längre är åtkomlig glöms och blir null.
 * Ingen krasch: sessionen är användarens, inte systemets.
 *
 * Klassen är en injicerbar stödklass, samma form som
 * App\Support\Plan\Entitlements och App\Support\Notification\LocaleResolver.
 */
final class ActiveContainer
{
    /**
     * Sessionsnyckeln. Stavas bara här.
     */
    public const SESSION_KEY = 'active_container_ulid';

    /**
     * ULID:t för den aktiva containern, eller null när ingen är satt, när
     * den är okänd, eller när den inte längre är åtkomlig för $user.
     */
    public function forUser(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $ulid = session()->get(self::SESSION_KEY);

        if (! is_string($ulid) || $ulid === '') {
            return null;
        }

        $accountIds = $user->accounts->pluck('id')->values()->all();

        $container = Container::query()
            ->accessibleBy($user, $accountIds)
            ->where('ulid', $ulid)
            ->first();

        if ($container === null) {
            $this->forget();

            return null;
        }

        return $container->ulid;
    }

    /**
     * Gör $container aktiv för $user. Ingen åtkomstkontroll kastar här:
     * en container användaren inte kommer åt blir aldrig aktiv, nyckeln
     * glöms i stället för att skrivas — samma svar som forUser() ger för
     * en nyckel som inte längre håller.
     */
    public function set(User $user, Container $container): void
    {
        if (! $this->isAccessible($user, $container)) {
            $this->forget();

            return;
        }

        session()->put(self::SESSION_KEY, $container->ulid);
    }

    public function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    private function isAccessible(User $user, Container $container): bool
    {
        $accountIds = $user->accounts->pluck('id')->values()->all();

        return Container::query()
            ->accessibleBy($user, $accountIds)
            ->whereKey($container->id)
            ->exists();
    }
}
