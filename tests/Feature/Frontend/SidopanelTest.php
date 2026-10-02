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
 * Klart när: över `md:` visas sidopanelen med raderna, containerlistan,
 * *Nyligen besökta* och *Favoriter* i den ordningen.
 *
 * Ordningen läses ur källan i stället för att skrivas av här: en sektion som
 * flyttar eller försvinner ska fälla provet, och panelen är den enda platsen
 * de fyra möts.
 */
it('ritar sidopanelen med raderna, containerlistan, de senast besökta och favoriterna i den ordningen', function () {
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
            '<ShellSections />',
            '<ShellContainerList',
            '<RecentVisitList',
            '<ShellSections part="favorites" />',
        ],
    );

    // Fyra sektioner, och alla fyra står i panelen.
    expect($ordning[0])->toBeInt()
        ->and($ordning[1])->toBeInt()
        ->and($ordning[2])->toBeInt()
        ->and($ordning[3])->toBeInt();

    expect($ordning[0])->toBeLessThan($ordning[1])
        ->and($ordning[1])->toBeLessThan($ordning[2])
        ->and($ordning[2])->toBeLessThan($ordning[3]);

    // De två listorna som frågar servern får sin `load` av layouten, som är
    // den som vet att panelen ritas; favoriterna är delade och behöver inget
    // besked.
    expect($panel)->toContain('<ShellContainerList v-if="user" :load="isDesktopPanel" />')
        ->toContain('<RecentVisitList v-if="user" :load="isDesktopPanel" />');
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
 * Klart när: den aktiva containern och sidan bär `aria-current`.
 *
 * Det är ett källkodsprov: `aria-current` sätts av vyn när raden ritas, och
 * ett svar från servern bär ingen markup. Den aktiva containern kommer ur den
 * delade proppen `activeContainer` — satt av den kontroller som ÖPPNADE
 * containern (issue 83) — och vyn prövar inte åtkomsten en gång till.
 */
it('märker den aktiva containern och den aktuella sidan med aria-current', function () {
    $lista = sidopanelKod('components/ShellContainerList.vue');
    $sektioner = sidopanelKod('components/ShellSections.vue');

    // Containerraden: ULID:n ur `activeContainer`, och token-färgen ur
    // ADR-0042 — rollen "den aktiva raden i sidopanelen".
    expect($lista)->toContain(":aria-current=\"isActive(container) ? 'page' : undefined\"")
        ->toContain('page.props.activeContainer')
        ->toContain('bg-shell-active');

    // Sektionsraden: sidans egen väg, utan querysträngen.
    expect($sektioner)->toContain(":aria-current=\"isCurrent(section) ? 'page' : undefined\"")
        ->toContain("page.url.split('?')[0]")
        ->toContain('bg-shell-active');

    // Och den aktiva containern ligger i listan: vyn har alltid en rad att
    // märka, för proppen `activeContainer` sätts bara för en container
    // användaren når (App\Support\Frontend\ActiveContainer) och listan är
    // omfångsprövad på samma villkor.
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
