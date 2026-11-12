<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 1158 (M53): uppgiftens sida och redigeringen vet var användaren kom
 * ifrån. `?return=` prövas med TaskCreateController::safeReturn(), tillbaka-
 * länken går till `back`, och skrivningarna behåller adressen.
 */

/**
 * @return array{0: User, 1: Container, 2: Item, 3: Schedule}
 */
function returUppgift(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);
    $schema = Schedule::factory()->for($item, 'item')->create([
        'title' => 'Byt olja',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2027-05-05',
        'lead_days' => 0,
        'is_active' => true,
    ]);

    return [$anvandare, $container, $item, $schema];
}

function returUrl(Container $container, Item $item, Schedule $schema): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}";
}

function returFlik(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}?tab=schedules";
}

function returKod(string $sokvag): string
{
    return File::get(resource_path("js/{$sokvag}"));
}

it('ger sidan returTo och back ur en giltig ?return=', function (string $rutt) {
    withoutVite();
    [$anvandare, $container, $item, $schema] = returUppgift();

    actingAs($anvandare)->get(returUrl($container, $item, $schema).$rutt.'?return=%2Fcontainers%2Fabc%3Ftab%3Dtodo')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('returnTo', '/containers/abc?tab=todo')
            ->where('back', '/containers/abc?tab=todo'));
})->with(['uppgiftens sida' => [''], 'redigeringen' => ['/edit']]);

it('låter back peka på itemets To do-flik utan ?return=', function (string $rutt) {
    withoutVite();
    [$anvandare, $container, $item, $schema] = returUppgift();

    actingAs($anvandare)->get(returUrl($container, $item, $schema).$rutt)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('returnTo', null)
            ->where('back', returFlik($container, $item)));
})->with(['uppgiftens sida' => [''], 'redigeringen' => ['/edit']]);

it('faller tillbaka på To do-fliken för en extern eller ogiltig ?return=', function (string $rutt, string $return) {
    withoutVite();
    [$anvandare, $container, $item, $schema] = returUppgift();

    actingAs($anvandare)->get(returUrl($container, $item, $schema).$rutt.'?return='.rawurlencode($return))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('returnTo', null)
            ->where('back', returFlik($container, $item)));
})->with([
    'extern, sidan' => ['', '//evil.example'],
    'extern, redigeringen' => ['/edit', '//evil.example'],
    'absolut, sidan' => ['', 'https://evil.example'],
    'backslash, redigeringen' => ['/edit', '/\\evil.example'],
]);

it('behåller returadressen i en paus och känner fortfarande igen den som paus', function () {
    [$anvandare, $container, $item, $schema] = returUppgift();

    actingAs($anvandare)->patch(returUrl($container, $item, $schema), ['is_active' => false, 'return' => '/tasks?x=1'])
        ->assertRedirect(returUrl($container, $item, $schema).'?return='.rawurlencode('/tasks?x=1'))
        ->assertSessionHas('status', 'schedule-paused');

    expect($schema->fresh()->is_active)->toBeFalse();
});

it('behåller returadressen i en sparning från formuläret', function () {
    [$anvandare, $container, $item, $schema] = returUppgift();

    actingAs($anvandare)->patch(returUrl($container, $item, $schema), [
        'title' => 'Byt olja och filter',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 6,
        'anchor_date' => '2027-05-05',
        'lead_days' => 7,
        'return' => '/tasks',
    ])
        ->assertRedirect(returUrl($container, $item, $schema).'?return='.rawurlencode('/tasks'))
        ->assertSessionHas('status', 'schedule-updated');

    expect($schema->fresh()->title)->toBe('Byt olja och filter');
});

it('landar en sparning med extern return på uppgiftens sida utan return', function () {
    [$anvandare, $container, $item, $schema] = returUppgift();

    actingAs($anvandare)->patch(returUrl($container, $item, $schema), ['title' => 'Nytt', 'return' => '//evil.example'])
        ->assertRedirect(returUrl($container, $item, $schema));
});

it('landar raderingen på returadressen, eller på To do-fliken utan den', function () {
    [$anvandare, $container, $item, $schema] = returUppgift();

    actingAs($anvandare)->delete(returUrl($container, $item, $schema), ['return' => '/tasks?lista=idag'])
        ->assertRedirect('/tasks?lista=idag')
        ->assertSessionHas('status', 'schedule-deleted');

    [$anvandare, $container, $item, $schema] = returUppgift();

    actingAs($anvandare)->delete(returUrl($container, $item, $schema))
        ->assertRedirect(returFlik($container, $item));
});

it('faller tillbaka på To do-fliken när raderingens return är extern', function () {
    [$anvandare, $container, $item, $schema] = returUppgift();

    actingAs($anvandare)->delete(returUrl($container, $item, $schema), ['return' => '//evil.example'])
        ->assertRedirect(returFlik($container, $item));
});

it('länkar tillbaka till back och bär returadressen vidare i vyerna', function () {
    $visa = returKod('pages/Containers/Items/Schedules/Show.vue');
    $redigera = returKod('pages/Containers/Items/Schedules/Edit.vue');

    expect($visa)->toContain(':href="back"')->toContain("t('item.schedule.back_to_list')");
    expect($redigera)->toContain(':href="back"')->toContain("t('item.schedule.back_to_list')");

    // Edit-länken, pausen och raderingen bär returadressen vidare.
    expect($visa)->toContain('?return=${encodeURIComponent(props.returnTo)}');
    expect($visa)->toContain('...(props.returnTo !== null ? { return: props.returnTo } : {})');
    expect($visa)->toContain('data: props.returnTo !== null ? { return: props.returnTo } : {}');

    // Formuläret skickar return i PATCH:en när propen är satt.
    expect($redigera)->toContain(':return-to="returnTo"');
    expect(returKod('components/ScheduleForm.vue'))
        ->toContain('returnTo: { type: String, default: null }')
        ->toContain('props.schedule !== null && props.returnTo !== null ? { return: props.returnTo } : {}');
});

it('TodoRow länkar till uppgiften via showHref med page.url, och lämnar scheduleHref orörd', function () {
    $rad = returKod('components/TodoRow.vue');

    expect($rad)->toContain('const showHref = computed(() => `${scheduleHref.value}?return=${encodeURIComponent(page.url)}`);');
    expect($rad)->toContain('<Link :href="showHref"');
    expect($rad)->toContain('`${scheduleHref.value}/occurrences/${props.entry.ulid}`');
    expect($rad)->toContain('router.post(`${scheduleHref.value}/move`');
});
