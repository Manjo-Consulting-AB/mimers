<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Issue 137 · Användarens dag i vyn, se [[ADR-0044 Användarens dag]] § Beslut
 * 4, HandleInertiaRequests::share() och tests/Feature/Frontend/DatumregelTest.
 *
 * Servern skickar användarens `today` (`Y-m-d`) och `timezone` (IANA) till
 * varje sida, och datumregeln räknar relativa datum och klockslag mot dem i
 * stället för mot webbläsarens klocka. Här prövas den delade YTAN: att båda
 * propparna finns på varje sida, att de följer användarens tidszon — kontots
 * när hon inte valt en egen — och att en gäst får `null`.
 *
 * **Komposabeln kan inte köras i node.** `usePage()` har ingen sida utanför en
 * Inertia-app, så räkningen prövas med sina argument i DatumregelTest och
 * kopplingen till propparna här, som ett källkodsprov — samma uppdelning som
 * DatumregelTest gör för `locale`.
 *
 * Tiderna är valda för att skilja dagarna åt: 23:30 UTC är 01:30 dagen efter i
 * Stockholm och 19:30 samma dag i New York. Vid lunch hade alla varit ense,
 * och ett fel i regeln hade inte synts.
 */

it('delar användarens dag och tidszon med varje sida', function () {
    withoutVite();

    $konto = Account::factory()->create(['timezone' => 'Europe/Stockholm']);
    $anvandare = User::factory()->create(['timezone' => 'Europe/Stockholm']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    Carbon::setTestNow(Carbon::parse('2026-09-26 23:30:00', 'UTC'));

    foreach (['/dashboard', '/tasks'] as $url) {
        actingAs($anvandare)->get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('today', '2026-09-27')
            ->where('timezone', 'Europe/Stockholm')
        );
    }

    Carbon::setTestNow();
});

it('delar ingen dag och ingen tidszon med en gäst', function () {
    withoutVite();

    get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('today', null)
        ->where('timezone', null)
        ->where('auth.user', null)
    );
});

/*
 * Regeln är User::preferredTimezone(): användarens egen zon, annars kontots,
 * annars appens. En användare som inte valt någon får alltså sin dag ur
 * kontots zon — samma dag som itemträdet och todo-listan räknar
 * ([[Konton och åtkomst]] § user).
 */
it('räknar med kontots tidszon när användaren inte valt en egen', function () {
    withoutVite();

    $konto = Account::factory()->create(['timezone' => 'America/New_York']);
    $anvandare = User::factory()->create(['timezone' => null]);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    Carbon::setTestNow(Carbon::parse('2026-09-26 23:30:00', 'UTC'));

    actingAs($anvandare)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('today', '2026-09-26')
        ->where('timezone', 'America/New_York')
    );

    Carbon::setTestNow();
});

it('läser dagen och tidszonen ur de delade propparna', function () {
    $källa = File::get(resource_path('js/composables/useRelativeDate.js'));

    // Dagen ur `today`, med webbläsarens dag som reserv när proppen saknas
    // (ADR-0044 § Beslut 4), och tidszonen ur `timezone`.
    expect($källa)->toContain('parseDateOnly(usePage().props.today) ?? now()')
        ->and($källa)->toContain('usePage().props.timezone');
});
