<?php

use App\Actions\Account\RequestPasswordChange;
use App\Models\PasswordChange;
use App\Models\SecurityLog;
use App\Models\TotpRecoveryCode;
use App\Models\User;
use App\Notifications\PasswordChangeConfirmationNotification;
use App\Notifications\PasswordChangedNotification;
use App\Support\Auth\RecoveryCodeBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;
use function Pest\Laravel\travel;
use function Pest\Laravel\withCookie;

/*
 * Issue 140 · Lösenordsbytet bekräftas via mejl, se [[M20 Kontot]] § 140,
 * App\Http\Controllers\Settings\PasswordController,
 * App\Http\Requests\Settings\UpdatePasswordRequest,
 * App\Actions\Account\RequestPasswordChange,
 * App\Actions\Account\ConfirmPasswordChange och
 * [[ADR-0011 Autentisering]] § Uppföljning 2026-09-26.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 *
 * **Flödet är två anrop och det är hela poängen:** PUT begär, GET bekräftar.
 * Lösenordet byts aldrig i samma steg som det begärs, och därför prövar proven
 * nedan både att begäran lämnar `password_hash` orörd och att bara länken i
 * mejlet skriver om den.
 *
 * Hjälparna heter losenords* för att inte krocka med de globala i
 * tests/Support/Testhjalpare.php eller med grannfilernas — Pests funktioner
 * är globala och hela sviten körs i en process.
 */

/**
 * Kroppen en begäran skickar: det nya lösenordet och dess bekräftelse. Koden
 * läggs till av det enskilda provet, för det är den som skiljer kontona med
 * tvåfaktor åt.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function losenordsKropp(array $overrides = []): array
{
    return array_merge([
        'password' => 'nytt-losenord-2026',
        'password_confirmation' => 'nytt-losenord-2026',
    ], $overrides);
}

/**
 * Länken ur mejlet till kontots egen adress. Tokenet finns bara där — svaret
 * på PUT bär det aldrig.
 */
function losenordsLänk(User $user): string
{
    $url = null;

    Notification::assertSentTo(
        $user,
        PasswordChangeConfirmationNotification::class,
        function (PasswordChangeConfirmationNotification $notis) use (&$url): bool {
            $url = $notis->url;

            return true;
        }
    );

    if ($url === null) {
        throw new RuntimeException('Ingen bekräftelselänk hittades.');
    }

    return $url;
}

/**
 * Begär ett byte och returnerar länken ur mejlet. Kastar om begäran inte gick
 * igenom — varje prov som använder hjälparen förväntar sig ett lyckat utskick,
 * och ett tyst misslyckande hade gjort provet grönt av fel skäl.
 *
 * @param  array<string, string>  $overrides
 */
function losenordsBegär(User $user, array $overrides = []): string
{
    from('/settings/security')
        ->put('/settings/security/password', losenordsKropp($overrides))
        ->assertRedirect('/settings/security')
        ->assertSessionHas('status', 'password-change-requested');

    return losenordsLänk($user);
}

/**
 * Ett konto med bekräftad tvåfaktor men UTAN lösenord — den som bara använt
 * magic link. användareMedBekräftadTotp() i Testhjalpare sätter alltid ett
 * lösenord, och skillnaden är hela poängen med det här provet.
 *
 * @return array{0: User, 1: string}
 */
function losenordsTotpUtanLosenord(): array
{
    $user = User::factory()->create(['password_hash' => null]);

    $secret = (new Google2FA)->generateSecretKey();
    $user->totp_secret = $secret;
    $user->totp_confirmed_at = now();
    $user->save();

    return [$user, $secret];
}

// --- Begäran -------------------------------------------------------------

it('byter inte lösenordet när begäran tas emot', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'gammalt-losenord']);
    actingAs($user);

    losenordsBegär($user);

    // Hashen står kvar: begäran skickar bara mejlet, och bytet sker först när
    // länken öppnas. Det är hela tvåstegsflödet.
    expect(Hash::check('gammalt-losenord', $user->fresh()->password_hash))->toBeTrue()
        ->and(PasswordChange::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('kräver inget nuvarande lösenord för ett konto som har ett', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'gammalt-losenord']);
    actingAs($user);

    // Ingen `current_password` i kroppen alls — fältet finns inte i reglerna
    // längre, och kravet var det som stängde vägen ut för den som glömt sitt.
    $svar = from('/settings/security')->put('/settings/security/password', losenordsKropp());

    $svar->assertRedirect('/settings/security');
    $svar->assertSessionHas('status', 'password-change-requested');
    $svar->assertSessionDoesntHaveErrors();
});

it('ignorerar ett nuvarande lösenord som skickas med och är fel', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'gammalt-losenord']);
    actingAs($user);

    // Motprov mot en "optional"-regel: fältet är inte prövat alls, så ett fel
    // värde fäller ingenting. Är regeln kvar någonstans faller provet här.
    from('/settings/security')
        ->put('/settings/security/password', losenordsKropp(['current_password' => 'fel-losenord']))
        ->assertRedirect('/settings/security')
        ->assertSessionDoesntHaveErrors();
});

it('sätter ett första lösenord för ett konto som bara använt magic link', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => null]);
    actingAs($user);

    $länk = losenordsBegär($user);

    // Begäran rör ingenting, allra minst den tomma kolumnen.
    expect($user->fresh()->password_hash)->toBeNull();

    get($länk)
        ->assertRedirect('/settings/security')
        ->assertSessionHas('status', 'password-changed');

    expect(Hash::check('nytt-losenord-2026', $user->fresh()->password_hash))->toBeTrue();

    // `meta` säger att ett lösenord inte fanns förut — det enda raden får bära.
    expect(SecurityLog::query()->where('action', SecurityLog::ACTION_PASSWORD_CHANGE_REQUESTED)->sole()->meta)
        ->toBe(['had_password' => false])
        ->and(SecurityLog::query()->where('action', SecurityLog::ACTION_PASSWORD_CHANGED)->sole()->meta)
        ->toBe(['had_password' => false]);
});

it('validerar det nya lösenordet med registreringens regel och kräver bekräftelsen', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => null]);
    actingAs($user);

    // Kortare än åtta tecken: samma `Password::defaults()` som
    // RegisterRequest, alltså samma svar som registreringen ger.
    from('/settings/security')
        ->put('/settings/security/password', losenordsKropp([
            'password' => 'kort',
            'password_confirmation' => 'kort',
        ]))
        ->assertSessionHasErrors('password');

    // Bekräftelsen saknas.
    from('/settings/security')
        ->put('/settings/security/password', [
            'password' => 'nytt-losenord-2026',
            'password_confirmation' => 'nagot-annat-2026',
        ])
        ->assertSessionHasErrors('password');

    expect($user->fresh()->password_hash)->toBeNull()
        ->and(PasswordChange::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

// --- Tvåfaktorn ----------------------------------------------------------

it('kräver en kod när tvåfaktorn är på', function () {
    Notification::fake();

    [$user] = användareMedBekräftadTotp();
    actingAs($user);

    $svar = from('/settings/security')->put('/settings/security/password', losenordsKropp());

    $svar->assertSessionHasErrors('code');

    // Meningen, inte nyckeln — samma fältfel som inloggningen ger.
    expect(session('errors')->get('code')[0])->toBe(trans('auth.totp_required', [], 'en'));

    expect(Hash::check('ratt-losenord', $user->fresh()->password_hash))->toBeTrue()
        ->and(PasswordChange::query()->count())->toBe(0);
});

it('kräver en kod när tvåfaktorn är på och kontot saknar lösenord', function () {
    Notification::fake();

    [$user] = losenordsTotpUtanLosenord();
    actingAs($user);

    $svar = from('/settings/security')->put('/settings/security/password', losenordsKropp());

    $svar->assertSessionHasErrors('code');
    expect(session('errors')->get('code')[0])->toBe(trans('auth.totp_required', [], 'en'));

    expect($user->fresh()->password_hash)->toBeNull();
});

it('avvisar en fel kod och ändrar ingenting', function () {
    Notification::fake();

    [$user] = användareMedBekräftadTotp();
    actingAs($user);

    $svar = from('/settings/security')->put('/settings/security/password', losenordsKropp([
        'code' => '000000',
    ]));

    $svar->assertSessionHasErrors('code');
    expect(session('errors')->get('code')[0])->toBe(trans('auth.totp_invalid', [], 'en'));

    expect(Hash::check('ratt-losenord', $user->fresh()->password_hash))->toBeTrue()
        ->and(PasswordChange::query()->count())->toBe(0);
});

it('godtar en återställningskod och förbrukar den', function () {
    Notification::fake();

    [$user] = användareMedBekräftadTotp();
    $koder = RecoveryCodeBroker::generate($user);

    actingAs($user);

    from('/settings/security')->put('/settings/security/password', losenordsKropp([
        'code' => $koder[0],
    ]))->assertRedirect('/settings/security');

    expect(PasswordChange::query()->where('user_id', $user->id)->count())->toBe(1)
        // Nio kvar: koden är prövad genom TwoFactorChallenge, som förbrukar
        // den — ingen egen kopia av kontrollen finns här.
        ->and(TotpRecoveryCode::query()->where('user_id', $user->id)->whereNull('used_at')->count())->toBe(9)
        // Och lösenordet står kvar: begäran byter ingenting.
        ->and(Hash::check('ratt-losenord', $user->fresh()->password_hash))->toBeTrue();
});

// --- Bekräftelsen --------------------------------------------------------

it('sätter det nya lösenordet via länken och användaren kan logga in med det', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'gammalt-losenord']);
    actingAs($user);

    $länk = losenordsBegär($user);

    get($länk)
        ->assertRedirect('/settings/security')
        ->assertSessionHas('status', 'password-changed');

    // **Hashen skrivs som hash.** `User::$casts['password_hash']` är `hashed`,
    // och castet hashar inte om ett värde som redan är en hash. Vore värdet
    // dubbelhashat hade `Hash::check()` fallerat — och inloggningen nedan med.
    expect(Hash::check('nytt-losenord-2026', $user->fresh()->password_hash))->toBeTrue()
        ->and(Hash::check('gammalt-losenord', $user->fresh()->password_hash))->toBeFalse();

    // Och vägen in fungerar: en riktig inloggning med det nya lösenordet.
    auth()->forgetGuards();

    post('/login', ['email' => $user->email, 'password' => 'nytt-losenord-2026'])
        ->assertRedirect();

    assertAuthenticatedAs($user->fresh());

    // Raden är kvittensen: bekräftad, och därmed förbrukad.
    expect(PasswordChange::query()->where('user_id', $user->id)->sole()->confirmed_at)->not->toBeNull();
});

it('fungerar en gång och inte en andra gång', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'gammalt-losenord']);
    actingAs($user);

    $länk = losenordsBegär($user);

    get($länk)->assertRedirect('/settings/security');
    get($länk)->assertNotFound();

    expect($user->fresh()->password_hash)->not->toBeNull()
        ->and(PasswordChange::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('går ut efter en timme', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'gammalt-losenord']);
    actingAs($user);

    $länk = losenordsBegär($user);

    travel(RequestPasswordChange::TTL_MINUTES + 1)->minutes();

    get($länk)->assertNotFound();

    expect(Hash::check('gammalt-losenord', $user->fresh()->password_hash))->toBeTrue();
});

it('gör den tidigare länken ogiltig när en ny begäran tas emot', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'gammalt-losenord']);
    actingAs($user);

    $första = losenordsBegär($user);
    $andra = losenordsBegär($user);

    get($första)->assertNotFound();

    expect(Hash::check('gammalt-losenord', $user->fresh()->password_hash))->toBeTrue();

    get($andra)->assertRedirect('/settings/security');

    expect(Hash::check('nytt-losenord-2026', $user->fresh()->password_hash))->toBeTrue();
});

it('ger en annan inloggad användare 404 och ändrar ingenting', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'gammalt-losenord']);
    $annan = User::factory()->create(['password_hash' => 'annans-losenord']);

    actingAs($user);
    $länk = losenordsBegär($user);

    somAnvandare($annan);

    get($länk)->assertNotFound();

    expect(Hash::check('gammalt-losenord', $user->fresh()->password_hash))->toBeTrue()
        ->and(Hash::check('annans-losenord', $annan->fresh()->password_hash))->toBeTrue()
        ->and(PasswordChange::query()->where('user_id', $user->id)->sole()->confirmed_at)->toBeNull();
});

/*
 * Ett okänt token ger samma svar som ett främmande, utgånget eller förbrukat:
 * `404` och ingenting annat. Skillnaden får inte synas — en gissad sträng ska
 * inte kunna skiljas från en utgången.
 */
it('ger 404 för ett okänt token', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'gammalt-losenord']);
    actingAs($user);

    get('/settings/security/password/'.str_repeat('x', 64))->assertNotFound();

    expect(Hash::check('gammalt-losenord', $user->fresh()->password_hash))->toBeTrue();
});

/*
 * Sessionerna: kontots ÖVRIGA webbsessioner ska vara borta, den egna ha ett
 * nytt id, och alla Sanctum-token bort. `SESSION_DRIVER` är `array` i sviten
 * (phpunit.xml), så raderna i `sessions` fylls på för hand — raderingen är
 * densamma, den går mot tabellen oavsett vilken drivrutin som skriver den.
 *
 * Den egna sessionen skickas in med sin kaka, så `regenerate()` har något att
 * byta ut: svaret bär ett annat id än det som kom in, och den gamla raden är
 * borta med de andra.
 */
it('loggar ut övriga sessioner, ger den egna ett nytt id och tar bort alla token', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'gammalt-losenord']);
    $annan = User::factory()->create(['password_hash' => 'gammalt-losenord']);

    $user->createToken('mobil');
    $user->createToken('integration');
    $annan->createToken('annans');

    // Sessionsid:n är fyrtio alfanumeriska tecken och ingenting annat:
    // `Store::setId()` förkastar allt annat och drar ett eget, och då hade
    // kakan nedan varit utan verkan (se Store::isValidId()).
    $egenGammal = str_repeat('e', 40);
    $annanEnhet = str_repeat('d', 40);
    $annansSession = str_repeat('a', 40);

    foreach ([
        ['id' => $egenGammal, 'user_id' => $user->id],
        ['id' => $annanEnhet, 'user_id' => $user->id],
        ['id' => $annansSession, 'user_id' => $annan->id],
    ] as $rad) {
        DB::table('sessions')->insert($rad + ['payload' => '', 'last_activity' => now()->timestamp]);
    }

    withCookie(config('session.cookie'), $egenGammal);
    actingAs($user);

    $länk = losenordsBegär($user);

    get($länk)->assertRedirect('/settings/security');

    // Den egna gamla raden och den andra enhetens rad är borta.
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)

        // En annan användares session rörs inte.
        ->and(DB::table('sessions')->where('id', $annansSession)->exists())->toBeTrue()

        // Alla kontots token är borta, och bara kontots.
        ->and($user->tokens()->count())->toBe(0)
        ->and($annan->tokens()->count())->toBe(1)

        // Och den egna sessionen har bytt id: requesten kom in med
        // `$egenGammal` och lämnar med ett annat.
        ->and(session()->getId())->not->toBe($egenGammal);
});

// --- Mejlen och loggen ---------------------------------------------------

it('mejlar bekräftelselänken till kontots adress och kvittensen först efter bekräftelsen', function () {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'agaren@example.com',
        'password_hash' => 'gammalt-losenord',
    ]);
    actingAs($user);

    $länk = losenordsBegär($user);

    Notification::assertSentTo(
        $user,
        PasswordChangeConfirmationNotification::class,
        // Adressen är användarens: notisen bär ingen mottagare själv, och
        // kanalen är `mail` — alltså den adress kontot har.
        fn (PasswordChangeConfirmationNotification $notis): bool => $notis->via($user) === ['mail']
    );

    // Kvittensen har inte gått ut: lösenordet är inte bytt än.
    Notification::assertNotSentTo($user, PasswordChangedNotification::class);

    get($länk)->assertRedirect('/settings/security');

    Notification::assertSentTo($user, PasswordChangedNotification::class);

    // Transaktionella utskick: ingen rad i `notification`.
    expect(DB::table('notification')->count())->toBe(0);
});

/*
 * Mejlet renderas på riktigt och inte bara fångas av faken: `toMail()` körs
 * aldrig när notisen är faked, så en nyckel som saknas i `notiser.php` hade
 * gått tyst förbi. Samma form som EpostbyteTest:s motsvarande prov.
 */
it('renderar bekräftelsemejlet med länken och löftet att ingenting ändras', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'gammalt-losenord']);
    actingAs($user);

    $länk = losenordsBegär($user);

    $innehåll = null;
    $ämne = null;

    Notification::assertSentTo(
        $user,
        PasswordChangeConfirmationNotification::class,
        function (PasswordChangeConfirmationNotification $notis, array $kanaler, object $mottagare) use (&$innehåll, &$ämne, $user): bool {
            $meddelande = $notis->toMail($user);

            $ämne = $meddelande->subject;
            $innehåll = (string) $meddelande->render();

            // Kanalen är `mail`, alltså den adress kontot har.
            return $kanaler === ['mail'] && $mottagare->is($user);
        }
    );

    // Länken står i mejlet — det är den ENDA plats klartext-tokenet finns.
    expect($innehåll)->toContain($länk)
        ->and($innehåll)->toContain(trans('notiser.password_change.confirm.action', [], 'en'))
        // Löftet flödet vilar på: den som tror att formuläret redan bytt
        // lösenordet stänger mejlet och står utan väg in.
        ->and($innehåll)->toContain('nothing changes')
        // Ämnesraden står inte i kroppen och prövas för sig.
        ->and($ämne)->toBe(trans('notiser.password_change.confirm.subject', [], 'en'))
        ->and($ämne)->not->toBe('notiser.password_change.confirm.subject');
});

it('skickar inget mejl när begäran avvisas', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);
    actingAs($user);

    from('/settings/security')->put('/settings/security/password', losenordsKropp([
        'password' => 'kort',
        'password_confirmation' => 'kort',
    ]));

    Notification::assertNothingSent();
});

it('skriver en rad per begäran och en per bekräftelse, utan hemligheter i meta', function () {
    Notification::fake();

    [$user, $secret] = användareMedBekräftadTotp();
    $nytt = 'hemligt-nytt-losenord-9911';
    $gammalt = 'ratt-losenord';

    actingAs($user);

    $kod = totpKodFör($secret);

    $länk = losenordsBegär($user, [
        'password' => $nytt,
        'password_confirmation' => $nytt,
        'code' => $kod,
    ]);

    get($länk)->assertRedirect('/settings/security');

    $begäran = SecurityLog::query()->where('action', SecurityLog::ACTION_PASSWORD_CHANGE_REQUESTED)->get();
    $bytet = SecurityLog::query()->where('action', SecurityLog::ACTION_PASSWORD_CHANGED)->get();

    expect($begäran)->toHaveCount(1)
        ->and($begäran->first()->meta)->toBe(['had_password' => true])
        ->and($begäran->first()->user_id)->toBe($user->id)
        ->and($bytet)->toHaveCount(1)
        ->and($bytet->first()->meta)->toBe(['had_password' => true])
        ->and($bytet->first()->user_id)->toBe($user->id)
        // Ingen annan rad skrevs av flödet.
        ->and(SecurityLog::query()->count())->toBe(2);

    // Varken lösenorden, koden, tokenet eller hashen finns i någon rad.
    $json = json_encode(SecurityLog::query()->get()->toArray(), JSON_THROW_ON_ERROR);
    $hash = PasswordChange::query()->where('user_id', $user->id)->sole()->password_hash;

    expect($json)->not->toContain($nytt)
        ->and($json)->not->toContain($gammalt)
        ->and($json)->not->toContain($kod)
        ->and($json)->not->toContain($hash)
        ->and($json)->not->toContain(basename($länk))
        ->and($hash)->not->toBeEmpty();
});

it('lagrar aldrig lösenordet i klartext', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'gammalt-losenord']);
    actingAs($user);

    $hemligt = 'hemligt-nytt-losenord-4471';

    $länk = losenordsBegär($user, [
        'password' => $hemligt,
        'password_confirmation' => $hemligt,
    ]);

    $rad = PasswordChange::query()->where('user_id', $user->id)->sole();
    $json = json_encode($rad->getAttributes(), JSON_THROW_ON_ERROR);

    expect($json)->not->toContain($hemligt)
        // Länkens klartext finns inte heller i raden, bara dess SHA-256.
        ->and($json)->not->toContain(basename($länk))
        // Och det som står där är en hash av det nya lösenordet.
        ->and(Hash::check($hemligt, $rad->password_hash))->toBeTrue();
});

// --- Takgränsen ----------------------------------------------------------

/*
 * Inloggningens egen begränsare — samma namn och samma trösklar som
 * inloggningen (5/minut per kontonyckel och 10/minut per IP), se
 * App\Support\Auth\LoginRateLimiter. Sedan issue 140 finns inget nuvarande
 * lösenord att gissa; kvar att gissa är engångskoden, och formuläret möts av
 * samma tak som förut (issuens flödespunkt 1).
 *
 * Kontonyckeln sätts av App\Support\Auth\BindsPasswordChangeThrottleToUser ur
 * den inloggade användarens adress och inte ur kroppen — rutten bär ingen
 * adress, och utan middlewaret hade nyckeln varit tom och blivit en hink hela
 * installationen delade.
 *
 * Webben svarar en omdirigering med ett formulärfel i stället för en tom
 * 429-sida (bootstrap/app.php, issue 53a § Beslut 6). Fältet felet hamnar på
 * är `email` — begränsaren är inloggningens och vet inget om det här
 * formulärets fält. PasswordForm.vue ritar det därför för hela formuläret,
 * se tests/Feature/Frontend/SakerhetsvyTest.php.
 */
it('har inloggningens takgräns', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);
    actingAs($user);

    // Ett för kort lösenord fälls av valideringen och skriver ingen rad — men
    // anropet räknas, precis som ett gissningsförsök gjorde förut.
    for ($i = 0; $i < 5; $i++) {
        from('/settings/security')
            ->put('/settings/security/password', losenordsKropp([
                'password' => 'kort',
                'password_confirmation' => 'kort',
            ]))
            ->assertSessionHasErrors('password');
    }

    $svar = from('/settings/security')->put('/settings/security/password', losenordsKropp());

    $svar->assertRedirect('/settings/security');
    $svar->assertSessionHasErrors('email');

    // Meningen och inte nyckeln: `auth.throttle` med antalet sekunder i.
    // Sekunderna läses ur meningen och pinnas inte till 60 — takgränsen räknar
    // hela sekunder, så ett prov vars sex anrop korsar en sekundgräns får 59.
    $mening = session('errors')->get('email')[0];
    preg_match('/(\d+)/', $mening, $träff);

    expect($träff)->not->toBeEmpty('meddelandet saknar antal sekunder')
        ->and((int) $träff[0])->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and($mening)->toBe(trans('auth.throttle', ['seconds' => (int) $träff[0]], 'en'));

    // Det sjätte försöket nådde aldrig fram: ingen rad skrevs.
    expect(PasswordChange::query()->count())->toBe(0);
});

it('skickar en utloggad besökare till inloggningen', function () {
    put('/settings/security/password', losenordsKropp())->assertRedirect('/login');
});

/*
 * Nyckeln är kontots och inte en tom sträng. Två konton har var sin hink, och
 * den enes förbrukade budget stänger inte den andres byte — utan middlewaret
 * hade båda delat samma tomma nyckel och den andra användaren mötts av en 429.
 */
it('throttlar varje konto för sig', function () {
    Notification::fake();

    $en = User::factory()->create(['password_hash' => 'ratt-losenord']);
    $annan = User::factory()->create(['password_hash' => 'ratt-losenord']);

    actingAs($en);

    for ($i = 0; $i < 5; $i++) {
        from('/settings/security')->put('/settings/security/password', losenordsKropp([
            'password' => 'kort',
            'password_confirmation' => 'kort',
        ]))->assertSessionHasErrors('password');
    }

    // Den förstas hink är tömd: det sjätte försöket stoppas av begränsaren.
    from('/settings/security')->put('/settings/security/password', losenordsKropp([
        'password' => 'kort',
        'password_confirmation' => 'kort',
    ]))->assertSessionHasErrors('email');

    // Den andra har sin egen hink och når hela vägen till valideringen.
    somAnvandare($annan);

    from('/settings/security')->put('/settings/security/password', losenordsKropp([
        'password' => 'kort',
        'password_confirmation' => 'kort',
    ]))->assertSessionHasErrors('password');
});

/*
 * Ett konto, en hink. Att gissa engångskoden i bytesformuläret är samma
 * angrepp som att gissa ett lösenord vid inloggning och ska räknas mot samma
 * gräns ([[ADR-0011 Autentisering]]). Nyckeln är därför användarens
 * e-postadress och inte hennes id.
 *
 * Riktningen är den omvända av provet ovanför: fem misslyckade inloggningar
 * tömmer kontots hink, och bytet stoppas av den — IP-nyckeln är långt ifrån
 * tömd (sex anrop av tio), så det är kontonyckeln som prövas.
 */
it('räknar lösenordsbytet och inloggningen mot samma hink', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

    for ($i = 0; $i < 5; $i++) {
        from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'fel-losenord',
        ]);
    }

    actingAs($user);

    $svar = from('/settings/security')->put('/settings/security/password', losenordsKropp());

    $svar->assertSessionHasErrors('email');

    // Meningen och inte nyckeln: `auth.throttle` med antalet sekunder i.
    // Sekunderna läses ur meningen — fönstret har redan tickat ett steg när
    // bytet görs, så 60 är inte givet.
    $mening = session('errors')->get('email')[0];
    preg_match('/(\d+)/', $mening, $träff);

    expect($träff)->not->toBeEmpty('meddelandet saknar antal sekunder')
        ->and($mening)->toBe(trans('auth.throttle', ['seconds' => (int) $träff[0]], 'en'));

    // Det takgränsade försöket nådde aldrig fram.
    expect(PasswordChange::query()->count())->toBe(0);
});

/*
 * Vid en 429 ligger gamla inmatningen i sessionen (bootstrap/app.php). Ingen
 * av lösenordsbytets känsliga fält får finnas där. `code` är med som motprov:
 * det är lösenordsfälten som undantas, inte hela kroppen.
 */
it('lämnar inga lösenordsfält i gamla inmatningen vid en 429', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);
    actingAs($user);

    for ($i = 0; $i < 5; $i++) {
        from('/settings/security')->put('/settings/security/password', losenordsKropp([
            'password' => 'kort',
            'password_confirmation' => 'kort',
        ]));
    }

    $svar = from('/settings/security')->put('/settings/security/password', [
        'password' => 'nytt-hemligt-9930',
        'password_confirmation' => 'nytt-hemligt-9930',
        'code' => '123456',
    ]);

    $svar->assertSessionHasErrors('email');

    $svar->assertSessionMissingInput('password');
    $svar->assertSessionMissingInput('password_confirmation');

    // Motprovet: ett fält som inte är ett lösenord fylls i igen.
    $svar->assertSessionHasInput('code', '123456');
});
