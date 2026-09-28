<?php

use App\Actions\Item\ListRecentVisits;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\RecentVisit;
use App\Models\User;
use App\Support\Access\AccessLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Issue 160 · Nyligen besökta — LISTAN. Se [[ADR-0049 Nyligen besökta]],
 * App\Actions\Item\ListRecentVisits,
 * App\Http\Middleware\HandleInertiaRequests::recentVisits() och
 * resources/js/components/RecentVisitList.vue.
 *
 * Skrivningen — att ett besök skriver en rad, att taket är tjugo per person
 * och att en nekad visning inte skriver något — prövas i
 * tests/Feature/Item/NyligenBesoktaTest.php. Här prövas läsningen: ordningen,
 * taket på tio, omfånget, papperskorgen, tystnaden om det som filtrerats
 * bort, att proppen inte frågas i onödan, och frågekostnaden.
 *
 * **Listan är en OPTIONAL prop, och det är därför proven hämtar den med en
 * partiell omladdning.** En vanlig sidladdning bär den inte alls — det är
 * hela poängen med `Inertia::optional()` — så ett prov som läste
 * `/dashboard` rakt av hade mätt en propp som aldrig kommer med, och ett prov
 * som glömde versionsheadern hade fått 409. Formen är
 * `klockaListan()` i tests/Feature/Frontend/NotisklockaTest.php.
 *
 * **Ett partiellt svar är JSON och ingen sida.** `assertInertia()` och
 * `inertiaProps()` läser rotvyns `page`-data, och ett svar på en partiell
 * omladdning har ingen — det läses därför med `->json('props.…')`, precis som
 * NotisklockaTest gör. Den vanliga sidladdningen prövas för sig, med
 * `assertInertia()`.
 *
 * **Frågekostnaden prövas med mönstret *värm, nollställ, mät*** från
 * tests/Feature/Frontend/SokvyTest.php. `ResolveItemScope` är `scoped` och
 * memoiserar per request i drift, men i testsviten överlever memon mellan
 * HTTP-anropen — utan `app()->forgetScopedInstances()` mäter man ett uppvärmt
 * anrop och provet bevisar ingenting.
 *
 * Proven kör WEBBENS sidor, inte `/api`: sektionen är en yta i skalet, och
 * `/api` får ingen ändpunkt för den. Fixturen är FavoritlistaTest:s: ett
 * ägarkonto med en container, och en mottagare utanför kontot med en grant på
 * ett enskilt item.
 *
 * Hjälparna har prefixet `nyligen` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ägarkontot, dess medlem och containern, i ordningen [$konto, $ägare, $container].
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function nyligenListaKontext(): array
{
    $konto = Account::factory()->create();
    $ägare = User::factory()->create();
    $konto->users()->attach($ägare, ['role' => 'owner']);

    return [$konto, $ägare, Container::factory()->for($konto, 'account')->create()];
}

/**
 * Ett item i containern. `created_by_*` sätts sammanhängande — fabrikens egna
 * default-skapare hade annars blivit två ovidkommande rader per item.
 */
function nyligenListaItem(Container $container, string $namn, ?User $skapare = null): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => ($skapare ?? User::factory()->create())->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En mottagare UTANFÖR ägarkontot med `read` på ett enda item.
 *
 * Returnerar BÅDE användaren och raden: åtkomsten ska kunna dras in och ges
 * tillbaka, och det är `ContainerAccess::revoked_at` som drar in den.
 *
 * @return array{0: User, 1: ContainerAccess}
 */
function nyligenListaItemgrant(Container $container, Item $item, ?User $mottagare = null): array
{
    $mottagare ??= User::factory()->create();

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
 * Itemets detaljvy — adressen ett besök skrivs på.
 */
function nyligenListaUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}";
}

/**
 * Öppnar itemet genom rutten, som ett klick gör — inte genom att skriva raden
 * förbi kontrollern. Ett prov som seedade `recent_visit` för hand hade kunnat
 * bevisa listan mot ett tillstånd sidan aldrig kan producera.
 */
function nyligenListaBesok(User $anvandare, Container $container, Item $item): void
{
    app()->forgetScopedInstances();

    actingAs($anvandare)->get(nyligenListaUrl($container, $item))->assertOk();
}

/**
 * Listan, hämtad som klienten hämtar den: en partiell omladdning av den
 * optionala proppen.
 *
 * Versionsheadern är den samma middleware skulle svara med — ett anrop med
 * fel version är 409, och det är inte det här provet handlar om.
 */
function nyligenListaSvar(User $anvandare, string $url = '/dashboard'): TestResponse
{
    app()->forgetScopedInstances();

    return actingAs($anvandare)->get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => 'Dashboard',
        'X-Inertia-Partial-Data' => 'recentVisits',
    ])->assertOk();
}

/**
 * Raderna ur det partiella svaret, i serverns ordning.
 *
 * @return list<array{name: string, container: string, url: string, visited_at: string}>
 */
function nyligenListaRader(TestResponse $svar): array
{
    /** @var list<array{name: string, container: string, url: string, visited_at: string}> $rader */
    $rader = $svar->json('props.recentVisits');

    return $rader;
}

/**
 * Radernas itemnamn, i serverns ordning.
 *
 * @return list<string>
 */
function nyligenListaNamn(TestResponse $svar): array
{
    return array_map(fn (array $rad): string => $rad['name'], nyligenListaRader($svar));
}

/**
 * Antalet frågor $anrop ställer, mätt efter ett omätt anrop som värmer
 * guarderna, kontocachen och omfånget — samma mönster som
 * `favoritlistaFrågor()` i tests/Feature/Frontend/FavoritlistaTest.php.
 */
function nyligenListaFragor(Closure $värm, Closure $anrop): int
{
    app()->forgetScopedInstances();

    $värm();

    $frågor = 0;
    DB::listen(function () use (&$frågor) {
        $frågor++;
    });

    $anrop();

    return $frågor;
}

/*
 * Klart när: listan visar de senaste i ordning.
 *
 * Itemena besöks i en ordning som varken är namnordning eller skapelseordning,
 * och en lista som råkade behålla någon av dem faller. Ett item som aldrig
 * besökts finns inte med, och formen är skalets: namn, container, adress och
 * tidsstämpel — ingen ULID och ingen väg genom trädet
 * ([[ADR-0041 Itemets vy]]).
 */
it('visar de senast besökta i ordning, nyast först', function () {
    withoutVite();

    [, $ägare, $container] = nyligenListaKontext();

    $masten = nyligenListaItem($container, 'Masten', $ägare);
    $ankaret = nyligenListaItem($container, 'Ankaret', $ägare);
    $motorn = nyligenListaItem($container, 'Motorn', $ägare);

    // Ett item hon aldrig öppnat: det hör inte i listan.
    nyligenListaItem($container, 'Seglet', $ägare);

    foreach ([$masten, $ankaret, $motorn] as $index => $item) {
        Carbon::setTestNow(Carbon::parse('2026-09-28 10:00:00')->addMinutes($index));

        nyligenListaBesok($ägare, $container, $item);
    }

    Carbon::setTestNow();

    $svar = nyligenListaSvar($ägare);

    expect(nyligenListaNamn($svar))->toBe(['Motorn', 'Ankaret', 'Masten'])
        ->and(nyligenListaRader($svar))->toHaveCount(3);

    $svar->assertJsonPath('props.recentVisits.0.url', nyligenListaUrl($container, $motorn))
        ->assertJsonPath('props.recentVisits.0.container', $container->name)
        ->assertJsonPath('props.recentVisits.2.name', 'Masten');

    // Formen är skalets och ingenting mer: namnet, containerns namn, adressen
    // och tidsstämpeln. Ingen ULID, ingen container-ULID, ingen väg.
    expect(array_keys(nyligenListaRader($svar)[0]))
        ->toBe(['name', 'container', 'url', 'visited_at']);

    // Tidsstämpeln kommer som ISO 8601 och skrivs av useRelativeDate() i
    // klienten — servern formulerar ingen mening (issue 104).
    expect(RecentVisit::query()->where('user_id', $ägare->id)->count())->toBe(3)
        ->and(nyligenListaRader($svar)[0]['visited_at'])->toBe(
            RecentVisit::query()->where('user_id', $ägare->id)->orderByDesc('visited_at')->first()->visited_at->toIso8601String()
        );
});

/*
 * Klart när: listan visar högst tio, även om personen besökt fler.
 *
 * Taket på tjugo hålls vid skrivningen (App\Actions\Item\RecordRecentVisit),
 * och listan tar de tio översta. De fem äldsta besöken finns alltså kvar i
 * tabellen men syns inte — och det är den ENDA skillnaden mellan de två
 * taken, som [[ADR-0049 Nyligen besökta]] § Beslut beskriver dem.
 */
it('visar högst tio rader även när personen besökt fler', function () {
    withoutVite();

    [, $ägare, $container] = nyligenListaKontext();

    $items = collect(range(1, 15))
        ->map(fn (int $nummer): Item => nyligenListaItem($container, "Item {$nummer}", $ägare));

    foreach ($items as $index => $item) {
        Carbon::setTestNow(Carbon::parse('2026-09-28 10:00:00')->addMinutes($index));

        nyligenListaBesok($ägare, $container, $item);
    }

    Carbon::setTestNow();

    expect(nyligenListaNamn(nyligenListaSvar($ägare)))->toBe([
        'Item 15', 'Item 14', 'Item 13', 'Item 12', 'Item 11',
        'Item 10', 'Item 9', 'Item 8', 'Item 7', 'Item 6',
    ]);

    expect(RecentVisit::query()->where('user_id', $ägare->id)->count())
        ->toBeGreaterThan(ListRecentVisits::LIMIT);
});

/*
 * Klart när: ett item utanför omfånget syns inte, och syns igen när åtkomsten
 * kommer tillbaka.
 *
 * Det är hela skillnaden mellan att filtrera vid LÄSNINGEN och att städa vid
 * skrivningen ([[ADR-0049 Nyligen besökta]] § Beslut): raden står kvar, och en
 * återfådd åtkomst gör den synlig igen utan att hon behöver besöka itemet på
 * nytt. Hade raden raderats när granten drogs in hade listan tappat historik
 * som var sann.
 */
it('ett item utanför omfånget syns inte, och syns igen när åtkomsten kommer tillbaka', function () {
    withoutVite();

    [, , $container] = nyligenListaKontext();

    $motorn = nyligenListaItem($container, 'Motorn');
    $masten = nyligenListaItem($container, 'Masten');

    [$mottagare] = nyligenListaItemgrant($container, $motorn);
    [$mottagare, $mastensGrant] = nyligenListaItemgrant($container, $masten, $mottagare);

    nyligenListaBesok($mottagare, $container, $masten);
    nyligenListaBesok($mottagare, $container, $motorn);

    // Båda raderna syns medan hon når båda — utan den halvan hade det korta
    // svaret nedan kunnat vara en trasig lista i stället för ett omfång.
    expect(nyligenListaNamn(nyligenListaSvar($mottagare)))->toBe(['Motorn', 'Masten']);

    $mastensGrant->revoked_at = now();
    $mastensGrant->save();

    $efter = nyligenListaSvar($mottagare);

    expect(nyligenListaNamn($efter))->toBe(['Motorn'])
        ->and($efter->getContent())->not->toContain($masten->ulid)
        ->and($efter->getContent())->not->toContain('Masten');

    // Raden ligger kvar i tabellen — det är omfånget som gömmer den.
    expect(RecentVisit::query()
        ->where('user_id', $mottagare->id)
        ->where('item_id', $masten->id)
        ->exists())->toBeTrue();

    // Och när granten kommer tillbaka är raden där igen, utan ett nytt besök.
    $mastensGrant->revoked_at = null;
    $mastensGrant->save();

    expect(nyligenListaNamn(nyligenListaSvar($mottagare)))->toBe(['Motorn', 'Masten']);
});

/*
 * Klart när: ett item i papperskorgen syns inte, och syns igen när itemet
 * kommer tillbaka.
 *
 * Samma regel som sökningen (tests/Feature/Frontend/SokvyTest.php): en rad som
 * pekar in i papperskorgen är en genväg till något användaren inte längre ser i
 * någon annan listning. `whereHas('item', …)` låter SoftDeletes' globala scope
 * gälla i underfrågan, och en återställd rad syns igen av samma skäl som den
 * försvann.
 */
it('ett item i papperskorgen syns inte, och syns igen när det återställs', function () {
    withoutVite();

    [, $ägare, $container] = nyligenListaKontext();

    $kvar = nyligenListaItem($container, 'Kvar', $ägare);
    $slangd = nyligenListaItem($container, 'Slängd', $ägare);

    nyligenListaBesok($ägare, $container, $slangd);
    nyligenListaBesok($ägare, $container, $kvar);

    expect(nyligenListaNamn(nyligenListaSvar($ägare)))->toBe(['Kvar', 'Slängd']);

    $slangd->delete();

    $efter = nyligenListaSvar($ägare);

    expect(nyligenListaNamn($efter))->toBe(['Kvar'])
        ->and($efter->getContent())->not->toContain($slangd->ulid)
        ->and($efter->getContent())->not->toContain('Slängd');

    $slangd->restore();

    expect(nyligenListaNamn(nyligenListaSvar($ägare)))->toBe(['Kvar', 'Slängd']);
});

/*
 * Klart när: ingenting avslöjar hur många som filtrerats bort.
 *
 * Två användare med EXAKT samma synliga rad — samma item — där den ena
 * dessutom besökt fyra items hon förlorat åtkomsten till. Svaren ska vara
 * ordagrant lika. Skillnaden mellan dem är hela läckan: ett tal, en gråad rad
 * eller en "och 4 till" hade berättat för den första att det finns items i
 * containern hon inte når, vilket är precis vad omfångsmodellen finns till för
 * att inte göra (issue 73 § Beslut 6).
 */
it('ingenting avslöjar hur många som filtrerats bort', function () {
    withoutVite();

    // Klockan står still: de två användarna besöker samma item, och svaren ska
    // kunna jämföras ordagrant — en tidsstämpel som skilde sig åt hade varit
    // en skillnad i svaret utan att något läckt.
    Carbon::setTestNow('2026-09-28 10:00:00');

    [, , $container] = nyligenListaKontext();

    $motorn = nyligenListaItem($container, 'Motorn');

    [$medDolda] = nyligenListaItemgrant($container, $motorn);
    [$utanDolda] = nyligenListaItemgrant($container, $motorn);

    nyligenListaBesok($medDolda, $container, $motorn);
    nyligenListaBesok($utanDolda, $container, $motorn);

    // Fyra item som bara den ena når, och som hon besöker medan hon når dem.
    $dolda = [];

    foreach (['Dold ett', 'Dold två', 'Dold tre', 'Dold fyra'] as $namn) {
        $item = nyligenListaItem($container, $namn);

        [, $grant] = nyligenListaItemgrant($container, $item, $medDolda);
        $dolda[] = [$item, $grant];

        nyligenListaBesok($medDolda, $container, $item);
    }

    foreach ($dolda as [, $grant]) {
        $grant->revoked_at = now();
        $grant->save();
    }

    $medDoltSvar = nyligenListaSvar($medDolda);
    $utanDoltSvar = nyligenListaSvar($utanDolda);

    // Ordagrant samma lista: samma rad, samma form, samma längd.
    expect(nyligenListaRader($medDoltSvar))
        ->toBe(nyligenListaRader($utanDoltSvar))
        ->toHaveCount(1);

    // Och ingenting i svarskroppen röjer de fyra: varken namnet, ULID:n eller
    // ett ord om dolda rader. Det partiella svaret ÄR det klienten får.
    foreach ($dolda as [$item]) {
        expect($medDoltSvar->getContent())->not->toContain($item->name)
            ->and($medDoltSvar->getContent())->not->toContain($item->ulid);
    }

    // Besöken finns kvar — det är filtreringen som är tyst, inte datan som
    // städats bort.
    expect(RecentVisit::query()->where('user_id', $medDolda->id)->count())->toBe(5);

    Carbon::setTestNow();
});

/*
 * Klart när: proppen frågas inte på sidor där menyn inte öppnats.
 *
 * Det är hela skillnaden mellan en DELAD prop och en OPTIONAL
 * ([[ADR-0049 Nyligen besökta]] § Beslut, samma konstruktion som
 * `notifications` i issue 127): favoriterna följer med varje sidladdning, och
 * besökslistan gör det inte. Den hämtas först av en partiell omladdning av
 * just den nyckeln — den som sidomenyn och desktopens sidopanel gör när de
 * ritas.
 *
 * Provet prövar BÅDA halvorna: frånvaron på en vanlig sidladdning, och att
 * nyckeln kommer när den efterfrågas. Utan den andra halvan hade en propp som
 * aldrig delas alls passerat.
 *
 * Gästen prövas FÖRST: `actingAs()` sätter guardens användare för resten av
 * testet, och `nyligenListaBesok()` nedanför är ett inloggat anrop.
 */
it('frågar inte efter listan på en vanlig sidladdning men svarar på en partiell omladdning', function () {
    withoutVite();

    [, $ägare, $container] = nyligenListaKontext();

    $motorn = nyligenListaItem($container, 'Motorn', $ägare);

    get('/')->assertOk()->assertInertia(fn ($page) => $page
        ->where('auth.user', null)
        ->missing('recentVisits')
    );

    // Favoriterna ÄR en delad prop och följer med — skillnaden är hela provet.
    actingAs($ägare)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
        ->has('favorites')
        ->missing('recentVisits')
    );

    nyligenListaBesok($ägare, $container, $motorn);

    expect(nyligenListaNamn(nyligenListaSvar($ägare)))->toBe(['Motorn']);
});

/*
 * Klart när: antalet frågor är konstant oavsett antal rader, mätt efter
 * `forgetScopedInstances()`.
 *
 * Tio rader får inte kosta mer än en: omfånget löses upp i ETT anrop över
 * containern (issue 70 § Beslut 2), besöksraderna hämtas i EN fråga och itemet
 * med sin container i TVÅ. En fråga per rad är den N+1 issue 9a § Att se upp
 * med varnar för, och den hade gömt sig i den här listan eftersom den är kort.
 */
it('antalet frågor är konstant oavsett antal rader', function () {
    withoutVite();

    [, $ägare, $container] = nyligenListaKontext();

    $första = nyligenListaItem($container, 'Första', $ägare);
    nyligenListaBesok($ägare, $container, $första);

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80, 477).
    Carbon::setTestNow(now());

    actingAs($ägare);

    $värm = fn () => nyligenListaSvar($ägare);

    $medEn = nyligenListaFragor($värm, function () use ($ägare) {
        expect(nyligenListaNamn(nyligenListaSvar($ägare)))->toBe(['Första']);
    });

    foreach (range(2, ListRecentVisits::LIMIT) as $nummer) {
        $item = nyligenListaItem($container, "Item {$nummer}", $ägare);

        nyligenListaBesok($ägare, $container, $item);
    }

    $medTio = nyligenListaFragor($värm, function () use ($ägare) {
        expect(nyligenListaRader(nyligenListaSvar($ägare)))->toHaveCount(ListRecentVisits::LIMIT);
    });

    expect($medTio)->toBe($medEn);

    Carbon::setTestNow();
});

/*
 * Sektionen är SKALETS och inte en sidas, och den ritas i båda ytorna ur samma
 * komponent — se resources/js/components/RecentVisitList.vue.
 *
 * Provet är ett källkodsprov, som favoritlistans och mobilskalets: Inertia
 * renderar mallen i klienten, så den renderade sektionen når aldrig
 * svarskroppen i en testsvit. Villkoret är listans LÄNGD och ingenting annat —
 * en rubrik över en tom lista är en yta som lovar något den inte har, och en
 * lista som ännu inte hämtats (`undefined`) ska varken ritas eller kallas tom.
 */
it('ritar sektionen i båda skalets ytor och bara när listan har rader', function () {
    $layout = File::get(resource_path('js/layouts/AppLayout.vue'));
    $meny = File::get(resource_path('js/components/MobileMenu.vue'));
    $komponenten = File::get(resource_path('js/components/RecentVisitList.vue'));

    // Samma komponent på båda ställena, och `load` kommer ur den yta som vet
    // att den ritas: layouten läser brytpunkten, menyn sin egen öppning.
    expect($layout)->toContain("import RecentVisitList from '../components/RecentVisitList.vue'")
        ->toContain('<RecentVisitList :load="isDesktopPanel" />')
        ->toContain("window.matchMedia('(min-width: 768px)')");

    expect($meny)->toContain("import RecentVisitList from './RecentVisitList.vue'")
        ->toContain('<RecentVisitList :load="props.open" />');

    // Den står direkt ovanför favoriterna i båda ytorna
    // ([[ADR-0049 Nyligen besökta]] § Beslut).
    expect(strpos($layout, '<RecentVisitList'))->toBeLessThan(strpos($layout, '<ShellSections part="favorites" />'))
        ->and(strpos($meny, '<RecentVisitList'))->toBeLessThan(strpos($meny, '<ShellSections part="favorites" />'));

    // Frågan ställs en gång, när ytan blir ritad — och aldrig annars.
    expect($komponenten)->toContain('const rows = computed(() => page.props.recentVisits ?? [])')
        ->toContain("router.reload({ only: ['recentVisits'] })")
        ->toContain('v-if="rows.length"')
        ->toContain("t('nav.recent_visits')")
        ->toContain('<UiListRow');

    // Rubriken kommer ur lang/ som all annan text i layouten.
    expect(trans('ui.nav.recent_visits', [], 'en'))->toBe('Recently visited');
});
