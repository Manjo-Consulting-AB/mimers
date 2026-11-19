<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * M28 · Testarnas fynd 2026-10-07: plusknappen i toppraden finns också på
 * containerns flikar, och den startar flikens EGEN handling. Målet i vyn kom
 * med issue 1400 · [[ADR-0074 Registrering i vyn]] § 7 och tillägget
 * 2026-11-18: knappen öppnar flikens eget formulär i stället för att lämna
 * fliken.
 *
 * **Fliken säger redan vad användaren vill göra.** Knappen får därför
 * `{ kind: 'inline', form: 'task' | 'item' | 'document' | 'cost' }` och ber
 * sidan öppna sitt formulär — knappen sitter i skalet, formuläret ägs av
 * sidan, och de två möts i resources/js/composables/useInlineCreate.js.
 *
 * **Grindarna är oförändrade.** En `read`-mottagare får ingen knapp på
 * *Documents* — listan över items hon får skapa i är tom, och formuläret har
 * då inget item att spara på. Samma tomma lista ger ingen knapp på *Costs*.
 *
 * **Dagens mål står kvar där formuläret inte finns i vyn.** *Done* har ingen
 * snabbrad och får därför `href` till uppgiftsformuläret, och kartläget skapar
 * under mitten och får därför `href` med `?parent`.
 *
 * **Formen prövas i källkoden** där den bara syns där: knappens inline-gren,
 * räknaren i komposabeln, och att sidorna lyssnar, rullar fram formuläret och
 * sätter fokus.
 *
 * Hjälparna har prefixet `flikknapp` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en ägare, och en container ägd av kontot.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function flikknappKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

/**
 * En containerbred grant till $user, på angiven nivå.
 *
 * `item_id` är null med flit: det är den containerbreda raden
 * `ContainerPolicy::view()` kräver, och den som gör att en mottagare når
 * flikarna utan att vara medlem.
 */
function flikknappGrant(Container $container, User $user, string $niva): ContainerAccess
{
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => null,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $niva,
        'kind' => 'member',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * Ett item i containern, med sammanhängande `created_by_*`.
 */
function flikknappItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * Källkoden med kommentarer borta. Samma tre slag som PlusknappTest rensar.
 */
function flikknappKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * ---------------------------------------------------------------------------
 * Målet. Klart när-punkterna 1, 2, 3 och 4.
 * ---------------------------------------------------------------------------
 */

/*
 * Klart när: "plusknappen på To do öppnar flikens formulär".
 *
 * Målet är flikens EGET — snabbraden — och inte formuläret på en annan sida.
 * Servern skickar formen, inte ett `href`: knappen ber sidan öppna formuläret
 * genom resources/js/composables/useInlineCreate.js.
 */
it('öppnar flikens formulär på tasks-fliken', function () {
    withoutVite();

    [, $anvandare, $container] = flikknappKontext();

    actingAs($anvandare)->get("/containers/{$container->ulid}/tasks")->assertInertia(
        fn (AssertableInertia $page) => $page->where('create.kind', 'inline')
            ->where('create.form', 'task')
            ->missing('create.href'),
    );
});

/*
 * Klart när: "Done har dagens mål".
 *
 * *Done* har ingen snabbrad — avbockade uppgifter är ingenting att lägga till
 * — och servern skickar därför formuläret med `?return` tillbaka till fliken,
 * precis som före issue 1400.
 */
it('behåller dagens mål på Done', function () {
    withoutVite();

    [, $anvandare, $container] = flikknappKontext();

    actingAs($anvandare)->get("/containers/{$container->ulid}/tasks?list=done")->assertInertia(
        fn (AssertableInertia $page) => $page->where('create.kind', 'task')
            ->where('create.href', "/tasks/create?return=/containers/{$container->ulid}/tasks"),
    );
});

/*
 * Klart när: "plusknappen på Documents öppnar flikens formulär".
 *
 * Formen, inte en meny: uppladdningen står i vyn, och knappen behöver bara
 * säga vilket formulär sidan ska fälla ut.
 */
it('öppnar flikens formulär på documents-fliken', function () {
    withoutVite();

    [, $anvandare, $container] = flikknappKontext();

    flikknappItem($container, 'Motorn');
    flikknappItem($container, 'Seglet');

    actingAs($anvandare)->get("/containers/{$container->ulid}/documents")->assertInertia(
        fn (AssertableInertia $page) => $page->where('create.kind', 'inline')
            ->where('create.form', 'document'),
    );
});

/*
 * Klart när: "plusknappen på Costs öppnar flikens formulär".
 */
it('öppnar flikens formulär på costs-fliken', function () {
    withoutVite();

    [, $anvandare, $container] = flikknappKontext();

    flikknappItem($container, 'Motorn');

    actingAs($anvandare)->get("/containers/{$container->ulid}/costs")->assertInertia(
        fn (AssertableInertia $page) => $page->where('create.kind', 'inline')
            ->where('create.form', 'cost'),
    );
});

/*
 * Klart när: "en tom itemlista på Documents och Costs ger ingen knapp".
 *
 * Utan ett item finns ingenstans att spara, och formuläret har inget mål.
 * Samma svar som före issue 1400, och samma regel som rubrikknappen: raden
 * ritas bara när listan har items.
 */
it('ger ingen knapp på documents och costs när itemlistan är tom', function () {
    withoutVite();

    [, $anvandare, $container] = flikknappKontext();

    foreach (['documents', 'costs'] as $flik) {
        actingAs($anvandare)->get("/containers/{$container->ulid}/{$flik}")->assertInertia(
            fn (AssertableInertia $page) => $page->where('create', null),
        );
    }
});

/*
 * Klart när: "en läsare får ingen plusknapp på documents".
 *
 * En `read`-mottagare når fliken — det är själva poängen med
 * `ContainerPolicy::view()` — men får inte skapa något, och då finns det
 * inget mål att skicka: `ItemPolicy::create()` nekar per item, listan blir
 * tom, och `forContainerTab()` svarar null. Samma grind som
 * `POST …/items/{item}/attachments` prövar.
 */
it('ger en läsare ingen plusknapp på documents', function () {
    withoutVite();

    [, , $container] = flikknappKontext();

    flikknappItem($container, 'Motorn');

    $lasare = User::factory()->create();
    flikknappGrant($container, $lasare, 'read');

    actingAs($lasare)->get("/containers/{$container->ulid}/documents")->assertInertia(
        fn (AssertableInertia $page) => $page->where('create', null),
    );
});

/*
 * Klart när: "Items (lista och träd) öppnar flikens formulär, kartläget har
 * dagens mål".
 *
 * I trädet står formuläret i vyn och skapar på toppnivån, så knappen får
 * `{ kind: 'inline', form: 'item' }`. Kartan skapar under mitten, och
 * formuläret i vyn kan inte det: där gäller dagens mål med `?parent`
 * (issue 296 · [[ADR-0059 Kartan med en mitt]] § 4). Kontrollern vet vilket
 * läge som visas.
 */
it('öppnar flikens formulär på items-fliken och behåller dagens mål i kartan', function () {
    withoutVite();

    [, $anvandare, $container] = flikknappKontext();

    $motorn = flikknappItem($container, 'Motorn');

    actingAs($anvandare)->get("/containers/{$container->ulid}/items")->assertInertia(
        fn (AssertableInertia $page) => $page->where('create.kind', 'inline')
            ->where('create.form', 'item'),
    );

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}/items?view=map&focus={$motorn->ulid}")
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page->where('create.kind', 'item')
                ->where('create.href', "/containers/{$container->ulid}/items/create?parent={$motorn->ulid}"),
        );
});

/*
 * ---------------------------------------------------------------------------
 * Formen. Klart när-punkt 5, och kopplingen mellan proppen och skalet.
 * ---------------------------------------------------------------------------
 */

/*
 * Klart när: "knappen ber sidan öppna formuläret".
 *
 * Knappen sitter i skalet och formuläret ägs av sidan, så klicket går genom
 * en räknare på modulnivå i stället för en prop nedåt: `request()` ökar den,
 * och `onRequest()` lyssnar så länge sidan är monterad. Sidan rullar fram
 * formuläret — på mobilen sitter knappen längst ned — och sätter fokus i
 * första fältet. Snabbraden har sin egen `focus()`; de andra fäller ut
 * `InlineCreate` och får fokus i dess första fält.
 */
it('låter knappen be sidan öppna formuläret', function () {
    $knapp = flikknappKod('components/CreateButton.vue');

    expect($knapp)->toContain("props.create.kind === 'inline'")
        ->toContain("import { useInlineCreate } from '../composables/useInlineCreate.js'")
        ->toContain('@click="request()"')
        ->toContain("t('create.label')");

    $komposabel = flikknappKod('composables/useInlineCreate.js');

    expect($komposabel)->toContain('let requests = 0')
        ->toContain('listeners.forEach')
        ->toContain('onBeforeUnmount')
        ->toContain('listeners.delete(callback)');

    foreach ([
        'pages/Containers/Tasks.vue',
        'pages/Containers/Items/Index.vue',
        'pages/Containers/Documents.vue',
        'pages/Containers/Costs.vue',
    ] as $sokvag) {
        expect(flikknappKod($sokvag))->toContain('useInlineCreate')
            ->toContain('onRequest(')
            ->toContain("scrollIntoView({ block: 'nearest' })");
    }

    // Fokus: snabbraden via sin egen focus(), de tre formulären i första fältet.
    expect(flikknappKod('pages/Containers/Tasks.vue'))->toContain('quickCreate.value?.focus()');

    foreach ([
        'pages/Containers/Items/Index.vue',
        'pages/Containers/Documents.vue',
        'pages/Containers/Costs.vue',
    ] as $sokvag) {
        expect(flikknappKod($sokvag))
            ->toContain("querySelector('summary, input, select, textarea, button')?.focus()");
    }
});

/*
 * Klart när: "menyn ritar radens namn när den har ett".
 *
 * Raden bär `label` när namnet är användarens eget — ett itemnamn har ingen
 * nyckel i katalogen — och faller annars tillbaka på `create.rows.<key>`,
 * precis som förut. Rubriken är två props: serverns nyckel `pick_item` går in
 * som `headingKey` och slås upp, medan `heading` bär färdig text (fokuskartans
 * nodnamn, issue 156) och ritas ordagrant — användardata får aldrig bli en
 * nyckel. AppLayout förmedlar serverns `create.heading` till `heading-key`.
 */
it('ritar radens namn när den har ett', function () {
    $meny = flikknappKod('components/CreateMenu.vue');

    expect($meny)->toContain('row.label')
        ->toContain('create.rows.${row.key}')
        ->toContain('create.heading')
        ->toContain('props.headingKey');

    expect(flikknappKod('layouts/AppLayout.vue'))->toContain(':heading-key="create.heading"');
});

/*
 * Och de tre flikarna skickar sitt mål vidare till sitt skal. En sida som
 * glömmer raden får ingen knapp, och det är tyst — därför räknas de upp.
 *
 * Sedan issue 275 står målet i `AppLayout`-ledet i sidans kedja: skalet ligger
 * först, och `ContainerLayout` — som förmedlade det förut — rör det inte.
 * Containerns `can` står i `ContainerLayout`-ledet, inte här: pennan ritas av
 * `ContainerLayout` i toppraden och teleporteras in i skalet.
 */
it('skickar flikens mål vidare till containerns skal', function () {
    foreach ([
        'pages/Containers/Documents.vue',
        'pages/Containers/Tasks.vue',
        'pages/Containers/Costs.vue',
    ] as $sokvag) {
        expect(flikknappKod($sokvag))->toContain('[AppLayout, { create: props.create }]');
    }
});

/*
 * Rubriknyckeln står i katalogen. Provet är samma form som PlusknappTest:s
 * radprov: en nyckel som saknas renderas som sin egen nyckel — `create.
 * pick_item` i arkets rubrik — och det är vad raden nedan fångar.
 */
it('har en mening till flikmenyns rubrik', function () {
    expect(Lang::get('ui.create.pick_item', [], 'en'))->toBe('Choose item');
});
