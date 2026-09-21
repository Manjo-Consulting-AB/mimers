<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 85 · Valutan ärvs nedåt — kontots halva. Se [[ADR-0037 Valutans
 * arv]].
 *
 * Kontot bär valutan och är botten i arvet: containern faller tillbaka på den
 * när den saknar en egen (App\Models\Container::effectiveCurrency()). Den
 * sätts av kolumnens förval när kontot skapas — registreringsformuläret frågar
 * inte efter den, och [[ADR-0037 Valutans arv]] § Konsekvenser kallar det
 * uttryckligen "aldrig en blockerande fråga".
 *
 * Att den sedan går att ÄNDRA prövas här: skrivvägen är inställningarnas
 * PATCH (app/Http/Requests/Settings/UpdateAccountSettingsRequest.php och
 * App\Http\Controllers\Settings\AccountSettingsController), som kontosidan
 * redan ägde — issue 85 lägger bara ett femte fält i den. Rollerna, regel 4
 * och objektet-ur-rutten prövas i
 * tests/Feature/Konto/KontoinstallningarTest.php och upprepas inte här.
 *
 * **Beviset för att ett byte av kontots valuta lämnar en skriven kostnadsrad
 * orörd ligger också här**, och det går genom inställningsändpunkten och inte
 * genom `$account->update(...)` på modellen: det är ytan användaren faktiskt
 * kan skriva på, och en invariant som bara prövas mot modellen säger
 * ingenting om vad formuläret gör.
 *
 * Hur containern ärver kontot prövas i
 * tests/Feature/Container/ContainerValutaTest.php, och kostnadsradens egen
 * halva — förvalet i API:et och att ett byte av CONTAINERNS valuta lämnar
 * raden orörd — i tests/Feature/Kostnad/ValutansArvTest.php.
 *
 * Hjälparna har prefixet kontovaluta* för att inte krocka med de globala
 * hjälparna i andra Feature-filer — Pest lägger alla filer i samma namnrymd
 * när hela sviten körs.
 */

it('ger ett nyregistrerat konto en valuta utan att fråga efter den', function () {
    Notification::fake();

    postJson('/register', [
        'name' => 'Ny Person',
        'email' => 'valuta@example.com',
        'password' => 'giltigt-losenord',
    ])->assertRedirect(route('dashboard'));

    $anvandare = User::query()->where('email', 'valuta@example.com')->firstOrFail();
    $konto = $anvandare->accounts()->firstOrFail();

    expect($konto->currency)->toBe('SEK');

    Notification::assertSentTo($anvandare, VerifyEmail::class);
});

it('bär valutan som en obligatorisk kolumn på kontot', function () {
    // Kontot är botten i arvet, som `locale`, `timezone` och `unit_system`:
    // ett `null` där hade lämnat containerns arv utan något att falla tillbaka
    // på. Containerns kolumn är den nullbara halvan — se
    // tests/Feature/Container/ContainerValutaTest.php.
    $kolumn = collect(Schema::getColumns('account'))->firstWhere('name', 'currency');

    expect($kolumn)->not->toBeNull();
    expect($kolumn['nullable'])->toBeFalse();
});

/**
 * Ett konto med en `owner`, och kroppen för kontots PATCH med valfria
 * överstyrningar. De fyra första fälten är obligatoriska i requesten och
 * måste skickas giltiga för att ett test ska nå fram till valutan.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{0: Account, 1: User, 2: array<string, mixed>}
 */
function kontovalutaKonto(array $overrides = []): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $kropp = array_merge([
        'name' => $konto->name,
        'locale' => $konto->locale,
        'timezone' => $konto->timezone,
        'unit_system' => $konto->unit_system,
    ], $overrides);

    return [$konto, $anvandare, $kropp];
}

it('visar kontots valuta på kontosidan', function () {
    withoutVite();

    [$konto, $anvandare] = kontovalutaKonto();

    actingAs($anvandare)->get('/settings/accounts')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Settings/Accounts')
        ->where('accounts.0.ulid', $konto->ulid)
        ->where('accounts.0.currency', 'SEK')
    );
});

it('låter en owner ändra kontots valuta i inställningarna', function () {
    withoutVite();

    // `eur` och inte `EUR`: servern normaliserar till versaler, samma väg som
    // containerns valuta går i
    // app/Http/Requests/Container/UpdateContainerRequest.php.
    [$konto, $anvandare, $kropp] = kontovalutaKonto(['currency' => 'eur']);

    actingAs($anvandare)
        ->from('/settings/accounts')
        ->patch("/settings/accounts/{$konto->ulid}", $kropp)
        ->assertRedirect('/settings/accounts')
        ->assertSessionHas('status', 'account-updated');

    expect($konto->fresh()->currency)->toBe('EUR');
});

/*
 * Valutan är OBLIGATORISK i schemat (`account.currency` är inte nullbar), så
 * ett värde som ÄR där får aldrig vara tomt eller fel format. Nyckeln får
 * däremot saknas — det är skillnaden mellan `sometimes` och ett tomt värde,
 * och den prövas i nästa test.
 */
it('avvisar en valuta som inte är tre bokstäver', function () {
    withoutVite();

    [$konto, $anvandare, $kropp] = kontovalutaKonto();

    actingAs($anvandare);

    // Tomt värde: ConvertEmptyStringsToNull gör rutan till `null` innan
    // reglerna körs, och `required` avvisar den — kolumnen är inte nullbar.
    $tomt = from('/settings/accounts')->patch("/settings/accounts/{$konto->ulid}", $kropp + ['currency' => '']);
    $tomt->assertSessionHasErrors('currency');

    $siffror = from('/settings/accounts')->patch("/settings/accounts/{$konto->ulid}", $kropp + ['currency' => 'SE1']);
    $siffror->assertSessionHasErrors('currency');

    $forLangt = from('/settings/accounts')->patch("/settings/accounts/{$konto->ulid}", $kropp + ['currency' => 'KRONOR']);
    $forLangt->assertSessionHasErrors('currency');

    expect($konto->fresh()->currency)->toBe('SEK');
});

it('lämnar valutan orörd när kroppen inte nämner den', function () {
    withoutVite();

    // `sometimes`: de anropare som redan skickar de fyra obligatoriska fälten
    // — och formulärversioner före issue 85 — ska inte behöva lära sig ett
    // femte, och en nyckel som saknas kan därför aldrig tömma kolumnen.
    $konto = Account::factory()->create(['currency' => 'NOK']);
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    actingAs($anvandare)
        ->from('/settings/accounts')
        ->patch("/settings/accounts/{$konto->ulid}", [
            'name' => 'Nytt namn',
            'locale' => $konto->locale,
            'timezone' => $konto->timezone,
            'unit_system' => $konto->unit_system,
        ])
        ->assertRedirect('/settings/accounts')
        ->assertSessionHasNoErrors();

    $konto->refresh();

    expect($konto->name)->toBe('Nytt namn');
    expect($konto->currency)->toBe('NOK');
});

it('lämnar befintliga kostnadsrader oförändrade när kontots valuta byts i inställningarna', function () {
    withoutVite();

    // Issuens viktigaste acceptanskriterium, prövat genom den yta en
    // användare faktiskt kan skriva på. Ett ändrat standardval gäller bara
    // NYA poster: det som står i en rad är vad som betalades.
    [$konto, $anvandare, $kropp] = kontovalutaKonto();

    // Containern har ingen egen valuta, så den ärver kontots — och kostnaden
    // är skriven medan kontot stod i SEK.
    $container = Container::factory()->for($konto, 'account')->create(['currency' => null]);
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);
    $kostnad = CostEntry::factory()->for($item, 'item')->create([
        'currency' => 'SEK',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    actingAs($anvandare)
        ->from('/settings/accounts')
        ->patch("/settings/accounts/{$konto->ulid}", $kropp + ['currency' => 'EUR'])
        ->assertRedirect('/settings/accounts')
        ->assertSessionHasNoErrors();

    expect($konto->fresh()->currency)->toBe('EUR');
    // Containern ärver det nya värdet — för NYA poster.
    expect($container->fresh()->effectiveCurrency())->toBe('EUR');
    // Raden står kvar: det som står i en rad är vad som betalades.
    expect($kostnad->fresh()->currency)->toBe('SEK');
});
