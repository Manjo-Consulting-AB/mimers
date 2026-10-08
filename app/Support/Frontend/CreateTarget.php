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
 * **Vad knappen gör avgörs av sidan, inte av knappen.** Därför fem metoder och
 * inte en: `forContainers()` för dashboarden och containerlistan,
 * `forContainer()` för en sida inuti en container, `forItem()` för ett item —
 * där knappen öppnar en meny i stället för att leda någonstans — `forNode()`
 * för en nod i fokuskartan, som bär de två raderna om noden själv (issue 156),
 * och `forContainerTab()` för en av containerns flikar, som gör flikens egen
 * handling (M28).
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
 * **Menyraden *Kostnad* kom med issue 168.** [[ADR-0048 Mobilen och
 * plusknappen]] § 2 räknar upp fem rader, och den femte leder till
 * kostnadsraden. Fram till issue 168 fanns ingen webbyta som skapade en
 * kostnadsrad — `cost_entry` nåddes bara genom `POST /api/containers/
 * {container}/items/{item}/costs` — och raden byggdes därför inte: en menyrad
 * dit hade varit en död länk, och en yta ingen hittar är samma sak som en yta
 * som inte finns. Nu finns ytan, och raden pekar på itemets kostnadsflik.
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
     * Målet för plusknappen på en av containerns flikar — *Documents*,
     * *Tasks* och *Costs* (M28 · testarnas fynd 2026-10-07).
     *
     * **Fliken säger redan vad användaren vill göra**, och knappen gör därför
     * flikens EGEN handling i stället för att öppna den allmänna menyn från
     * översikten.
     *
     * - **`tasks`** leder till formuläret för en ny uppgift, med `?return`
     *   tillbaka till fliken. Uppgiften börjar i användarens inbox — en
     *   container är inte ett item, så det finns ingen plats att ärva, och
     *   platsen byts med *Change…* — precis som *New task* i flikens rubrikrad
     *   (issue 246 § Beslut 2). Målet ritas alltid: inboxen är alltid ett
     *   möjligt mål.
     * - **`documents`** och **`costs`** öppnar en meny med en rad per item
     *   användaren får skapa i, och raden leder till itemets bilage- respektive
     *   kostnadsflik.
     *
     * **`$items` är flikens egen lista** — samma `creatableItems()` som
     * rubrikknappen ritar, så samma grindar gäller och frågan "vilka items får
     * hon skapa i" aldrig formuleras en andra gång. Är listan tom returneras
     * null: ingen meny, ingen knapp.
     *
     * **`heading` är en NYCKEL efter `create.` och ingen färdig mening**
     * ([[ADR-0021 Frontendteknik]]): skalet slår upp `create.pick_item` och
     * formulerar *Choose item* på användarens språk. AppLayout skickar nyckeln
     * vidare som `headingKey`, skild från `CreateMenu`s `heading`, som bär
     * färdig text och ritas ordagrant — ett itemnamn är användarens eget och
     * får aldrig slås upp som en nyckel.
     *
     * En flik utan plusknapp — och en okänd flik — ger null: skalet ritar
     * ingen knapp åt en sida som inte frågar.
     *
     * @param  list<array{ulid: string, name: string}>  $items  items användaren får skapa i, redan grindade av kontrollern
     * @return array{kind: string, href: string}|array{kind: string, heading: string, rows: list<array{key: string, label: string, href: string}>}|null
     */
    public function forContainerTab(Container $container, string $tab, array $items = []): ?array
    {
        if ($tab === 'tasks') {
            return [
                'kind' => 'task',
                'href' => route('tasks.create', absolute: false)
                    .'?return='.route('containers.tasks', $container, absolute: false),
            ];
        }

        $targetTab = match ($tab) {
            'documents' => 'attachments',
            'costs' => 'costs',
            default => null,
        };

        if ($targetTab === null || $items === []) {
            return null;
        }

        $rows = [];

        foreach ($items as $item) {
            $rows[] = [
                'key' => 'item_target',
                'label' => $item['name'],
                'href' => route('containers.items.show', [$container, $item['ulid']], absolute: false)
                    .'?tab='.$targetTab,
            ];
        }

        return ['kind' => 'menu', 'heading' => 'pick_item', 'rows' => $rows];
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
     * *Relation*, *Bild eller dokument*, *Uppgift* och sist *Kostnad* —
     * den femte raden, som pekar på itemets kostnadsflik (issue 168).
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

            // *Kostnad* (issue 168 · [[ADR-0048 Mobilen och plusknappen]] § 2).
            // Raden leder till kostnadsfliken, precis som *Relation* och *Bild
            // eller dokument* leder till sina — `tabHref` bär därför den
            // aktuella förekomsten vidare, så ett flikbyte stannar på samma
            // väg. Grinden är itemets `create`, samma pinne som
            // App\Http\Controllers\CostEntryController::store() prövar.
            $rows[] = ['key' => 'cost', 'href' => $this->tabHref($base, $path, 'costs')];
        }

        if ($rows === []) {
            return null;
        }

        return ['kind' => 'menu', 'rows' => $rows];
    }

    /**
     * Grafens meny för EN nod (issue 156 · [[M23 Mobilen och kartan]] § 156):
     * *Item under* och *Relation*, och ingenting mer.
     *
     * **Urvalet är [[ADR-0048 Mobilen och plusknappen]] § 4.** Raden för ett
     * item med samma förälder finns inte i gränssnittet, och bilagan och
     * uppgiften hör till itemets egen sida — en nod i kartan bär de två rader
     * som handlar om noden själv. Ordningen är `forItem()`:s, så menyn i
     * kartan och menyn i skalet inte kan visa samma rader i olika ordning.
     *
     * **Rader och ingen `kind`.** Nodens plus är alltid en meny, och en meny
     * utan rader ritas inte — en tom lista är svaret när ingen av de två
     * policyerna tillåter något, precis som `forItem()` svarar null.
     *
     * `$path` hör bara till den nod som ÄR itemet, alltså den i kartans mitt:
     * `forItem()` bär den aktuella förekomsten vidare till flikadresserna. För
     * en motpart är förekomsten okänd, och raden leder därför till motpartens
     * egen sida utan `?path=`.
     *
     * @return list<array{key: string, href: string}>
     */
    public function forNode(User $user, Container $container, Item $item, ?string $path = null): array
    {
        $menu = $this->forItem($user, $container, $item, $path);

        if ($menu === null) {
            return [];
        }

        return array_values(array_filter(
            $menu['rows'],
            fn (array $row): bool => in_array($row['key'], ['item', 'relation'], true),
        ));
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
