<?php

use App\Actions\Account\RequestEmailChange;
use App\Models\EmailChange;
use App\Models\MagicLinkToken;
use App\Models\SecurityLog;
use App\Models\User;
use App\Notifications\EmailChangeConfirmationNotification;
use App\Notifications\EmailChangeRequestedNotification;
use App\Notifications\MagicLinkNotification;
use App\Support\Auth\MagicLinkBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;
use function Pest\Laravel\travel;

/*
 * Issue 130 · E-postadressen går att byta, se [[M20 Kontot]] § 130,
 * App\Http\Controllers\Settings\EmailChangeController,
 * App\Actions\Account\RequestEmailChange, App\Actions\Account\ConfirmEmailChange
 * och [[ADR-0011 Autentisering]].
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 *
 * **Flödet är två anrop och det är hela poängen:** POST begär, GET bekräftar.
 * Adressen byts aldrig i samma steg som den begärs, och därför prövar proven
 * nedan både att begäran lämnar `user.email` orörd och att bara länken i
 * mejlet skriver om den.
 *
 * Hjälparna heter epost* för att inte krocka med de globala i
 * tests/Support/Testhjalpare.php eller med grannfilernas — Pests funktioner
 * är globala och hela sviten körs i en process.
 */

/** Lösenordet hjälparna sätter på kontona, och det kropparna skickar. */
function epostLosenord(): string
{
    return 'ratt-losenord';
}

/**
 * Kroppen en begäran skickar. Fältet heter `new_email` och inte `email` — se
 * App\Http\Requests\Settings\RequestEmailChangeRequest.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function epostKropp(string $nyAdress, array $overrides = []): array
{
    return array_merge([
        'new_email' => $nyAdress,
        'current_password' => epostLosenord(),
    ], $overrides);
}

/**
 * Länken ur mejlet till den nya adressen. Tokenet finns bara där — svaret på
 * POST bär det aldrig.
 */
function epostLänk(string $nyAdress): string
{
    $url = null;

    Notification::assertSentOnDemand(
        EmailChangeConfirmationNotification::class,
        function (EmailChangeConfirmationNotification $notis, array $kanaler, object $mottagare) use (&$url, $nyAdress): bool {
            if (($mottagare->routes['mail'] ?? null) === $nyAdress) {
                $url = $notis->url;
            }

            return true;
        }
    );

    if ($url === null) {
        throw new RuntimeException("Ingen bekräftelselänk hittades för {$nyAdress}.");
    }

    return $url;
}

/**
 * Begär ett byte och returnerar länken ur mejlet. Kastar om begäran inte gick
 * igenom — varje prov som använder hjälparen förväntar sig ett lyckat
 * utskick, och ett tyst misslyckande hade gjort provet grönt av fel skäl.
 */
function epostBegär(User $user, string $nyAdress): string
{
    from('/settings/profile')
        ->post('/settings/profile/email', epostKropp($nyAdress))
        ->assertRedirect('/settings/profile');

    return epostLänk($nyAdress);
}

/**
 * Ett konto med ett lösenord och ingen verifierad adress — bytet ska sätta
 * `email_verified_at`, och det syns bara om den var tom.
 */
function epostKonto(string $email = 'gammal@example.com'): User
{
    return User::factory()->unverified()->create([
        'email' => $email,
        'password_hash' => epostLosenord(),
    ]);
}

// --- Begäran -------------------------------------------------------------

it('byter adressen via länken och sätter email_verified_at', function () {
    Notification::fake();

    $user = epostKonto();
    actingAs($user);

    $länk = epostBegär($user, 'ny@example.com');

    // Begäran har inte rört adressen — det är hela tvåstegsflödet.
    expect($user->fresh()->email)->toBe('gammal@example.com');

    get($länk)
        ->assertRedirect('/settings/profile')
        ->assertSessionHas('status', 'email-changed');

    $user->refresh();

    expect($user->email)->toBe('ny@example.com')
        ->and($user->email_verified_at)->not->toBeNull();

    // Raden är kvittensen: bekräftad, och därmed förbrukad.
    expect(EmailChange::query()->where('user_id', $user->id)->sole()->confirmed_at)->not->toBeNull();
});

it('ändrar inte user.email när begäran tas emot', function () {
    Notification::fake();

    $user = epostKonto();
    actingAs($user);

    epostBegär($user, 'ny@example.com');

    expect($user->fresh()->email)->toBe('gammal@example.com');
    expect(EmailChange::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('avvisar ett fel lösenord, ändrar ingenting och skickar inget mejl', function () {
    Notification::fake();

    $user = epostKonto();
    actingAs($user);

    $svar = from('/settings/profile')->post('/settings/profile/email', epostKropp('ny@example.com', [
        'current_password' => 'fel-losenord',
    ]));

    $svar->assertRedirect('/settings/profile');
    $svar->assertSessionHasErrors('current_password');

    expect($user->fresh()->email)->toBe('gammal@example.com')
        ->and(EmailChange::query()->count())->toBe(0)
        ->and(SecurityLog::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

it('avvisar en adress som redan finns, på fältet new_email', function () {
    Notification::fake();

    User::factory()->create(['email' => 'upptagen@example.com']);

    $user = epostKonto();
    actingAs($user);

    $svar = from('/settings/profile')->post('/settings/profile/email', epostKropp('upptagen@example.com'));

    $svar->assertSessionHasErrors('new_email');

    // Ingen rad skapas: den vanliga kollisionen ska vara ett fältfel och inte
    // ett mejl till en adress som redan har ett konto.
    expect(EmailChange::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('kräver en giltig kod när tvåfaktorn är på', function () {
    Notification::fake();

    [$user, $hemlighet] = användareMedBekräftadTotp();
    actingAs($user);

    // Ingen kod alls.
    $svar = from('/settings/profile')->post('/settings/profile/email', epostKropp('ny@example.com'));

    $svar->assertSessionHasErrors('code');
    expect(session('errors')->get('code')[0])->toBe(trans('auth.totp_required', [], 'en'))
        ->and(EmailChange::query()->count())->toBe(0);

    // Fel kod.
    $svar = from('/settings/profile')->post('/settings/profile/email', epostKropp('ny@example.com', [
        'code' => '000000',
    ]));

    $svar->assertSessionHasErrors('code');
    expect(session('errors')->get('code')[0])->toBe(trans('auth.totp_invalid', [], 'en'))
        ->and(EmailChange::query()->count())->toBe(0);

    // Giltig kod går igenom.
    from('/settings/profile')->post('/settings/profile/email', epostKropp('ny@example.com', [
        'code' => totpKodFör($hemlighet),
    ]))->assertRedirect('/settings/profile');

    expect(EmailChange::query()->count())->toBe(1)
        ->and($user->fresh()->email)->not->toBe('ny@example.com');
});

it('avslöjar inte tvåfaktorn när lösenordet är fel', function () {
    Notification::fake();

    [$user] = användareMedBekräftadTotp();
    actingAs($user);

    $svar = from('/settings/profile')->post('/settings/profile/email', epostKropp('ny@example.com', [
        'current_password' => 'fel-losenord',
    ]));

    $svar->assertSessionHasErrors('current_password');
    $svar->assertSessionDoesntHaveErrors('code');
});

it('låter inte ett konto utan lösenord begära ett byte', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create([
        'email' => 'gammal@example.com',
        'password_hash' => null,
    ]);
    actingAs($user);

    $svar = from('/settings/profile')->post('/settings/profile/email', epostKropp('ny@example.com', [
        'current_password' => 'vad-som-helst',
    ]));

    $svar->assertSessionHasErrors('current_password');

    // Meningen säger vad hon ska göra i stället, och inte att hon angett ett
    // fel lösenord — hon har inget.
    expect(session('errors')->get('current_password')[0])
        ->toBe(trans('ui.settings.profile.email_change.password_first', [], 'en'));

    expect(EmailChange::query()->count())->toBe(0)
        ->and($user->fresh()->email)->toBe('gammal@example.com');

    Notification::assertNothingSent();
});

// --- Länken --------------------------------------------------------------

it('fungerar en gång och inte en andra gång', function () {
    Notification::fake();

    $user = epostKonto();
    actingAs($user);

    $länk = epostBegär($user, 'ny@example.com');

    get($länk)->assertRedirect('/settings/profile');
    get($länk)->assertNotFound();

    expect($user->fresh()->email)->toBe('ny@example.com')
        ->and(EmailChange::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('går ut efter en timme', function () {
    Notification::fake();

    $user = epostKonto();
    actingAs($user);

    $länk = epostBegär($user, 'ny@example.com');

    travel(RequestEmailChange::TTL_MINUTES + 1)->minutes();

    get($länk)->assertNotFound();

    expect($user->fresh()->email)->toBe('gammal@example.com');
});

it('gör den föregående länken ogiltig när en ny begäran görs', function () {
    Notification::fake();

    $user = epostKonto();
    actingAs($user);

    $första = epostBegär($user, 'första@example.com');
    $andra = epostBegär($user, 'andra@example.com');

    // Den gamla raden finns kvar — den ogiltigförklaras, den raderas inte.
    expect(EmailChange::query()->where('user_id', $user->id)->count())->toBe(2);

    get($första)->assertNotFound();
    get($andra)->assertRedirect('/settings/profile');

    expect($user->fresh()->email)->toBe('andra@example.com');
});

it('ger 404 för en annan inloggad användare, lika som för ett okänt token', function () {
    Notification::fake();

    $user = epostKonto();
    $annan = epostKonto('annan@example.com');

    actingAs($user);

    $länk = epostBegär($user, 'ny@example.com');

    // En annan inloggad användare: samma svar som ett token ingen har.
    somAnvandare($annan);
    get($länk)->assertNotFound();

    get('/settings/profile/email/'.Str::random(64))->assertNotFound();

    // Och ingenting hände med någon av kontona.
    expect($user->fresh()->email)->toBe('gammal@example.com')
        ->and($annan->fresh()->email)->toBe('annan@example.com')
        ->and(EmailChange::query()->whereNotNull('confirmed_at')->count())->toBe(0);
});

it('ger ett fel när adressen tagits efter begäran, och ändrar ingenting', function () {
    Notification::fake();

    $user = epostKonto();
    actingAs($user);

    $länk = epostBegär($user, 'ny@example.com');

    // Adressen tas av någon annan under timmen mellan begäran och länken.
    User::factory()->create(['email' => 'ny@example.com']);

    $svar = get($länk);

    $svar->assertRedirect('/settings/profile');
    $svar->assertSessionHasErrors('new_email');
    expect(session('errors')->get('new_email')[0])
        ->toBe(trans('ui.settings.profile.email_change.taken', [], 'en'));

    // Kontot står kvar precis som det var, och raden är inte förbrukad — en
    // adress kan bli ledig igen.
    expect($user->fresh()->email)->toBe('gammal@example.com')
        ->and($user->fresh()->email_verified_at)->toBeNull()
        ->and(EmailChange::query()->where('user_id', $user->id)->sole()->confirmed_at)->toBeNull();
});

it('tar bort magic link-token för den gamla adressen, och länken slutar fungera', function () {
    Notification::fake();

    $user = epostKonto();
    actingAs($user);

    MagicLinkBroker::issue('gammal@example.com');

    $magicLink = null;
    Notification::assertSentTo($user, MagicLinkNotification::class, function (MagicLinkNotification $notis) use (&$magicLink): bool {
        $magicLink = $notis->url;

        return true;
    });

    expect(MagicLinkToken::query()->where('email', 'gammal@example.com')->whereNull('used_at')->count())->toBe(1);

    $länk = epostBegär($user, 'ny@example.com');
    get($länk)->assertRedirect('/settings/profile');

    // Raden är borta, och länken som utfärdades före bytet går inte att lösa
    // in: adressen den var bunden till finns inte längre på kontot.
    expect(MagicLinkToken::query()->where('email', 'gammal@example.com')->count())->toBe(0);

    // Consume-rutten ligger i `guest`-gruppen, så länken prövas av en utloggad
    // besökare — samma väg en riktig mottagare kommer in genom.
    post('/logout');
    get($magicLink)->assertForbidden();

    expect($user->fresh()->email)->toBe('ny@example.com');
});

// --- Mejlen och loggen ---------------------------------------------------

it('skickar ett meddelande till den gamla adressen som inte nämner den nya', function () {
    Notification::fake();

    $user = epostKonto();
    actingAs($user);

    $nyAdress = 'ny@example.com';
    epostBegär($user, $nyAdress);

    $innehåll = null;

    Notification::assertSentTo(
        $user,
        EmailChangeRequestedNotification::class,
        function (EmailChangeRequestedNotification $notis, array $kanaler, object $mottagare) use (&$innehåll, $user): bool {
            // Kanalen är `mail`, alltså den adress kontot har — den gamla.
            $innehåll = (string) $notis->toMail($user)->render();

            return $kanaler === ['mail'];
        }
    );

    // Mejlet nämner inte den nya adressen, och bär ingen länk. Notisen har
    // ingen konstruktor alls — den KAN inte bära adressen vidare.
    expect($innehåll)->not->toContain($nyAdress)
        ->and((new ReflectionClass(EmailChangeRequestedNotification::class))->getConstructor())->toBeNull();

    // Och klockan får ingen rad: båda mejlen är transaktionella utskick.
    expect(DB::table('notification')->count())->toBe(0);
});

it('skickar bekräftelselänken till den nya adressen och varningen till den gamla', function () {
    Notification::fake();

    $user = epostKonto();
    actingAs($user);

    epostBegär($user, 'ny@example.com');

    Notification::assertSentOnDemand(
        EmailChangeConfirmationNotification::class,
        fn (EmailChangeConfirmationNotification $notis, array $kanaler, object $mottagare): bool => ($mottagare->routes['mail'] ?? null) === 'ny@example.com'
            && str_contains($notis->url, '/settings/profile/email/')
    );

    Notification::assertSentTo($user, EmailChangeRequestedNotification::class);
});

it('skriver de två raderna i säkerhetsloggen, utan en adress i meta', function () {
    Notification::fake();

    $gammal = 'gammal@example.com';
    $ny = 'ny@example.com';

    $user = epostKonto($gammal);
    actingAs($user);

    $länk = epostBegär($user, $ny);
    get($länk)->assertRedirect('/settings/profile');

    $begäran = SecurityLog::query()->where('action', SecurityLog::ACTION_EMAIL_CHANGE_REQUESTED)->sole();
    $bytet = SecurityLog::query()->where('action', SecurityLog::ACTION_EMAIL_CHANGED)->sole();

    expect($begäran->meta)->toBe([])
        ->and($bytet->meta)->toBe([])
        ->and($begäran->user_id)->toBe($user->id)
        ->and($bytet->user_id)->toBe($user->id)
        // Ingen annan rad skrevs av de två anropen.
        ->and(SecurityLog::query()->count())->toBe(2);

    // Varken den gamla eller den nya adressen finns i hela tabellen.
    $json = json_encode(SecurityLog::query()->get()->toArray(), JSON_THROW_ON_ERROR);

    expect($json)->not->toContain($gammal)
        ->and($json)->not->toContain($ny);
});

// --- Det som inte fick ändras -------------------------------------------

it('tar fortfarande inte emot email i PATCH /settings/profile', function () {
    $user = epostKonto();
    actingAs($user);

    patch('/settings/profile', [
        'name' => $user->name,
        'locale' => null,
        'timezone' => null,
        'unit_system' => null,
        'email' => 'ny@example.com',
    ])->assertRedirect('/settings/profile');

    expect($user->fresh()->email)->toBe('gammal@example.com')
        ->and(EmailChange::query()->count())->toBe(0);
});

it('skickar en utloggad besökare till inloggningen', function () {
    post('/settings/profile/email', epostKropp('ny@example.com'))->assertRedirect('/login');
    get('/settings/profile/email/'.Str::random(64))->assertRedirect('/login');
});

/*
 * Takgränsen: inloggningens egen begränsare, som på lösenordsbytet — kroppen
 * prövar ett lösenord och en engångskod, alltså inloggningens två gissningsbara
 * värden. Fem försök med fel lösenord släpps igenom till valideringen; det
 * sjätte stoppas.
 *
 * Fältet felet hamnar på är `email` — begränsaren är inloggningens och vet
 * inget om det här formulärets fält. Middlewaret
 * (App\Support\Auth\BindsPasswordChangeThrottleToUser) sätter det till
 * användarens EGEN adress ur sessionen, så hinken är kontots; utan det hade
 * nyckeln varit tom och blivit en hink hela installationen delade.
 */
it('har inloggningens takgräns', function () {
    Notification::fake();

    $user = epostKonto();
    actingAs($user);

    for ($i = 0; $i < 5; $i++) {
        from('/settings/profile')
            ->post('/settings/profile/email', epostKropp('ny@example.com', ['current_password' => 'fel-losenord']))
            ->assertSessionHasErrors('current_password');
    }

    $svar = from('/settings/profile')->post('/settings/profile/email', epostKropp('ny@example.com'));

    $svar->assertSessionHasErrors('email');

    // Meningen och inte nyckeln: `auth.throttle` med antalet sekunder i.
    // Sekunderna LÄSES ur meningen och pinnas inte till 60. Takgränsen räknar
    // hela sekunder — `availableIn()` är `timer − nu`, båda trunkerade — så ett
    // prov vars sex anrop korsar en sekundgräns får 59 och faller på en
    // kapplöpning i stället för på ett fel. Samma form och samma skäl som
    // tests/Feature/Frontend/TakgransTest.php och syskonprovet i
    // tests/Feature/Auth/LosenordsbyteTest.php.
    $mening = session('errors')->get('email')[0];
    preg_match('/(\d+)/', $mening, $träff);

    expect($träff)->not->toBeEmpty('meddelandet saknar antal sekunder')
        ->and((int) $träff[0])->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and($mening)->toBe(trans('auth.throttle', ['seconds' => (int) $träff[0]], 'en'));

    // Det sjätte försöket nådde aldrig valideringen: ingen rad skrevs.
    expect(EmailChange::query()->count())->toBe(0);
});
