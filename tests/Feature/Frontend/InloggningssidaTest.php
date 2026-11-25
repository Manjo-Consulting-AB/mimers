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
 * Login.vue och skalet den ritar i, som markup och utan kommentarer.
 *
 * Sedan issue 1671 bor vänsterkolumnen, bilden, FlashMessage och länkarna
 * under kortet i `components/AuthSplitLayout.vue`, delat med /register; Login.vue
 * bär kortets innehåll. Proven läser de två filerna som en källa — det är
 * sidan som användaren ser, oavsett var raden står.
 */
function inloggningskalla(): string
{
    return inloggningUtanKommentarer(
        File::get(resource_path('js/pages/Auth/Login.vue'))
        .File::get(resource_path('js/components/AuthSplitLayout.vue')),
    );
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
    expect($kod)->toContain("import FlashMessage from './FlashMessage.vue'");
    expect($kod)->toContain("import AuthSplitLayout from '../../components/AuthSplitLayout.vue'");
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

it('ritar en slumpad bild bakom vänsterkolumnen', function () {
    // Issue 490. De tre sökvägarna står utskrivna i en konstant, och en av dem
    // väljs med Math.random() exakt en gång när komponenten sätts upp — inte i
    // en computed som kan räknas om och byta bild medan sidan står öppen.
    $kod = inloggningskalla();

    foreach ([
        '/images/login/boat.webp',
        '/images/login/caravan.webp',
        '/images/login/cabin.webp',
    ] as $sökväg) {
        expect(str_contains($kod, "'{$sökväg}'"))->toBeTrue("{$sökväg} saknas i Login.vue");
    }

    expect(substr_count($kod, 'Math.random()'))->toBe(1, 'Slumpen dras inte exakt en gång i Login.vue');
    expect(preg_match('/const\s+loginImage\s*=[^;]*Math\.random\(\)/', $kod))->toBe(
        1,
        'Login.vue väljer inte bilden i en konstant i setup',
    );

    // En bild, inte ett bildspel: ingen timer och ingen övertoning.
    foreach (['setInterval', 'setTimeout', 'transition-opacity'] as $förbjuden) {
        expect(str_contains($kod, $förbjuden))->toBeFalse("Login.vue innehåller {$förbjuden}");
    }

    // Exakt en <img>, och den är dekoration som ligger bakom texten:
    // alt="" och aria-hidden, loading="lazy" så att telefonen inte hämtar
    // den, och object-cover över hela kolumnen.
    expect(substr_count($kod, '<img'))->toBe(1, 'Login.vue ritar inte exakt en <img>');

    foreach ([
        ':src="loginImage"',
        'alt=""',
        'aria-hidden="true"',
        'loading="lazy"',
        'decoding="async"',
        'object-cover',
        'absolute inset-0',
        'bg-shell/70',
        'overflow-hidden',
    ] as $nål) {
        expect(str_contains($kod, $nål))->toBeTrue("{$nål} saknas i Login.vue");
    }

    // Märket och textblocket ligger ovanpå bilden och slöjan.
    expect(str_contains($kod, '<div class="relative">'))->toBeTrue(
        'Textblocket i vänsterkolumnen ligger inte ovanpå bilden',
    );
});

it('har de tre inloggningsbilderna i public', function () {
    // Issue 490: bilderna ligger redan i repot, beskurna till 4:5 och
    // konverterade till WebP. Provet vaktar att Login.vue inte pekar på filer
    // som inte finns.
    foreach (['boat.webp', 'caravan.webp', 'cabin.webp'] as $fil) {
        expect(File::exists(public_path("images/login/{$fil}")))->toBeTrue(
            "public/images/login/{$fil} saknas",
        );
    }
});

it('säger i docblocket att fotot ritas', function () {
    // Beslut 6 i issue 203 sade att fotot inte ritas; sedan issue 490 gör det.
    // Kommentarerna rensas bort i de andra proven, så den råa filen läses här.
    $rå = File::get(resource_path('js/components/AuthSplitLayout.vue'));

    expect(str_contains($rå, 'Fotot, raden *Används för* och rutan om molnlagringen ritas inte'))->toBeFalse(
        'Docblocket säger fortfarande att fotot inte ritas',
    );
    expect(str_contains($rå, 'en slumpad bild av tre'))->toBeTrue(
        'Docblocket säger inte att en slumpad bild av tre ritas',
    );
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

it('kodfältet får fokus när servern ber om koden', function () {
    // Issue 267 § Beslut 2. `focusFirstError` fokuserar felmeningen
    // (`#code-error`), men när servern ber om engångskoden är `errors.code`
    // en fråga och inte ett fel: markören ska hamna i inmatningen, annars
    // måste användaren klicka i fältet innan hon kan skriva. Felhanteraren
    // grenar därför på `errors.code` och fokuserar `#code`.
    $kod = inloggningskalla();

    // `errors.code` förekommer också i `codeRequested`-computed, så nålen
    // måste peka på själva grenen i felhanteraren och inte på strängen.
    expect(str_contains($kod, 'if (errors.code)'))->toBeTrue(
        'Login.vue grenar inte på errors.code i felhanteringen',
    );
    expect(str_contains($kod, "getElementById('code')"))->toBeTrue(
        'Login.vue fokuserar inte kodfältet när servern ber om koden',
    );
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

it('länkar till informationssidorna', function () {
    // Beslut 3: länkarna under kortet. Sidorna kommer med issue 202;
    // *Subprocessors* kom med M86 · issue 480, efter *Terms of use*, och
    // *Contact* med M89 — efter *Subprocessors* och före *Help*.
    // *Refund policy* (M86) står direkt efter *Terms of use*.
    $kod = inloggningskalla();

    foreach (['/about', '/privacy', '/terms', '/refund-policy', '/subprocessors', '/contact', '/help'] as $mål) {
        expect(str_contains($kod, "href=\"{$mål}\""))->toBeTrue("Login.vue länkar inte till {$mål}");
    }

    expect((int) strpos($kod, 'href="/terms"'))->toBeLessThan((int) strpos($kod, 'href="/refund-policy"'));
    expect((int) strpos($kod, 'href="/refund-policy"'))->toBeLessThan((int) strpos($kod, 'href="/subprocessors"'));
    expect((int) strpos($kod, 'href="/subprocessors"'))->toBeLessThan((int) strpos($kod, 'href="/contact"'));
    expect((int) strpos($kod, 'href="/contact"'))->toBeLessThan((int) strpos($kod, 'href="/help"'));
});

it('har engelska strängar för den nya sidan', function () {
    // Beslut 5. `toBe` mot det exakta värdet bevisar både att nyckeln finns
    // (en saknad nyckel ger nyckeln själv tillbaka) och att ordet är rätt.
    expect(trans('ui.auth.login.heading', [], 'en'))->toBe('Welcome back');
    expect(trans('ui.auth.login.forgot', [], 'en'))->toBe('Forgot your password?');
    expect(trans('ui.auth.landing.heading', [], 'en'))->toBe('Everything in one place for what you');
    expect(trans('ui.auth.landing.links.privacy', [], 'en'))->toBe('Privacy policy');
    expect(trans('ui.auth.landing.links.contact', [], 'en'))->toBe('Contact');
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

it('ritar registreringen i samma skal som inloggningen', function () {
    // Issue 1671. /register är en egen helsida i samma två kolumner som
    // /login: samma skal, utan AppLayout, och fälten kvar som de var.
    $register = inloggningUtanKommentarer(File::get(resource_path('js/pages/Auth/Register.vue')));
    $login = inloggningUtanKommentarer(File::get(resource_path('js/pages/Auth/Login.vue')));

    foreach (['Login.vue' => $login, 'Register.vue' => $register] as $fil => $kod) {
        expect(str_contains($kod, "import AuthSplitLayout from '../../components/AuthSplitLayout.vue'"))->toBeTrue(
            "{$fil} importerar inte det delade skalet",
        );
        expect(str_contains($kod, '<AuthSplitLayout>'))->toBeTrue("{$fil} ritar inte det delade skalet");
        expect(str_contains($kod, 'AppLayout'))->toBeFalse("{$fil} ritar AppLayout");
    }

    foreach ([
        'name="voucher_code"',
        'name="name"',
        'name="email"',
        'name="password"',
        'autocomplete="new-password"',
    ] as $nål) {
        expect(str_contains($register, $nål))->toBeTrue("{$nål} saknas i Register.vue");
    }
});
