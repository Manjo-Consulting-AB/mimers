<?php

use App\Actions\Inbox\ResolveInbox;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use App\Support\Access\AccessLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Issue 242 · Väljaren för målitem. Se [[M27 Flytten och inboxen]] § 242,
 * App\Actions\Item\ListCreatableItems, App\Http\Middleware\
 * HandleInertiaRequests::itemTargets() och
 * resources/js/components/ItemTargetPicker.vue.
 *
 * **Proppen är OPTIONAL, och det är därför proven hämtar den med en partiell
 * omladdning.** En vanlig sidladdning bär den inte alls — det är hela poängen
 * med `Inertia::optional()` — så ett prov som läste `/dashboard` rakt av hade
 * mätt en propp som aldrig kommer med. Formen är `nyligenListaSvar()` i
 * tests/Feature/Frontend/NyligenBesoktaTest.php.
 *
 * **Ett partiellt svar är JSON och ingen sida.** `assertInertia()` läser
 * rotvyns `page`-data, och ett svar på en partiell omladdning har ingen — det
 * läses därför med `->json('props.itemTargets')`.
 *
 * **Frågekostnaden prövas med mönstret *värm, nollställ, mät*** från
 * NyligenBesoktaTest och SokvyTest: `ResolveItemScope` är `scoped` och
 * memoiserar per request i drift, men i testsviten överlever memon mellan
 * HTTP-anropen — utan `app()->forgetScopedInstances()` mäter man ett uppvärmt
 * anrop och provet bevisar ingenting.
 *
 * Hjälparna har prefixet `malitem` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett ägarkonto med en medlem och en container, i ordningen
 * [$konto, $ägare, $container].
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function malitemKonto(): array
{
    $konto = Account::factory()->create();
    $ägare = User::factory()->create();
    $konto->users()->attach($ägare, ['role' => 'owner']);

    return [$konto, $ägare, Container::factory()->for($konto, 'account')->create(['name' => 'Vindil'])];
}

/**
 * En container till, ägd av samma konto.
 */
function malitemBehallare(Account $konto, string $namn): Container
{
    return Container::factory()->for($konto, 'account')->create(['name' => $namn]);
}

/**
 * Ett item i containern, med sammanhängande `created_by_*` — fabrikens egna
 * default-skapare hade annars blivit två ovidkommande rader per item.
 */
function malitemItem(Container $container, string $namn, ?User $skapare = null): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => ($skapare ?? User::factory()->create())->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En grant till $mottagare: itemnivå när $item ges, containerbred annars —
 * samma två former som foralderGrant och nyligenListaItemgrant använder.
 */
function malitemGrant(Container $container, User $mottagare, ?Item $item, string $niva): User
{
    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $niva,
        'kind' => $item === null ? 'member' : 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/**
 * En `parent`-kant, kanoniskt lagrad — samma form som foralderKant.
 */
function malitemKant(Item $foralder, Item $barn): ItemLink
{
    return ItemLink::factory()->create([
        'from_item_id' => $foralder->id,
        'to_item_id' => $barn->id,
        'relation' => 'parent',
    ]);
}

/**
 * Proppen, hämtad som klienten hämtar den: en partiell omladdning av just
 * `itemTargets`.
 *
 * Versionsheadern är den samma middleware skulle svara med — ett anrop med
 * fel version är 409, och det är inte det här provet handlar om.
 */
function malitemSvar(User $anvandare, string $url = '/dashboard', string $komponent = 'Dashboard'): TestResponse
{
    app()->forgetScopedInstances();

    return actingAs($anvandare)->get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => $komponent,
        'X-Inertia-Partial-Data' => 'itemTargets',
    ])->assertOk();
}

/**
 * Grupperna ur det partiella svaret, i serverns ordning.
 *
 * @return list<array{container: array{ulid: string, name: string}, items: list<array{ulid: string, name: string}>}>
 */
function malitemGrupper(TestResponse $svar): array
{
    /** @var list<array{container: array{ulid: string, name: string}, items: list<array{ulid: string, name: string}>}> $grupper */
    $grupper = $svar->json('props.itemTargets');

    return $grupper;
}

/**
 * Antalet frågor anropet ställer, mätt efter ett omätt anrop som värmer
 * guarderna, kontocachen och omfånget — samma mönster som
 * `nyligenListaFragor()`.
 */
function malitemFragor(User $anvandare, string $url = '/dashboard', string $komponent = 'Dashboard'): int
{
    malitemSvar($anvandare, $url, $komponent);

    app()->forgetScopedInstances();

    $frågor = 0;
    DB::listen(function () use (&$frågor) {
        $frågor++;
    });

    malitemSvar($anvandare, $url, $komponent);

    return $frågor;
}

/**
 * Källkoden med kommentarer borta — samma tre slag som foralderKod rensar.
 */
function malitemKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * Klart när: "en vanlig sidladdning saknar proppen, och en partiell begäran
 * med only=itemTargets har den".
 *
 * Provet prövar BÅDA halvorna: frånvaron på en vanlig sidladdning, och att
 * nyckeln kommer när den efterfrågas. Utan den andra halvan hade en propp som
 * aldrig delas alls passerat.
 *
 * Gästen prövas FÖRST: `actingAs()` sätter guardens användare för resten av
 * testet.
 */
it('bär itemTargets bara på begäran', function () {
    withoutVite();

    [, $ägare, $container] = malitemKonto();
    malitemItem($container, 'Motorn', $ägare);

    get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.user', null)
        ->missing('itemTargets')
    );

    // Favoriterna ÄR en delad prop och följer med — skillnaden är halva provet.
    actingAs($ägare)->get('/dashboard')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('favorites')
        ->missing('itemTargets')
    );

    expect(malitemGrupper(malitemSvar($ägare)))->toHaveCount(1);
});

/*
 * Klart när: "listar items i alla containrar användaren får skapa i".
 *
 * Två containrar, och containrarnas namnordning är den omvända mot
 * skapelseordningen: en lista som råkade behålla skapelseordningen faller.
 * Itemens ordning prövas inom gruppen, och formen är exakt
 * `{container: {ulid, name}, items: [{ulid, name}]}` — ingen ULID och ingen
 * väg genom trädet.
 */
it('listar items i alla containrar användaren får skapa i', function () {
    withoutVite();

    [$konto, $ägare, $vindil] = malitemKonto();
    $ankaret = malitemBehallare($konto, 'Ankaret');

    $rodret = malitemItem($vindil, 'Rodret', $ägare);

    $seglet = malitemItem($ankaret, 'Seglet', $ägare);
    $masten = malitemItem($ankaret, 'Masten', $ägare);

    expect(malitemGrupper(malitemSvar($ägare)))->toBe([
        [
            'container' => ['ulid' => $ankaret->ulid, 'name' => 'Ankaret'],
            'items' => [
                ['ulid' => $masten->ulid, 'name' => 'Masten'],
                ['ulid' => $seglet->ulid, 'name' => 'Seglet'],
            ],
        ],
        [
            'container' => ['ulid' => $vindil->ulid, 'name' => 'Vindil'],
            'items' => [
                ['ulid' => $rodret->ulid, 'name' => 'Rodret'],
            ],
        ],
    ]);
});

/*
 * Klart när: "en read-grant på en container ger inga rader därifrån".
 *
 * Mottagaren står utanför ägarkontot och har `read` i den ena containern och
 * `create` i den andra: den första ger inga rader alls — containern utelämnas
 * när inget item i den får användas — och den andra ger alla sina.
 */
it('utelämnar items där användaren bara har read', function () {
    withoutVite();

    [$konto, , $läses] = malitemKonto();
    $skapas = malitemBehallare($konto, 'Ankaret');

    malitemItem($läses, 'Motorn');

    $seglet = malitemItem($skapas, 'Seglet');

    $mottagare = User::factory()->create();
    malitemGrant($läses, $mottagare, null, AccessLevel::READ);
    malitemGrant($skapas, $mottagare, null, AccessLevel::CREATE);

    expect(malitemGrupper(malitemSvar($mottagare)))->toBe([
        [
            'container' => ['ulid' => $skapas->ulid, 'name' => 'Ankaret'],
            'items' => [['ulid' => $seglet->ulid, 'name' => 'Seglet']],
        ],
    ]);
});

/*
 * Klart när: "en itemgrant ger itemet och dess ättlingar men inte syskonen".
 *
 * Granten står på Motorn, Impellern är dess barn och Seglet dess syskon:
 * omfånget följer `parent`-kanterna nedåt och aldrig i sidled, så bara de två
 * första får användas.
 */
it('en itemgrant ger itemet och dess ättlingar men inte syskonen', function () {
    withoutVite();

    [, , $container] = malitemKonto();

    $motorn = malitemItem($container, 'Motorn');
    $impellern = malitemItem($container, 'Impellern');
    malitemItem($container, 'Seglet');

    malitemKant($motorn, $impellern);

    $mottagare = malitemGrant($container, User::factory()->create(), $motorn, AccessLevel::CREATE);

    expect(malitemGrupper(malitemSvar($mottagare)))->toBe([
        [
            'container' => ['ulid' => $container->ulid, 'name' => 'Vindil'],
            'items' => [
                ['ulid' => $impellern->ulid, 'name' => 'Impellern'],
                ['ulid' => $motorn->ulid, 'name' => 'Motorn'],
            ],
        ],
    ]);
});

/*
 * Klart när: "utelämnar mjukraderade items och containrar utan valbara
 * items".
 *
 * Det mjukraderade itemet faller på SoftDeletes' globala scope, och containern
 * det låg i utelämnas när ingenting i den får användas — en containerrubrik
 * utan rader är en plats som ser ut att finnas.
 */
it('utelämnar mjukraderade items och containrar utan valbara items', function () {
    withoutVite();

    [$konto, $ägare, $kvar] = malitemKonto();
    $tömd = malitemBehallare($konto, 'Ankaret');

    $slängt = malitemItem($tömd, 'Slängt', $ägare);
    $slängt->delete();

    $kvarItem = malitemItem($kvar, 'Kvar', $ägare);

    $svar = malitemSvar($ägare);

    expect(malitemGrupper($svar))->toBe([
        [
            'container' => ['ulid' => $kvar->ulid, 'name' => 'Vindil'],
            'items' => [['ulid' => $kvarItem->ulid, 'name' => 'Kvar']],
        ],
    ])->and($svar->getContent())->not->toContain($slängt->ulid);
});

/*
 * Klart när: "användaren har en inbox med `create` på itemet, och den finns
 * inte i itemTargets".
 *
 * Inboxen ÄR åtkomlig för sin ägare — `ResolveInbox` ger henne hela
 * containern på `delete` — men den listas inte (Container::scopeListable()):
 * den är en egen destination och ritas som ett eget val där den behövs,
 * aldrig som ett vanligt item.
 */
it('utelämnar användarens egen inbox', function () {
    withoutVite();

    $person = User::factory()->create();
    $konto = Account::factory()->create(['type' => 'personal']);
    $konto->users()->attach($person, ['role' => 'owner']);

    $inboxItem = app(ResolveInbox::class)->handle($person);

    $container = Container::factory()->for($konto, 'account')->create(['name' => 'Vindil']);
    $motorn = malitemItem($container, 'Motorn', $person);

    $svar = malitemSvar($person);

    expect(malitemGrupper($svar))->toBe([
        [
            'container' => ['ulid' => $container->ulid, 'name' => 'Vindil'],
            'items' => [['ulid' => $motorn->ulid, 'name' => 'Motorn']],
        ],
    ])->and($svar->getContent())->not->toContain($inboxItem->ulid)
        ->and($svar->getContent())->not->toContain($inboxItem->name);
});

/*
 * Klart när: "2 och 8 containrar ger samma antal frågor".
 *
 * Omfånget löses upp i ETT anrop för alla containrar (issue 70 § Beslut 2) och
 * alla item hämtas i EN fråga, så en container till kostar inga nya frågor.
 * En fråga per container är den N+1 som `ItemPolicy::create` per rad hade
 * kostat utan värmningen.
 */
it('kostar ett konstant antal frågor', function () {
    withoutVite();

    [$konto, $ägare, $första] = malitemKonto();
    malitemItem($första, 'Motorn', $ägare);

    expect(malitemGrupper(malitemSvar($ägare)))->toHaveCount(1);

    // Klockan står still runt mätningarna: UpdateLastActiveAt skriver
    // `user.last_active_at` vid varje autentiserat anrop, och faller en
    // sekundgräns mellan värmningen och mätningen blir det en UPDATE extra
    // (issue 477).
    Carbon::setTestNow(now());

    // Inställningssidan och inte `/dashboard`: dashboarden värmer omfånget åt
    // oss genom sina egna brickor, och då hade provet mätt någon annans
    // värmning i stället för ListCreatableItems egen.
    $medTvå = malitemFragor($ägare, '/settings/profile', 'Settings/Profile');

    foreach (range(2, 8) as $nummer) {
        malitemItem(malitemBehallare($konto, "Pärm {$nummer}"), "Item {$nummer}", $ägare);
    }

    expect(malitemGrupper(malitemSvar($ägare, '/settings/profile', 'Settings/Profile')))->toHaveCount(8);

    $medÅtta = malitemFragor($ägare, '/settings/profile', 'Settings/Profile');

    expect($medÅtta)->toBe($medTvå);

    Carbon::setTestNow();
});

/*
 * Klart när: "ItemTargetPicker.vue innehåller Teleport, showModal,
 * router.reload med only, excludeItem och min-h-11".
 *
 * Källkodsprov av samma skäl som ForaldervaljareTest: fokusfällan, den partiella
 * hämtningen och träffytan är klientsidans, och det som går att avgöra ur
 * källkoden är att mönstret är ParentPickers — `<dialog>` med `showModal()`,
 * ingen egen tabbhanterare och inget `aria-modal`.
 */
it('dialogen följer ParentPickers mönster', function () {
    $väljaren = malitemKod('components/ItemTargetPicker.vue');

    expect($väljaren)->toContain('<Teleport to="body">')
        ->toContain('showModal()')
        ->toContain('@close=')
        ->toContain('?.focus()')
        ->toContain('router.reload')
        ->toContain("only: ['itemTargets']")
        ->toContain('excludeItem')
        ->toContain('min-h-11')
        ->toContain("emit('choose'");

    expect($väljaren)->not->toContain('aria-modal');
    expect($väljaren)->not->toMatch('/@keydown\.(tab|esc)/');
});

/*
 * Klart när: "de fyra i Beslut 4".
 *
 * Nycklarna ligger i `lang/en/ui.php`, och komponenten slår upp dem med `t()`
 * som varje annan komponent — ingen mening bor i .vue-filen.
 */
it('har nycklarna', function () {
    expect(Lang::get('ui.item.target.search', [], 'en'))->toBe('Search items')
        ->and(Lang::get('ui.item.target.empty', [], 'en'))->toBe('There is no item you can add to.')
        ->and(Lang::get('ui.item.target.loading', [], 'en'))->toBe('Loading…')
        ->and(Lang::get('ui.item.target.choose', [], 'en'))->toBe('Choose');

    $väljaren = malitemKod('components/ItemTargetPicker.vue');

    expect($väljaren)->toContain('item.target.search')
        ->toContain('item.target.empty')
        ->toContain('item.target.loading')
        ->toContain('item.target.choose');
});
