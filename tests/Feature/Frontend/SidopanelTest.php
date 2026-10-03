<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use App\Support\Access\AccessLevel;
use App\Support\Frontend\ActiveContainer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;
use function Pest\Laravel\withSession;

/*
 * Issue 169 · Desktopskalets sidopanel, se [[ADR-0050 Desktopdesignen]] § 1,
 * [[ADR-0036 Containerns art]] och [[ADR-0048 Mobilen och plusknappen]] § 1.
 *
 * Skalet ritas i klienten: Inertia renderar mallen i webbläsaren, och en
 * svarskropp i en testsvit bär bara rotvyn. Provet är därför två slag, som
 * MobilskalTest och NyligenBesoktaTest:
 *
 *   - **Formen på källkoden** — ordningen mellan sektionerna i panelen, att
 *     varje rad är en länk med träffyta och fokusring, och att den aktuella
 *     raden bär `aria-current`. Det går inte att se i ett svar.
 *   - **Serverns svar** — att `shellContainers` är en optional propp, hur
 *     containrarna grupperas, och vilka containrar användaren når. Det går
 *     inte att se i källkoden.
 *
 * Kommentarer rensas bort före varje källkodskontroll, som i MobilskalTest:
 * filerna är fulla av svensk prosa, och en regel som letar efter
 * `aria-current` ska inte kunna nöjas av en mening i ett docblock.
 *
 * **Det som INTE prövas här** är det som kräver en webbläsare: att panelen ser
 * ut som `docs/Design/main.jpeg`, att den mörka ytan bär rätt kontrast, och
 * att fokusringen syns mot `--color-shell`. Handprovet står i PR-kroppen.
 *
 * Hjälparna har prefixet `sidopanel` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * En fil under resources/js, med kommentarer borta. Samma tre slag som
 * GenomgangTest och MobilskalTest rensar.
 */
function sidopanelKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Ägarkontot, dess medlem och en container, i ordningen
 * [$konto, $ägare, $container].
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function sidopanelKontext(): array
{
    [$konto, $ägare] = sidopanelKonto();

    return [$konto, $ägare, Container::factory()->for($konto, 'account')->create()];
}

/**
 * Ett konto med en ägare och inga containrar, i ordningen [$konto, $ägare] —
 * för de prov som bygger sin egen uppsättning.
 *
 * @return array{0: Account, 1: User}
 */
function sidopanelKonto(): array
{
    $konto = Account::factory()->create();
    $ägare = User::factory()->create();
    $konto->users()->attach($ägare, ['role' => 'owner']);

    return [$konto, $ägare];
}

/**
 * En container med ett bestämt namn och en bestämd art. `null` är en container
 * utan art — fältet är frivilligt sedan issue 84 ([[ADR-0036 Containerns
 * art]]), och den hamnar i högen.
 */
function sidopanelContainer(Account $konto, string $namn, ?string $art = null): Container
{
    return Container::factory()->for($konto, 'account')->create([
        'name' => $namn,
        'kind' => $art,
    ]);
}

/**
 * Ett item i containern, skapat av ägaren.
 */
function sidopanelItem(Container $container, string $namn, User $skapare): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $skapare->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En mottagare UTANFÖR ägarkontot med `read` på ett enda item — samma form
 * som `nyligenListaItemgrant()` i NyligenBesoktaTest.
 *
 * @return array{0: User, 1: ContainerAccess}
 */
function sidopanelItemgrant(Container $container, Item $item): array
{
    $mottagare = User::factory()->create();

    $access = ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => AccessLevel::READ,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return [$mottagare, $access];
}

/**
 * Listan, hämtad som klienten hämtar den: en partiell omladdning av den
 * optionala proppen.
 *
 * Versionsheadern är den samma middleware skulle svara med — ett anrop med
 * fel version är 409, och det är inte det här provet handlar om.
 */
function sidopanelSvar(User $anvandare, string $url = '/dashboard', string $komponent = 'Dashboard'): TestResponse
{
    return actingAs($anvandare)->get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => $komponent,
        'X-Inertia-Partial-Data' => 'shellContainers',
    ])->assertOk();
}

/**
 * Grupperna ur det partiella svaret, i serverns ordning.
 *
 * @return list<array{kind: string|null, containers: list<array{ulid: string, name: string}>}>
 */
function sidopanelGrupper(TestResponse $svar): array
{
    /** @var list<array{kind: string|null, containers: list<array{ulid: string, name: string}>}> $grupper */
    $grupper = $svar->json('props.shellContainers');

    return $grupper;
}

/**
 * Containernamnen i en grupp, i serverns ordning.
 *
 * @param  array{kind: string|null, containers: list<array{ulid: string, name: string}>}  $grupp
 * @return list<string>
 */
function sidopanelNamn(array $grupp): array
{
    return array_column($grupp['containers'], 'name');
}

/*
 * Klart när (issue 653 och 677): över `md:` står sidopanelen i mockupens
 * ordning — översikten, containrarna, resten av raderna, favoriterna, och
 * användaren med vägen ut sist ([[ADR-0050 Desktopdesignen]] § 1).
 *
 * Ordningen läses ur källan i stället för att skrivas av här: en sektion som
 * flyttar eller försvinner ska fälla provet, och panelen är den enda platsen
 * de fem möts. *Nyligen besökta* är borta sedan issue 677 och får inte komma
 * tillbaka genom en import som ingen ser.
 */
it('ritar sidopanelen i mockupens ordning', function () {
    $layout = sidopanelKod('layouts/AppLayout.vue');

    // Panelen är en mörk yta över `md:` och ritas av samma komponenter som
    // mobilens sidomeny ([[ADR-0048 Mobilen och plusknappen]] § 1).
    expect($layout)->toContain('shell-tone')
        ->toContain('bg-shell')
        ->toContain('md:flex');

    $start = (int) strpos($layout, '<aside');
    $panel = substr($layout, $start, (int) strpos($layout, '</aside>', $start) - $start);

    expect($panel)->not->toBeEmpty('layouten har ingen sidopanel');

    $ordning = array_map(
        fn (string $nål): int|false => strpos($panel, $nål),
        [
            '<ShellSections part="top" />',
            '<ShellContainerList',
            '<ShellSections />',
            '<ShellSections part="favorites" />',
            '<ShellSections part="account" />',
        ],
    );

    // Fem anrop, och alla fem står i panelen.
    foreach ($ordning as $position) {
        expect($position)->not->toBeFalse();
    }

    for ($i = 1; $i < count($ordning); $i++) {
        expect($ordning[$i - 1])->toBeLessThan($ordning[$i]);
    }

    // *Nyligen besökta* ritas inte längre i skalet (issue 677), varken i
    // panelen eller utanför den.
    expect($panel)->not->toContain('RecentVisitList')
        ->and($layout)->not->toContain('RecentVisitList');

    // Användarens rad står sist och är tryckt till panelens botten: den ska
    // inte följa med i skrollen när panelen är lång. Linjen överst i blocket
    // kom med issue 677.
    expect($panel)->toMatch('/<div class="mt-auto">\s*<hr class="my-2 border-white\/10" \/>\s*<ShellSections part="account" \/>/');

    // Listan som frågar servern får sin `load` av layouten, som är den som vet
    // att panelen ritas; favoriterna och kontoraden är delade och behöver
    // inget besked.
    expect($panel)->toContain('<ShellContainerList v-if="user" :load="isDesktopPanel" />');
});

/*
 * Klart när (issue 677): panelens block skiljs åt av exakt fyra linjer, och
 * bara i panelen — mobilmenyn får inga ([[ADR-0050 Desktopdesignen]] § 1).
 *
 * Den första och den tredje ritas bara när blocket under dem har ett innehåll:
 * en linje över en tom containerlista eller tomma favoriter är en gräns mot
 * ingenting. Linjen över kontoblocket står som första barn i `mt-auto`, och
 * den prövas av ordningsprovet ovanför.
 */
it('skiljer sidopanelens block åt med en linje', function () {
    $layout = sidopanelKod('layouts/AppLayout.vue');
    $meny = sidopanelKod('components/MobileMenu.vue');

    $start = (int) strpos($layout, '<aside');
    $panel = substr($layout, $start, (int) strpos($layout, '</aside>', $start) - $start);

    // Fyra linjer, och alla fyra står i panelen.
    expect(substr_count($panel, '<hr'))->toBe(4);

    // Före containerlistan, villkorad av att användaren finns.
    expect($panel)->toMatch('/<hr v-if="user" class="my-2 border-white\/10" \/>\s*<ShellContainerList/');

    // Mellan containerlistan och raderna, utan villkor.
    expect($panel)->toMatch('/<ShellContainerList[^>]*\/>\s*<hr class="my-2 border-white\/10" \/>\s*<ShellSections \/>/');

    // Före favoriterna, villkorad av att listan har rader.
    expect($panel)->toMatch('/<hr v-if="hasFavorites" class="my-2 border-white\/10" \/>\s*<ShellSections part="favorites" \/>/');

    // Villkoret är listans längd ur den delade proppen.
    expect($layout)->toContain('(page.props.favorites ?? []).length > 0');

    // Menyn får inga linjer.
    expect($meny)->not->toContain('<hr');
});

/*
 * Klart när (issue 677): *Add container* är sista raden i containerlistan och
 * leder till formuläret.
 *
 * Raden står i en egen `<ul>` efter grupperna, inuti samma `<nav>`, och bär
 * containerlänkarnas klasser plus ikonen. Adressen är ruttens sanna form, och
 * etiketten kommer ur `lang/` som all annan text i skalet.
 */
it('leder sista raden i containerlistan till att skapa en container', function () {
    $lista = sidopanelKod('components/ShellContainerList.vue');

    // Raden står efter grupperna, inte inuti dem: den hör till blocket och
    // inte till en art.
    $grupper = (int) strpos($lista, 'v-for="group in groups"');
    $raden = (int) strpos($lista, 'href="/containers/create"');

    expect($grupper)->not->toBeFalse()
        ->and($raden)->not->toBeFalse()
        ->and($raden)->toBeGreaterThan($grupper);

    // Målet, etiketten och ikonen — plustecknet ur CreateButton.vue.
    expect($lista)->toContain("t('nav.add_container')")
        ->toContain('<path d="M12 5v14">')
        ->toContain('<path d="M5 12h14">')
        ->toContain('class="h-5 w-5"')
        ->toContain('hover:bg-shell-active/50');

    expect(route('containers.create', [], false))->toBe('/containers/create');
    expect(trans('ui.nav.add_container', [], 'en'))->toBe('Add container');
});

/*
 * Klart när (issue 677): blocket ritas även utan containrar, med högens rubrik
 * och raden *Add container* — den sista finns för varje inloggad.
 *
 * `loaded` skiljer "frågan är ställd men inte besvarad" (`undefined`) från
 * "användaren har inga containrar" (`[]`): det första ritar ingenting, det
 * andra ritar rubriken och raden. Ett `v-if="groups.length"` hade gömt båda.
 */
it('ritar containerblocket med bara Add container när användaren saknar containrar', function () {
    $lista = sidopanelKod('components/ShellContainerList.vue');

    expect($lista)->toContain('page.props.shellContainers !== undefined')
        ->toContain('<h2 v-if="!groups.length"');

    expect($lista)->not->toContain('v-if="groups.length"');

    // Serverns halva: en kontoägare utan containrar får en TOM lista ur den
    // partiella omladdningen — samma form som en gäst, och den form `loaded`
    // läser som "svaret är här".
    withoutVite();

    [, $ägare] = sidopanelKonto();

    expect(sidopanelGrupper(sidopanelSvar($ägare)))->toBe([]);
});

/*
 * Klart när (issue 653): raderna delas mellan `top`, `rows` och `account`, och
 * delarna är urval ur SAMMA lista — `sections` delas inte i tre arrayer.
 *
 * `top` ritar bara översikten, `account` bara användarens rad med vägen ut,
 * och `rows` resten. Provet läser grenarna i källan: en rad i fel gren är en
 * rad på fel plats i panelen, och det syns inte i en svarskropp.
 */
it('delar raderna mellan top, rows och account', function () {
    $sektioner = sidopanelKod('components/ShellSections.vue');

    expect($sektioner)->toContain("'top'")->toContain("'account'");

    // top-grenen: fönstret är filen från `'top'` fram till `'account'`, alltså
    // grenen före kontoradens.
    $top = substr($sektioner, (int) strpos($sektioner, "'top'"));
    $top = substr($top, 0, (int) strpos($top, "'account'"));

    expect($top)->toContain("'dashboard'")
        ->and($top)->not->toContain("'settings'");

    // account-grenen bär inställningsraden, vägen ut och gästens väg in.
    $konto = substr($sektioner, (int) strpos($sektioner, "'account'"));

    expect($konto)->toContain("'settings'")
        ->toContain('/logout')
        ->toContain('/login');
});

/*
 * Klart när: `shellContainers` saknas i en vanlig sidladdning och finns vid en
 * partiell omladdning.
 *
 * Det är hela skillnaden mellan en DELAD prop och en OPTIONAL (samma
 * konstruktion som `recentVisits`, issue 160): listan behövs bara i
 * sidopanelen och sidomenyn, och en sida där ingen av dem ritas ska inte
 * betala för den. Provet prövar BÅDA halvorna — utan den andra hade en propp
 * som aldrig delas alls passerat.
 *
 * Gästen prövas FÖRST: `actingAs()` sätter guardens användare för resten av
 * testet, och anropen nedanför är inloggade.
 */
it('saknar shellContainers i en vanlig sidladdning och har den vid en partiell omladdning', function () {
    withoutVite();

    [, $ägare, $container] = sidopanelKontext();

    get('/')->assertOk()->assertInertia(fn ($page) => $page
        ->where('auth.user', null)
        ->missing('shellContainers')
    );

    // Gästen får samma TOMHET som en inloggad utan containrar — formen är
    // densamma, så skalet aldrig behöver två avpackningsvägar. Anropet görs
    // som gäst, alltså utan `actingAs()`: `/` är den enda sidan en gäst når,
    // och `shellContainers()` returnerar tidigt för henne.
    $gäst = get('/', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => 'Welcome',
        'X-Inertia-Partial-Data' => 'shellContainers',
    ])->assertOk();

    expect($gäst->json('props.shellContainers'))->toBe([]);

    // Först HÄR blir anropen inloggade: `actingAs()` sätter guardens användare
    // för resten av testet, och gästen ovanför måste därför prövas först.
    actingAs($ägare)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
        ->has('favorites')
        ->missing('shellContainers')
    );

    $grupper = sidopanelGrupper(sidopanelSvar($ägare));

    expect($grupper)->toHaveCount(1)
        ->and(sidopanelNamn($grupper[0]))->toBe([$container->name]);
});

/*
 * Klart när: en art med två containrar blir en grupp och en ensam art hamnar i
 * högen ([[ADR-0036 Containerns art]]).
 *
 * Regeln är den som dashboardens kort redan följer, och den bor i
 * App\Actions\Container\ContainerKindGroups: servern skickar `kind: null` för
 * högen och artens eget ord för de andra, så att vyn kan se skillnaden — en
 * art är användarens egen sträng och slås aldrig upp i `lang/`.
 */
it('gör en art med två containrar till en grupp och lägger en ensam art i högen', function () {
    [$konto, $ägare] = sidopanelKonto();

    sidopanelContainer($konto, 'Båten', 'Båt');
    sidopanelContainer($konto, 'Jollen', 'Båt');
    sidopanelContainer($konto, 'Traktorn', 'Traktor');
    sidopanelContainer($konto, 'Utan art', null);

    $grupper = sidopanelGrupper(sidopanelSvar($ägare));

    // Högen FÖRST och arterna därefter i bokstavsordning, som i mockupen.
    expect(array_column($grupper, 'kind'))->toBe([null, 'Båt']);

    // Den ensamma arten OCH den artlösa containern ligger i högen, i
    // namnordning — en art med ett objekt under sig är en rubrik som ser ut
    // som ett fel i programmet.
    expect(sidopanelNamn($grupper[0]))->toBe(['Traktorn', 'Utan art'])
        ->and(sidopanelNamn($grupper[1]))->toBe(['Båten', 'Jollen']);

    // Raden bär namn och ULID och ingenting mer: talen och bilden hör till
    // dashboardens kort, och adressen byggs i klienten ur ULID:n.
    expect(array_keys($grupper[0]['containers'][0]))->toBe(['ulid', 'name'])
        ->and($grupper[0]['containers'][0]['ulid'])->toBe(
            Container::query()->where('name', 'Traktorn')->firstOrFail()->ulid
        );
});

/*
 * Klart när: containerlistan visar bara containrar användaren når.
 *
 * Urvalet är `Container::scopeAccessibleBy()` — samma villkor som
 * `/containers` och dashboardens kort ställer (issue 73 § Beslut 6). En
 * främmande container får varken synas eller höras: namnet, ULID:n eller ett
 * tal om hur många som dolts hade berättat för användaren att det finns
 * containrar hon inte når, vilket är precis vad omfångsmodellen finns för att
 * inte göra.
 */
it('visar bara de containrar användaren når', function () {
    [, $ägare, $container] = sidopanelKontext();

    $motorn = sidopanelItem($container, 'Motorn', $ägare);

    // Ett annat konto, som ägaren inte är medlem i.
    $frammandeKonto = Account::factory()->create();
    $frammande = sidopanelContainer($frammandeKonto, 'Främmande båt', 'Båt');

    [$mottagare] = sidopanelItemgrant($container, $motorn);

    $ägarensSvar = sidopanelSvar($ägare);

    expect(sidopanelNamn(sidopanelGrupper($ägarensSvar)[0]))->toBe([$container->name])
        ->and($ägarensSvar->getContent())->not->toContain($frammande->ulid)
        ->and($ägarensSvar->getContent())->not->toContain('Främmande båt');

    // Mottagaren når containern genom sin grant på ett ENSKILT item — samma
    // väg in som `/containers` ger henne — och ser den därför i listan.
    expect(sidopanelNamn(sidopanelGrupper(sidopanelSvar($mottagare))[0]))->toBe([$container->name]);
});

/*
 * Klart när (issue 676): en container markeras bara när sidans adress ligger i
 * den — på `/dashboard` med sessionsnyckeln satt är varje rad omarkerad.
 *
 * Det är ett källkodsprov: `aria-current` sätts av vyn när raden ritas, och
 * ett svar från servern bär ingen markup. Adressen är `page.url` utan
 * querysträng — samma form som `currentPath` i ShellSections — och jämförs med
 * containerns egen väg och dess undersidor. Den delade proppen
 * `activeContainer` läses inte längre här: den sätts av den kontroller som
 * ÖPPNADE containern (issue 83) och är sann även på `/dashboard`, där både
 * översiktsraden och containerraden blev markerade samtidigt.
 */
it('markerar en container bara när sidans adress ligger i den', function () {
    $lista = sidopanelKod('components/ShellContainerList.vue');
    $sektioner = sidopanelKod('components/ShellSections.vue');

    // Containerraden: sidans egen väg, utan querysträngen, mot radens väg och
    // dess undersidor. `activeContainer` läses inte alls — proppen lever kvar
    // för Containers/Index.vue (beslut 2), men den här listan frågar den inte.
    expect($lista)->toContain(":aria-current=\"isActive(container) ? 'page' : undefined\"")
        ->toContain("page.url.split('?')[0]")
        ->toContain('`/containers/${container.ulid}`')
        ->toContain('`/containers/${container.ulid}/`')
        ->toContain('bg-shell-active');

    expect($lista)->not->toContain('activeContainer');

    // Sektionsraden är oförändrad: samma väg och samma token-färg.
    expect($sektioner)->toContain(":aria-current=\"isCurrent(section) ? 'page' : undefined\"")
        ->toContain("page.url.split('?')[0]")
        ->toContain('bg-shell-active');

    // Och proppen `activeContainer` lever: `/dashboard` med sessionsnyckeln
    // satt delar fortfarande containerns ulid (beslut 2).
    withoutVite();

    [, $ägare, $container] = sidopanelKontext();

    withSession([ActiveContainer::SESSION_KEY => $container->ulid])
        ->actingAs($ägare)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('activeContainer', $container->ulid));

    expect(sidopanelGrupper(sidopanelSvar($ägare))[0]['containers'][0]['ulid'])->toBe($container->ulid);
});

/*
 * Klart när (issue 676): hover är en bakgrund, inte en understrykning — i
 * sidopanelen och i containernas rader, som delar ytan med sidomenyn.
 *
 * Varje `<Link>` i de två filerna bär `hover:bg-shell-active/50`, antingen i
 * `class` (rader utan aktiv gren: utloggningen, `/login`, favoriten) eller i
 * `:class` (raderna med en aktiv gren). Provet läser taggen och inte filen, så
 * en ny rad utan hover fälls även om grannarna har den.
 */
it('ger varje länk i sidopanelen en bakgrund vid hover och ingen understrykning', function () {
    foreach (['components/ShellContainerList.vue', 'components/ShellSections.vue'] as $sokvag) {
        $kod = sidopanelKod($sokvag);

        expect($kod)->not->toContain('hover:underline');

        preg_match_all('#<Link\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*?>#s', $kod, $länkar);

        expect($länkar[0])->not->toBeEmpty("{$sokvag} har inga länkar");

        foreach ($länkar[0] as $länk) {
            // Ingen förklaring som andra argument: `toContain` tar fler nålar,
            // inte ett meddelande — strängen hade blivit en andra nål.
            expect($länk)->toContain('hover:bg-shell-active/50');
        }
    }
});

/*
 * Klart när (issue 676): den aktiva raden behåller full markering vid hover.
 *
 * Hoverklassen står i `:class`-grenen för en INAKTIV rad och inte i den
 * statiska `class`, så en rad som redan är markerad inte tonas ned när pekaren
 * vilar på den. Provet läser hela uttrycket: en hoverklass i `class` hade
 * gett den aktiva raden halv bakgrund ovanpå sin fulla.
 */
it('låter den aktiva raden behålla full markering vid hover', function () {
    $lista = sidopanelKod('components/ShellContainerList.vue');
    $sektioner = sidopanelKod('components/ShellSections.vue');

    expect($lista)->toContain("isActive(container) ? 'bg-shell-active text-white' : 'hover:bg-shell-active/50'");
    expect($sektioner)->toContain("isCurrent(section) ? 'bg-shell-active text-white' : 'hover:bg-shell-active/50'");
});

/*
 * Klart när: varje rad i sidopanelen är en länk med fokusring.
 *
 * Träffytan är 44 px och bor på länken (issue 68a § Beslut 3); fokusringen är
 * `--color-focus` och får aldrig tas bort ([[ADR-0042 Designsystemet]]
 * § Beslut). Ringen prövas bara i den nya komponenten — `outline-none` utan en
 * ring som tar över river tangentbordsarbetet, och regeln gäller varje fil som
 * nollställer en outline.
 */
it('gör varje rad i sidopanelen till en länk med träffyta och fokusring', function () {
    foreach (['components/ShellContainerList.vue', 'components/ShellSections.vue'] as $sokvag) {
        $kod = sidopanelKod($sokvag);

        preg_match_all('#<li\b.*?</li>#s', $kod, $träffar);

        expect($träffar[0])->not->toBeEmpty("{$sokvag} har inga rader");

        foreach ($träffar[0] as $rad) {
            // Raden bär ett mål och en träffyta. Ingen <div> med @click: en
            // yta ingen når med tabb är ingen yta (issue 68a § Beslut 1).
            expect($rad)->toMatch('#<(Link|button)\b#', "en rad i {$sokvag} är inget mål")
                ->toMatch('#\bmin-h-11\b#', "en rad i {$sokvag} har en träffyta under 44 px");
        }

        if (str_contains($kod, 'outline-none')) {
            expect($kod)->toMatch('#focus-visible:ring-2#')
                ->toMatch('#focus-visible:ring-focus#');
        }
    }

    // Panelen själv: märket och avataren är också mål, och de bär samma
    // träffyta som raderna.
    $layout = sidopanelKod('layouts/AppLayout.vue');

    preg_match_all('#<Link\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*?>#s', $layout, $länkar);

    expect($länkar[0])->not->toBeEmpty();

    foreach ($länkar[0] as $länk) {
        expect($länk)->toMatch('#\bmin-h-11\b#', "ett mål i skalet har en träffyta under 44 px:\n{$länk}");
    }
});
