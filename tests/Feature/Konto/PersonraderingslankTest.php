<?php

use App\Models\Account;
use App\Models\LegalHold;
use App\Models\User;
use App\Models\UserDeletion;
use App\Support\User\DeletionBlocker;
use App\Support\User\DeletionWarning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\travel;
use function Pest\Laravel\withoutVite;

/*
 * Issue 145 · Länken i mejlet — bekräftelsesidan och raderingen, se
 * app/Http/Controllers/Settings/UserDeletionController::confirm() och
 * ::destroy(), app/Actions/User/ConfirmUserDeletion.php,
 * resources/js/pages/Settings/ConfirmUserDeletion.vue,
 * [[M22 Redo för testare]] § 145 och [[ADR-0045 Radering av konto och person]]
 * § Uppföljning 2026-09-28.
 *
 * **Det här är rättelsen av bugg #577.** Fram till 2026-09-28 krävde länken
 * samma inloggade användare, och en testare som öppnade mejlet i sin
 * mejlklients inbyggda webbläsare möttes av /login och fick aldrig sin
 * radering. Proven nedan utgår därför från en GÄST, och från en inloggad som
 * är någon annan än den länken gäller.
 *
 * **Två anrop, och bara det andra raderar.** `GET` visar vad en radering
 * skulle göra; `POST` raderar. Ett prov som bara öppnade länken och sedan
 * kontrollerade att personen var borta hade inte kunnat skilja de två stegen
 * åt, och det är hela poängen med mellansteget: en mejlskanner som
 * förhandshämtar länkar gör en `GET`.
 *
 * Hjälparna `raderingsyta*` bor i tests/Feature/Konto/PersonraderingsytaTest.php
 * och används härifrån. Pests funktioner är globala, och hela sviten körs i en
 * process — se docblocket där.
 */

/**
 * Gör testet till en gäst igen. `actingAs()` sätter användaren direkt på
 * vakten utan att logga in via sessionen, så en ny vakt räcker: nästa request
 * löser upp sig ur en session som inte bär någon inloggning.
 */
function raderingslankSomGäst(): void
{
    auth()->forgetGuards();
}

// --- Bekräftelsesidan ----------------------------------------------------

it('visar bekräftelsesidan för en gäst och raderar ingenting', function () {
    Notification::fake();
    withoutVite();

    $person = User::factory()->create();
    $eget = raderingsytaEgetKonto($person);
    $delat = raderingsytaDelatKonto($person);

    actingAs($person);

    $länk = raderingsytaBegär($person);

    raderingslankSomGäst();

    get($länk)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/ConfirmUserDeletion')
            // Samma tre listor som säkerhetssidan, för tokenets person.
            ->where('deletion.accountsToDelete', [
                ['ulid' => $eget->ulid, 'name' => $eget->name],
            ])
            ->where('deletion.accountsToLeave', [
                ['ulid' => $delat->ulid, 'name' => $delat->name],
            ])
            ->where('deletion.blockers', [])
            ->where('deletion.warnings', []));

    // Mellansteget: ingen har raderats av att länken öppnades.
    expect(User::query()->whereKey($person->id)->exists())->toBeTrue()
        ->and(Account::query()->whereKey($eget->id)->exists())->toBeTrue();
});

it('rör ingen rad i user_deletion när länken öppnas', function () {
    Notification::fake();
    withoutVite();

    $person = User::factory()->create();
    raderingsytaEgetKonto($person);

    actingAs($person);

    $länk = raderingsytaBegär($person);

    $före = UserDeletion::query()->where('user_id', $person->id)->sole();

    get($länk)->assertOk();

    // Varken förbrukad, förlängd eller rörd: `GET` läser raden och ingenting
    // annat. Det är det som gör att en mejlskanner inte kan radera något.
    $efter = UserDeletion::query()->where('user_id', $person->id)->sole();

    expect($efter->confirmed_at)->toBeNull()
        ->and($efter->getKey())->toBe($före->getKey())
        ->and($efter->expires_at->equalTo($före->expires_at))->toBeTrue()
        ->and($efter->updated_at->equalTo($före->updated_at))->toBeTrue();
});

// --- Raderingen ----------------------------------------------------------

it('raderar personen när en gäst skickar bekräftelsen', function () {
    Notification::fake();

    $person = User::factory()->create();
    $konto = raderingsytaEgetKonto($person);

    actingAs($person);

    $länk = raderingsytaBegär($person);

    raderingslankSomGäst();

    post($länk)
        ->assertRedirect('/')
        ->assertSessionHas('status', 'user-deleted');

    // Personen, kontot där hon var ensam, och raden som bar bekräftelsen.
    expect(User::query()->whereKey($person->id)->exists())->toBeFalse()
        ->and(Account::query()->whereKey($konto->id)->exists())->toBeFalse()
        ->and(UserDeletion::query()->where('user_id', $person->id)->count())->toBe(0);
});

it('loggar ut den som raderar sig själv', function () {
    Notification::fake();

    $person = User::factory()->create();
    raderingsytaEgetKonto($person);

    actingAs($person);

    $länk = raderingsytaBegär($person);

    post($länk)
        ->assertRedirect('/')
        ->assertSessionHas('status', 'user-deleted');

    // Sessionen pekade på en person som inte finns; den ska inte leva vidare.
    assertGuest();
});

it('raderar tokenets person när någon annan är inloggad, och lämnar den inloggade kvar', function () {
    Notification::fake();
    withoutVite();

    $person = User::factory()->create();
    raderingsytaEgetKonto($person);

    actingAs($person);

    $länk = raderingsytaBegär($person);

    // En annan person, i sin egen webbläsare. Tokenet avgör vem som raderas —
    // hon ska se samma sida och förbli inloggad efteråt.
    $annan = User::factory()->create();

    actingAs($annan);

    get($länk)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Settings/ConfirmUserDeletion'));

    post($länk)
        ->assertRedirect('/')
        ->assertSessionHas('status', 'user-deleted');

    expect(User::query()->whereKey($person->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey($annan->id)->exists())->toBeTrue();

    assertAuthenticatedAs($annan);
});

// --- En spärr mellan begäran och länken -----------------------------------

it('visar spärren på bekräftelsesidan och stoppar raderingen', function () {
    Notification::fake();
    withoutVite();

    $person = User::factory()->create();
    $konto = raderingsytaEgetKonto($person);

    actingAs($person);

    $länk = raderingsytaBegär($person);

    // Spärren uppstår EFTER begäran: en rättslig spärr läggs på kontot.
    // Begäran kunde tas emot — spärren fanns inte då. Den delade containern
    // är ingen spärr sedan 2026-11-14, så den rättsliga spärren är det som
    // fortfarande stoppar på den här vägen.
    LegalHold::factory()->create(['account_id' => $konto->id]);

    raderingslankSomGäst();

    get($länk)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/ConfirmUserDeletion')
            ->where('deletion.blockers.0.code', DeletionBlocker::CODE_LEGAL_HOLD));

    post($länk)
        ->assertRedirect(route('settings.delete-user.confirm', ['token' => basename($länk)]))
        ->assertSessionHas('status', 'user-deletion-blocked');

    expect(User::query()->whereKey($person->id)->exists())->toBeTrue()
        ->and(Account::query()->whereKey($konto->id)->exists())->toBeTrue();

    // Förbrukningen rullades tillbaka med allt annat: ingenting hände, och
    // länken är fortfarande lösbar. Spärren kan ju hävas inom timmen.
    expect(UserDeletion::query()
        ->where('user_id', $person->id)
        ->whereNull('confirmed_at')
        ->count())->toBe(1);

    // Hävs spärren går samma länk igenom.
    DB::table('legal_hold')->where('account_id', $konto->id)->update(['lifted_at' => now()]);

    post($länk)->assertRedirect('/');

    expect(User::query()->whereKey($person->id)->exists())->toBeFalse();
});

// --- En upplysning mellan begäran och länken -----------------------------

/*
 * Klart när: bekräftelsen visar upplysningarna som de ser ut NU — en medlem
 * som tillkommit efter begäran syns — och kräver `confirm_warnings`.
 * Upplysningen är ingen spärr, så ett fältfel är hela hindret; länken
 * förbrukas inte av det (transaktionen rullas tillbaka), och samma länk går
 * igenom när rutan är ikryssad.
 */
it('visar upplysningen på bekräftelsesidan, kräver bekräftelse och förbrukar inte länken på ett fältfel', function () {
    Notification::fake();
    withoutVite();

    $person = User::factory()->create();
    $konto = raderingsytaEgetKonto($person);

    actingAs($person);

    // Begäran görs innan det finns något att upplysa om.
    $länk = raderingsytaBegär($person);

    // Medlemmen tillkommer EFTER begäran. Sidan räknar om underlaget vid varje
    // GET, så det är den här medlemmen som syns och kräver samtycke.
    $container = raderingsytaContainer($konto);
    $medlem = User::factory()->create(['name' => 'Maja Medlem']);
    beviljaAccess($container, $medlem, 'read', 'managed');

    raderingslankSomGäst();

    get($länk)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/ConfirmUserDeletion')
            ->where('deletion.blockers', [])
            ->where('deletion.warnings.0.code', DeletionWarning::CODE_SHARED_CONTAINER)
            ->where('deletion.warnings.0.account', $konto->name)
            ->where('deletion.warnings.0.containers', [$container->name])
            ->where('deletion.warnings.0.members', ['Maja Medlem']));

    // Utan rutan: ett fältfel, och ingenting raderat.
    from($länk)
        ->post($länk, [])
        ->assertSessionHasErrors('confirm_warnings');

    expect(User::query()->whereKey($person->id)->exists())->toBeTrue()
        ->and(Account::query()->whereKey($konto->id)->exists())->toBeTrue()
        // Länken är fortfarande lösbar: fältfelet rullade tillbaka
        // förbrukningen med resten av transaktionen.
        ->and(UserDeletion::query()
            ->where('user_id', $person->id)
            ->whereNull('confirmed_at')
            ->count())->toBe(1);

    // Med rutan går SAMMA länk igenom.
    post($länk, ['confirm_warnings' => true])
        ->assertRedirect('/')
        ->assertSessionHas('status', 'user-deleted');

    expect(User::query()->whereKey($person->id)->exists())->toBeFalse();
});

// --- En länk som inte gäller ----------------------------------------------

it('renderar en egen sida med 404 för ett okänt token', function () {
    withoutVite();

    $okänt = route('settings.delete-user.confirm', ['token' => Str::random(64)]);

    get($okänt)
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/UserDeletionLinkInvalid'));

    post($okänt)->assertNotFound();
});

it('ger 404 för ett utgånget token, och raderar ingenting på POST', function () {
    Notification::fake();
    withoutVite();

    $person = User::factory()->create();
    raderingsytaEgetKonto($person);

    actingAs($person);

    $länk = raderingsytaBegär($person);

    travel(61)->minutes();

    get($länk)
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/UserDeletionLinkInvalid'));

    raderingslankSomGäst();

    post($länk)->assertNotFound();

    expect(User::query()->whereKey($person->id)->exists())->toBeTrue()
        ->and(UserDeletion::query()->where('user_id', $person->id)->whereNull('confirmed_at')->count())->toBe(1);
});

it('ger 404 för ett redan förbrukat token, och raderar ingenting på POST', function () {
    withoutVite();

    $person = User::factory()->create();
    raderingsytaEgetKonto($person);

    // En förbrukad rad, som den ser ut efter en genomförd radering. Provet
    // ställer upp tillståndet direkt: i det verkliga flödet raderas raden med
    // personen, så en andra öppning är en gäst som möts av 404 på samma sätt.
    $raw = Str::random(64);

    UserDeletion::query()->create([
        'user_id' => $person->id,
        'token_hash' => hash('sha256', $raw),
        'expires_at' => now()->addHour(),
        'confirmed_at' => now(),
    ]);

    $länk = route('settings.delete-user.confirm', ['token' => $raw]);

    get($länk)
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/UserDeletionLinkInvalid'));

    post($länk)->assertNotFound();

    expect(User::query()->whereKey($person->id)->exists())->toBeTrue();
});

it('ogiltigförklarar den tidigare länken när en ny begäran görs', function () {
    Notification::fake();
    withoutVite();

    $person = User::factory()->create();
    raderingsytaEgetKonto($person);

    actingAs($person);

    $första = raderingsytaBegär($person);

    travel(1)->minutes();

    $andra = raderingsytaBegär($person);

    // Den gamla länken slutar gälla direkt, och den nya gäller. Raden ligger
    // kvar — den är beviset på att begäran gjordes — men `expires_at` sattes
    // till nu, och `isFuture()` är strängt.
    get($första)->assertNotFound();

    expect(User::query()->whereKey($person->id)->exists())->toBeTrue();

    get($andra)->assertOk();

    post($andra)->assertRedirect('/');

    expect(User::query()->whereKey($person->id)->exists())->toBeFalse();
});
