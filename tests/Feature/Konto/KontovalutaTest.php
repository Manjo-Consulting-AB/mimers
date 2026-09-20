<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\postJson;

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
 * Att den går att ÄNDRA i inställningarna prövas inte här: skrivvägen ligger i
 * app/Http/Requests/Settings/UpdateAccountSettingsRequest.php, som inte står i
 * issue 85:s `In scope`. Se PR:ens `## Frågor och antaganden` — rutan och
 * "Klart när" säger emot varandra på den punkten, och rutan är bindande.
 *
 * Hur containern ärver kontot prövas i
 * tests/Feature/Container/ContainerValutaTest.php, och vad ett byte betyder
 * för en skriven kostnadsrad i tests/Feature/Kostnad/ValutansArvTest.php.
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
