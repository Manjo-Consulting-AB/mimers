<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

/*
 * Issue 4 · Autentisering med lösenord.
 *
 * `App\Models\User` implementerar `MustVerifyEmail` (tillagt i den här
 * issuen, se issue #17 § Att se upp med). E-postverifiering krävs innan en
 * användare kan ta emot delning, se [[ADR-0011 Autentisering]] §
 * Konsekvenser — den fulla regeln (nekad delning för overifierade konton)
 * byggs i en senare issue, det som testas här är själva verifieringsflödet.
 */

function signeradVerifieringslank(User $user): string
{
    return URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
    );
}

it('markerar e-posten som verifierad via en giltig signerad länk', function () {
    Event::fake([Verified::class]);

    $user = User::factory()->unverified()->create();

    actingAs($user);
    get(signeradVerifieringslank($user))->assertRedirect(route('dashboard'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatched(Verified::class);
});

it('avvisar en manipulerad verifieringslänk — 403, ingen krasch', function () {
    $user = User::factory()->unverified()->create();

    $länk = signeradVerifieringslank($user).'&manipulerad=1';

    actingAs($user);
    get($länk)->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('är idempotent — en redan verifierad e-post kan besökas igen utan fel', function () {
    Event::fake([Verified::class]);

    $user = User::factory()->create();
    expect($user->hasVerifiedEmail())->toBeTrue();

    actingAs($user);
    get(signeradVerifieringslank($user))->assertRedirect(route('dashboard'));

    Event::assertNotDispatched(Verified::class);
});

it('skickar om verifieringsmejlet till en inloggad, overifierad webbanvändare', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    actingAs($user);
    // postJson skickar Accept: application/json, så kontrollern svarar
    // 202 (samma JSON-gren som API-ytan) i stället för att omdirigera —
    // se App\Http\Controllers\Auth\EmailVerificationNotificationController.
    postJson('/email/verification-notification')->assertAccepted();

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('skickar om verifieringsmejlet till en token-autentiserad API-användare', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();
    $token = $user->createToken('api');

    postJson('/api/email/verification-notification', [], [
        'Authorization' => "Bearer {$token->plainTextToken}",
    ])->assertAccepted();

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('skickar inget nytt mejl till en redan verifierad användare', function () {
    Notification::fake();

    $user = User::factory()->create();

    actingAs($user);
    postJson('/email/verification-notification')->assertNoContent();

    Notification::assertNothingSent();
});
