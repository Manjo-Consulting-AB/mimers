<?php

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * Pests globala hjälpfunktioner används i stället för $this->get(...).
 * PHPStan kan inte härleda vad $this är bundet till inne i en Pest-closure,
 * medan funktionerna har riktiga returtyper. Så förblir tests/ analyserat
 * utan en enda undertryckning. Se ADR-0022.
 */

/*
 * Issue 1244 · Startsidan renderar inte längre en Inertia-sida, se
 * routes/web.php och Tonys beslut 2026-11-14. Den omdirigerar TILLFÄLLIGT —
 * tills landningssidan finns — och statusen är därför 302 och aldrig 301,
 * som en webbläsare hade cachat.
 */
it('skickar en gäst från rotrutten till inloggningssidan', function () {
    get('/')
        ->assertStatus(302)
        ->assertRedirect(route('login'));
});

it('skickar en inloggad från rotrutten direkt till dashboarden', function () {
    actingAs(User::factory()->create());

    get('/')
        ->assertStatus(302)
        ->assertRedirect(route('dashboard'));
});

it('har hälsokontrollen påslagen', function () {
    get('/up')->assertOk();
});
