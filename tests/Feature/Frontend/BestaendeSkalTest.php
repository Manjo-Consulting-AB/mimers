<?php

use Illuminate\Support\Facades\File;

/*
 * Det bestående skalet — M31 Flytande navigering · issue 273 (#832), se
 * resources/js/layouts/AppLayout.vue och de tjugo sidorna under
 * resources/js/pages/.
 *
 * **Sidan deklarerar layouten, mallen bär den inte** (Beslut 1): var och en av
 * de tjugo sidorna får `defineOptions({ layout: AppLayout })` i
 * `<script setup>`, och `<AppLayout>`-taggarna försvinner ur mallen. Inertia
 * behåller då skalets instans mellan två sidor i stället för att riva och
 * bygga upp den vid varje visit.
 *
 * **Källkodsprov och inte sidprov.** Skalet ritas i klienten — Inertia
 * renderar mallen i webbläsaren, och en svarskropp i en testsvit bär bara
 * rotvyn. Det som går att pröva på serversidan är formen på källkoden: vilken
 * rad, vilken prop, vilken vakt. Samma form som GlobalHistorikvyTest och
 * MobilskalTest.
 *
 * **Plusknappens mål är undantaget** (Beslut 2): de två sidor som skickar
 * `create` bär målet som en layoutprop i en pilfunktion med exakt en
 * parameter. Formen är inte kosmetisk — Inertia 3.7 skiljer en propfunktion
 * från en renderfunktion på antalet parametrar och på att pilfunktioner saknar
 * `prototype` (`isRenderFunction` i @inertiajs/vue3), så en `function (props)`
 * eller en funktion med två parametrar tolkas fel.
 *
 * **Menyerna stängs vid navigering** (Beslut 3): när skalet står kvar står
 * också dess tillstånd kvar, och `watch(() => page.url)` nollställer de två
 * menyerna. Utan den står mobilmenyn öppen över nästa sida efter ett klick i
 * den.
 *
 * Kommentarerna rensas bort före varje källkodsprov, som i grannfilerna:
 * filerna är fulla av svensk prosa, och en regel som letar efter en tagg ska
 * inte kunna nöjas av en mening i ett docblock.
 *
 * Hjälparna har prefixet `bestaendeSkal` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * De tjugo sidorna i rutan, relativa `resources/js/pages/`.
 *
 * @return list<string>
 */
function bestaendeSkalSidor(): array
{
    return [
        'Auth/MagicLink.vue',
        'Auth/MagicLinkCode.vue',
        'Auth/Register.vue',
        'Auth/VerifyEmail.vue',
        'Containers/Create.vue',
        'Containers/Index.vue',
        'Dashboard.vue',
        'Documents/Index.vue',
        'Error.vue',
        'History/Index.vue',
        'Inbox/Index.vue',
        'Info.vue',
        'Invitations/Show.vue',
        'Search.vue',
        'Settings/ConfirmUserDeletion.vue',
        'Settings/UserDeletionLinkInvalid.vue',
        'Tasks/Create.vue',
        'Tasks/Index.vue',
        'Trash/Containers.vue',
        'Welcome.vue',
    ];
}

/**
 * Källkoden med kommentarer borta. Samma tre slag som GlobalHistorikvyTest
 * rensar: blockkommentarer, HTML-kommentarer och radkommentarer.
 */
function bestaendeSkalKod(string $sokvag): string
{
    $kod = File::get(resource_path($sokvag));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * De åtta inställningssidorna i rutan, relativa `resources/js/pages/`.
 *
 * Kedjan `[AppLayout, SettingsLayout]` gäller dem och ingen av de tjugo andra:
 * en sida utan sektionsmeny deklarerar bara `AppLayout` (issue 274).
 *
 * @return list<string>
 */
function bestaendeSkalInstallningssidor(): array
{
    return [
        'Settings/Accounts.vue',
        'Settings/Notifications.vue',
        'Settings/Plan.vue',
        'Settings/Profile.vue',
        'Settings/Security.vue',
        'Settings/Storage.vue',
        'Settings/Webhooks.vue',
        'Transfers/Index.vue',
    ];
}

/*
 * Klart när: `de tjugo sidorna deklarerar AppLayout` — var och en av sidorna i
 * rutan innehåller `defineOptions(`, `layout:` och `AppLayout` (Beslut 1).
 *
 * Listan räknas och inte bara läsas: en sida som tappas ur rutan ska falla
 * här, och antalet är issuens eget — tjugo.
 */
it('de tjugo sidorna deklarerar AppLayout', function () {
    $sidor = bestaendeSkalSidor();

    expect($sidor)->toHaveCount(20);

    foreach ($sidor as $sida) {
        expect(bestaendeSkalKod("js/pages/{$sida}"))
            ->toContain('defineOptions(')
            ->toContain('layout:')
            ->toContain('AppLayout');
    }
});

/*
 * Klart när: `ingen av de tjugo sidorna bär AppLayout i mallen` (Beslut 1).
 *
 * Regeln läser den KOMMENTARRENSADE koden: en sida får nämna `AppLayout` i
 * importen och i `defineOptions`, men taggen `<AppLayout>` ska vara borta ur
 * mallen. En mall med flera rotnoder är giltig i Vue 3, och `<Head>` står
 * kvar som första nod.
 */
it('ingen av de tjugo sidorna bär AppLayout i mallen', function () {
    foreach (bestaendeSkalSidor() as $sida) {
        $kod = bestaendeSkalKod("js/pages/{$sida}");

        expect($kod)->not->toContain('<AppLayout');
        expect($kod)->not->toContain('</AppLayout>');
    }
});

/*
 * Klart när: `plusknappens mål följer med som layoutprop` — `Dashboard.vue` och
 * `Containers/Index.vue` bär målet i en pilfunktion med exakt en parameter
 * (Beslut 2).
 *
 * De arton andra skickar ingen `create` i dag och gör det inte heller efteråt:
 * de deklarerar layouten utan proppar. Skillnaden är hela poängen — en sida
 * vars knapp ska ha ett mål bär det, och en sida utan mål får ingen knapp
 * (PlusknappTest prövar serverns sida av samma kontrakt).
 */
it('plusknappens mål följer med som layoutprop', function () {
    $medMal = ['Dashboard.vue', 'Containers/Index.vue'];
    $rad = 'defineOptions({ layout: (props) => [AppLayout, { create: props.create }] });';

    foreach ($medMal as $sida) {
        expect(bestaendeSkalKod("js/pages/{$sida}"))->toContain($rad);
    }

    foreach (array_diff(bestaendeSkalSidor(), $medMal) as $sida) {
        expect(bestaendeSkalKod("js/pages/{$sida}"))->toContain('defineOptions({ layout: AppLayout });');
    }
});

/*
 * Klart när: `menyerna stängs vid navigering` — `AppLayout.vue` vaktar
 * `page.url` och nollställer `menuOpen` och `createMenuOpen` (Beslut 3).
 *
 * Provet fäster både vakten och dess innehåll: en `watch` som inte rör de två
 * menyerna är samma bugg som ingen vakt alls, och mobilmenyn står då öppen
 * över nästa sida. `watch` ska komma ur `vue` tillsammans med de tre andra
 * reaktiva hjälparna.
 */
it('menyerna stängs vid navigering', function () {
    $layout = bestaendeSkalKod('js/layouts/AppLayout.vue');

    preg_match("/import \{([^}]*)\} from 'vue'/", $layout, $vue);

    expect($vue)->not->toBeEmpty('importen från vue saknas');
    expect($vue[1])->toContain('watch');

    expect($layout)->toContain('watch(() => page.url');

    // Kroppen: båda menyerna nollställs, inte bara den ena.
    preg_match('#watch\(\(\) => page\.url, \(\) => \{(.*?)\}\)#s', $layout, $vakt);

    expect($vakt)->not->toBeEmpty('vakten kring page.url saknas');

    expect($vakt[1])->toContain('menuOpen.value = false')
        ->toContain('createMenuOpen.value = false');
});

/*
 * Inställningarnas sidor — M31 Flytande navigering · issue 274 (#833), se
 * resources/js/layouts/SettingsLayout.vue.
 *
 * **Kedjan deklareras av sidan** (Beslut 2): var och en av de åtta sidorna får
 * `defineOptions({ layout: [AppLayout, SettingsLayout] })`, och
 * `<SettingsLayout>`-taggarna försvinner ur mallen. Sidan är då rotvyn och
 * inställningarnas sidonavigering står kvar när användaren går mellan två
 * inställningssidor — och mellan en inställningssida och en sida från issue
 * 273.
 *
 * **SettingsLayout ritar bara sin egen del** (Beslut 1): `<AppLayout>` och
 * importen av den är borta. Skalet kommer in utifrån, ur kedjan.
 */

/*
 * Klart när: `inställningarnas sidor deklarerar kedjan` — var och en av de
 * åtta sidorna innehåller `layout: [AppLayout, SettingsLayout]` (Beslut 2).
 *
 * Listan räknas och inte bara läsas: en sida som tappas ur rutan ska falla
 * här, och antalet är issuens eget — åtta.
 */
it('inställningarnas sidor deklarerar kedjan', function () {
    $sidor = bestaendeSkalInstallningssidor();

    expect($sidor)->toHaveCount(8);

    foreach ($sidor as $sida) {
        expect(bestaendeSkalKod("js/pages/{$sida}"))
            ->toContain('defineOptions(')
            ->toContain('layout: [AppLayout, SettingsLayout]');
    }
});

/*
 * Klart när: `ingen inställningssida bär SettingsLayout i mallen` (Beslut 2).
 *
 * Sidan får nämna `SettingsLayout` i importen och i `defineOptions`, men
 * taggen ska vara borta ur mallen — samma regel som för `<AppLayout>` i issue
 * 273.
 */
it('ingen inställningssida bär SettingsLayout i mallen', function () {
    foreach (bestaendeSkalInstallningssidor() as $sida) {
        $kod = bestaendeSkalKod("js/pages/{$sida}");

        expect($kod)->not->toContain('<SettingsLayout');
        expect($kod)->not->toContain('</SettingsLayout>');
    }
});

/*
 * Klart när: `SettingsLayout ritar inte AppLayout` — varken taggen eller
 * importen finns kvar (Beslut 1).
 */
it('SettingsLayout ritar inte AppLayout', function () {
    $layout = bestaendeSkalKod('js/layouts/SettingsLayout.vue');

    expect($layout)->not->toContain('<AppLayout');
    expect($layout)->not->toContain('import AppLayout');
});

/*
 * Klart när: `sektionsmenyn stängs vid navigering` — `SettingsLayout.vue`
 * vaktar `page.url` och nollställer `sectionsOpen` (Beslut 3).
 *
 * Provet fäster både vakten och dess innehåll, som för AppLayouts två menyer:
 * en `watch` som inte rör menyn är samma bugg som ingen vakt alls, och den
 * hopfällda sektionsmenyn står då öppen på mobilen över nästa sida.
 */
it('sektionsmenyn stängs vid navigering', function () {
    $layout = bestaendeSkalKod('js/layouts/SettingsLayout.vue');

    preg_match("/import \{([^}]*)\} from 'vue'/", $layout, $vue);

    expect($vue)->not->toBeEmpty('importen från vue saknas');
    expect($vue[1])->toContain('watch');

    expect($layout)->toContain('watch(() => page.url');

    preg_match('#watch\(\(\) => page\.url, \(\) => \{(.*?)\}\)#s', $layout, $vakt);

    expect($vakt)->not->toBeEmpty('vakten kring page.url saknas');
    expect($vakt[1])->toContain('sectionsOpen.value = false');
});
