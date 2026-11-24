<?php

use App\Console\PrunesExpiredAccountChanges;
use App\Models\EmailChange;
use App\Models\PasswordChange;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;
use function Pest\Laravel\travel;

/*
 * M83 · issue 473. `email_change` och `password_change` blir obrukbara efter
 * en timme eller när de bekräftats, men raden låg annars kvar till personen
 * raderades av DeleteUser — och `email_change` bär den nya adressen i
 * klartext. Se App\Console\PrunesExpiredAccountChanges,
 * [[Registerförteckning]] och [[Konton och åtkomst]] § email_change och
 * § password_change.
 *
 * Hjälparna heter kontoandring* av samma skäl som grannfilernas epost*-namn:
 * Pests funktioner är globala och hela sviten körs i en process.
 */

/** Lösenordet raderna i password_change bär, och som provet löser in. */
function kontoandringNyttLosenord(): string
{
    return 'nytt-losenord';
}

/**
 * En rad i email_change. `$token` är klartexten ur länken när provet behöver
 * lösa in den; annars dras en slump som ingen känner.
 */
function kontoandringEmail(
    User $user,
    Carbon $expiresAt,
    ?string $token = null,
    bool $bekraftad = false,
): EmailChange {
    return EmailChange::query()->create([
        'user_id' => $user->id,
        'new_email' => 'ny-'.str()->random(8).'@example.com',
        'token_hash' => hash('sha256', $token ?? (string) str()->random(64)),
        'expires_at' => $expiresAt,
        'confirmed_at' => $bekraftad ? now() : null,
    ]);
}

/**
 * En rad i password_change. Lösenordet lagras hashat, som
 * App\Actions\Account\RequestPasswordChange gör.
 */
function kontoandringLosenord(
    User $user,
    Carbon $expiresAt,
    ?string $token = null,
    bool $bekraftad = false,
): PasswordChange {
    return PasswordChange::query()->create([
        'user_id' => $user->id,
        'password_hash' => Hash::make(kontoandringNyttLosenord()),
        'token_hash' => hash('sha256', $token ?? (string) str()->random(64)),
        'expires_at' => $expiresAt,
        'confirmed_at' => $bekraftad ? now() : null,
    ]);
}

it('tar bort utgångna och bekräftade rader ur båda tabellerna, men behåller en giltig rad', function () {
    $user = User::factory()->create();

    $utgangenEpost = kontoandringEmail($user, now()->subMinute());
    $bekraftadEpost = kontoandringEmail($user, now()->addHour(), bekraftad: true);
    $giltigEpost = kontoandringEmail($user, now()->addHour());

    $utgangetLosenord = kontoandringLosenord($user, now()->subMinute());
    $bekraftatLosenord = kontoandringLosenord($user, now()->addHour(), bekraftad: true);
    $giltigtLosenord = kontoandringLosenord($user, now()->addHour());

    expect((new PrunesExpiredAccountChanges)->handle())->toBe(4);

    expect(EmailChange::query()->whereKey($utgangenEpost->getKey())->exists())->toBeFalse()
        ->and(EmailChange::query()->whereKey($bekraftadEpost->getKey())->exists())->toBeFalse()
        ->and(EmailChange::query()->whereKey($giltigEpost->getKey())->exists())->toBeTrue()
        ->and(PasswordChange::query()->whereKey($utgangetLosenord->getKey())->exists())->toBeFalse()
        ->and(PasswordChange::query()->whereKey($bekraftatLosenord->getKey())->exists())->toBeFalse()
        ->and(PasswordChange::query()->whereKey($giltigtLosenord->getKey())->exists())->toBeTrue();
});

/*
 * En ny begäran ogiltigförklarar en tidigare obekräftad rad genom att sätta
 * `expires_at` till nu — se RequestEmailChange och RequestPasswordChange.
 * Raden är obrukbar direkt, och jobbet som kör nästa natt ser den som
 * utgången. Provet flyttar klockan en minut för att stå i det läget.
 */
it('tar bort en rad som en ny begäran ogiltigförklarat genom att sätta expires_at till nu', function () {
    $user = User::factory()->create();

    $epost = kontoandringEmail($user, now());
    $losenord = kontoandringLosenord($user, now());

    travel(1)->minutes();

    expect((new PrunesExpiredAccountChanges)->handle())->toBe(2);

    expect(EmailChange::query()->whereKey($epost->getKey())->exists())->toBeFalse()
        ->and(PasswordChange::query()->whereKey($losenord->getKey())->exists())->toBeFalse();
});

/*
 * En rad som fortfarande gäller rörs inte, och det syns bäst i att länken
 * fortfarande går att lösa in efter jobbet: ConfirmEmailChange och
 * ConfirmPasswordChange svarar 404 på en utgången eller bekräftad rad, så en
 * rad som gallrats hade gett 404 i stället för ett genomfört byte.
 */
it('lämnar en rad vars länk gäller kvar, och länken går fortfarande att lösa in', function () {
    $user = User::factory()->create([
        'email' => 'gammal@example.com',
        'password_hash' => 'gammalt-losenord',
    ]);

    $epostToken = str_repeat('e', 64);
    $losenordToken = str_repeat('l', 64);

    $epost = kontoandringEmail($user, now()->addHour(), token: $epostToken);
    $losenord = kontoandringLosenord($user, now()->addHour(), token: $losenordToken);

    expect((new PrunesExpiredAccountChanges)->handle())->toBe(0);

    expect(EmailChange::query()->whereKey($epost->getKey())->exists())->toBeTrue()
        ->and(PasswordChange::query()->whereKey($losenord->getKey())->exists())->toBeTrue();

    actingAs($user);

    get('/settings/profile/email/'.$epostToken)->assertRedirect('/settings/profile');
    expect($user->fresh()->email)->toBe($epost->new_email);

    get('/settings/security/password/'.$losenordToken)->assertRedirect('/settings/security');
    expect(Hash::check(kontoandringNyttLosenord(), $user->fresh()->password_hash))->toBeTrue();
});

it('schemalägger gallringen dagligen, som ett call() och inte ett command()', function () {
    // artisan(...) tvingar konsol-kerneln att bootstrapas, vilket i sin tur
    // laddar routes/console.php — samma grepp som i
    // tests/Feature/Auth/GallraMagicLinkTokensTest.php.
    artisan('inspire');

    $händelse = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'prune-account-changes');

    expect($händelse)->not->toBeNull();
    expect($händelse->getExpression())->toBe('0 0 * * *');

    // Schemalagd som en closure (Schedule::call), inte som ett
    // Artisan-kommando — se AGENTS.md § Subprocesser i driftmiljön.
    expect($händelse->command ?? null)->toBeNull();
});
