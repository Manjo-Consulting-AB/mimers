<?php

use App\Mail\ContactMessageMail;
use App\Models\Account;
use App\Models\User;
use App\Support\Contact\ContactCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * M89 · Kontaktformuläret — /contact, [[ADR-0096 Kontaktformuläret]] § 1–6.
 *
 * Filen prövar serverns halva av kontraktet: sidan och dess proppar,
 * inskickningen, mejlet, skräpskyddet, begränsaren och felsvaren. Vyns
 * halva — att inga engelska strängar står i Contact.vue och att länken finns
 * på båda ställena — prövas som källkodsprov längst ned, samma form som
 * InloggningssidaTest och GenomgangTest.
 *
 * Hjälparna har prefixet kontakt* — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Källkoden med kommentarer borta — en förklaring i ett docblock ska inte
 * kunna få ett prov att tro att koden gör något.
 */
function kontaktKalla(string $sokvag): string
{
    $kod = File::get(resource_path($sokvag));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/** Serverns tid, krypterad — det `rendered_at`-värde sidan får. */
function kontaktRenderedAt(int $secondsAgo = 5): string
{
    return Crypt::encryptString(now()->subSeconds($secondsAgo)->toIso8601String());
}

/**
 * En giltig inskickning från en gäst.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function kontaktPayload(array $overrides = []): array
{
    return array_merge([
        'category' => 'general',
        'message' => 'Det här är mitt meddelande.',
        'name' => 'Gäst Person',
        'email' => 'gast@example.com',
        'website' => '',
        'rendered_at' => kontaktRenderedAt(),
    ], $overrides);
}

/** Ett konto som personen är medlem i — mejlet bär kontots ULID. */
function kontaktKontoFor(User $user): Account
{
    $konto = Account::factory()->create();
    $konto->users()->attach($user, ['role' => 'member']);

    return $konto;
}

it('renderar Contact för en gäst med kategorierna i ADR-0096:s ordning', function () {
    withoutVite();

    $svar = get('/contact')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Contact')
            ->where('categories', ContactCategory::KEYS)
            ->where('name', null)
            ->where('email', null)
        );

    $renderedAt = (string) $svar->inertiaProps()['rendered_at'];

    expect($renderedAt)->not->toBe('');
    expect(Crypt::decryptString($renderedAt))->not->toBe('');
});

it('renderar Contact för en inloggad med namn och adress från kontot', function () {
    withoutVite();

    $person = User::factory()->create([
        'name' => 'Inloggad Person',
        'email' => 'inloggad@example.com',
    ]);

    actingAs($person)
        ->get('/contact')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Contact')
            ->where('name', 'Inloggad Person')
            ->where('email', 'inloggad@example.com')
        );
});

it('namnger rutterna contact och contact.store', function () {
    expect(route('contact', [], false))->toBe('/contact');
    expect(route('contact.store', [], false))->toBe('/contact');
});

it('skickar exakt ett mejl till supportadressen från en gäst', function () {
    Mail::fake();

    post('/contact', kontaktPayload())
        ->assertOk()
        ->assertExactJson(['status' => 'sent']);

    Mail::assertSent(ContactMessageMail::class, 1);
    Mail::assertNotQueued(ContactMessageMail::class);
    Mail::assertNothingQueued();

    Mail::assertSent(ContactMessageMail::class, function (ContactMessageMail $mail): bool {
        $mail->assertTo('support@mimers.app');
        $mail->assertFrom((string) config('mail.from.address'));
        $mail->assertHasReplyTo('gast@example.com');
        $mail->assertHasSubject('[testing] [Contact] General Inquiry — Gäst Person');
        $mail->assertSeeInText('Det här är mitt meddelande.');
        $mail->assertSeeInText('Not signed in — identity not verified.');
        $mail->assertDontSeeInText('127.0.0.1');

        return true;
    });
});

it('bär den inloggades identitet och ignorerar name och email i begäran', function () {
    Mail::fake();

    $person = User::factory()->create([
        'name' => 'Riktiga Namnet',
        'email' => 'riktig@example.com',
    ]);
    $konto = kontaktKontoFor($person);

    actingAs($person);

    post('/contact', kontaktPayload([
        'category' => 'technical',
        'name' => 'Bedragare',
        'email' => 'bedragare@example.com',
    ]))->assertOk();

    Mail::assertSent(ContactMessageMail::class, function (ContactMessageMail $mail) use ($person, $konto): bool {
        $mail->assertHasReplyTo('riktig@example.com');
        $mail->assertSeeInText('Riktiga Namnet');
        $mail->assertSeeInText($person->ulid);
        $mail->assertSeeInText($konto->ulid);
        $mail->assertDontSeeInText('bedragare@example.com');

        return true;
    });
});

it('skickar inget mejl till avsändaren och skriver ingen rad i databasen', function () {
    Mail::fake();

    $tabeller = Schema::getTableListing();
    $innan = [];

    foreach ($tabeller as $tabell) {
        $innan[$tabell] = DB::table($tabell)->count();
    }

    post('/contact', kontaktPayload())->assertOk();

    $efter = [];

    foreach ($tabeller as $tabell) {
        $efter[$tabell] = DB::table($tabell)->count();
    }

    expect($efter)->toBe($innan);

    // Ett mejl, till supporten — ingenting till den adress personen angett.
    Mail::assertSent(ContactMessageMail::class, 1);
    Mail::assertSent(ContactMessageMail::class, fn (ContactMessageMail $mail): bool => $mail->hasTo('support@mimers.app'));
});

it('svarar som ett riktigt ärende men skickar inget när honeypot-fältet är fyllt', function () {
    Mail::fake();
    $logg = Log::spy();

    post('/contact', kontaktPayload(['website' => 'https://spam.example']))
        ->assertOk()
        ->assertExactJson(['status' => 'sent']);

    Mail::assertNothingSent();

    $logg->shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'contact.trapped'
            && ($context['reason'] ?? null) === 'honeypot');
});

it('svarar som ett riktigt ärende när rendered_at inte går att dekryptera', function () {
    Mail::fake();
    $logg = Log::spy();

    post('/contact', kontaktPayload(['rendered_at' => 'inte-krypterat']))
        ->assertOk()
        ->assertExactJson(['status' => 'sent']);

    Mail::assertNothingSent();

    $logg->shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'contact.trapped'
            && ($context['reason'] ?? null) === 'rendered_at');
});

it('svarar som ett riktigt ärende när inskickningen kommer för snabbt', function () {
    Mail::fake();
    $logg = Log::spy();

    Carbon::setTestNow('2026-11-24 12:00:00');

    post('/contact', kontaktPayload([
        'rendered_at' => Crypt::encryptString(now()->toIso8601String()),
    ]))->assertOk()->assertExactJson(['status' => 'sent']);

    Mail::assertNothingSent();

    $logg->shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'contact.trapped'
            && ($context['reason'] ?? null) === 'too_fast');
});

it('nekar en ogiltig inskickning med en kod per fält', function (array $overrides, string $fält, string $kod) {
    post('/contact', kontaktPayload($overrides))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath("error.data.fields.{$fält}.0.code", $kod);
})->with([
    'saknad kategori' => [['category' => ''], 'category', 'validation.required'],
    'okänd kategori' => [['category' => 'nonsense'], 'category', 'validation.in'],
    'tomt meddelande' => [['message' => ''], 'message', 'validation.required'],
    'för långt meddelande' => [['message' => str_repeat('a', 5001)], 'message', 'validation.max'],
    'saknat namn' => [['name' => ''], 'name', 'validation.required'],
    'ogiltig adress' => [['email' => 'inte-en-adress'], 'email', 'validation.email'],
]);

it('bär gränsen i data för ett för långt fält', function () {
    post('/contact', kontaktPayload(['message' => str_repeat('a', 5001)]))
        ->assertStatus(422)
        ->assertJsonPath('error.data.fields.message.0.data.max', 5000);
});

it('ger 429 på den sjätte inskickningen i timmen från samma IP', function () {
    Mail::fake();

    for ($i = 0; $i < 5; $i++) {
        post('/contact', kontaktPayload())->assertOk();
    }

    $svar = post('/contact', kontaktPayload());

    $svar->assertStatus(429)
        ->assertJsonPath('error.code', 'auth.too_many_attempts')
        ->assertJsonPath(
            'error.data.retry_after_seconds',
            fn (mixed $sekunder): bool => is_int($sekunder) && $sekunder > 0,
        );

    Mail::assertSent(ContactMessageMail::class, 5);
});

it('svarar 503 contact.send_failed och loggar utan personuppgifter när mailern kastar', function () {
    $logg = Log::spy();

    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP down'));

    post('/contact', kontaktPayload(['message' => 'Hemligt meddelande']))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'contact.send_failed')
        ->assertJsonPath('error.data', []);

    $logg->shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'contact.send_failed'
            && ($context['category'] ?? null) === 'general'
            && ! array_key_exists('message', $context)
            && ! array_key_exists('name', $context)
            && ! array_key_exists('email', $context));
});

it('prefixar ämnet med miljön utanför produktion', function () {
    // Mailet prövas direkt: en HTTP-inskickning i en annan miljö hade slagit
    // av testets CSRF-undantag och gett 419 i stället för ett mejl.
    $mail = new ContactMessageMail(
        category: 'general',
        name: 'Gäst Person',
        email: 'gast@example.com',
        message: 'Hej',
        user: null,
        accountUlid: null,
        contentLocale: 'en',
        sentAt: now(),
    );

    app()->detectEnvironment(fn (): string => 'staging');
    expect($mail->envelope()->subject)->toBe('[staging] [Contact] General Inquiry — Gäst Person');

    app()->detectEnvironment(fn (): string => 'production');
    expect($mail->envelope()->subject)->toBe('[Contact] General Inquiry — Gäst Person');
});

it('lägger Contact-länken efter Subprocessors i sidfoten och på inloggningssidan', function () {
    $layout = kontaktKalla('js/layouts/AppLayout.vue');
    $login = kontaktKalla('js/pages/Auth/Login.vue');

    foreach (['js/layouts/AppLayout.vue' => $layout, 'js/pages/Auth/Login.vue' => $login] as $fil => $kod) {
        expect($kod)->toContain('href="/contact"')
            ->and($kod)->toContain("t('auth.landing.links.contact')");

        expect((int) strpos($kod, 'href="/subprocessors"'))
            ->toBeLessThan((int) strpos($kod, 'href="/contact"'), "{$fil} lägger Contact före Subprocessors");
    }

    // Sidfoten har ingen Help-länk; inloggningssidan har den efter Contact.
    expect((int) strpos($login, 'href="/contact"'))
        ->toBeLessThan((int) strpos($login, 'href="/help"'));
});

it('har ingen engelsk copy i Contact.vue', function () {
    $kod = kontaktKalla('js/pages/Contact.vue');

    preg_match('#<template>(.*)</template>#s', $kod, $träff);
    $mall = $träff[1] ?? '';

    expect($mall)->not->toBe('');

    // Varje synlig text kommer ur t(): mustascherna bort, och kvar ska bara
    // whitespace finnas mellan taggarna.
    $utanMustascher = (string) preg_replace('/\{\{.*?\}\}/s', '', $mall);

    preg_match_all('/>([^<>]+)</s', $utanMustascher, $texter);

    foreach ($texter[1] as $text) {
        expect(trim($text))->toBe('', 'Contact.vue har engelsk copy: '.trim($text));
    }

    expect($mall)->toContain('contact.categories.');
});
