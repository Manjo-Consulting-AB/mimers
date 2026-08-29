<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 10b · Inbjudningar, mottagarsidan. Se
 * App\Http\Controllers\Api\InvitationResponseController,
 * App\Http\Requests\Invitation\InvitationTokenRequest,
 * App\Actions\Invitation\AcceptInvitation och
 * App\Notifications\InvitationNotification.
 *
 * Avsändarytan — skapa, lista, dra tillbaka — är 10a och testas i
 * tests/Feature/Container/InbjudanTest.php. Behörighetsreglerna
 * (App\Policies\ContainerPolicy) är 9b:s och prövas inte här: accept
 * anropar ingen policy alls (§ Beslut 12), och att den beviljade åtkomsten
 * BITER prövas via en vanlig GET på containern.
 *
 * kontoMedMedlem() är deklarerad i
 * tests/Feature/Container/ContainerCrudTest.php och beviljaAccess() i
 * tests/Feature/Container/ContainerAtkomstTest.php — Pests globala
 * namnrymd gör dem åtkomliga rakt av här, samma mönster som
 * InbjudanTest.php redan använder.
 *
 * Mejl skickas aldrig mot en riktig utgång — `Notification::fake()` i det
 * test som går genom avsändarrutten, som i
 * tests/Feature/Auth/MagicLinkTest.php.
 *
 * "Klart när" (InbjudanAcceptTest):
 * - en inbjudan mejlas till adressen med tokenet i länken
 * - mottagaren måste ha verifierat sin e-post för att acceptera
 * - en annan användare kan inte acceptera någon annans inbjudan
 * - accept skapar en member-access med inbjudans level
 * - accept sätter granted_by till den som bjöd in
 * - accept ger mottagaren behörighet direkt
 * - accept svarar med containern
 * - accept sätter status accepted
 * - en accepterad inbjudan kan inte accepteras igen
 * - en utgången inbjudan kan inte accepteras
 * - en tillbakadragen inbjudan kan inte accepteras
 * - accept skapar ingen andra access när en giltig redan finns
 * - en inbjudan kan accepteras även när ägarkontot är read_only
 * - avvisa sätter status rejected och skapar ingen access
 * - avvisa kräver inte verifierad e-post
 * - avvisa av någon annans inbjudan nekas
 * - ett okänt token ger 404
 * - en inbjudan till en mjukraderad container ger 404
 * - oautentiserad accept ger 401
 */

/**
 * Skapar en `pending` inbjudan direkt i databasen och returnerar den
 * tillsammans med KLARTEXTTOKENET — raden lagrar bara hashen (10a §
 * Beslut 5), och en riktig mottagare får klartexten enbart i mejlet.
 * Testerna som prövar accept/avvisa behöver den för kroppen, och den
 * ligger därför bara i minnet här.
 *
 * `invited_by_user_id` är obligatorisk (FK); vem som bjöd in spelar roll
 * i ett enda test, så den går att skicka in.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{0: Invitation, 1: string} [$invitation, $rawToken]
 */
function inbjudanMedToken(Container $container, string $email, array $overrides = []): array
{
    $rawToken = Str::random(64);

    $invitation = Invitation::factory()->create(array_merge([
        'container_id' => $container->id,
        'email' => $email,
        'level' => 'read',
        'token_hash' => hash('sha256', $rawToken),
        'status' => 'pending',
        'expires_at' => now()->addDays(Invitation::TTL_DAYS),
        'invited_by_user_id' => User::factory()->create()->id,
    ], $overrides));

    return [$invitation, $rawToken];
}

/**
 * En inloggad mottagare med en given adress, plus ett Sanctum-headerpar —
 * samma form som kontoMedMedlem() i ContainerCrudTest.php, men utan konto:
 * den inbjudna har per definition ingen relation till containern, och
 * behöver inget konto för att kunna svara på inbjudan.
 *
 * @return array{0: User, 1: array<string, string>} [$user, $headers]
 */
function mottagare(string $email, bool $verifierad = true): array
{
    $factory = User::factory();

    if (! $verifierad) {
        $factory = $factory->unverified();
    }

    $user = $factory->create(['email' => $email]);

    $token = $user->createToken('api');

    return [$user, ['Authorization' => "Bearer {$token->plainTextToken}"]];
}

it('en inbjudan mejlas till adressen med tokenet i länken', function () {
    Notification::fake();

    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'read',
    ], $headers)->assertCreated();

    $invitation = Invitation::query()->where('email', 'ny@exempel.se')->firstOrFail();

    Notification::assertSentOnDemand(
        InvitationNotification::class,
        function (InvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($invitation) {
            $prefix = rtrim((string) config('app.url'), '/').'/invitations/';

            expect($notifiable->routes['mail'])->toBe('ny@exempel.se')
                ->and($notification->url)->toStartWith($prefix);

            // Länken bär KLARTEXTEN — det enda stället tokenet någonsin
            // syns. Att dess hash är radens `token_hash` bevisar både att
            // mejlet är användbart och att databasen bara har hashen.
            $raw = Str::after($notification->url, $prefix);

            expect(hash('sha256', $raw))->toBe($invitation->token_hash);

            return true;
        }
    );
});

it('mottagaren måste ha verifierat sin e-post för att acceptera', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    [, $rawToken] = inbjudanMedToken($container, 'ny@exempel.se');
    [, $headers] = mottagare('ny@exempel.se', verifierad: false);

    $response = postJson('/api/invitations/accept', ['token' => $rawToken], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('invitation.email_not_verified')
        ->and(ContainerAccess::query()->count())->toBe(0);
});

it('en annan användare kan inte acceptera någon annans inbjudan', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    [, $rawToken] = inbjudanMedToken($container, 'ny@exempel.se');
    [, $headers] = mottagare('nagon.annan@exempel.se');

    $response = postJson('/api/invitations/accept', ['token' => $rawToken], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('invitation.email_mismatch')
        ->and(ContainerAccess::query()->count())->toBe(0);
});

it('accept skapar en member-access med inbjudans level', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    [, $rawToken] = inbjudanMedToken($container, 'ny@exempel.se', ['level' => 'write']);
    [$user, $headers] = mottagare('ny@exempel.se');

    postJson('/api/invitations/accept', ['token' => $rawToken], $headers)->assertOk();

    $access = ContainerAccess::query()->sole();

    expect($access->container_id)->toBe($container->id)
        ->and($access->grantee_type)->toBe('user')
        ->and($access->grantee_id)->toBe($user->id)
        ->and($access->level)->toBe('write')
        ->and($access->kind)->toBe('member')
        ->and($access->expires_at)->toBeNull()
        ->and($access->revoked_at)->toBeNull();
});

it('accept sätter granted_by till den som bjöd in', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    $inbjudare = User::factory()->create();
    [, $rawToken] = inbjudanMedToken($container, 'ny@exempel.se', ['invited_by_user_id' => $inbjudare->id]);
    [$user, $headers] = mottagare('ny@exempel.se');

    postJson('/api/invitations/accept', ['token' => $rawToken], $headers)->assertOk();

    // Spåret ska peka på den som DELEGERADE, inte på den som klickade.
    expect(ContainerAccess::query()->sole()->granted_by_user_id)->toBe($inbjudare->id)
        ->and(ContainerAccess::query()->sole()->granted_by_user_id)->not->toBe($user->id);
});

it('accept ger mottagaren behörighet direkt', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    [, $rawToken] = inbjudanMedToken($container, 'ny@exempel.se');
    [, $headers] = mottagare('ny@exempel.se');

    getJson("/api/containers/{$container->ulid}", $headers)->assertStatus(403);

    postJson('/api/invitations/accept', ['token' => $rawToken], $headers)->assertOk();

    getJson("/api/containers/{$container->ulid}", $headers)->assertOk();
});

it('accept svarar med containern', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    [, $rawToken] = inbjudanMedToken($container, 'ny@exempel.se');
    [, $headers] = mottagare('ny@exempel.se');

    $response = postJson('/api/invitations/accept', ['token' => $rawToken], $headers);

    $response->assertOk();
    $response->assertJson([
        'data' => [
            'ulid' => $container->ulid,
            'name' => $container->name,
            'account' => $container->account->ulid,
        ],
    ]);
});

it('accept sätter status accepted', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    [$invitation, $rawToken] = inbjudanMedToken($container, 'ny@exempel.se');
    [, $headers] = mottagare('ny@exempel.se');

    postJson('/api/invitations/accept', ['token' => $rawToken], $headers)->assertOk();

    expect($invitation->fresh()->status)->toBe('accepted');
});

it('en accepterad inbjudan kan inte accepteras igen', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    [, $rawToken] = inbjudanMedToken($container, 'ny@exempel.se');
    [, $headers] = mottagare('ny@exempel.se');

    postJson('/api/invitations/accept', ['token' => $rawToken], $headers)->assertOk();

    $response = postJson('/api/invitations/accept', ['token' => $rawToken], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('invitation.not_pending')
        ->and(ContainerAccess::query()->count())->toBe(1);
});

it('en utgången inbjudan kan inte accepteras', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    [, $rawToken] = inbjudanMedToken($container, 'ny@exempel.se', [
        'expires_at' => Carbon::now()->subDay(),
    ]);
    [, $headers] = mottagare('ny@exempel.se');

    $response = postJson('/api/invitations/accept', ['token' => $rawToken], $headers);

    // `expired` skiljs från `not_pending` så klienten kan säga "be om en
    // ny" i stället för "den är redan besvarad" — § Beslut 5.
    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('invitation.expired')
        ->and(ContainerAccess::query()->count())->toBe(0);
});

it('en tillbakadragen inbjudan kan inte accepteras', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    [, $rawToken] = inbjudanMedToken($container, 'ny@exempel.se', ['status' => 'revoked']);
    [, $headers] = mottagare('ny@exempel.se');

    $response = postJson('/api/invitations/accept', ['token' => $rawToken], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('invitation.not_pending')
        ->and(ContainerAccess::query()->count())->toBe(0);
});

it('accept skapar ingen andra access när en giltig redan finns', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    [$invitation, $rawToken] = inbjudanMedToken($container, 'ny@exempel.se', ['level' => 'write']);
    [$user, $headers] = mottagare('ny@exempel.se');

    $befintlig = beviljaAccess($container, $user, 'read', 'member');

    postJson('/api/invitations/accept', ['token' => $rawToken], $headers)->assertOk();

    // Inbjudan är ändå accepterad; svaret är detsamma — § Beslut 8 punkt 2.
    expect(ContainerAccess::query()->count())->toBe(1)
        ->and(ContainerAccess::query()->sole()->id)->toBe($befintlig->id)
        ->and(ContainerAccess::query()->sole()->level)->toBe('read')
        ->and($invitation->fresh()->status)->toBe('accepted');
});

it('en inbjudan kan accepteras även när ägarkontot är read_only', function () {
    $container = Container::factory()
        ->for(Account::factory()->create(['status' => 'read_only']), 'account')
        ->create();
    [, $rawToken] = inbjudanMedToken($container, 'ny@exempel.se');
    [, $headers] = mottagare('ny@exempel.se');

    // § Beslut 11: regel 4 spärrar SKRIVANDE i containern, och det gör
    // policyn redan. Att låsa accept vore en återvändsgränd.
    postJson('/api/invitations/accept', ['token' => $rawToken], $headers)->assertOk();

    expect(ContainerAccess::query()->count())->toBe(1);
});

it('avvisa sätter status rejected och skapar ingen access', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    [$invitation, $rawToken] = inbjudanMedToken($container, 'ny@exempel.se');
    [, $headers] = mottagare('ny@exempel.se');

    postJson('/api/invitations/reject', ['token' => $rawToken], $headers)->assertNoContent();

    expect($invitation->fresh()->status)->toBe('rejected')
        ->and(ContainerAccess::query()->count())->toBe(0);
});

it('avvisa kräver inte verifierad e-post', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    [$invitation, $rawToken] = inbjudanMedToken($container, 'ny@exempel.se');
    [, $headers] = mottagare('ny@exempel.se', verifierad: false);

    // § Beslut 7: att tacka nej ger ingen behörighet, och att tvinga fram
    // en verifiering för att bli av med ett mejl vore fel väg.
    postJson('/api/invitations/reject', ['token' => $rawToken], $headers)->assertNoContent();

    expect($invitation->fresh()->status)->toBe('rejected');
});

it('avvisa av någon annans inbjudan nekas', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    [$invitation, $rawToken] = inbjudanMedToken($container, 'ny@exempel.se');
    [, $headers] = mottagare('nagon.annan@exempel.se');

    $response = postJson('/api/invitations/reject', ['token' => $rawToken], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('invitation.email_mismatch')
        ->and($invitation->fresh()->status)->toBe('pending');
});

it('ett okänt token ger 404', function () {
    [, $headers] = mottagare('ny@exempel.se');

    $response = postJson('/api/invitations/accept', ['token' => Str::random(64)], $headers);

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
});

it('en inbjudan till en mjukraderad container ger 404', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    [, $rawToken] = inbjudanMedToken($container, 'ny@exempel.se');
    [, $headers] = mottagare('ny@exempel.se');

    $container->delete();

    // Invitation::container() går mot en modell med SoftDeletes och ger
    // `null` för en raderad rad — kontrollen är uttrycklig i
    // InvitationTokenRequest, annars blir det en TypeError i stället.
    $response = postJson('/api/invitations/accept', ['token' => $rawToken], $headers);

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found')
        ->and(ContainerAccess::query()->count())->toBe(0);
});

it('oautentiserad accept ger 401', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    [, $rawToken] = inbjudanMedToken($container, 'ny@exempel.se');

    $response = postJson('/api/invitations/accept', ['token' => $rawToken]);

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
});
