<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * M86 Integritetspolicyn · issue 479 (GitHub #1619) — den godkända
 * integritetspolicyn på /privacy.
 *
 * Texten bor i `lang/en/pages/privacy.md` och ritas på servern av
 * `App\Support\InfoPage` med `HelpMarkdown`, [[ADR-0094 Hjälpcentret]] § 4.
 * Sidan `Info.vue` får HTML:en i `body` och ritar den med `v-html`; ingen ny
 * kod behövs, så det här provet läser innehållet i `body` och de punkter
 * issuen räknar upp.
 *
 * Avvikelserna mot #1600 § *Approved Privacy Policy copy* står i filen själv:
 * `### Privacy Policy` är struken (sidans `<h1>` kommer ur
 * `info.privacy.title`), de tre `contact form` länkar till `/contact`,
 * underbiträdesadressen länkar till `/subprocessors`, fristtabellen har två
 * loggrader och § 13 nämner inga delade konton.
 *
 * Hjälparna har prefixet `integritetspolicy` — Pest lägger alla testfiler i
 * samma namnrymd när hela sviten körs.
 */

/**
 * `body`-proppen från /privacy.
 */
function integritetspolicyKropp(): string
{
    withoutVite();

    $svar = get('/privacy')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Info')
        ->where('page', 'privacy')
    );

    $body = $svar->inertiaProps()['body'];

    expect($body)->toBeString();

    return (string) $body;
}

it('visar de 18 numrerade rubrikerna som h2 och uppdateringsdatumet', function () {
    $body = integritetspolicyKropp();

    $rubriker = [
        '1. Introduction',
        '2. Who is responsible for your personal data?',
        '3. What personal data do we collect?',
        '4. Why do we process personal data?',
        '5. User content and shared information',
        '6. How we use cookies and analytics',
        '7. Payments and Paddle',
        '8. Service notifications and communications',
        '9. AI agents and third-party AI services',
        '10. Who may receive personal data?',
        '11. Where is personal data stored and processed?',
        '12. How long do we retain personal data?',
        '13. Account deletion',
        '14. Your rights under GDPR',
        '15. Data security',
        '16. Children\'s privacy',
        '17. Changes to this Privacy Policy',
        '18. Complaints and supervisory authority',
    ];

    $senast = -1;

    foreach ($rubriker as $rubrik) {
        expect($body)->toContain('>'.$rubrik.'</h2>');

        $position = strpos($body, $rubrik);

        expect($position)->not->toBeFalse("rubriken \"{$rubrik}\" saknas i body");
        expect($position)->toBeGreaterThan($senast, "rubriken \"{$rubrik}\" står i fel ordning");

        $senast = $position;
    }

    expect($body)->toContain('Last updated: 23 November 2026');
});

it('ritar ändamålstabellen i § 4 och fristtabellen i § 12 som tabeller', function () {
    $body = integritetspolicyKropp();

    expect(substr_count($body, '<table>'))->toBe(2)
        ->and($body)->toContain('<th>Purpose</th>')
        ->and($body)->toContain('<th>Legal basis</th>')
        ->and($body)->toContain('<th>Data</th>')
        ->and($body)->toContain('<th>Retention</th>');
});

it('har en rad för Audit log och en för Security log i fristtabellen', function () {
    $body = integritetspolicyKropp();

    expect($body)->toContain('<td>Audit log</td>')
        ->and($body)->toContain('<td>12 months after the relevant container has been purged or the account deleted</td>')
        ->and($body)->toContain('<td>Security log</td>')
        ->and($body)->toContain('<td>12 months</td>')
        ->and($body)->not->toContain('<td>Audit and security logs</td>');
});

it('beskriver Paddle som Merchant of Record med köparvillkor, Manage billing och återbetalning', function () {
    $body = integritetspolicyKropp();

    expect($body)->toContain('Merchant of Record')
        ->and($body)->toContain('href="https://www.paddle.com/legal/checkout-buyer-terms"')
        ->and($body)->toContain('Manage billing')
        ->and($body)->toContain('href="https://manjo.me/refund-policy.php"')
        ->and($body)->toContain('described separately from our subprocessor list');
});

it('nämner aldrig Resend eller Amazon SES', function () {
    $body = integritetspolicyKropp();

    expect($body)->not->toContain('Resend')
        ->and($body)->not->toContain('Amazon SES');
});

it('har 18-årsgränsen och IMY med länk till imy.se', function () {
    $body = integritetspolicyKropp();

    expect($body)->toContain('18 years old')
        ->and($body)->toContain('Integritetsskyddsmyndigheten (IMY)')
        ->and($body)->toContain('href="https://www.imy.se"');
});

it('länkar varje contact form till /contact utan e-postadress', function () {
    $body = integritetspolicyKropp();

    expect(substr_count($body, '<a href="/contact">contact form</a>'))->toBe(3)
        ->and($body)->not->toContain('mailto:')
        ->and($body)->not->toContain('@');
});

it('länkar underbiträdeslistan till /subprocessors', function () {
    $body = integritetspolicyKropp();

    expect($body)->toContain('<a href="/subprocessors">mimers.app/subprocessors</a>');
});

it('nämner inga delade konton i § 13', function () {
    $body = integritetspolicyKropp();

    expect($body)->not->toContain('shared accounts')
        ->and($body)->not->toContain('If an account is shared')
        ->and($body)->toContain('You lose access to containers shared with you, in accordance with the applicable access and ownership rules.')
        ->and($body)->toContain('Deleting your account does not delete content in containers owned by other accounts.');
});

it('nås av en gäst och av en inloggad med 200', function () {
    withoutVite();

    get('/privacy')->assertOk();

    actingAs(User::factory()->create())
        ->get('/privacy')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Info')
            ->where('page', 'privacy')
        );
});

it('lämnar /about och /terms som förut', function () {
    withoutVite();

    $about = get('/about')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Info')
        ->where('page', 'about')
    );

    expect((string) $about->inertiaProps()['body'])->not->toBe('');

    $terms = get('/terms')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Info')
        ->where('page', 'terms')
    );

    expect($terms->inertiaProps()['body'])->toBeNull();
});
