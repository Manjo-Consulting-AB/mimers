<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\Container;
use App\Models\User;

/**
 * Behörighet till en container, se [[Konton och åtkomst]] §
 * Behörighetsregler och issue 8 § Beslut 2. Den första policyn i
 * projektet — auto-upptäckt av Laravel via namnkonventionen
 * `App\Models\Container` → `App\Policies\ContainerPolicy`, ingen
 * registrering behövs.
 *
 * Implementerar bara regel 1 och 4 här:
 *
 * 1. Ägarkontots medlemmar (`owner`, `admin`, `member` — alla tre lika,
 *    se issue 8 § Beslut 3, ingen rollgradering är beslutad) har full
 *    behörighet.
 * 4. Är ägarkontot `read_only` nekas allt skrivande oavsett behörighet.
 *    Läsning är alltid tillåten.
 *
 * Regel 2 och 3 (`container_access`, nivåerna `read`/`write` för andra än
 * ägarkontots medlemmar) hör till issue 9 och läggs till i den HÄR
 * policyn, inte en ny — se kommentaren vid varje metod nedan för var den
 * hakar i. Regel 5 (uppladdningar räknas mot den uppladdande användarens
 * konto) är inte en behörighetsfråga och hör inte hemma här.
 *
 * Ingen behörighetslogik får bo i App\Http\Controllers\Api\ContainerController
 * — den anropar bara Gate::authorize() och litar på svaret härifrån.
 */
class ContainerPolicy
{
    /**
     * Får användaren se containern? Regel 1: ägarkontots medlemmar. Regel
     * 4 gäller INTE här — läsning är alltid tillåten oavsett
     * `account.status`.
     *
     * Issue 9 hakar i här: en användare som har `container_access` (read
     * eller write, ej återkallad, ej utgången) för containern eller för
     * hela ägarkontots organisation ska ORAS in i det här villkoret.
     */
    public function view(User $user, Container $container): bool
    {
        return $this->isMemberOfOwnerAccount($user, $container->account);
    }

    /**
     * Får användaren skapa en container åt det angivna kontot? Regel 1
     * (medlemskap) OCH regel 4 (kontot får inte vara `read_only` — att
     * skapa en container är att skriva).
     *
     * `container_access` (issue 9) ger aldrig rätt att SKAPA containers åt
     * ett annat konto — den behörigheten gäller en befintlig container, inte
     * kontot i stort. Den här metoden ändras alltså inte av issue 9.
     */
    public function create(User $user, Account $account): bool
    {
        return $this->isMemberOfOwnerAccount($user, $account) && ! $this->isReadOnly($account);
    }

    /**
     * Får användaren ändra namn/kind på containern? Regel 1 + regel 4.
     *
     * Issue 9 hakar i här: en `write`-nivå via `container_access` ska också
     * ge true (förutsatt att regel 4 fortfarande nekar om kontot är
     * `read_only` — den kontrollen ska gälla oavsett väg in).
     */
    public function update(User $user, Container $container): bool
    {
        return $this->isMemberOfOwnerAccount($user, $container->account) && ! $this->isReadOnly($container->account);
    }

    /**
     * Får användaren radera containern? Regel 1 + regel 4. Till skillnad
     * från update() ska den HÄR metoden INTE utökas av issue 9 — regel 3
     * säger uttryckligen att `write`-nivå aldrig får radera containern,
     * bara ägarkontots egna medlemmar.
     */
    public function delete(User $user, Container $container): bool
    {
        return $this->isMemberOfOwnerAccount($user, $container->account) && ! $this->isReadOnly($container->account);
    }

    /**
     * Regel 1: är användaren medlem (någon roll) i kontot som äger
     * containern?
     */
    private function isMemberOfOwnerAccount(User $user, Account $account): bool
    {
        return $account->users()->whereKey($user->id)->exists();
    }

    /**
     * Regel 4: kontots skrivspärr. Sitter på kontot (`account.status`),
     * aldrig på användaren — ett `closed`-konto hör till kontolivscykeln,
     * issue 29, och hanteras inte här.
     */
    private function isReadOnly(Account $account): bool
    {
        return $account->status === 'read_only';
    }
}
