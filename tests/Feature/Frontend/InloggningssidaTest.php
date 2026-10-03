<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 203 · Inloggningssidan i den nya formen, M24 § 203, se
 * resources/js/pages/Auth/Login.vue, lang/en/ui.php och
 * docs/Design/frontpage.png samt [[ADR-0050 Desktopdesignen]].
 *
 * **Det här är ett källkodsprov, inte ett sidprov.** Sidan ritar bara former:
 * att `/login` renderar Auth/Login prövas redan i
 * tests/Feature/Frontend/InloggningsvyerTest.php och SkalTest.php, och att
 * formuläret postar till rätt rutt och får tillbaka serverns svar prövas där.
 * Här prövas det som bara syns i källkoden — att layouten är två kolumner utan
 * AppLayout, att FlashMessage följer med, att fälten och deras beteende står
 * kvar, att länkarna pekar rätt, och att strängarna finns i `lang/en/ui.php`.
 *
 * **Kommentarerna rensas bort före varje kontroll**, precis som i
 * GenomgangTest, SprakTest och DesignsystemTest: filen är full av svensk prosa
 * som förklarar varför, och ett prov som letar efter `AppLayout` ska inte
 * fällas av en mening i ett docblock (eller nöjas av en).
 *
 * Hjälparna har prefixet `inloggning` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta. Samma tre slag som resten av sviten rensar:
 * blockkommentarer, HTML-kommentarer och radkommentarer.
 */
function inloggningUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Login.vue som markup, utan kommentarer.
 */
function inloggningskalla(): string
{
    return inloggningUtanKommentarer(File::get(resource_path('js/pages/Auth/Login.vue')));
}

it('ritar inloggningen utan AppLayout och med FlashMessage', function () {
    // Beslut 1. Mockupen har varken sidopanel eller sidhuvud; sidan är en egen
    // helsida, och layouten hade ritat skalet runt den.
    $kod = inloggningskalla();

    expect(str_contains($kod, 'AppLayout'))->toBeFalse(
        'Login.vue ritar AppLayout — mockupen har varken sidopanel eller sidhuvud',
    );

    // Flashmeddelandet följer ändå med, samma komponent som AppLayout lägger i
    // innehållsytan: ett "status" ur sessionen ska inte tappas på vägen in.
    expect($kod)->toContain("import FlashMessage from '../../components/FlashMessage.vue'");
    expect($kod)->toContain('<FlashMessage');
});

it('ritar vänsterkolumnen med fyra punkter bara från lg', function () {
    // Beslut 2. Kolumnen är dold under lg: — på en telefon är hela ytan
    // kortet — och bär de fyra punkterna i bildens ordning.
    $kod = inloggningskalla();

    expect($kod)->toContain('hidden lg:flex');
    expect($kod)->toContain('bg-shell');

    foreach ([
        'auth.landing.features.documents',
        'auth.landing.features.maintenance',
        'auth.landing.features.costs',
        'auth.landing.features.relations',
    ] as $nyckel) {
        expect(str_contains($kod, $nyckel))->toBeTrue("{$nyckel} saknas i Login.vue");
    }
});

it('behåller formulärets fält och beteende', function () {
    // Beslut 3: fälten, deras autocomplete, kodfältet och fokusmekanismen står
    // oförändrade — bara klasserna byter skepnad.
    $kod = inloggningskalla();

    foreach ([
        'autocomplete="email"',
        'autocomplete="current-password"',
        'autocomplete="one-time-code"',
        'codeRequested',
        'focusFirstError',
    ] as $nål) {
        expect(str_contains($kod, $nål))->toBeTrue("{$nål} saknas i Login.vue");
    }
});

it('länkar glömt lösenord till magic link och registreringen', function () {
    // Beslut 3 och 4. *Glömt lösenordet?* är vägen in för den som tappat
    // lösenordet — det finns ingen återställning för en utloggad — och den
    // ersätter den separata magic link-länken under formuläret.
    $kod = inloggningskalla();

    expect($kod)->toContain('href="/login/magic-link"');
    expect($kod)->toContain('href="/register"');

    expect(str_contains($kod, 'auth.magic_link.link'))->toBeFalse(
        'Den separata magic link-länken står kvar — *Glömt lösenordet?* ersätter den (Beslut 4)',
    );
});

it('länkar till de fyra informationssidorna', function () {
    // Beslut 3: länkarna under kortet. Sidorna kommer med issue 202.
    $kod = inloggningskalla();

    foreach (['/about', '/privacy', '/terms', '/help'] as $mål) {
        expect(str_contains($kod, "href=\"{$mål}\""))->toBeTrue("Login.vue länkar inte till {$mål}");
    }
});

it('har engelska strängarna för den nya sidan i ui.php', function () {
    // Beslut 5. `toBe` mot det exakta värdet bevisar både att nyckeln finns
    // (en saknad nyckel ger nyckeln själv tillbaka) och att ordet är rätt.
    expect(trans('ui.auth.login.heading', [], 'en'))->toBe('Welcome back');
    expect(trans('ui.auth.login.forgot', [], 'en'))->toBe('Forgot your password?');
    expect(trans('ui.auth.landing.heading', [], 'en'))->toBe('Everything in one place for what you');
    expect(trans('ui.auth.landing.links.privacy', [], 'en'))->toBe('Privacy policy');
});

/*
 * **Brödtexten namnger inga fordon, och det är ett beslut och inte ett fel.**
 * Issuens ordlista föreskrev mockupens mening ordagrant — *"whether it is a
 * boat, a caravan, a cottage, a car or a project"* — men [[ADR-0033
 * Produktens omfång]] § Beslut förbjuder fordon i varje text en ny användare
 * möter, och `/login` är den första skärmen en ny användare ser. SprakTest
 * *"beskriver produkten generiskt, utan fordon i copyn"* vaktar regeln och
 * issuen kräver att den är grön utan ändring — ett acceptanskriterium går
 * före exempeltexten (AGENTS.md). Provet står här så att avvikelsen är
 * avsiktlig och stannar avsiktlig.
 */
it('namnger inga fordon i landningstexten enligt ADR-0033', function () {
    $brödtext = trans('ui.auth.landing.body', [], 'en');

    expect(trim($brödtext))->not->toBe('');

    expect(preg_match('/\b(boat|car|caravan|vessel|vehicle)\b/i', $brödtext))->toBe(
        0,
        "auth.landing.body namnger ett fordon: {$brödtext}",
    );
});
