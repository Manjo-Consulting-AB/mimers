<?php

use App\Models\Account;
use App\Models\User;
use App\Policies\AccountPolicy;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 398 · Profilen bär personkontot. Se [[ADR-0085 Profilen bär
 * personkontot]], App\Http\Controllers\Settings\ProfileController och
 * App\Http\Requests\Settings\UpdateProfileRequest.
 *
 * Den skrivande yta som gör issuen till riskklassen `elevated`: den skriver
 * `account` utanför registreringen. Filen prövar därför att kontot hämtas på
 * servern som personkontot och aldrig ur kroppen, att policyn avgör om kontots
 * fält skrivs, och att personens egna fält skrivs oavsett.
 *
 * Sidornas props och språkbytet prövas i
 * tests/Feature/Frontend/InstallningsvyerTest.php.
 *
 * Hjälparna har prefixet kontoinstallning* för att inte krocka med de
 * globala hjälparna i andra Feature-filer.
 */

/**
 * Ett konto med en medlem i angiven roll.
 *
 * @param  array<string, mixed>  $kontoAttribut
 * @return array{0: Account, 1: User}
 */
function kontoinstallningKonto(
    string $roll = 'owner',
    array $kontoAttribut = [],
    ?string $anvandarLocale = null,
): array {
    $konto = Account::factory()->create($kontoAttribut);
    $anvandare = User::factory()->create(['locale' => $anvandarLocale]);
    $konto->users()->attach($anvandare, ['role' => $roll]);

    return [$konto, $anvandare];
}

/**
 * Kroppen för PATCH /settings/profile med kontots två fält.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function kontoinstallningKropp(User $anvandare, Account $konto, array $overrides = []): array
{
    return array_merge([
        'name' => $anvandare->name,
        'locale' => 'sv_SE',
        'timezone' => 'Europe/Stockholm',
        'unit_system' => 'metric',
        'account_name' => $konto->name,
        'currency' => $konto->currency,
    ], $overrides);
}

it('låter personkontots owner spara kontonamn och valuta', function () {
    withoutVite();

    [$konto, $anvandare] = kontoinstallningKonto('owner');
    actingAs($anvandare);

    from('/settings/profile')
        ->patch('/settings/profile', kontoinstallningKropp($anvandare, $konto, [
            'account_name' => 'Varvet Oskarshamn',
            'currency' => 'eur',
        ]))
        ->assertRedirect('/settings/profile')
        ->assertSessionHas('status', 'profile-updated');

    $konto->refresh();

    expect($konto->name)->toBe('Varvet Oskarshamn');
    expect($konto->currency)->toBe('EUR');
});

it('lämnar kontots språk, tidszon och enhetssystem orörda vid en sparning', function () {
    withoutVite();

    [$konto, $anvandare] = kontoinstallningKonto('owner');
    $fore = $konto->only(['locale', 'timezone', 'unit_system']);
    actingAs($anvandare);

    from('/settings/profile')
        ->patch('/settings/profile', kontoinstallningKropp($anvandare, $konto, [
            'locale' => 'en_GB',
            'timezone' => 'Europe/Oslo',
            'unit_system' => 'imperial',
        ]))
        ->assertRedirect('/settings/profile');

    expect($anvandare->fresh()->only(['locale', 'timezone', 'unit_system']))
        ->toBe(['locale' => 'en_GB', 'timezone' => 'Europe/Oslo', 'unit_system' => 'imperial']);
    expect($konto->fresh()->only(['locale', 'timezone', 'unit_system']))->toBe($fore);
});

it('lämnar kontot orört när kroppen inte nämner kontots fält', function () {
    withoutVite();

    [$konto, $anvandare] = kontoinstallningKonto('owner', ['currency' => 'NOK']);
    $namn = $konto->name;
    actingAs($anvandare);

    from('/settings/profile')
        ->patch('/settings/profile', ['name' => 'Nytt personnamn'])
        ->assertRedirect('/settings/profile')
        ->assertSessionHasNoErrors();

    expect($anvandare->fresh()->name)->toBe('Nytt personnamn');
    expect($konto->fresh()->name)->toBe($namn);
    expect($konto->fresh()->currency)->toBe('NOK');
});

it('avvisar tomt kontonamn och ogiltig valuta utan att skriva något', function () {
    withoutVite();

    [$konto, $anvandare] = kontoinstallningKonto('owner');
    $namn = $konto->name;
    actingAs($anvandare);

    from('/settings/profile')
        ->patch('/settings/profile', kontoinstallningKropp($anvandare, $konto, ['account_name' => '']))
        ->assertSessionHasErrors('account_name');

    foreach (['', 'SE1', 'KRONOR', 'ABC', 'XAU'] as $kod) {
        from('/settings/profile')
            ->patch('/settings/profile', kontoinstallningKropp($anvandare, $konto, ['currency' => $kod, 'name' => 'Ska inte sparas']))
            ->assertSessionHasErrors('currency');
    }

    expect($konto->fresh()->name)->toBe($namn);
    expect($konto->fresh()->currency)->toBe('SEK');
    expect($anvandare->fresh()->name)->not->toBe('Ska inte sparas');
});

/*
 * ADR-0085 § 5: ett `read_only`- eller `closed`-konto nekas av policyn, och då
 * skrivs bara personens egna fält — sparningen lyckas ändå.
 */
it('skriver bara user när personkontot är read_only eller closed', function (array $attribut) {
    withoutVite();

    [$konto, $anvandare] = kontoinstallningKonto('owner', $attribut);
    $namn = $konto->name;
    actingAs($anvandare);

    actingAs($anvandare)->get('/settings/profile')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('personalAccount.canUpdate', false)
        ->where('personalAccount.name', $namn)
    );

    from('/settings/profile')
        ->patch('/settings/profile', kontoinstallningKropp($anvandare, $konto, [
            'name' => 'Personens nya namn',
            'locale' => 'en_GB',
            'account_name' => 'Kapat kontonamn',
            'currency' => 'EUR',
        ]))
        ->assertRedirect('/settings/profile')
        ->assertSessionHasNoErrors();

    $anvandare->refresh();
    $konto->refresh();

    expect($anvandare->name)->toBe('Personens nya namn');
    expect($anvandare->locale)->toBe('en_GB');
    expect($konto->name)->toBe($namn);
    expect($konto->currency)->toBe('SEK');
})->with([
    'read_only' => [['status' => 'read_only', 'read_only_reason' => 'over_quota']],
    'closed' => [['status' => 'closed']],
]);

/*
 * ADR-0085 § 5: kontot hämtas som personkontot, aldrig ur kroppen. Ett
 * konto-ULID i kroppen — eget eller någon annans — ändrar ingenting.
 */
it('skriver aldrig till ett annat konto, inte heller med ett ULID i kroppen', function () {
    withoutVite();

    [$konto, $anvandare] = kontoinstallningKonto('owner');
    $frammande = Account::factory()->create(['name' => 'Främmande varv']);

    actingAs($anvandare)
        ->from('/settings/profile')
        ->patch('/settings/profile', kontoinstallningKropp($anvandare, $konto, [
            'account_name' => 'Mitt nya namn',
            'account' => $frammande->ulid,
            'account_ulid' => $frammande->ulid,
            'ulid' => $frammande->ulid,
            'id' => $frammande->id,
        ]))
        ->assertRedirect('/settings/profile');

    expect($frammande->fresh()->name)->toBe('Främmande varv');
    expect($konto->fresh()->name)->toBe('Mitt nya namn');
});

it('ignorerar ett organisationskonto och ett konto där användaren bara är admin', function () {
    withoutVite();

    $anvandare = User::factory()->create();
    $organisation = Account::factory()->organisation()->create(['name' => 'Org']);
    $organisation->users()->attach($anvandare, ['role' => 'owner']);
    $delat = Account::factory()->create(['name' => 'Delat']);
    $delat->users()->attach($anvandare, ['role' => 'admin']);

    actingAs($anvandare)->get('/settings/profile')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('personalAccount', null)
    );

    actingAs($anvandare)
        ->from('/settings/profile')
        ->patch('/settings/profile', ['name' => $anvandare->name, 'account_name' => 'Kapat', 'currency' => 'EUR'])
        ->assertRedirect('/settings/profile');

    expect($organisation->fresh()->name)->toBe('Org');
    expect($delat->fresh()->name)->toBe('Delat');
});

it('ger 404 för de borttagna kontorutterna', function () {
    withoutVite();

    [$konto, $anvandare] = kontoinstallningKonto('owner');

    actingAs($anvandare)->get('/settings/accounts')->assertNotFound();
});

/*
 * Beslut 5, sista punkten: de tre BEFINTLIGA metoderna beter sig oförändrat.
 * Att `member` fortfarande får SE och RENSA bilagor på ett fryst konto är
 * hela den nedgraderingsväg regel 4 undantar — och den nya update() får inte
 * ha råkat dra in dem i roll- eller frystvilkoret.
 */
it('lämnar de tre befintliga policymetoderna oförändrade', function () {
    $policy = new AccountPolicy;

    $medlem = User::factory()->create();
    $fryst = Account::factory()->create(['status' => 'read_only']);
    $fryst->users()->attach($medlem, ['role' => 'member']);

    expect($policy->viewStorage($medlem, $fryst))->toBeTrue();
    expect($policy->manageStorage($medlem, $fryst))->toBeTrue();
    expect($policy->update($medlem, $fryst))->toBeFalse();
    expect($policy->manageWebhooks($medlem, $fryst))->toBeFalse();

    // Och den nya metoden själv, i sina två tillåtande fall.
    $owner = User::factory()->create();
    $friskt = Account::factory()->create(['status' => 'active']);
    $friskt->users()->attach($owner, ['role' => 'owner']);

    expect($policy->update($owner, $friskt))->toBeTrue();
    expect($policy->update($medlem, $friskt))->toBeFalse();
});

/*
 * Beslut 4: `user.timezone` skrivs på två ställen, och det är rätt — tysta
 * timmar är oskiljaktigt från ett tidsfönster (issue 31b/36a), och den här
 * issuen skriver samma kolumn som en allmän inställning. Regeln finns bara på
 * ett ställe (Rule::in(DateTimeZone::listIdentifiers()) i båda
 * FormRequests), och den här raden bevisar att den befintliga ytan lever
 * vidare orörd.
 */
it('låter tysta timmar fortfarande skriva user.timezone', function () {
    [, $anvandare, $headers] = kontoMedMedlem();

    patchJson('/api/me/quiet-hours', [
        'quiet_hours_start' => '22:00',
        'quiet_hours_end' => '07:00',
        'timezone' => 'Europe/Oslo',
    ], $headers)->assertOk();

    expect($anvandare->fresh()->timezone)->toBe('Europe/Oslo');
});
