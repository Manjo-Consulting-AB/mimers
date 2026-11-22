<?php

use App\Actions\Access\ResolveItemScope;
use App\Actions\Container\ListShellContainers;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * Issue 1521 · Den låsta containern syns men går inte att öppna, se ADR-0092
 * (valvissue #1524). En inbjuden utan tvåfaktor ser containern med lås och
 * raden om tvåfaktor — på /containers, dashboarden och i sidopanelen — och
 * `/containers/{ulid}` svarar med låssidan. Allt annat är 403.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-02 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/** @return array{0: Container, 1: User} */
function lastBas(): array
{
    [$konto] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create([
        'name' => 'Vindil',
        'kind' => 'Hus',
        'require_two_factor' => true,
    ]);
    [, $mottagare] = kontoMedMedlem();
    beviljaAccess($container, $mottagare, 'write', 'guest');

    return [$container, $mottagare];
}

it('visar den låsta containern utan siffror på listan, dashboarden och i sidopanelen', function () {
    [$container, $mottagare] = lastBas();
    actingAs($mottagare);

    get('/containers')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('containers', 1)
        ->where('containers.0', ['ulid' => $container->ulid, 'name' => 'Vindil', 'kind' => 'Hus', 'locked' => true]));

    get('/dashboard')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('containerGroups.0.containers.0', ['ulid' => $container->ulid, 'name' => 'Vindil', 'locked' => true])
        ->where('stats.containers', 0));

    expect(app(ListShellContainers::class)->handle($mottagare))
        ->toBe([['kind' => null, 'containers' => [['ulid' => $container->ulid, 'name' => 'Vindil', 'locked' => true]]]]);
});

it('visar låssidan på direktlänken och 403 på alla andra containersidor', function () {
    [$container, $mottagare] = lastBas();
    actingAs($mottagare);

    get("/containers/{$container->ulid}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Containers/Locked')
        ->where('container', ['ulid' => $container->ulid, 'name' => 'Vindil', 'locked' => true])
        ->missing('counts'));

    get("/containers/{$container->ulid}/items")->assertForbidden();
});

it('ger en utomstående 403 och inte låssidan', function () {
    [$container] = lastBas();
    [, $annan] = kontoMedMedlem();
    actingAs($annan);

    get("/containers/{$container->ulid}")->assertForbidden();
});

it('visar containern som vanligt när tvåfaktor är påslagen', function () {
    [$container, $mottagare] = lastBas();
    $mottagare->forceFill(['totp_confirmed_at' => now()])->save();
    app(ResolveItemScope::class)->flush();
    auth()->forgetGuards();
    actingAs($mottagare->fresh());

    get('/containers')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('containers.0.locked', false)
        ->where('containers.0.ulid', $container->ulid)
        ->has('containers.0.can'));

    get("/containers/{$container->ulid}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Containers/Overview'));
});
