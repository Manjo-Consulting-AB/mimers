<?php

use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Pests globala hjälpfunktioner används i stället för $this->get(...).
 * PHPStan kan inte härleda vad $this är bundet till inne i en Pest-closure,
 * medan funktionerna har riktiga returtyper. Så förblir tests/ analyserat
 * utan en enda undertryckning. Se ADR-0022.
 */

it('svarar på rotrutten med en Inertia-sida', function () {
    withoutVite();

    get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Welcome')
            ->has('version')
        );
});

it('har hälsokontrollen påslagen', function () {
    get('/up')->assertOk();
});
