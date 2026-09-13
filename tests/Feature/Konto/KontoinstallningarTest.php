<?php

use App\Models\Account;
use App\Models\User;
use App\Policies\AccountPolicy;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 53c · Kontots inställningar — behörigheten. Se
 * App\Policies\AccountPolicy::update(),
 * App\Http\Controllers\Settings\AccountSettingsController och
 * App\Http\Requests\Settings\UpdateAccountSettingsRequest.
 *
 * Den skrivande yta som gör issuen till riskklassen `elevated`: den första
 * som skriver `account` utanför registreringen. Filen prövar därför rollerna,
 * regel 4 och att ett konto som inte är användarens aldrig kan skrivas — och
 * att de tre befintliga policymetoderna beter sig exakt som förut (Beslut 5).
 *
 * Sidornas props och språkbytet prövas i
 * tests/Feature/Frontend/InstallningsvyerTest.php.
 *
 * Hjälparna har prefixet kontoinstallning* för att inte krocka med de
 * globala hjälparna i andra Feature-filer — Pest lägger alla filer i samma
 * namnrymd när hela sviten körs.
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
 * Kroppen för PATCH /settings/accounts/{account} — kontots fyra fält, med
 * valfria överstyrningar. Alla fyra är obligatoriska (Beslut 5), så ett test
 * som vill pröva ett fält måste skicka de andra giltiga.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function kontoinstallningKropp(Account $konto, array $overrides = []): array
{
    return array_merge([
        'name' => $konto->name,
        'locale' => $konto->locale,
        'timezone' => $konto->timezone,
        'unit_system' => $konto->unit_system,
    ], $overrides);
}

it('låter en owner spara kontots fyra fält', function () {
    withoutVite();

    [$konto, $anvandare] = kontoinstallningKonto('owner');
    actingAs($anvandare);

    from('/settings/accounts')
        ->patch("/settings/accounts/{$konto->ulid}", [
            'name' => 'Varvet Oskarshamn',
            'locale' => 'en_GB',
            'timezone' => 'Europe/Oslo',
            'unit_system' => 'imperial',
        ])
        ->assertRedirect('/settings/accounts')
        ->assertSessionHas('status', 'account-updated');

    $konto->refresh();

    expect($konto->name)->toBe('Varvet Oskarshamn');
    expect($konto->locale)->toBe('en_GB');
    expect($konto->timezone)->toBe('Europe/Oslo');
    expect($konto->unit_system)->toBe('imperial');
});

it('låter en admin spara kontots fyra fält', function () {
    withoutVite();

    [$konto, $anvandare] = kontoinstallningKonto('admin');
    actingAs($anvandare);

    from('/settings/accounts')
        ->patch("/settings/accounts/{$konto->ulid}", kontoinstallningKropp($konto, ['name' => 'Admin döper om']))
        ->assertRedirect('/settings/accounts');

    expect($konto->fresh()->name)->toBe('Admin döper om');
});

/*
 * Beslut 5 och 9: en `member` ser värdena men har inget formulär, och en
 * direkt PATCH mot rutten nekar. Att byta kontots namn ändrar vad ANDRA ser i
 * deltagarlistan — det är därför rollen krävs, och därför vyn visar värdena
 * utan formulär i stället för att bara dölja knappen: behörigheten görs i
 * policyn, inte i markupen.
 */
it('visar värdena men inget formulär för en member — och nekar en direkt PATCH', function () {
    withoutVite();

    [$konto, $anvandare] = kontoinstallningKonto('member');
    $ursprungligtNamn = $konto->name;
    actingAs($anvandare);

    actingAs($anvandare)->get('/settings/accounts')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('accounts', 1)
        ->where('accounts.0.role', 'member')
        ->where('accounts.0.canUpdate', false)
        ->where('accounts.0.name', $ursprungligtNamn)
    );

    from('/settings/accounts')
        ->patch("/settings/accounts/{$konto->ulid}", kontoinstallningKropp($konto, ['name' => 'Medlemmens namn']))
        ->assertForbidden();

    expect($konto->fresh()->name)->toBe($ursprungligtNamn);
});

/*
 * Regel 4: ett `read_only`-konto nekas även för sin `owner`. Undantagen i
 * regel 4 är att återkalla en åtkomst och att rensa lagring — båda MINSKAR
 * exponeringen. Ett namnbyte gör inte det.
 */
it('nekar en owner på ett read_only-konto', function () {
    withoutVite();

    [$konto, $anvandare] = kontoinstallningKonto('owner', ['status' => 'read_only', 'read_only_reason' => 'over_quota']);
    $ursprungligtNamn = $konto->name;
    actingAs($anvandare);

    from('/settings/accounts')
        ->patch("/settings/accounts/{$konto->ulid}", kontoinstallningKropp($konto, ['name' => 'Nytt namn', 'locale' => 'en_GB']))
        ->assertForbidden();

    $konto->refresh();

    expect($konto->locale)->toBe('sv_SE');
    expect($konto->name)->toBe($ursprungligtNamn);
});

it('nekar en owner på ett stängt konto', function () {
    withoutVite();

    [$konto, $anvandare] = kontoinstallningKonto('owner', ['status' => 'closed']);
    $ursprungligtNamn = $konto->name;
    actingAs($anvandare);

    from('/settings/accounts')
        ->patch("/settings/accounts/{$konto->ulid}", kontoinstallningKropp($konto, ['name' => 'Stängt namn']))
        ->assertForbidden();

    expect($konto->fresh()->name)->toBe($ursprungligtNamn);
});

/*
 * Objektet kommer ur rutten och auktoriseras mot det (Beslut 1): ett konto
 * användaren inte är med i finns i databasen, binder därför till rutten, och
 * nekas av policyn. Ingen tyst lyckad skrivning — det är den här raden som
 * skiljer ytan från en rutt som läser kontot ur kroppen.
 */
it('nekar en användare som inte är medlem i kontot', function () {
    withoutVite();

    $frammande = Account::factory()->create(['name' => 'Främmande varv']);
    $inkraktare = User::factory()->create();

    actingAs($inkraktare)
        ->from('/settings/accounts')
        ->patch("/settings/accounts/{$frammande->ulid}", kontoinstallningKropp($frammande, ['name' => 'Kapat']))
        ->assertForbidden();

    expect($frammande->fresh()->name)->toBe('Främmande varv');
});

it('svarar 404 för en okänd ULID', function () {
    withoutVite();

    [, $anvandare] = kontoinstallningKonto('owner');

    actingAs($anvandare)
        ->patch('/settings/accounts/'.(string) Str::ulid(), ['name' => 'Ingen'])
        ->assertNotFound();
});

/*
 * Kontots värden är botten i kedjan (Beslut 5): ingen av de fyra får vara
 * tom, och `locale` får inte sparas som null — det vore en inställning utan
 * svar när användarens null betyder "följ kontot".
 */
it('avvisar ett null-locale på kontot', function () {
    withoutVite();

    [$konto, $anvandare] = kontoinstallningKonto('owner');
    actingAs($anvandare);

    $svar = from('/settings/accounts')->patch("/settings/accounts/{$konto->ulid}", kontoinstallningKropp($konto, [
        'locale' => null,
    ]));

    $svar->assertRedirect('/settings/accounts');
    $svar->assertSessionHasErrors('locale');

    expect($konto->fresh()->locale)->toBe('sv_SE');
});

it('avvisar ett tomt namn och en ogiltig tidszon på kontot', function () {
    withoutVite();

    [$konto, $anvandare] = kontoinstallningKonto('owner');
    $ursprungligtNamn = $konto->name;
    actingAs($anvandare);

    $tomtNamn = from('/settings/accounts')->patch("/settings/accounts/{$konto->ulid}", kontoinstallningKropp($konto, [
        'name' => '',
    ]));
    $tomtNamn->assertSessionHasErrors('name');

    $felZon = from('/settings/accounts')->patch("/settings/accounts/{$konto->ulid}", kontoinstallningKropp($konto, [
        'timezone' => 'Mars/Olympus',
    ]));
    $felZon->assertSessionHasErrors('timezone');

    $konto->refresh();

    expect($konto->name)->toBe($ursprungligtNamn);
    expect($konto->timezone)->toBe('Europe/Stockholm');
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
