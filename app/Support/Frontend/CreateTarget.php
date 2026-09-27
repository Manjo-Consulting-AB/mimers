<?php

namespace App\Support\Frontend;

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * Plusknappens mål, se [[ADR-0048 Mobilen och plusknappen]] § 2 och
 * [[M23 Mobilen och kartan]] § 152.
 *
 * Klassen svarar på EN fråga: vad ska plusknappen på den här sidan göra?
 * Svaret blir sidans `create`-propp, och skalet ritar knappen ur den. En sida
 * som inte frågar får ingen propp — och därmed ingen knapp.
 *
 * **Vad knappen gör avgörs av sidan, inte av knappen.** Därför tre metoder och
 * inte en: `forContainers()` för dashboarden och containerlistan,
 * `forContainer()` för en sida inuti en container, och `forItem()` för ett
 * item — där knappen öppnar en meny i stället för att leda någonstans.
 *
 * **Adresserna är relativa** (`route(..., absolute: false)`), som varje annan
 * href i skalet: `AppLayout` skriver `href="/dashboard"` och flikarna bygger
 * sina ur itemets ULID. En absolut adress hade burit `APP_URL` in i en
 * Inertia-navigering, och två svar från samma rutt hade kunnat peka på olika
 * värdar beroende på vad proxyn vidarebefordrade.
 *
 * **Grindarna är de befintliga policyerna, prövade på servern.** Ingen ny
 * behörighetslogik bor här: `ContainerPolicy::create()` för en ny container,
 * `ContainerPolicy::createItem()` för ett nytt item i containern, och för
 * menyraderna exakt de policyer flikarna och formulären i itemvyn redan
 * prövar — `ItemPolicy::create()` för *Item under*, *Bild eller dokument*,
 * *Uppgift* och *Kostnad*, och `ItemPolicy::update()` för *Relation*, som
 * `ItemLinkController::store()` auktoriserar med. En rad man inte får använda
 * skickas inte med, och blir menyn tom returneras null: ingen meny, ingen
 * knapp. Flaggan är presentation; rutterna prövar samma grindar på nytt.
 *
 * **Menyraden *Kostnad* saknas, och det är ett fynd och inte ett val.**
 * [[ADR-0048 Mobilen och plusknappen]] § 2 räknar upp fem rader, och den
 * femte leder till "kostnadsraden" — men ingen webbyta skapar en kostnadsrad i
 * dag. `cost_entry` nås bara genom `POST /api/containers/{container}/items/
 * {item}/costs` (App\Http\Controllers\Api\CostEntryController), och en menyrad
 * dit hade varit en död länk: en yta ingen hittar är samma sak som en yta som
 * inte finns. Raden byggs därför inte förrän ytan finns, och frågan står i
 * PR:ens `## Frågor och antaganden`. Se `rows()`.
 *
 * Klassen är en injicerbar stödklass, samma form som
 * App\Support\Frontend\ActiveContainer.
 */
final class CreateTarget
{
    public function __construct(private readonly Gate $gate) {}

    /**
     * Målet för en sida utanför en container — dashboarden och
     * containerlistan. Knappen skapar en container.
     *
     * **Kontot är användarens första som får skapa**, och listan över konton
     * finns redan i den delade proppen `auth.accounts`: formuläret
     * (pages/Containers/Create.vue) låter användaren välja konto själv, och
     * knappen ska därför visas när något av hennes konton tillåter det — inte
     * bara när det första gör det. Är inget konto skrivbart (ett fruset konto
     * nekas av regel 4) blir svaret null.
     */
    public function forContainers(User $user): ?array
    {
        $gate = $this->gate->forUser($user);

        $account = $user->accounts->first(
            fn (Account $account): bool => $gate->allows('create', [Container::class, $account]),
        );

        if ($account === null) {
            return null;
        }

        return ['kind' => 'container', 'href' => route('containers.create', absolute: false)];
    }

    /**
     * Målet för en sida inuti en container. Knappen skapar ett item i
     * containern.
     *
     * `$parent` är noden man står på, och läggs till som `?parent` —
     * formuläret tar redan emot den (issue 57b och 58). Ingen sida i den här
     * issuen står på en nod; trädet och kartan som gör det byggs i 154, 156 och
     * 157, och de skickar sin nod hit.
     *
     * **Grinden följer `$parent`, för det gör serverns grind också.**
     * `ItemController::create()` prövar `create` på föräldern när en sådan
     * finns och `createItem` på containern annars — samma två grenar står
     * här. En omfångsbegränsad mottagare som nått en nod får därför sin knapp
     * (hon får skapa barn under det hon nått), medan samma mottagare utan nod
     * inte får någon: hon når inte containern själv.
     */
    public function forContainer(User $user, Container $container, ?Item $parent = null): ?array
    {
        $gate = $this->gate->forUser($user);

        $allows = $parent !== null
            ? $gate->allows('create', $parent)
            : $gate->allows('createItem', $container);

        if (! $allows) {
            return null;
        }

        $href = route('containers.items.create', $container, absolute: false);

        if ($parent !== null) {
            $href .= '?parent='.$parent->ulid;
        }

        return ['kind' => 'item', 'href' => $href];
    }

    /**
     * Målet för ett item. Knappen öppnar menyn med de rader användaren får
     * använda, och blir menyn tom returneras null.
     *
     * `$path` är den aktuella förekomsten ur adressen. Flikraderna i
     * `pages/Containers/Items/Show.vue` bär den med sig, och det gör
     * menyraderna också: den som står på en väg i ett item som hänger under
     * flera föräldrar ska stanna på samma väg när hon väljer en flik ur menyn.
     *
     * Ordningen är [[ADR-0048 Mobilen och plusknappen]] § 2:s: *Item under*,
     * *Relation*, *Bild eller dokument*, *Uppgift* — och sist *Kostnad*, som
     * inte finns; se klassens docblock.
     *
     * @return array{kind: string, rows: list<array{key: string, href: string}>}|null
     */
    public function forItem(User $user, Container $container, Item $item, ?string $path = null): ?array
    {
        $gate = $this->gate->forUser($user);
        $base = route('containers.items.show', [$container, $item], absolute: false);

        $rows = [];

        if ($gate->allows('create', $item)) {
            $rows[] = [
                'key' => 'item',
                'href' => route('containers.items.create', $container, absolute: false).'?parent='.$item->ulid,
            ];
        }

        // Relationen är den ENDA raden med en annan grind: en `related`-länk
        // ändrar båda itemen, och `ItemLinkController::store()` kräver
        // `update` på dem. En `create`-mottagare ser därför de andra raderna
        // och inte den här — se `Klart när`-punkten "en rad vars policy nekar
        // finns inte i menyn".
        if ($gate->allows('update', $item)) {
            $rows[] = ['key' => 'relation', 'href' => $this->tabHref($base, $path, 'relations')];
        }

        if ($gate->allows('create', $item)) {
            $rows[] = ['key' => 'attachment', 'href' => $this->tabHref($base, $path, 'attachments')];

            $rows[] = [
                'key' => 'schedule',
                'href' => route('containers.items.schedules.create', [$container, $item], absolute: false),
            ];
        }

        if ($rows === []) {
            return null;
        }

        return ['kind' => 'menu', 'rows' => $rows];
    }

    /**
     * En fliks adress, i samma form `pages/Containers/Items/Show.vue` bygger:
     * `path` FÖRE `tab`, och `path` bara när adressen har en. Ordningen är
     * bindande — `UiTabs` känner igen den aktuella raden på att flikens
     * parametrar finns i adressen, och den här raden ska vara samma adress som
     * fliken bär.
     *
     * `urlencode()` och inte rå interpolation: en `path` är ULID:n skilda av
     * punkter och förändras inte av kodningen, men en främmande querysträng
     * ska inte kunna skriva sig in i en href.
     */
    private function tabHref(string $base, ?string $path, string $key): string
    {
        if ($path === null) {
            return "{$base}?tab={$key}";
        }

        return "{$base}?path=".urlencode($path)."&tab={$key}";
    }
}
