<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * M86 Integritetspolicyn · issue 489 (GitHub #1646) — användarvillkoren på
 * /terms.
 *
 * Texten bor i `lang/en/pages/terms.md` och ritas på servern av
 * `App\Support\InfoPage` med `HelpMarkdown`, [[ADR-0094 Hjälpcentret]] § 4.
 * Sidan `Info.vue` får HTML:en i `body` och ritar den med `v-html`; ingen ny
 * kod behövs, så det här provet läser innehållet i `body` och de punkter
 * issuen räknar upp.
 *
 * Ändringarna mot #1646 § *Full proposed page content* står i filen själv:
 * `# Terms of Use` är struken (sidans `<h1>` kommer ur `info.terms.title`),
 * de två `contact form` länkar till `/contact` och den nakna adressraden i
 * § 18 är struken, och prislöftet i § 7 gäller när vi mejlar, inte priset
 * ([[ADR-0086 Pro, Pro+ och grundarpris]] § 5). Orgnummerraden i `privacy.md`
 * heter *Company registration number*, som i villkoren.
 *
 * Hjälparna har prefixet `villkorssidan` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * `body`-proppen från /terms.
 */
function villkorssidanKropp(): string
{
    withoutVite();

    $svar = get('/terms')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Info')
        ->where('page', 'terms')
    );

    $body = $svar->inertiaProps()['body'];

    expect($body)->toBeString();

    return (string) $body;
}

/*
 * Klart när: de arton numrerade rubrikerna från *1. About Mimers* till
 * *18. Contact* ritas som `<h2>`, raden *Last updated: 25 November 2026*
 * finns, och `body` har ingen `<h1>` — sidans rubrik kommer ur
 * `info.terms.title`.
 */
it('visar de 18 numrerade rubrikerna som h2 och uppdateringsdatumet', function () {
    $body = villkorssidanKropp();

    $rubriker = [
        '1. About Mimers',
        '2. Eligibility and accounts',
        '3. Your content and responsibilities',
        '4. Containers, sharing and invited users',
        '5. AI agents and external services',
        '6. Plans, features and storage limits',
        '7. Subscriptions, payments and cancellation',
        '8. Downgrading and exceeding storage limits',
        '9. Inactive accounts',
        '10. Availability, maintenance and data protection',
        '11. Acceptable use',
        '12. Suspension and termination',
        '13. Intellectual property',
        '14. Limitation of liability',
        '15. Changes to Mimers and these Terms',
        '16. Privacy and personal data',
        '17. Governing law and disputes',
        '18. Contact',
    ];

    $senast = -1;

    foreach ($rubriker as $rubrik) {
        expect($body)->toContain('>'.$rubrik.'</h2>');

        $position = strpos($body, $rubrik);

        expect($position)->not->toBeFalse("rubriken \"{$rubrik}\" saknas i body");
        expect($position)->toBeGreaterThan($senast, "rubriken \"{$rubrik}\" står i fel ordning");

        $senast = $position;
    }

    expect(substr_count($body, '<h2'))->toBe(18)
        ->and($body)->toContain('Last updated: 25 November 2026')
        ->and($body)->not->toContain('<h1');
});

/*
 * Klart när: /terms och /privacy säger båda *Company registration number:
 * 556813-8464*, och *Corporate identity number* finns inte kvar på någon av
 * dem. Det är den enda ändringen i privacy.md.
 */
it('säger Company registration number på både /terms och /privacy', function () {
    $villkor = villkorssidanKropp();

    withoutVite();

    $privacy = get('/privacy')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Info')
        ->where('page', 'privacy')
    );

    $policy = (string) $privacy->inertiaProps()['body'];

    expect($villkor)->toContain('Company registration number: 556813-8464')
        ->and($villkor)->not->toContain('Corporate identity number')
        ->and($policy)->toContain('Company registration number: 556813-8464')
        ->and($policy)->not->toContain('Corporate identity number')
        ->and($policy)->toContain('Integritetsskyddsmyndigheten (IMY)');
});

/*
 * Klart när: § 7 innehåller *We will always notify you by email at least one
 * month before your next renewal.* och inte *before the new price applies*.
 */
it('lovar att mejla minst en månad före förnyelsen i § 7', function () {
    $body = villkorssidanKropp();

    $start = strpos($body, '7. Subscriptions, payments and cancellation');
    $slut = strpos($body, '8. Downgrading and exceeding storage limits');

    expect($start)->not->toBeFalse()->and($slut)->not->toBeFalse();

    $avsnitt = substr($body, (int) $start, (int) $slut - (int) $start);

    expect($avsnitt)->toContain('We will always notify you by email at least one month before your next renewal.')
        ->and($avsnitt)->not->toContain('before the new price applies');
});

/*
 * Klart när: `body` innehåller bolagsuppgifterna, åldersgränsen, Paddle som
 * *Merchant of Record*, *Malmö District Court* och de tre planerna.
 */
it('bär bolagsuppgifterna, åldersgränsen, Paddle, domstolen och planerna', function () {
    $body = villkorssidanKropp();

    expect($body)->toContain('Company registration number: 556813-8464')
        ->and($body)->toContain('SE556813846401')
        ->and($body)->toContain('at least 18 years old')
        ->and($body)->toContain('Merchant of Record')
        ->and($body)->toContain('Malmö District Court')
        ->and($body)->toContain('<strong>Free</strong>')
        ->and($body)->toContain('<strong>Pro</strong>')
        ->and($body)->toContain('<strong>Pro+</strong>');
});

/*
 * Klart när: `body` innehåller länken till Paddles köparvillkor och länken
 * till återbetalningspolicyn.
 */
it('länkar Paddles köparvillkor och återbetalningspolicyn', function () {
    $body = villkorssidanKropp();

    expect($body)->toContain('href="https://www.paddle.com/legal/checkout-buyer-terms"')
        ->and($body)->toContain('href="/refund-policy"')
        ->and($body)->not->toContain('manjo.me/refund-policy.php');
});

/*
 * Klart när: § 8:s borttagningsordning ritas som en `<ol>` med fyra punkter i
 * ordningen slängda bilagor, levande bilagor, slängda items, levande items.
 * Ordningen är densamma som [[Planer och kvoter]] § Nedgradering.
 */
it('ritar borttagningsordningen i § 8 som en ol med fyra punkter', function () {
    $body = villkorssidanKropp();

    preg_match('/<ol>(.*?)<\/ol>/s', $body, $lista);

    $ordning = $lista[1] ?? '';

    expect($ordning)->not->toBe('', 'body ritar ingen <ol>')
        ->and(substr_count($body, '<ol>'))->toBe(1)
        ->and(substr_count($ordning, '<li>'))->toBe(4);

    $senast = -1;

    foreach (['Attachments in the trash', 'Active attachments', 'Items in the trash', 'Active items'] as $rad) {
        $position = strpos($ordning, $rad);

        expect($position)->not->toBeFalse("punkten \"{$rad}\" saknas i <ol>");
        expect($position)->toBeGreaterThan($senast, "punkten \"{$rad}\" står i fel ordning");

        $senast = $position;
    }
});

/*
 * Klart när: länkarna till /privacy (tre ställen: § 10, § 12 och § 16),
 * /subprocessors och /contact finns, och varje *contact form* är en länk till
 * /contact.
 */
it('länkar /privacy, /subprocessors och /contact', function () {
    $body = villkorssidanKropp();

    expect(substr_count($body, '<a href="/privacy">Privacy Policy</a>'))->toBe(3)
        ->and($body)->toContain('<a href="/subprocessors">Subprocessors page</a>')
        ->and(substr_count($body, '<a href="/contact">contact form</a>'))->toBe(2)
        ->and(substr_count($body, 'contact form'))->toBe(2);
});

/*
 * Klart när: `body` har ingen `mailto:`, ingen e-postadress och ingen naken
 * adress till https://mimers.app/contact. Ingen e-postadress eller postadress
 * läggs till som reserv.
 */
it('har ingen e-postadress och ingen naken kontaktadress', function () {
    $body = villkorssidanKropp();

    expect($body)->not->toContain('mailto:')
        ->and($body)->not->toContain('@')
        ->and($body)->not->toContain('https://mimers.app/contact');
});

/*
 * Klart när: `body` innehåller varken skribentens granskningsanteckningar
 * eller de interna rubrikerna från #1646 — sidtexten är bara den avgränsade
 * delen av issuen.
 */
it('publicerar inte granskningsanteckningarna från #1646', function () {
    $body = villkorssidanKropp();

    expect($body)->not->toContain('Legal review')
        ->and($body)->not->toContain('Acceptance criteria')
        ->and($body)->not->toContain('Product and policy decisions')
        ->and($body)->not->toContain('DSA');
});

/*
 * Klart när: /terms nås av en gäst och av en inloggad med 200, med samma
 * `body` för båda.
 */
it('ger en gäst och en inloggad samma body', function () {
    $gast = villkorssidanKropp();

    withoutVite();

    $inloggad = actingAs(User::factory()->create())
        ->get('/terms')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Info')
            ->where('page', 'terms')
        );

    expect($inloggad->inertiaProps()['body'])->toBe($gast);
});

/*
 * Klart när: /about och /subprocessors ritar som förut. /privacys enda
 * ändring är orgnummerraden, som prövas ovan.
 */
// vakt: regressionsvakt — /about och /subprocessors ska rita som förut, så provet är grönt på basen med flit.
it('lämnar /about och /subprocessors orörda', function () {
    withoutVite();

    $about = get('/about')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Info')
        ->where('page', 'about')
    );

    $underbitraden = get('/subprocessors')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Info')
        ->where('page', 'subprocessors')
    );

    expect((string) $about->inertiaProps()['body'])->toContain('Structure on your terms.')
        ->and((string) $underbitraden->inertiaProps()['body'])->toContain('Inleed');
});

/*
 * Klart när: § 2 låter Mimers stänga ett konto som tillhör någon under 18, och
 * § 15 säger vad som händer med betalda perioder om tjänsten läggs ned.
 */
it('har meningarna om konton under 18 och om nedläggning', function () {
    $body = villkorssidanKropp();

    expect($body)->toContain('If we learn that an account belongs to someone under 18, we may close the account and delete its data.')
        ->and($body)->toContain('If we decide to shut down Mimers, we will stop accepting new subscriptions and stop renewals, and let paid subscription periods run to their end before the service closes. If we have to close the service before your paid period ends, we will refund the part of the period that was not delivered, pro rata.');
});
