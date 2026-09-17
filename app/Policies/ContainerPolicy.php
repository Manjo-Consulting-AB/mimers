<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\User;
use App\Support\Access\AccessLevel;

/**
 * Behörighet till en container, se [[Konton och åtkomst]] §
 * Behörighetsregler och issue 8 § Beslut 2. Den första policyn i
 * projektet — auto-upptäckt av Laravel via namnkonventionen
 * `App\Models\Container` → `App\Policies\ContainerPolicy`, ingen
 * registrering behövs.
 *
 * Fem regler, fyra av dem här:
 *
 * 1. Ägarkontots medlemmar (`owner`, `admin`, `member` — alla tre lika,
 *    se issue 8 § Beslut 3, ingen rollgradering är beslutad) har full
 *    behörighet.
 * 2. Övriga får behörighet via `container_access` där `revoked_at IS NULL`
 *    och `expires_at` inte passerats — se issue 9a § Beslut 5,
 *    hasContainerAccess() nedan.
 * 3. `level` avgör, `kind` avgör aldrig. Sedan issue 69 är `level` en
 *    ladder om fyra steg — `read` < `create` < `write` < `delete`, se
 *    App\Support\Access\AccessLevel och [[ADR-0028 Åtkomst på itemnivå]] §
 *    Beslut. Grinden frågar efter en MINIMINIVÅ, och en rad når den om
 *    dess nivå ligger på eller ovanför. Ingen nivå får radera containern
 *    eller hantera åtkomster — inte heller `delete`, som betyder
 *    mjukradering inom sitt eget omfång, se delete() nedan och issue 9b.
 * 4. Är kontot fryst — `read_only` (nedgraderingen, issue 28) eller
 *    `closed` (kontolivscykeln, issue 29a) — nekas allt skrivande oavsett
 *    behörighet. Läsning är alltid tillåten. Sedan issue 9a gäller det här
 *    ÄVEN det mottagande kontot på en `managed`-rad, se issue 9a § Beslut 9
 *    — hasContainerAccess()s `$excludeFrozenGranteeAccounts`.
 *
 * Regel 5 (uppladdningar räknas mot den uppladdande användarens konto) är
 * inte en behörighetsfråga och hör inte hemma här.
 *
 * Ingen behörighetslogik får bo i App\Http\Controllers\Api\ContainerController
 * — den anropar bara Gate::authorize() och litar på svaret härifrån.
 */
class ContainerPolicy
{
    /**
     * Får användaren se containern? Regel 1 ELLER regel 2 (en giltig
     * access på minst `read`). Regel 4 gäller INTE här — läsning är
     * alltid tillåten oavsett `account.status`, för ingendera vägen in.
     *
     * Minimikravet är `read`, den LÄGSTA nivån, så alla fyra nivåerna
     * passerar. Sätt ALDRIG ett `item_id IS NULL`-filter här: en
     * omfångsbegränsad mottagare måste passera den här grinden för att nå
     * `GET /containers/{container}/items` över huvud taget, och det är
     * issue 73 som sedan filtrerar bort det hon inte når, se issue 69
     * § Beslut 4.
     */
    public function view(User $user, Container $container): bool
    {
        return $this->isMemberOfOwnerAccount($user, $container->account)
            || $this->hasContainerAccess($user, $container, AccessLevel::READ);
    }

    /**
     * Får användaren skapa en container åt det angivna kontot? Regel 1
     * (medlemskap) OCH regel 4 (kontot får inte vara fryst — `read_only`
     * eller `closed` — att skapa en container är att skriva).
     *
     * `container_access` (issue 9) ger aldrig rätt att SKAPA containers åt
     * ett annat konto — den behörigheten gäller en befintlig container, inte
     * kontot i stort. Den här metoden ändras alltså inte av issue 9.
     */
    public function create(User $user, Account $account): bool
    {
        return $this->isMemberOfOwnerAccount($user, $account) && ! $this->isFrozen($account);
    }

    /**
     * Får användaren ändra namn/kind på containern? Regel 1 ELLER regel 2
     * (en giltig access på minst `write` — alltså `write` eller `delete`),
     * plus regel 4 — som nu gäller på TVÅ
     * nivåer: ägarkontot fryser containern för alla oavsett väg in (kollas
     * först, innan någon väg prövas), och en `managed`-access dessutom
     * nekas om DET MOTTAGANDE kontot är fryst (hanteras inuti
     * hasContainerAccess()). En `member`/`guest`-access (mottagaren är en
     * användare, inte ett konto) får ingen extra kontokontroll, se issue 9a
     * § Beslut 9.
     *
     * Sedan issue 70 krävs att raden är CONTAINER-BRED (`item_id IS NULL`,
     * `containerWideOnly`). Att byta namn på containern är en containervid
     * handling, och en `write` på ett enskilt item får inte ge den — annars
     * hade en itemgrant blivit en ContainerPolicy::create() i smyg. Det här
     * är den ENDA platsen i systemet där `item_id IS NULL` står som villkor:
     * view() får det aldrig, för då låses en omfångsbegränsad mottagare ute
     * från själva containerrutten (issue 69 § Beslut 4).
     */
    public function update(User $user, Container $container): bool
    {
        if ($this->isFrozen($container->account)) {
            return false;
        }

        return $this->isMemberOfOwnerAccount($user, $container->account)
            || $this->hasContainerAccess(
                $user,
                $container,
                AccessLevel::WRITE,
                excludeFrozenGranteeAccounts: true,
                containerWideOnly: true,
            );
    }

    /**
     * Får användaren skapa ett TOPPNIVÅ-item i containern? Regel 1 ELLER en
     * CONTAINER-BRED grant på `create` eller högre, plus regel 4. Sedan
     * issue 70 § Beslut 9.
     *
     * En omfångsbegränsad mottagare når aldrig den här metoden: hon skapar
     * barn-items UNDER det hon fått, och det auktoriseras med
     * App\Policies\ItemPolicy::create() mot föräldern. En itemgrant — även
     * på `delete` — ger därför ingen rot i containern.
     *
     * `ContainerPolicy::create()` kan INTE återanvändas: den tar ett
     * `Account` och handlar om att skapa containers åt ett konto, se dess
     * docblock.
     */
    public function createItem(User $user, Container $container): bool
    {
        if ($this->isFrozen($container->account)) {
            return false;
        }

        return $this->isMemberOfOwnerAccount($user, $container->account)
            || $this->hasContainerAccess(
                $user,
                $container,
                AccessLevel::CREATE,
                excludeFrozenGranteeAccounts: true,
                containerWideOnly: true,
            );
    }

    /**
     * Får användaren radera containern? Regel 1 + regel 4. Till skillnad
     * från update() utökas INTE den HÄR metoden av issue 9a — regel 3
     * säger uttryckligen att `write`-nivå aldrig får radera containern,
     * bara ägarkontots egna medlemmar. `manageAccess()` (att bevilja/
     * återkalla åtkomster) hör av samma skäl till issue 9b, med en
     * konsument där, inte här.
     */
    public function delete(User $user, Container $container): bool
    {
        return $this->isMemberOfOwnerAccount($user, $container->account) && ! $this->isFrozen($container->account);
    }

    /**
     * Får användaren SE containerns delegerade åtkomster (issue 9b)? Bara
     * regel 1 — INGEN regel 4-kontroll. [[Konton och åtkomst]] §
     * Behörighetsregler regel 4 gäller skrivande, och issue 9a § Beslut 9
     * säger uttryckligen att "läsning påverkas aldrig av regel 4": ett
     * fruset konto måste kunna se vem som har åtkomst till dess containers.
     *
     * Skiljs medvetet från revokeAccess() nedan trots identisk kropp i dag —
     * se den metodens docblock.
     */
    public function viewAccesses(User $user, Container $container): bool
    {
        return $this->isMemberOfOwnerAccount($user, $container->account);
    }

    /**
     * Får användaren BEVILJA en ny åtkomst (issue 9b, POST)? Regel 1 + regel
     * 4, exakt som delete() ovan — att bevilja ÖKAR exponeringen, så ett
     * fryst ägarkonto (`read_only` eller `closed`) nekas.
     */
    public function manageAccess(User $user, Container $container): bool
    {
        return $this->isMemberOfOwnerAccount($user, $container->account) && ! $this->isFrozen($container->account);
    }

    /**
     * Får användaren INITIERA ett ägarbyte av containern (issue 39a, POST
     * och DELETE)? Regel 1 + regel 4, exakt som manageAccess() ovan —
     * [[Konton och åtkomst]] § Behörighetsregler: "Ingen nivå får radera
     * containern, hantera åtkomster eller initiera ägarbyte". En
     * `write`-innehavare når den alltså aldrig, och ett fryst ägarkonto
     * nekas. Plangrinden (ownership_transfer är en Pro-funktion) sitter i
     * kontrollern efter den här gaten, se issue 39a § Beslut 8.
     */
    public function transfer(User $user, Container $container): bool
    {
        return $this->isMemberOfOwnerAccount($user, $container->account) && ! $this->isFrozen($container->account);
    }

    /**
     * Får användaren SE containerns ägarbyten (issue 39a, avsändarens lista)?
     * Bara regel 1 — INGEN regel 4-kontroll, av samma skäl som
     * viewAccesses(): ett `read_only`-ägarkonto måste kunna se sina
     * utestående ägarbyten, för att se listan är att läsa.
     *
     * Identisk kropp med viewAccesses() i dag — ändå TVÅ metoder, för de
     * betyder olika saker (åtkomsthistorik vs överlåtelsehistorik) och kan
     * komma att ändras oberoende av varandra: 39b och 40 bygger på den här
     * ytan. Slå inte ihop dem.
     *
     * Asymmetrin mot transfer() (DELETE, initieringen) är avsiktlig: att dra
     * tillbaka ett ägarbyte är en skrivning som regel 4 INTE undantar — de
     * uttömmande uppräknade undantagen i regel 4 är återkalla åtkomst och
     * rensa lagring, och ägarbyte är inte ett av dem.
     */
    public function viewTransfers(User $user, Container $container): bool
    {
        return $this->isMemberOfOwnerAccount($user, $container->account);
    }

    /**
     * Får användaren SE containerns revisionslogg (issue 40)? Bara regel 1 —
     * INGEN regel 4-kontroll, av samma skäl som viewAccesses() och
     * viewTransfers(): läsning påverkas aldrig av att kontot är fryst, och
     * ett konto som håller på att nedgraderas måste kunna se sin egen
     * historik. En delegerad `write`-innehavare får inget se: loggen berättar
     * vem som haft åtkomst och när, och det är ägarens uppgift (issue 40 §
     * Beslut 7).
     *
     * Identisk kropp med viewAccesses() i dag — ändå en egen metod, av samma
     * skäl som viewTransfers() ovan: åtkomsthistorik och överlåtelsehistorik
     * är olika ytor och kan ändras oberoende av varandra. Slå inte ihop dem.
     */
    public function viewAuditLog(User $user, Container $container): bool
    {
        return $this->isMemberOfOwnerAccount($user, $container->account);
    }

    /**
     * Får användaren ÅTERKALLA en åtkomst (issue 9b, DELETE)? Bara regel 1 —
     * INGEN regel 4-kontroll. Att återkalla är en skrivning, men regel 4
     * undantar den uttryckligen: den MINSKAR exponeringen i stället för att
     * öka den, och ett konto som frysts (t.ex. ett kort som gick ut) ska
     * inte vara utlåst från att klippa en relation det inte längre vill ha.
     * Se [[Konton och åtkomst]] § Behörighetsregler regel 4.
     *
     * Identisk kropp med viewAccesses() i dag — ändå TVÅ metoder, för de
     * betyder olika saker och kan komma att ändras oberoende av varandra.
     * Slå inte ihop dem.
     */
    public function revokeAccess(User $user, Container $container): bool
    {
        return $this->isMemberOfOwnerAccount($user, $container->account);
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
     * aldrig på användaren. Två tillstånd fryser skrivandet: `read_only`
     * (nedgraderingen, issue 28) och `closed` (kontolivscykeln, issue 29a —
     * ett stängt konto ska inte kunna skrivas i, då vore stängningen en
     * etikett utan verkan). Läsning påverkas aldrig av regel 4.
     */
    private function isFrozen(Account $account): bool
    {
        return in_array($account->status, ['read_only', 'closed'], true);
    }

    /**
     * Regel 2 (giltig access) + regel 3 (bara `level` avgör, `kind` aldrig)
     * i en enda `exists()`-fråga, se issue 9a § Att se upp med
     * ("uppslagningen får inte bli N+1 — policyn anropas per container i
     * vissa flöden").
     *
     * Träffar $user på de två vägar issue 9a § Beslut 5 beskriver: hens
     * egen `member`/`guest`-rad, eller en `managed`-rad på ett konto hon är
     * medlem i — se ContainerAccess::scopeValidFor().
     *
     * $excludeFrozenGranteeAccounts implementerar regel 4:s andra gren
     * (§ Beslut 9): en `managed`-rad ska INTE ge skrivbehörighet om det
     * MOTTAGANDE kontot är fryst — `read_only` eller `closed` — även om
     * ägarkontot är friskt. Sätts bara av update() — läsning (regel 4:
     * "påverkas aldrig") skickar in hela kontolistan ofiltrerad. En
     * `member`/`guest`-rad (mottagaren är en användare) berörs aldrig av
     * det här filtret, se § Beslut 9: "en användares eget konto styr inte
     * vad hon får göra i någon annans container."
     *
     * Sedan issue 69 tar metoden ett MINIMIKRAV i stället för en lista med
     * tillåtna nivåer: en rad når grinden om dess nivå ligger på eller
     * ovanför minimikravet. Det ger samma svar på samma rader som förut —
     * `view()` gav `read`|`write` och får nu alla fyra, men `create` och
     * `delete` hade inga innehavare förrän migreringen skapade dem, och
     * de `write`-rader som fanns blev `delete`, som båda minimikraven
     * släpper igenom.
     *
     * Jämförelsen görs som `whereIn` mot AccessLevel::atOrAbove(), ALDRIG
     * som en strängjämförelse i SQL: `'delete' >= 'write'` är falskt i
     * varje kollation som finns, och ordningen är semantisk och finns bara
     * i PHP, se [[ADR-0028 Åtkomst på itemnivå]] § Beslut 2.
     *
     * `$containerWideOnly` begränsar till rader med `item_id IS NULL` — en
     * CONTAINER-BRED grant, alltså. Sätts av update() och createItem(),
     * aldrig av view(): containervida handlingar får inte vinnas på en
     * itemgrant, men en omfångsbegränsad mottagare måste ändå nå
     * containerrutten för att kunna se det hon fått (issue 69 § Beslut 4
     * och issue 70 § Beslut 9).
     *
     * Upplösningen av VILKA items en mottagare når bor i
     * App\Actions\Access\ResolveItemScope, inte här, se issue 70 § Beslut 1.
     */
    private function hasContainerAccess(
        User $user,
        Container $container,
        string $minimumLevel,
        bool $excludeFrozenGranteeAccounts = false,
        bool $containerWideOnly = false,
    ): bool {
        $accounts = $user->accounts;

        if ($excludeFrozenGranteeAccounts) {
            $accounts = $accounts->reject(fn (Account $account) => $this->isFrozen($account));
        }

        $query = ContainerAccess::query()
            ->where('container_id', $container->id)
            ->whereIn('level', AccessLevel::atOrAbove($minimumLevel))
            ->validFor($user, $accounts->pluck('id')->values()->all());

        if ($containerWideOnly) {
            $query->whereNull('item_id');
        }

        return $query->exists();
    }
}
