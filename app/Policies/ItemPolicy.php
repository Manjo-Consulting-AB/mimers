<?php

namespace App\Policies;

use App\Actions\Access\ResolveItemScope;
use App\Models\Account;
use App\Models\Item;
use App\Models\User;
use App\Support\Access\AccessLevel;

/**
 * Behörighet till ett enskilt item, se [[ADR-0028 Åtkomst på itemnivå]] §
 * Beslut och [[Konton och åtkomst]] § Behörighetsregler regel 3.
 *
 * Fyra metoder, en per pinne i laddern, och var och en frågar
 * App\Actions\Access\ResolveItemScope i stället för att låna containerns
 * grindar. Fram till issue 71 varje item auktoriserades mot containern
 * (`ContainerPolicy::view()`/`update()`); det valet — backlog 13a § Beslut
 * 2 — upphör här, men bara i policylagret. Grindarna i controllers byts i
 * issue 71, och itemåtkomster går att bevilja först i 72.
 *
 * Ingen registrering behövs: Laravel hittar policyn på namnkonventionen
 * `App\Models\Item` → `App\Policies\ItemPolicy`, samma som
 * ContainerPolicy.
 *
 * Itemets BEROENDEN följer itemet genom att auktoriseras mot itemet, inte
 * mot sig själva — ingen AttachmentPolicy och ingen SchedulePolicy skrivs
 * här (issue 70 § Beslut 7). Det är hela mekanismen.
 *
 * Regel 4 — den frusna kontospärren — ligger oförändrad ovanpå: `create`,
 * `update` och `delete` nekas när ÄGARKONTOT är `read_only` eller `closed`,
 * `view` aldrig. Spärren sitter på kontot (`account.status`), aldrig på
 * användaren, exakt som i ContainerPolicy.
 */
class ItemPolicy
{
    public function __construct(private readonly ResolveItemScope $scopes) {}

    /**
     * Får användaren se itemet? Nivå `read`. Regel 4 gäller INTE här — att
     * läsa är alltid tillåtet, se [[Konton och åtkomst]] §
     * Behörighetsregler regel 4.
     */
    public function view(User $user, Item $item): bool
    {
        return $this->allows($user, $item, AccessLevel::READ);
    }

    /**
     * Får användaren lägga till något PÅ eller UNDER det här itemet — en
     * bilaga, en kostnadsrad, ett schema, en utlåning, ett barn-item? Nivå
     * `create`.
     *
     * Signaturen tar itemet man lägger till PÅ, inte det som skapas: det
     * nya itemet finns inte än och har ingen grant. Ett barn-item
     * auktoriseras alltså mot sin blivande FÖRÄLDER, och en
     * omfångsbegränsad mottagare skapar därmed bara under det hon redan
     * nått — se [[ADR-0028 Åtkomst på itemnivå]] § Beslut ("create får
     * skapa både inuti itemet och nya barn-items").
     */
    public function create(User $user, Item $item): bool
    {
        return $this->allows($user, $item, AccessLevel::CREATE);
    }

    /**
     * Får användaren ändra itemet? Nivå `write` — ändrar det som redan står
     * där, lägger inte till och tar inte bort.
     */
    public function update(User $user, Item $item): bool
    {
        return $this->allows($user, $item, AccessLevel::WRITE);
    }

    /**
     * Får användaren mjukradera itemet? Nivå `delete`. Metoden täcker ÄVEN
     * återställning ur papperskorgen — [[Konton och åtkomst]] §
     * Behörighetsregler regel 3: "`delete` mjukraderar och återställer ur
     * papperskorgen". Ingen femte metod.
     *
     * Nivån räcker aldrig för att radera CONTAINERN: den grinden är
     * ContainerPolicy::delete() och förblir ägarkontots.
     */
    public function delete(User $user, Item $item): bool
    {
        return $this->allows($user, $item, AccessLevel::DELETE);
    }

    /**
     * Den gemensamma formen: regel 4 först (ett fryst ägarkonto nekar allt
     * skrivande oavsett nivå), sedan omfånget.
     *
     * `$item->container` slås upp lat och cachas på item-instansen. En
     * anropare som auktoriserar många items i en listning bör eager-ladda
     * `container.account`, annars kostar varje rad ett uppslag — memon i
     * ResolveItemScope tar bort grant-frågorna, inte den här.
     */
    private function allows(User $user, Item $item, string $minimum): bool
    {
        if ($minimum !== AccessLevel::READ && $this->isFrozen($item->container->account)) {
            return false;
        }

        return $this->scopes->handle($user, $item->container)->allows($item->id, $minimum);
    }

    /**
     * Regel 4: kontots skrivspärr. `read_only` (nedgraderingen, issue 28)
     * och `closed` (kontolivscykeln, issue 29a) fryser skrivandet; läsning
     * påverkas aldrig.
     *
     * Identisk med ContainerPolicy::isFrozen() — medvetet en andra kopia,
     * se PR:ens "Frågor och antaganden": den delade platsen hade blivit en
     * tredje fil utanför issue 70:s omfång, och en flytt hade rört en
     * befintlig policy för namnkonventionens skull. Formuleringen är två
     * strängar och en `in_array`; glider de isär är det ett fel någon ser.
     */
    private function isFrozen(Account $account): bool
    {
        return in_array($account->status, ['read_only', 'closed'], true);
    }
}
