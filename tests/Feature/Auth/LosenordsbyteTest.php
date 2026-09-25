<?php

use App\Models\SecurityLog;
use App\Models\TotpRecoveryCode;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Support\Auth\RecoveryCodeBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\put;
use function Pest\Laravel\withCookie;

/*
 * Issue 129 · Lösenordet går att byta, se [[M20 Kontot]] § 129,
 * App\Http\Controllers\Settings\PasswordController,
 * App\Http\Requests\Settings\UpdatePasswordRequest och
 * [[ADR-0011 Autentisering]].
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 *
 * Hjälparna heter losenords* för att inte krocka med de globala i
 * tests/Support/Testhjalpare.php eller med grannfilernas — Pests funktioner
 * är globala och hela sviten körs i en process.
 */

/**
 * Kroppen ett komplett byte skickar: det nya lösenordet och dess bekräftelse.
 * Det nuvarande och koden läggs till av det enskilda provet, för det är de
 * två som skiljer lägena åt.
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

it('byter lösenordet för ett konto som anger sitt nuvarande', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'gammalt-losenord']);
    actingAs($user);

    $svar = from('/settings/security')->put('/settings/security/password', losenordsKropp([
        'current_password' => 'gammalt-losenord',
        // Formulärets kodfält finns med även utan tvåfaktor, och är tomt.
        'code' => '',
    ]));

    $svar->assertRedirect('/settings/security');
    $svar->assertSessionHas('status', 'password-changed');

    expect(Hash::check('nytt-losenord-2026', $user->fresh()->password_hash))->toBeTrue()
        ->and(Hash::check('gammalt-losenord', $user->fresh()->password_hash))->toBeFalse();
});

it('avvisar ett fel nuvarande lösenord och ändrar ingenting', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'gammalt-losenord']);
    $user->createToken('api');
    actingAs($user);

    $svar = from('/settings/security')->put('/settings/security/password', losenordsKropp([
        'current_password' => 'fel-losenord',
    ]));

    $svar->assertRedirect('/settings/security');
    $svar->assertSessionHasErrors('current_password');

    // Ingenting rört: lösenordet står kvar, tokenet finns kvar, och ingen
    // rad skrevs i säkerhetsloggen — ett gissningsförsök är inte ett byte.
    expect(Hash::check('gammalt-losenord', $user->fresh()->password_hash))->toBeTrue()
        ->and($user->tokens()->count())->toBe(1)
        ->and(SecurityLog::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

it('kräver det nuvarande lösenordet när kontot har ett', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'gammalt-losenord']);
    actingAs($user);

    // Ingen `current_password` alls: regeln är `requiredIf` och läser kontot,
    // inte ett fält i kroppen.
    $svar = from('/settings/security')->put('/settings/security/password', losenordsKropp());

    $svar->assertSessionHasErrors('current_password');
    expect(Hash::check('gammalt-losenord', $user->fresh()->password_hash))->toBeTrue();
});

it('sätter ett första lösenord för ett konto som bara använt magic link', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => null]);
    actingAs($user);

    // Exakt den kropp PasswordForm.vue skickar i det här läget: fälten finns
    // med, men tomma. Ett tomt fält blir null (ConvertEmptyStringsToNull),
    // och requesten måste tåla både det och att fältet saknas helt.
    $svar = from('/settings/security')->put('/settings/security/password', losenordsKropp([
        'current_password' => '',
        'code' => '',
    ]));

    $svar->assertRedirect('/settings/security');
    $svar->assertSessionHas('status', 'password-changed');

    expect(Hash::check('nytt-losenord-2026', $user->fresh()->password_hash))->toBeTrue();

    // `meta` säger att ett lösenord inte fanns förut — det enda raden får bära.
    expect(SecurityLog::query()->where('action', SecurityLog::ACTION_PASSWORD_CHANGED)->sole()->meta)
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

    expect($user->fresh()->password_hash)->toBeNull();
});

it('kräver en kod när tvåfaktorn är på, också med ett nuvarande lösenord', function () {
    Notification::fake();

    [$user] = användareMedBekräftadTotp();
    actingAs($user);

    $svar = from('/settings/security')->put('/settings/security/password', losenordsKropp([
        'current_password' => 'ratt-losenord',
    ]));

    $svar->assertSessionHasErrors('code');

    // Meningen, inte nyckeln — samma fältfel som inloggningen ger.
    expect(session('errors')->get('code')[0])->toBe(trans('auth.totp_required', [], 'en'));

    expect(Hash::check('ratt-losenord', $user->fresh()->password_hash))->toBeTrue();
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
        'current_password' => 'ratt-losenord',
        'code' => '000000',
    ]));

    $svar->assertSessionHasErrors('code');
    expect(session('errors')->get('code')[0])->toBe(trans('auth.totp_invalid', [], 'en'));

    expect(Hash::check('ratt-losenord', $user->fresh()->password_hash))->toBeTrue();
});

/*
 * Ett fel nuvarande lösenord får aldrig avslöja om kontot har tvåfaktor
 * påslagen — samma ordning som LoginRequest::authenticate(): lösenordet
 * först. Provet är att felet hamnar på `current_password` och att `code`
 * inte får något alls, trots att ingen kod skickades.
 */
it('avslöjar inte tvåfaktorn när det nuvarande lösenordet är fel', function () {
    Notification::fake();

    [$user] = användareMedBekräftadTotp();
    actingAs($user);

    $svar = from('/settings/security')->put('/settings/security/password', losenordsKropp([
        'current_password' => 'fel-losenord',
    ]));

    $svar->assertSessionHasErrors('current_password');
    $svar->assertSessionDoesntHaveErrors('code');

    expect(Hash::check('ratt-losenord', $user->fresh()->password_hash))->toBeTrue();
});

it('godtar en återställningskod och förbrukar den', function () {
    Notification::fake();

    [$user] = användareMedBekräftadTotp();
    $koder = RecoveryCodeBroker::generate($user);

    actingAs($user);

    from('/settings/security')->put('/settings/security/password', losenordsKropp([
        'current_password' => 'ratt-losenord',
        'code' => $koder[0],
    ]))->assertRedirect('/settings/security');

    expect(Hash::check('nytt-losenord-2026', $user->fresh()->password_hash))->toBeTrue()
        // Nio kvar: koden är prövad genom TwoFactorChallenge, som förbrukar
        // den — ingen egen kopia av kontrollen finns här.
        ->and(TotpRecoveryCode::query()->where('user_id', $user->id)->whereNull('used_at')->count())->toBe(9);
});

/*
 * Sessionerna: kontots ÖVRIGA webbsessioner ska vara borta och den egna ha
 * ett nytt id. `SESSION_DRIVER` är `array` i sviten (phpunit.xml), så
 * raderna i `sessions` fylls på för hand — raderingen är densamma, den går
 * mot tabellen oavsett vilken drivrutin som skriver den.
 *
 * Den egna sessionen skickas in med sin kaka, så `regenerate()` har något
 * att byta ut: svaret bär ett annat id än det som kom in, och den gamla
 * raden är borta med de andra.
 */
it('loggar ut kontots övriga sessioner och ger den egna ett nytt id', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);
    $annan = User::factory()->create(['password_hash' => 'ratt-losenord']);

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

    from('/settings/security')->put('/settings/security/password', losenordsKropp([
        'current_password' => 'ratt-losenord',
    ]))->assertRedirect('/settings/security');

    // Den egna gamla raden och den andra enhetens rad är borta — kvar finns
    // bara den nya sessionen, som skrivs när requesten avslutas och därför
    // inte syns här.
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);

    // En annan användares session rörs inte.
    expect(DB::table('sessions')->where('id', $annansSession)->exists())->toBeTrue();

    // Och den egna sessionen har bytt id: requesten kom in med
    // `$egenGammal` och lämnar med ett annat.
    expect(session()->getId())->not->toBe($egenGammal);
});

it('tar bort kontots alla Sanctum-token', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);
    $user->createToken('mobil');
    $user->createToken('integration');

    expect($user->tokens()->count())->toBe(2);

    actingAs($user);

    from('/settings/security')->put('/settings/security/password', losenordsKropp([
        'current_password' => 'ratt-losenord',
    ]))->assertRedirect('/settings/security');

    expect($user->tokens()->count())->toBe(0);
});

it('skriver exakt en rad i säkerhetsloggen, utan lösenord och utan kod', function () {
    Notification::fake();

    [$user, $secret] = användareMedBekräftadTotp();
    $nytt = 'hemligt-nytt-losenord-9911';
    $gammalt = 'ratt-losenord';

    actingAs($user);

    $kod = totpKodFör($secret);

    from('/settings/security')->put('/settings/security/password', [
        'current_password' => $gammalt,
        'password' => $nytt,
        'password_confirmation' => $nytt,
        'code' => $kod,
    ])->assertRedirect('/settings/security');

    $rader = SecurityLog::query()->where('action', SecurityLog::ACTION_PASSWORD_CHANGED)->get();

    expect($rader)->toHaveCount(1)
        ->and($rader->first()->meta)->toBe(['had_password' => true])
        ->and($rader->first()->user_id)->toBe($user->id)
        // Ingen annan rad skrevs av bytet.
        ->and(SecurityLog::query()->count())->toBe(1);

    // Varken det gamla eller det nya lösenordet eller koden finns i raden.
    $json = json_encode(SecurityLog::query()->get()->toArray(), JSON_THROW_ON_ERROR);

    expect($json)->not->toContain($nytt)
        ->and($json)->not->toContain($gammalt)
        ->and($json)->not->toContain($kod);
});

/*
 * Mejlet: en Laravel-notis till användarens egen adress, och INGEN rad i
 * `notification` — det är ett transaktionellt utskick, inte en notis i
 * klockan ([[Notiser]] § notification). `via()` är `['mail']`, så ingen
 * leveransrad skapas av App\Actions\Notification\CreateNotification.
 */
it('mejlar användaren om att lösenordet har ändrats', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);
    actingAs($user);

    from('/settings/security')->put('/settings/security/password', losenordsKropp([
        'current_password' => 'ratt-losenord',
    ]))->assertRedirect('/settings/security');

    Notification::assertSentTo(
        $user,
        PasswordChangedNotification::class,
        function (PasswordChangedNotification $notification) use ($user): bool {
            // Adressen är användarens: notisen bär ingen mottagare själv, och
            // kanalen är `mail` — alltså den adress kontot har.
            return $notification->via($user) === ['mail'];
        }
    );

    expect(DB::table('notification')->count())->toBe(0);
});

it('skickar inget mejl när bytet avvisas', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);
    actingAs($user);

    from('/settings/security')->put('/settings/security/password', losenordsKropp([
        'current_password' => 'fel-losenord',
    ]));

    Notification::assertNotSentTo($user, PasswordChangedNotification::class);
});

/*
 * Takgränsen: inloggningens egen begränsare — samma namn och samma trösklar
 * som inloggningen (5/minut per kontonyckel och 10/minut per IP), se
 * App\Support\Auth\LoginRateLimiter. Fem försök med fel lösenord släpps
 * igenom till valideringen; det sjätte stoppas av begränsaren.
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

    for ($i = 0; $i < 5; $i++) {
        from('/settings/security')
            ->put('/settings/security/password', losenordsKropp([
                'current_password' => 'fel-losenord',
            ]))
            ->assertSessionHasErrors('current_password');
    }

    $svar = from('/settings/security')->put('/settings/security/password', losenordsKropp([
        'current_password' => 'ratt-losenord',
    ]));

    $svar->assertRedirect('/settings/security');
    $svar->assertSessionHasErrors('email');

    // Meningen och inte nyckeln: `auth.throttle` med antalet sekunder i.
    // Sekunderna läses ur meningen och pinnas inte till 60 — takgränsen räknar
    // hela sekunder, så ett prov vars sex anrop korsar en sekundgräns får 59.
    // Samma form som provet längre ned i filen och som
    // tests/Feature/Frontend/TakgransTest.php.
    $mening = session('errors')->get('email')[0];
    preg_match('/(\d+)/', $mening, $träff);

    expect($träff)->not->toBeEmpty('meddelandet saknar antal sekunder')
        ->and((int) $träff[0])->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and($mening)->toBe(trans('auth.throttle', ['seconds' => (int) $träff[0]], 'en'));

    // Det sjätte försöket nådde aldrig valideringen: lösenordet står kvar.
    expect(Hash::check('ratt-losenord', $user->fresh()->password_hash))->toBeTrue();
});

it('skickar en utloggad besökare till inloggningen', function () {
    put('/settings/security/password', losenordsKropp(['current_password' => 'vad-som-helst']))
        ->assertRedirect('/login');
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
            'current_password' => 'fel-losenord',
        ]))->assertSessionHasErrors('current_password');
    }

    // Den förstas hink är tömd: det sjätte försöket stoppas av begränsaren.
    from('/settings/security')->put('/settings/security/password', losenordsKropp([
        'current_password' => 'fel-losenord',
    ]))->assertSessionHasErrors('email');

    // Den andra har sin egen hink och når hela vägen till valideringen.
    actingAs($annan);

    from('/settings/security')->put('/settings/security/password', losenordsKropp([
        'current_password' => 'fel-losenord',
    ]))->assertSessionHasErrors('current_password');
});

/*
 * Samma konto, samma hink. Att gissa det nuvarande lösenordet i
 * bytesformuläret är samma angrepp som att gissa det vid inloggning och ska
 * räknas mot samma gräns ([[ADR-0011 Autentisering]]). Nyckeln är därför
 * användarens e-postadress och inte hennes id.
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

    $svar = from('/settings/security')->put('/settings/security/password', losenordsKropp([
        'current_password' => 'ratt-losenord',
    ]));

    $svar->assertSessionHasErrors('email');

    // Meningen och inte nyckeln: `auth.throttle` med antalet sekunder i.
    // Sekunderna läses ur meningen — fönstret har redan tickat ett steg när
    // bytet görs, så 60 är inte givet.
    $mening = session('errors')->get('email')[0];
    preg_match('/(\d+)/', $mening, $träff);

    expect($träff)->not->toBeEmpty('meddelandet saknar antal sekunder')
        ->and($mening)->toBe(trans('auth.throttle', ['seconds' => (int) $träff[0]], 'en'));

    // Det takgränsade försöket nådde aldrig valideringen.
    expect(Hash::check('ratt-losenord', $user->fresh()->password_hash))->toBeTrue();
});

/*
 * Vid en 429 ligger gamla inmatningen i sessionen (bootstrap/app.php). Ingen
 * av lösenordsbytets tre känsliga fält får finnas där. `code` är med som
 * motprov: det är de tre som undantas, inte hela kroppen.
 */
it('lämnar inga lösenordsfält i gamla inmatningen vid en 429', function () {
    Notification::fake();

    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);
    actingAs($user);

    for ($i = 0; $i < 5; $i++) {
        from('/settings/security')->put('/settings/security/password', losenordsKropp([
            'current_password' => 'fel-losenord',
        ]));
    }

    $svar = from('/settings/security')->put('/settings/security/password', [
        'current_password' => 'gammalt-hemligt-7712',
        'password' => 'nytt-hemligt-9930',
        'password_confirmation' => 'nytt-hemligt-9930',
        'code' => '123456',
    ]);

    $svar->assertSessionHasErrors('email');

    $svar->assertSessionMissingInput('current_password');
    $svar->assertSessionMissingInput('password');
    $svar->assertSessionMissingInput('password_confirmation');

    // Motprovet: ett fält som inte är ett lösenord fylls i igen.
    $svar->assertSessionHasInput('code', '123456');
});

/*
 * Ordningen lösenord före kod gäller också förbrukningen: en återställningskod
 * som skickas med ett FEL nuvarande lösenord får inte gå förlorad. Koden
 * prövas först efter att `current_password`-regeln har gått igenom — den är en
 * valideringsregel och kastar innan UpdatePasswordRequest::authenticate() ens
 * anropas.
 */
it('förbrukar inte återställningskoden när det nuvarande lösenordet är fel', function () {
    Notification::fake();

    [$user] = användareMedBekräftadTotp();
    $koder = RecoveryCodeBroker::generate($user);

    actingAs($user);

    from('/settings/security')->put('/settings/security/password', losenordsKropp([
        'current_password' => 'fel-losenord',
        'code' => $koder[0],
    ]))->assertSessionHasErrors('current_password');

    expect(TotpRecoveryCode::query()->where('user_id', $user->id)->whereNull('used_at')->count())->toBe(10)
        ->and(Hash::check('ratt-losenord', $user->fresh()->password_hash))->toBeTrue();
});
