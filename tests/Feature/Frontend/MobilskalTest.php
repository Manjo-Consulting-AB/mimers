<?php

use App\Models\User;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 151 · Mobilskalet, se [[M23 Mobilen och kartan]] § 151 och
 * [[ADR-0048 Mobilen och plusknappen]] § 1.
 *
 * **Det här är ett källkodsprov, inte ett sidprov.** Skalet ritas i klienten:
 * Inertia renderar mallen i webbläsaren, och en svarskropp i en testsvit bär
 * bara rotvyn. Det som går att pröva på serversidan är därför formen på
 * källkoden — att toppraden och flikraden bara ritas under `md:`, att den
 * gamla menyknappen är borta, att sidomenyn är webbläsarens `<dialog>` med de
 * tre stängningsvägarna, att fokus lämnas tillbaka till *Meny*, att varje mål
 * i flikraden är 44 px, och att sektionerna kommer ur samma lista på båda
 * ställena.
 *
 * **Det som INTE prövas här** är det som kräver en webbläsare: att skalet ser
 * ut som `docs/Design/mobil.png`, att skrollen i flikraden känns rätt, och att
 * fokusfällan faktiskt håller. Handprovet står i PR-kroppen.
 *
 * Kommentarer rensas bort före varje kontroll, precis som i GenomgangTest och
 * de andra källkodsproven: filerna är fulla av svensk prosa som förklarar
 * varför, och en regel som letar efter `bg-shell` ska inte kunna nöjas av en
 * mening i ett docblock.
 *
 * Hjälparna har prefixet `mobilskal` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * En fil under resources/js, med kommentarer borta. Samma tre slag som
 * GenomgangTest rensar.
 */
function mobilskalKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Varje klickbart mål i en fil: `<Link>` och `<button>` som rå markup.
 *
 * Attributvärdena matchas med citattecken runt om — samma form som
 * genomgangTaggar och flikradTaggar: ett `'` inuti ett `"`-attribut
 * (`@click="emit('open-menu', $event.currentTarget)"`) hade annars avslutat
 * taggen i förtid.
 *
 * @return array<int, string>
 */
function mobilskalMal(string $kod): array
{
    preg_match_all(
        '#<(Link|button)\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)>#s',
        $kod,
        $träffar,
    );

    return $träffar[0];
}

it('ritar toppraden och flikraden under md: och har kvar desktopraden över', function () {
    $layout = mobilskalKod('layouts/AppLayout.vue');

    // Mobilskalets två ytor, och de ritas bara under `md:`.
    expect($layout)->toContain('import MobileTabBar from')
        ->toContain('import MobileMenu from')
        ->toContain('<MobileTabBar')
        ->toContain('<MobileMenu');

    expect(mobilskalKod('components/MobileTabBar.vue'))->toContain('md:hidden');

    // Toppraden är mörk — rollen ur ADR-0042, ingen palettfärg — och bär
    // sidans titel, som en sida med ett eget namn fyller i.
    expect($layout)->toContain('bg-shell text-white md:hidden')
        ->toContain('<slot name="topbar">');

    // Och desktopraden står kvar, dold under brytpunkten i stället för
    // hopfälld: över `md:` möter användaren exakt den navigering hon mötte
    // före genomgången (issue 68a § Beslut 2).
    expect($layout)->toContain('hidden border-b border-slate-200 bg-white md:block')
        ->toContain('id="huvudmenyn"');

    // Den gamla menyknappen finns inte: ingen `<button>` alls i layouten, och
    // inget tillstånd som fäller upp en lista. Skalets menyknapp är flikradens
    // *Meny* (MobileTabBar), och den öppnar en dialog.
    expect($layout)->not->toContain('<button');
    expect($layout)->not->toContain('menuOpen = !menuOpen');
    expect($layout)->not->toContain('aria-controls="huvudmenyn"');
});

it('öppnar sidomenyn med Meny och stänger den med knappen, Esc och ett tryck utanför', function () {
    $flikrad = mobilskalKod('components/MobileTabBar.vue');
    $meny = mobilskalKod('components/MobileMenu.vue');

    // *Meny* är flikradens femte mål och öppnar menyn — knappen äger inget
    // tillstånd, den skickar med sig sitt eget element så att fokus kan
    // lämnas tillbaka.
    expect($flikrad)->toContain("t('nav.menu')")
        ->toContain("@click=\"emit('open-menu', \$event.currentTarget)\"");

    // Menyn är webbläsarens `<dialog>` (issue 68b § Beslut 2): `showModal()`
    // ger fokusfällan och Esc, och `close`-händelsen är samma väg ut för
    // knappen och för Esc.
    expect($meny)->toContain('<dialog')
        ->toContain('showModal()')
        ->toContain('@close="onClosed"');

    expect($meny)->not->toContain('aria-modal');

    // Stängningsknappen, och ytan utanför panelen. Den sista är en `<button>`
    // och inte en `<div>` med `@click`: en yta ingen når med tabb är ingen
    // yta (issue 68a § Beslut 1).
    $stangningar = preg_match_all('/@click="close"/', $meny);

    expect($stangningar)->toBe(2, 'menyn har inte både en stängknapp och ett tryck utanför')
        ->and($meny)->toContain("t('nav.menu_close')");

    // Dialogen täcker skärmen, så trycket utanför landar på ett element och
    // inte i en bakgrund utan mål.
    expect($meny)->toContain('h-full w-full');
});

it('visar samma sektioner i samma ordning ur samma data i menyn som i sidopanelen', function () {
    $layout = mobilskalKod('layouts/AppLayout.vue');
    $meny = mobilskalKod('components/MobileMenu.vue');
    $sektioner = mobilskalKod('components/ShellSections.vue');

    // Båda ytorna ritar SAMMA komponent, och ingen av dem bär en egen rad:
    // en rad som läggs till i listan syns på båda ställena, och en som tas
    // bort försvinner från båda.
    expect($layout)->toContain('<ShellSections')
        ->and($meny)->toContain('<ShellSections />');

    expect($layout)->not->toContain("t('nav.settings')")
        ->and($meny)->not->toContain("t('nav.settings')");

    // Ordningen är listans, och den läses här i stället för att skrivas av:
    // provet följer med när en rad flyttar, och faller när en rad försvinner.
    preg_match_all("/key: '([a-z0-9_]+)', href: '([^']+)'/", $sektioner, $träffar, PREG_SET_ORDER);

    expect(array_column($träffar, 1))->toBe([
        'dashboard',
        'tasks',
        // Inboxen kom med M27 · issue 245: raden står bredvid uppgifterna och
        // bär antalet obearbetat. Ändringen ligger utanför issue 245:s ruta
        // och deklareras i PR:en — provet listar skalets rader och fälls av en
        // ny rad, med flit (en rad som glöms i katalogen ska synas).
        'inbox',
        'containers',
        'transfers',
        'search',
        'settings',
    ]);

    // Etiketten byggs ur nyckeln och slås upp i katalogen — ingen färdig
    // mening i filen ([[ADR-0013 Språk och i18n]]).
    expect($sektioner)->toContain('t(`nav.${section.key}`)');

    // Favoriterna är samma sektion i båda ytorna: samma anrop, samma data.
    expect($layout)->toContain('<ShellSections part="favorites" />')
        ->and($meny)->toContain('<ShellSections part="favorites" />');

    // *Nyligen besökta* ritas inte i någon av ytorna sedan issue 677: den kom
    // med issue 160 · [[ADR-0049 Nyligen besökta]] som en egen lista ovanför
    // favoriterna, och är borta ur skalet. Se
    // tests/Feature/Frontend/NyligenBesoktaTest.php.
    expect($sektioner)->not->toContain('recent_visits')
        ->and($layout)->not->toContain('<RecentVisitList')
        ->and($meny)->not->toContain('<RecentVisitList');
});

/*
 * Klart när (issue 653 och 677): sidomenyn staplar samma fem anrop i samma
 * ordning som sidopanelen — översikten, containrarna, raderna, favoriterna och
 * användaren sist ([[ADR-0050 Desktopdesignen]] § 1). *Nyligen besökta* är
 * borta sedan issue 677.
 *
 * Ordningen läses ur BÅDA yternas `<aside>` och jämförs: en sektion som
 * flyttar i den ena men inte i den andra ska fälla provet, och den enda vägen
 * till det är att ställa de två följderna mot varandra.
 */
it('ritar sidomenyn i samma ordning som sidopanelen', function () {
    $layout = mobilskalKod('layouts/AppLayout.vue');
    $meny = mobilskalKod('components/MobileMenu.vue');

    $start = (int) strpos($layout, '<aside');
    $panelen = substr($layout, $start, (int) strpos($layout, '</aside>', $start) - $start);

    $start = (int) strpos($meny, '<aside');
    $menyn = substr($meny, $start, (int) strpos($meny, '</aside>', $start) - $start);

    expect($panelen)->not->toBeEmpty('layouten har ingen sidopanel')
        ->and($menyn)->not->toBeEmpty('menyn har ingen sidopanel');

    $sektion = '#<ShellSections part="top" />|<ShellContainerList|<ShellSections />|<ShellSections part="favorites" />|<ShellSections part="account" />#';

    preg_match_all($sektion, $panelen, $iPanelen);
    preg_match_all($sektion, $menyn, $iMenyn);

    // Alla fem anrop står i båda ytorna, och i mockupens ordning.
    expect($iPanelen[0])->toBe([
        '<ShellSections part="top" />',
        '<ShellContainerList',
        '<ShellSections />',
        '<ShellSections part="favorites" />',
        '<ShellSections part="account" />',
    ])->and($iMenyn[0])->toBe($iPanelen[0]);
});

it('lämnar fokus tillbaka till Meny när menyn stängs', function () {
    $layout = mobilskalKod('layouts/AppLayout.vue');
    $flikrad = mobilskalKod('components/MobileTabBar.vue');
    $meny = mobilskalKod('components/MobileMenu.vue');

    // Fällan är webbläsarens: `showModal()` håller fokus i dialogen medan den
    // är öppen. En egen tabbhanterare är den fälla som fastnar.
    expect($meny)->toContain('showModal()');
    expect($meny)->not->toMatch('/@keydown\.(tab|esc)/');

    // Återlämningen är uttryckligen gjord, som i bildvisaren: `<dialog>` lämnar
    // tillbaka till det element som hade fokus, och det elementet känner bara
    // den som öppnade menyn.
    expect($meny)->toContain('props.trigger?.focus()');

    // Kedjan hela vägen: knappen skickar sitt element, layouten håller det,
    // menyn får det.
    expect($flikrad)->toContain('$event.currentTarget')
        ->and($layout)->toContain(':trigger="menuTrigger"');
});

it('ger varje mål i flikraden en träffyta på minst 44 px och en plats i tabordningen', function () {
    $flikrad = mobilskalKod('components/MobileTabBar.vue');

    $mal = mobilskalMal($flikrad);

    // Tre mål i flikraden själv: Översikt, Sök och Meny. Det fjärde —
    // Notiser — är klockan, och det femte är plusknappen: båda är komponenter
    // och inga råa taggar, och de prövas strax nedanför.
    expect($mal)->toHaveCount(3);

    foreach ($mal as $tag) {
        expect($tag)->toMatch('/\bmin-h-11\b/', "ett mål i flikraden är under 44 px högt:\n{$tag}")
            ->and($tag)->toMatch('/\bw-full\b/', "ett mål i flikraden fyller inte sin plats:\n{$tag}");
    }

    // Notiserna är klockan själv (issue 127) i sin flikform, och den bär
    // träffytan i sin egen klass.
    expect($flikrad)->toContain('<NotificationBell variant="tab" />')
        ->and(mobilskalKod('components/NotificationBell.vue'))
        ->toContain('min-h-11 min-w-11')
        ->toContain('w-full flex-col');

    // Målen är länkar och knappar — alltså tabbbara — och ingen positiv
    // tabindex flyttar ordningen (issue 68a § Beslut 4).
    expect($flikrad)->not->toMatch('/tabindex="[1-9]/');
    expect($flikrad)->not->toContain('@keydown');

    // Plusknappens plats fylldes i issue 152, och den ritas av `CreateButton`
    // — alltså en komponent och ingen rå tagg. Det är därför de tre målen
    // ovan fortfarande är tre: platsen bär en knapp, men knappen bor i sin
    // egen fil med sin egen träffyta, och den ritas bara när sidan har ett mål
    // (tests/Feature/Frontend/PlusknappTest.php).
    expect($flikrad)->toContain("import CreateButton from './CreateButton.vue'")
        ->and($flikrad)->toContain('<CreateButton');
});

it('visar containerns namn och en tillbakaknapp i toppraden', function () {
    $layout = mobilskalKod('layouts/ContainerLayout.vue');

    expect($layout)->toContain('<template #topbar>')
        ->toContain('{{ heading }}')
        ->toContain('href="/containers"')
        ->toContain("t('nav.back')");
});

it('svarar på varje mål i flikraden och i menyn', function () {
    withoutVite();

    $anvandare = User::factory()->create();

    // Flikradens mål: översikten och sökningen. Plusknappen har inget mål i
    // den här issuen, notiserna är en dialog och menyn är en dialog.
    foreach (['/dashboard', '/search'] as $url) {
        actingAs($anvandare)->get($url)->assertOk();
    }

    // Menyns rader, ur samma lista som sidhuvudet: en rad som pekar på en
    // rutt ingen svarar på är en död länk ingen upptäcker förrän i handen.
    foreach (['/tasks', '/containers', '/transfers'] as $url) {
        actingAs($anvandare)->get($url)->assertOk();
    }

    // Inställningarna svarar med en omdirigering till profilen (issue 53c) —
    // samma svar som raden i sidhuvudet mötte före den här issuen.
    actingAs($anvandare)->get('/settings')->assertRedirect('/settings/profile');

    // Och tillbakaknappen i containerns topprad.
    actingAs($anvandare)->get('/containers')->assertOk();
});
