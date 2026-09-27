<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 153 · Ett item skapas där man står, se [[M23 Mobilen och kartan]]
 * § 153 och [[ADR-0048 Mobilen och plusknappen]] § 3.
 *
 * **Filen är två prov i ett, som PlusknappTest.** Det första är SVARET:
 * `structure`-proppen är samma omfångsfiltrerade träd som detaljvyns
 * vänsterpanel (App\Actions\Item\ResolveItemTree), och varje nod bär
 * `can_create` ur `ItemPolicy::create`. Det andra är FORMEN: att
 * placeringsraden ritar containern och föräldern, att *Ändra* öppnar
 * väljaren, att sökfältet filtrerar trädet på namn, och att trädet i
 * pick-läget stänger ett led användaren inte får välja.
 *
 * **Väljaren är en bekvämlighet ovanpå grinden, inte en ny grind.** Flaggan
 * `can_create` stänger en knapp; `store()` prövar samma policy på nytt, och
 * proven för ett förfalskat `parent` står därför mot RUTTEN och inte mot
 * proppen. Den som litar på flaggan har byggt en andra regel om behörighet —
 * det är den här filens viktigaste gräns.
 *
 * **Det som kräver en webbläsare prövas inte här.** Att sökfältet filtrerar
 * medan man skriver, att fokusfällan håller i handen och att trädet skrollar
 * står i PR-kroppens handprov. Det som går att avgöra ur källkoden är att
 * fällan är webbläsarens — `<dialog>` med `showModal()` — och inte en egen
 * tabbhanterare, samma form som PlusknappTest och GenomgangTest.
 *
 * Hjälparna har prefixet `foralder` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en ägare, och en container ägd av kontot.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function foralderKontext(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare, Container::factory()->for($konto, 'account')->create()];
}

/**
 * Ett item i containern, med sammanhängande `created_by_*`.
 */
function foralderItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En `parent`-kant, kanoniskt lagrad — samma form som trepanelKant och
 * itemrelationKant. Trädet läser kanter, så ett barn blir ett barn först när
 * raden finns.
 */
function foralderKant(Item $foralder, Item $barn): ItemLink
{
    return ItemLink::factory()->create([
        'from_item_id' => $foralder->id,
        'to_item_id' => $barn->id,
        'relation' => 'parent',
    ]);
}

/**
 * En grant till $user: itemnivå när $item ges, containerbred annars — samma
 * två former som plusknappGrant och itemrelationMottagare använder.
 */
function foralderGrant(Container $container, User $user, ?Item $item, string $niva): User
{
    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $niva,
        'kind' => $item === null ? 'member' : 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $user;
}

/**
 * Ett konto mottagaren äger själv, för `account`-fältet i StoreItemRequest:
 * medlemsprövningen i `ItemController::store()` gäller det anropade kontot,
 * inte containerns.
 */
function foralderEgetKonto(User $mottagare): Account
{
    $konto = Account::factory()->create();
    $konto->users()->attach($mottagare, ['role' => 'owner']);

    return $konto;
}

/**
 * Källkoden med kommentarer borta. Samma tre slag som PlusknappTest rensar —
 * docblocken är svenska med flit, och en regel som letar efter markup ska inte
 * kunna nöjas av en mening i en kommentar.
 */
function foralderKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * ---------------------------------------------------------------------------
 * Trädet och flaggan. Klart när-punkterna 1, 4 och 5.
 * ---------------------------------------------------------------------------
 */

it('ritar containerns träd med skapandeflaggan per nod', function () {
    withoutVite();

    [, $anvandare, $container] = foralderKontext();

    $motorn = foralderItem($container, 'Motorn');
    $impellern = foralderItem($container, 'Impellern');
    foralderKant($motorn, $impellern);
    foralderItem($container, 'Seglet');

    // Ägarkontots medlem når hela containern och får skapa var som helst:
    // varje nod bär `can_create`, och formen är detaljvyns — namnordning per
    // nivå och barnen under sin förälder.
    actingAs($anvandare)->get("/containers/{$container->ulid}/items/create")->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Create')
            ->has('structure', 2)
            ->where('structure.0.name', 'Motorn')
            ->where('structure.0.can_create', true)
            ->where('structure.0.children.0.name', 'Impellern')
            ->where('structure.0.children.0.can_create', true)
            ->where('structure.1.name', 'Seglet')
            ->where('can_create_root', true),
    );
});

/*
 * Klart när: "ett item utanför användarens omfång syns inte i väljaren".
 *
 * Trädet är ResolveItemTrees, alltså samma rotregel som detaljvyns panel:
 * mottagaren ser motorn som sin rot och ser den inte under någon förälder hon
 * inte når. Seglet finns i containern och syns inte — ingen räknare och
 * ingen rad om det (issue 73 § Beslut 6).
 */
it('utelämnar ett item utanför anroparens omfång', function () {
    withoutVite();

    [, , $container] = foralderKontext();

    $motorn = foralderItem($container, 'Motorn');
    $impellern = foralderItem($container, 'Impellern');
    foralderKant($motorn, $impellern);
    foralderItem($container, 'Seglet');

    $mottagare = foralderGrant($container, User::factory()->create(), $motorn, 'create');

    actingAs($mottagare)
        ->get("/containers/{$container->ulid}/items/create?parent={$motorn->ulid}")
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('structure', 1)
                ->where('structure.0.name', 'Motorn')
                ->where('structure.0.can_create', true)
                ->where('structure.0.children.0.name', 'Impellern')
                // Hon når ingen rot i containern: den översta raden i
                // väljaren är inte valbar för henne.
                ->where('can_create_root', false),
        );
});

/*
 * Klart när: "ett item utan skapandebehörighet går inte att välja".
 *
 * Läsare i hela containern och skapare under motorn och ingenting annat: hon
 * ser BÅDA noderna och får bara välja den ena. Det är det enda fallet där en
 * nod är synlig utan att vara valbar, och därför det prov som håller
 * `can_create` i sär från omfånget.
 */
it('märker ett item utan skapandebehörighet som icke valbart', function () {
    withoutVite();

    [, , $container] = foralderKontext();

    $motorn = foralderItem($container, 'Motorn');
    foralderItem($container, 'Seglet');

    $mottagare = User::factory()->create();
    foralderGrant($container, $mottagare, null, 'read');
    foralderGrant($container, $mottagare, $motorn, 'create');

    actingAs($mottagare)
        ->get("/containers/{$container->ulid}/items/create?parent={$motorn->ulid}")
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('structure', 2)
                ->where('structure.0.name', 'Motorn')
                ->where('structure.0.can_create', true)
                ->where('structure.1.name', 'Seglet')
                ->where('structure.1.can_create', false),
        );
});

/*
 * ---------------------------------------------------------------------------
 * Grinden. Klart när-punkterna 6 och 7.
 * ---------------------------------------------------------------------------
 */

/*
 * Klart när: "ett förfalskat `parent` avvisas av servern som i dag".
 *
 * Två vägar och två svar, precis som före issue 153: länken (`?parent`) slår
 * upp föräldern i containern och ger 404 när ULID:n ligger i en annan, och
 * postningen får samma svar ur `StoreItemRequest` som `/api` använder — ett
 * valideringsfel på `parent`, inte en tyst rot.
 */
it('avvisar en förälder ur en annan container', function () {
    withoutVite();

    [$konto, $anvandare, $container] = foralderKontext();
    $annan = Container::factory()->for($konto, 'account')->create();
    $frammande = foralderItem($annan, 'Främmande');

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}/items/create?parent={$frammande->ulid}")
        ->assertNotFound();

    actingAs($anvandare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Smugen',
        'account' => $konto->ulid,
        'parent' => $frammande->ulid,
    ])->assertSessionHasErrors('parent');

    expect(Item::query()->where('name', 'Smugen')->exists())->toBeFalse();
});

/*
 * Klart när: "väljaren visar bara items där användaren får skapa ett child"
 * — sedd från den som försöker förbi väljaren. En `read`-mottagare ser
 * motorn i trädet men får inget barn under den: 403, och ingenting skrivet.
 */
it('avvisar en förälder anroparen inte får skapa under', function () {
    withoutVite();

    [, , $container] = foralderKontext();

    $motorn = foralderItem($container, 'Motorn');

    $lasare = foralderGrant($container, User::factory()->create(), $motorn, 'read');
    $hennesKonto = foralderEgetKonto($lasare);

    actingAs($lasare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Impellerbyte 2026',
        'account' => $hennesKonto->ulid,
        'parent' => $motorn->ulid,
    ])->assertForbidden();

    expect(Item::query()->where('name', 'Impellerbyte 2026')->exists())->toBeFalse();
});

/*
 * Klart när: "utan förälder skapas itemet som rot". Ingen kant skrivs alls,
 * och itemet står som rot i väljarens träd — toppnivån är ett svar och inte
 * ett uteblivet val.
 */
it('skapar itemet som rot när ingen förälder skickas', function () {
    withoutVite();

    [$konto, $anvandare, $container] = foralderKontext();

    actingAs($anvandare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Nya motorn',
        'account' => $konto->ulid,
    ])->assertRedirect();

    $nytt = Item::query()->where('name', 'Nya motorn')->firstOrFail();

    expect($nytt->container_id)->toBe($container->id);

    // Ingen förälderkant — varken en `parent`-rad eller någon annan.
    expect(DB::table('item_link')->where('to_item_id', $nytt->id)->count())->toBe(0);

    actingAs($anvandare)->get("/containers/{$container->ulid}/items/create")->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('structure', 1)
            ->where('structure.0.ulid', $nytt->ulid)
            ->where('structure.0.name', 'Nya motorn')
            ->where('structure.0.can_create', true),
    );
});

/*
 * ---------------------------------------------------------------------------
 * Formen. Klart när-punkterna 1, 2, 3 och 8.
 * ---------------------------------------------------------------------------
 */

it('ritar placeringsraden och öppnar väljaren med Ändra', function () {
    $formuläret = foralderKod('components/ItemForm.vue');

    // Raden: containern, föräldern (eller toppnivån), raden under och
    // knappen. Alla fyra orden ur katalogen — ingen mening bor i komponenten.
    expect($formuläret)->toContain('item.form.location')
        ->toContain('item.form.location_root')
        ->toContain('item.form.location_hint')
        ->toContain('item.form.location_change');

    // *Ändra* bär sitt eget element vidare: väljaren lämnar tillbaka fokus
    // dit när den stängs, samma form som plusknappen i skalet.
    expect($formuläret)->toContain('<ParentPicker')
        ->toContain(':open="pickerOpen"')
        ->toContain('@click="openPicker($event.currentTarget)"');

    // Raden börjar på det länken gav — `parent`-proppen — och valet skriver
    // BÅDE raden och det postade fältet, så att de två inte kan visa olika
    // saker.
    expect($formuläret)->toContain('const location = ref(props.parent)')
        ->toContain('form.parent = node?.ulid ?? null');

    $sidan = foralderKod('pages/Containers/Items/Create.vue');

    expect($sidan)->toContain(':structure="structure"')
        ->toContain(':can-create-root="canCreateRoot"')
        ->toContain(':container-name="container.name"');
});

it('visar förälderfelet vid platsraden och låter fokus hamna där', function () {
    // Platsraden är formulärets enda fält utan FormField, och därför den enda
    // felraden som måste ritas för hand. Utan den blir ett 422 på `parent` —
    // en ULID som hunnit bli ogiltig mellan sidladdning och postning, se
    // StoreItemRequest — en tyst omladdning: ingen text att läsa, och
    // focusFirstError letar efter `parent-error` som inte finns.
    $formuläret = foralderKod('components/ItemForm.vue');

    expect($formuläret)->toContain('form.errors.parent')
        ->toContain('v-if="parentError"')
        ->toContain('id="parent-error"')
        ->toContain('tabindex="-1"')
        ->toContain(":aria-describedby=\"parentError ? 'parent-error' : undefined\"");

    // Felraden ligger i platsradens vänstra kolumn, under platsen — samma
    // ordning som FormFields fel under sitt fält.
    expect($formuläret)->toContain('item.form.location_hint');
});

it('filtrerar trädet på namn i väljaren', function () {
    $väljaren = foralderKod('components/ParentPicker.vue');

    // Sökningen går över NAMNET och bara i minnet: trädet är redan
    // omfångsfiltrerat av servern, så ett item hon inte når finns inte att
    // söka fram, och väljaren frågar aldrig servern medan hon skriver.
    expect($väljaren)->toContain('function filterNodes')
        ->toContain('node.name.toLowerCase().includes(needle)')
        ->toContain(':nodes="filtered"')
        ->toContain('item.form.parent_search');

    // Ett tryck på ett led markerar det; *Välj här* skickar valet vidare.
    expect($väljaren)->toContain('item.form.parent_choose')
        ->toContain('@choose="chosen = $event"')
        ->toContain("emit('choose', chosen.value)");
});

it('går inte att välja ett item utan skapandebehörighet', function () {
    $trädet = foralderKod('components/ItemStructureTree.vue');

    // Pick-läget: samma noder, ett annat element — och `can_create` ur
    // proppen stänger knappen. Komponenten frågar aldrig servern om lov.
    expect($trädet)->toContain(':disabled="!node.can_create"')
        ->toContain("emit('choose', node)")
        ->toContain('v-if="pick"');

    foreach (['usePage', 'fetch(', 'axios', 'router.'] as $hämtning) {
        expect($trädet)->not->toContain($hämtning);
    }
});

it('äger fokusfällan i webbläsaren och går att nå med tangentbord', function () {
    $väljaren = foralderKod('components/ParentPicker.vue');

    // Fällan är webbläsarens: `showModal()` håller fokus i dialogen och Esc
    // fyrar `close` (issue 68b § Beslut 2). Ingen egen tabbhanterare och
    // inget `aria-modal` — den fällan är den som fastnar.
    expect($väljaren)->toContain('<dialog')
        ->toContain('showModal()')
        ->toContain('@close=')
        ->toContain('?.focus()');

    expect($väljaren)->not->toContain('aria-modal');
    expect($väljaren)->not->toMatch('/@keydown\.(tab|esc)/');

    // Fokus landar i sökfältet när dialogen öppnas — utan `autofocus` tar
    // webbläsaren första tabbbara element, och det är tryckytan utanför
    // panelen. Leden och knapparna bär 44 px, som varje annan radåtgärd.
    expect($väljaren)->toContain('autofocus')
        ->toContain('min-h-11');

    // Dialogen står utanför formuläret. Ett `<dialog>` bryter ingen
    // formägarhet, så Enter i sökfältet hade lämnat in itemformuläret — ett
    // item skapat av ett sökord.
    expect($väljaren)->toContain('<Teleport to="body">');
});
