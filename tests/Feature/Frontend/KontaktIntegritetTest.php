<?php

use App\Mail\ContactMessageMail;
use App\Models\Account;
use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

/*
 * M89 · Kontaktformuläret, Data & Privacy — [[ADR-0096 Kontaktformuläret]]
 * § 7 och [[ADR-0095 Integritetsbegäranden]] § 2 och § 3.
 *
 * Filen prövar att kategorin `privacy` öppnar ett ärende genom
 * App\Actions\Privacy\OpenPrivacyRequest, att mejlet bär ärendets referens,
 * att ärendet står kvar när mailern kastar, och att ingen annan väg —
 * kategorin `general` eller en honeypot-träff — skapar ett ärende.
 *
 * Hjälparna har prefixet kontaktIntegritet*. Pest lägger alla testfiler i
 * samma namnrymd när hela sviten körs, och KontaktsidanTest äger redan
 * `kontakt*`-namnen.
 */

/** Serverns tid, krypterad — det `rendered_at`-värde sidan får. */
function kontaktIntegritetRenderedAt(): string
{
    return Crypt::encryptString(now()->subSeconds(5)->toIso8601String());
}

/**
 * En giltig inskickning från en gäst.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function kontaktIntegritetPayload(array $overrides = []): array
{
    return array_merge([
        'category' => 'privacy',
        'message' => 'Jag vill veta vilka uppgifter ni har om mig.',
        'name' => 'Gäst Person',
        'email' => 'gast@example.com',
        'website' => '',
        'rendered_at' => kontaktIntegritetRenderedAt(),
    ], $overrides);
}

it('öppnar exakt ett ärende för kategorin privacy som gäst', function () {
    Mail::fake();

    post('/contact', kontaktIntegritetPayload())->assertOk();

    $ärende = PrivacyRequest::query()->sole();

    expect($ärende->type)->toBe('other')
        ->and($ärende->status)->toBe(PrivacyRequest::STATUS_RECEIVED)
        ->and($ärende->requester_name)->toBe('Gäst Person')
        ->and($ärende->requester_email)->toBe('gast@example.com')
        ->and($ärende->description)->toContain('Gäst Person')
        ->and($ärende->description)->toContain('gast@example.com')
        ->and($ärende->description)->toContain('Jag vill veta vilka uppgifter ni har om mig.')
        ->and($ärende->user_id)->toBeNull()
        ->and($ärende->account_id)->toBeNull();

    expect(PrivacyRequestEvent::query()->sole()->kind)->toBe(PrivacyRequestEvent::KIND_CREATED);
});

it('kopplar ärendet till personen och kontot när hon är inloggad', function () {
    Mail::fake();

    $person = User::factory()->create([
        'name' => 'Riktiga Namnet',
        'email' => 'riktig@example.com',
    ]);
    $konto = Account::factory()->create();
    $konto->users()->attach($person, ['role' => 'member']);

    actingAs($person);

    post('/contact', kontaktIntegritetPayload([
        'name' => 'Bedragare',
        'email' => 'bedragare@example.com',
    ]))->assertOk();

    $ärende = PrivacyRequest::query()->sole();

    expect($ärende->requester_name)->toBe('Riktiga Namnet')
        ->and($ärende->requester_email)->toBe('riktig@example.com')
        ->and($ärende->user_id)->toBe($person->id)
        ->and($ärende->account_id)->toBe($konto->id)
        ->and($ärende->description)->toContain('Riktiga Namnet')
        ->and($ärende->description)->toContain('riktig@example.com')
        ->and($ärende->description)->not->toContain('bedragare@example.com');
});

it('bär ärendets referens i mejlets ämne och kropp', function () {
    Mail::fake();

    post('/contact', kontaktIntegritetPayload())->assertOk();

    $ärende = PrivacyRequest::query()->sole();

    expect($ärende->reference)->toStartWith('PR-');

    Mail::assertSent(ContactMessageMail::class, function (ContactMessageMail $mail) use ($ärende): bool {
        $mail->assertHasSubject('[testing] [Contact] Data & Privacy — Gäst Person — '.$ärende->reference);
        $mail->assertSeeInText('Privacy request: '.$ärende->reference);

        return true;
    });
});

it('lämnar ärendet kvar och svarar 503 när mailern kastar', function () {
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP down'));

    post('/contact', kontaktIntegritetPayload())
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'contact.send_failed')
        ->assertJsonPath('error.data', []);

    expect(PrivacyRequest::query()->count())->toBe(1);
    expect(PrivacyRequestEvent::query()->count())->toBe(1);
});

it('skapar inget ärende för kategorin general', function () {
    Mail::fake();

    post('/contact', kontaktIntegritetPayload(['category' => 'general']))->assertOk();

    expect(PrivacyRequest::query()->count())->toBe(0);
    expect(PrivacyRequestEvent::query()->count())->toBe(0);

    Mail::assertSent(ContactMessageMail::class, 1);
});

it('skapar varken ärende eller mejl för en honeypot-träff i privacy', function () {
    Mail::fake();

    post('/contact', kontaktIntegritetPayload(['website' => 'https://spam.example']))
        ->assertOk()
        ->assertExactJson(['status' => 'sent']);

    expect(PrivacyRequest::query()->count())->toBe(0);
    expect(PrivacyRequestEvent::query()->count())->toBe(0);

    Mail::assertNothingSent();
});
