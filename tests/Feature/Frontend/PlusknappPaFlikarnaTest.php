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
 * containerns flikar *Documents*, *Tasks* och *Costs*, och den startar flikens
 * EGEN handling.
 *
 * **Fliken säger redan vad användaren vill göra**, och knappen öppnar därför
 * inte den allmänna menyn från översikten (Tonys beslut 2026-10-07).
 * *Tasks* leder till uppgiftsformuläret, och *Documents* och *Costs* öppnar en
 * meny med en rad per item användaren får skapa i. Målet byggs av
 * App\Support\Frontend\CreateTarget::forContainerTab() och blir sidans
 * `create`-propp, precis som på de fem ytorna i PlusknappTest.
 *
 * **Listan är flikens egen.** `documents` och `costs` får sina rader ur samma
 * `creatableItems()` som rubrikknappen redan ritar — samma grindar, en
 * sanning — och en tom lista ger `null`: ingen meny, ingen knapp. Provet
 * fäster det med en `read`-mottagare, som når fliken men inte får skapa något.
 *
 * **Formen prövas i källkoden** där den bara syns där: att menyn ritar radens
 * eget namn när den har ett, och att de tre sidorna skickar proppen vidare till
 * `ContainerLayout`. Ett skal som glömmer raden får ingen knapp, och det är
 * tyst.
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
 * Klart när: "tasks-fliken har en plusknapp till formuläret".
 *
 * Målet är flikens EGET — formuläret för en ny uppgift — och inte den
 * allmänna menyn från översikten. `?return` bär fliken tillbaka, precis som
 * *New task* i rubrikraden (issue 246 § Beslut 3), och förvalet är inboxen:
 * en container är inte ett item. Målet ritas alltid — inboxen är alltid ett
 * möjligt mål — och därför prövas det för en `read`-mottagare längst ned.
 */
it('har en plusknapp till formuläret på tasks-fliken', function () {
    withoutVite();

    [, $anvandare, $container] = flikknappKontext();

    actingAs($anvandare)->get("/containers/{$container->ulid}/tasks")->assertInertia(
        fn (AssertableInertia $page) => $page->where('create.kind', 'task')
            ->where('create.href', "/tasks/create?return=/containers/{$container->ulid}/tasks"),
    );
});

/*
 * Klart när: "documents-fliken listar items användaren får skapa i".
 *
 * En rad per item, och raden leder till itemets bilageflik — samma mål som
 * rubrikknappen *Lägg till dokument* (Beslut 5). `create.heading` är en NYCKEL,
 * inte en färdig mening: skalet formulerar rubriken på användarens språk
 * ([[ADR-0021 Frontendteknik]]).
 */
it('listar items användaren får skapa i på documents-fliken', function () {
    withoutVite();

    [, $anvandare, $container] = flikknappKontext();

    $motorn = flikknappItem($container, 'Motorn');
    $seglet = flikknappItem($container, 'Seglet');

    actingAs($anvandare)->get("/containers/{$container->ulid}/documents")->assertInertia(
        fn (AssertableInertia $page) => $page->where('create.kind', 'menu')
            ->where('create.heading', 'pick_item')
            ->has('create.rows', 2)
            ->where('create.rows', [
                [
                    'key' => 'item_target',
                    'label' => 'Motorn',
                    'href' => "/containers/{$container->ulid}/items/{$motorn->ulid}?tab=attachments",
                ],
                [
                    'key' => 'item_target',
                    'label' => 'Seglet',
                    'href' => "/containers/{$container->ulid}/items/{$seglet->ulid}?tab=attachments",
                ],
            ]),
    );
});

/*
 * Klart när: "costs-fliken leder till itemets kostnadsflik".
 *
 * Samma form som documents, med `tab=costs` i stället för `tab=attachments`:
 * en kostnadsrad hör till ett item, och ytan där den skrivs är itemets
 * kostnadsflik (issue 168).
 */
it('leder till itemets kostnadsflik på costs-fliken', function () {
    withoutVite();

    [, $anvandare, $container] = flikknappKontext();

    $motorn = flikknappItem($container, 'Motorn');

    actingAs($anvandare)->get("/containers/{$container->ulid}/costs")->assertInertia(
        fn (AssertableInertia $page) => $page->where('create.kind', 'menu')
            ->where('create.heading', 'pick_item')
            ->where('create.rows', [
                [
                    'key' => 'item_target',
                    'label' => 'Motorn',
                    'href' => "/containers/{$container->ulid}/items/{$motorn->ulid}?tab=costs",
                ],
            ]),
    );
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
 * ---------------------------------------------------------------------------
 * Formen. Klart när-punkt 5, och kopplingen mellan proppen och skalet.
 * ---------------------------------------------------------------------------
 */

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
 * Flikarna skickar containerns `can` i samma led (fynd 2), för skalet ritar
 * pennan på mobilens topprad ur den.
 */
it('skickar flikens mål vidare till containerns skal', function () {
    foreach ([
        'pages/Containers/Documents.vue',
        'pages/Containers/Tasks.vue',
        'pages/Containers/Costs.vue',
    ] as $sokvag) {
        expect(flikknappKod($sokvag))->toContain('[AppLayout, { create: props.create, can: props.can }]');
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
