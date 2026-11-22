<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/*
 * M71 · issue 438 · Dashboardens hjälte på inställningssidorna, se
 * resources/js/layouts/SettingsLayout.vue, resources/js/components/AppHero.vue
 * och [[ADR-0090 Dashboardens hjälte]] § Tillägg 2026-11-22.
 *
 * Filen bevisar *Klart när*:
 *
 * 1. Alla nio inställningssidor visar hjälten med *Settings* och undertiteln:
 *    den ritas en gång i `SettingsLayout` och är samma `AppHero` som
 *    dashboarden och toppnivåsidorna ritar — alltså samma höjd, bild, brickor
 *    och donut. Formen prövas i `AppHjalteTest`; här prövas att layouten
 *    använder den och att varje sida delegerar till layouten.
 * 2. Hjälten bär sidans enda `<h1>`. Sidorna har ingen egen; den sammanlagda
 *    räkningen — sida plus `AppHero` — står i `GenomgangTest` § "ger varje
 *    sida exakt en h1", som räknar in hjältens rubrik för de nio sidorna.
 * 3. Sidomenyn och innehållet ritas som förut, under hjälten.
 * 4. Bekräftelsesidorna för radering använder inte layouten och ändras inte:
 *    de behåller sin egen `<h1>`.
 *
 * Filen bevisar dessutom granskningsfyndet 2026-11-22: `/transfers` ligger i
 * layouten bara för sidomenyn (issue 248) och ritar sin egen hjälte (issue
 * 437). Utan `hero: false` fick sidan två hjältar och två `<h1>`.
 *
 * Varför källkodsprov: hjälten är en form, inte en ny rutt eller propp, och
 * `AppHjalteTest` prövar den på samma sätt. Kommentarer rensas bort före varje
 * kontroll, så att en mening i ett docblock inte kan nöja ett prov.
 *
 * Hjälparna har prefixet `insthjalte` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * De nio sidorna och nyckeln deras egen rubrik bär, i issue 438:s ordning.
 * `AgentShow` bär agentens namn i stället för en nyckel.
 *
 * @return array<string, string|null>
 */
function insthjalteSidor(): array
{
    return [
        'js/pages/Settings/Profile.vue' => 'settings.profile.heading',
        'js/pages/Settings/Security.vue' => 'settings.security.heading',
        'js/pages/Settings/Plan.vue' => 'plan.heading',
        'js/pages/Settings/Storage.vue' => 'storage.heading',
        'js/pages/Settings/Notifications.vue' => 'notifications.heading',
        'js/pages/Settings/Agents.vue' => 'agents.heading',
        'js/pages/Settings/AgentShow.vue' => null,
        'js/pages/Settings/Webhooks.vue' => 'webhook.heading',
        'js/pages/Settings/Templates.vue' => 'settings.templates.heading',
    ];
}

/**
 * Källkoden med kommentarer borta — samma tre slag som GenomgangTest rensar.
 */
function insthjalteUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

function insthjalteLayout(): string
{
    return insthjalteUtanKommentarer(File::get(resource_path('js/layouts/SettingsLayout.vue')));
}

// --- hjälten ---------------------------------------------------------------

/*
 * Klart när: "Alla nio inställningssidor visar hjälten med *Settings* och
 * undertiteln, lika hög och med samma siffror som dashboardens."
 *
 * Layouten importerar samma `AppHero` som dashboarden ritar (`DashboardHero`),
 * skickar rubriken och undertiteln ur sina nya nycklar, och skickar inga egna
 * tal — siffrorna kommer ur den delade `heroFigures`-proppen, precis som på
 * toppnivåsidorna.
 */
it('ritar hjälten en gång i SettingsLayout med Settings och undertiteln', function () {
    $layout = insthjalteLayout();

    expect($layout)->toContain("import AppHero from '../components/AppHero.vue';")
        ->toContain('<AppHero')
        ->toContain(":heading=\"t('hero.heading.settings')\"")
        ->toContain(":subtitle=\"t('hero.subtitle.settings')\"");

    // Sidans enda <h1> bor i hjälten: layouten ritar den delade komponenten,
    // och rubriken i den är AppHeros enda <h1>.
    expect(preg_match_all('/<AppHero\b/', $layout))->toBe(1);

    $hjalten = insthjalteUtanKommentarer(File::get(resource_path('js/components/AppHero.vue')));

    expect(preg_match_all('/<h1\b/', $hjalten))->toBe(1);

    // Inga egna tal: hjälten hämtar den delade proppen när den saknas.
    expect($layout)->not->toContain(':stats=');
    expect($layout)->not->toContain(':costs=');
    expect($layout)->not->toContain(':greeting=');
});

/*
 * Klart när: rubriken är *Settings* och undertiteln står ordagrant i
 * `lang/en/ui.php`.
 */
it('har Settings och undertiteln ordagrant i ui.php', function () {
    expect(Lang::get('ui.hero.heading.settings', [], 'en'))->toBe('Settings');
    expect(Lang::get('ui.hero.subtitle.settings', [], 'en'))->toBe('Your profile, plan and preferences.');
});

/*
 * Klart när: "Varje inställningssida har exakt en `<h1>`, och den står i
 * hjälten."
 *
 * Sidorna deklarerar layoutkedjan och ritar ingen egen `<h1>`: deras nuvarande
 * rubrik är `<h2>`, med samma text och samma nyckel. `AgentShow` bär agentens
 * namn, som förut.
 */
it('låter de nio sidorna delegera hjälten och sin rubrik till layouten', function () {
    foreach (insthjalteSidor() as $fil => $nyckel) {
        $kod = insthjalteUtanKommentarer(File::get(resource_path($fil)));

        expect($kod)->toContain('layout: [AppLayout, SettingsLayout]');
        expect($kod)->not->toContain('<h1');

        if ($nyckel === null) {
            expect($kod)->toContain('<h2 class="mt-2 text-2xl font-semibold">{{ agent.name }}</h2>');
        } else {
            expect($kod)->toContain("<h2 class=\"text-2xl font-semibold\">{{ t('{$nyckel}') }}</h2>");
        }
    }
});

/*
 * Klart när: "En sida med en egen `<h2>` under rubriken flyttar ner den ett
 * steg (`<h3>`)."
 *
 * Avsnittsrubrikerna flyttar ner ett steg med sidans rubrik. `Security` har
 * dessutom två `<h3>` inuti ett avsnitt; de flyttar också ner ett steg, till
 * `<h4>`, så att nivåerna fortfarande ligger inuti varandra.
 */
it('flyttar ner sidornas egna avsnittsrubriker ett steg', function () {
    $profile = insthjalteUtanKommentarer(File::get(resource_path('js/pages/Settings/Profile.vue')));

    expect($profile)->toContain("<h3 class=\"text-lg font-semibold\">{{ t('settings.profile.avatar.heading') }}</h3>");

    $security = insthjalteUtanKommentarer(File::get(resource_path('js/pages/Settings/Security.vue')));

    expect($security)
        ->toContain("<h3 class=\"text-lg font-semibold\">{{ t('settings.security.totp.heading') }}</h3>")
        ->toContain("<h4 class=\"font-medium\">{{ t('settings.security.totp.recovery_heading') }}</h4>")
        ->toContain("<h4 class=\"font-medium\">{{ t('settings.security.totp.disable_heading') }}</h4>")
        ->toContain("<h3 class=\"text-lg font-semibold\">{{ t('settings.security.logins.heading') }}</h3>");
});

// --- skalet runt omkring ---------------------------------------------------

/*
 * Klart när: "Sidomenyn och innehållet fungerar som förut."
 *
 * Hjälten ligger ovanför en rad som fortfarande är menyn plus innehållsytan:
 * sektionerna ritas ur `settingsSections` med `v-for`, och sloten ligger i den
 * flexibla kolumnen.
 */
it('behåller sidomenyn och innehållet under hjälten', function () {
    $layout = insthjalteLayout();

    expect($layout)->toContain('v-for="section in settingsSections"')
        ->toContain('<slot />')
        ->toContain('md:flex-row')
        ->toContain('min-w-0 flex-1');
});

// --- en sida med egen hjälte -----------------------------------------------

/*
 * Klart när: "exakt en `<h1>`" — också på en sida som ligger i layouten men
 * ritar sin egen hjälte.
 *
 * Granskningsfynd 2026-11-22: `/transfers` ritar `AppHero` själv (issue 437)
 * och ligger i `SettingsLayout` bara för sidomenyn (issue 248). Layouten ritar
 * därför hjälten bara när sidan inte har stängt av den, och en sida som ritar
 * en egen `AppHero` måste skicka `hero: false` i layoutparet — annars fälls
 * den här. En sida som inte ritar någon egen hjälte får inte stänga av
 * layoutens: då står sidan utan `<h1>`. Provet läser alla sidor, inte bara de
 * nio, så att nästa sida som läggs i layouten prövas på samma gång.
 */
it('stänger av layoutens hjälte på den sida som ritar en egen', function () {
    expect(insthjalteLayout())
        ->toContain('hero: { type: Boolean, default: true }')
        ->toContain('v-if="hero"');

    $sidor = 0;

    foreach (File::allFiles(resource_path('js/pages')) as $fil) {
        $kod = insthjalteUtanKommentarer(File::get($fil->getPathname()));

        // `\b` skiljer `SettingsLayout` från `ContainerSettingsLayout`, som
        // containerns egna sidor deklarerar.
        if (preg_match('/\bSettingsLayout\b/', $kod) !== 1) {
            continue;
        }

        $sidor++;

        if (str_contains($kod, '<AppHero')) {
            expect($kod)->toContain('[SettingsLayout, { hero: false }]');
        } else {
            expect($kod)->not->toContain('hero: false');
        }
    }

    expect($sidor)->toBe(10, 'Provet hittade inte de tio sidorna i SettingsLayout');
});

/*
 * Klart när: "Sidor under `/settings` som inte använder `SettingsLayout`
 * ändras inte. Det gäller bekräftelsesidorna för radering."
 */
it('lämnar bekräftelsesidorna för radering utanför layouten', function () {
    foreach (['js/pages/Settings/ConfirmUserDeletion.vue', 'js/pages/Settings/UserDeletionLinkInvalid.vue'] as $fil) {
        $kod = insthjalteUtanKommentarer(File::get(resource_path($fil)));

        expect($kod)->toContain('<h1');
        expect($kod)->not->toContain('<AppHero');
        expect($kod)->not->toContain('SettingsLayout');
    }
});
