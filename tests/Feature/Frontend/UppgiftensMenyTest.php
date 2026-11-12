<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 1159 (M53): uppgiftens meny är en komponent, ScheduleActions.vue, som
 * både schemats sida och redigeringen ritar. Osparade ändringar i formuläret
 * gör att menyn frågar innan den kastar dem.
 */

/**
 * @return array{0: User, 1: Container, 2: Item, 3: Schedule}
 */
function menyUppgift(bool $inbox = false): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create(
        $inbox ? ['inbox_user_id' => $anvandare->id] : [],
    );
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);
    $schema = Schedule::factory()->for($item, 'item')->create([
        'title' => 'Byt olja',
        'recurrence_type' => 'none',
        'anchor_date' => '2027-05-05',
    ]);

    return [$anvandare, $container, $item, $schema];
}

function menyUrl(Container $container, Item $item, Schedule $schema): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}";
}

function menyKod(string $sokvag): string
{
    return File::get(base_path($sokvag));
}

it('ger redigeringen samma can och containerIsInbox som schemats sida', function () {
    withoutVite();

    [$anvandare, $container, $item, $schema] = menyUppgift();

    $can = null;
    $inbox = null;

    actingAs($anvandare)->get(menyUrl($container, $item, $schema))->assertOk()->assertInertia(
        function (AssertableInertia $page) use (&$can, &$inbox) {
            $props = $page->toArray()['props'];
            $can = $props['can'];
            $inbox = $props['containerIsInbox'];
        }
    );

    expect($can)->toBe(['update' => true, 'delete' => true]);
    expect($inbox)->toBeFalse();

    actingAs($anvandare)->get(menyUrl($container, $item, $schema).'/edit')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Schedules/Edit')
            ->where('can', $can)
            ->where('containerIsInbox', $inbox)
    );
});

it('ger redigeringen containerIsInbox sann för ett inbox-item', function () {
    withoutVite();

    [$anvandare, $container, $item, $schema] = menyUppgift(true);

    actingAs($anvandare)->get(menyUrl($container, $item, $schema).'/edit')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('containerIsInbox', true)
            ->where('can.delete', true)
    );
});

it('bygger can och containerIsInbox i en gemensam metod', function () {
    $kontroller = menyKod('app/Http/Controllers/ScheduleController.php');

    expect($kontroller)->toContain('private function menuProps(');
    expect(substr_count($kontroller, '...$this->menuProps('))->toBe(2);
    expect(substr_count($kontroller, "'containerIsInbox' =>"))->toBe(1);
});

it('ritar menyn på båda sidorna', function () {
    $visa = menyKod('resources/js/pages/Containers/Items/Schedules/Show.vue');
    $redigera = menyKod('resources/js/pages/Containers/Items/Schedules/Edit.vue');

    expect($visa)->toContain('<ScheduleActions')->toContain('context="show"');
    expect($redigera)->toContain('<ScheduleActions')->toContain('context="edit"')
        ->toContain(':dirty="dirty"')->toContain('@dirty="dirty = $event"');
    // Redigeringens egen tillbakalänk är borta.
    expect($redigera)->not->toContain('<Link');
});

it('redigeringens meny har Back to the task men ingen Edit', function () {
    $meny = menyKod('resources/js/components/ScheduleActions.vue');

    preg_match('#<Link\s[^>]*:href="editHref"[^>]*>#s', $meny, $edit);
    expect($edit)->not->toBeEmpty();
    expect($edit[0])->toContain('v-if="context === \'show\' && can.update"');

    preg_match('#<Link\s[^>]*:href="taskHref"[^>]*>#s', $meny, $uppgift);
    expect($uppgift)->not->toBeEmpty();
    expect($uppgift[0])->toContain('v-if="context === \'edit\'"');

    expect($meny)->toContain("t('item.schedule.back_to_task')");

    // Move…, Back to Inbox och Delete ritas i båda lägena.
    expect($meny)->toContain('v-if="can.delete && !containerIsInbox"')
        ->toContain("t('item.schedule.move')")
        ->toContain("t('item.schedule.destroy')");
});

it('låter Back to the task bära return när returnTo är satt', function () {
    $meny = menyKod('resources/js/components/ScheduleActions.vue');

    expect($meny)->toContain('const returnQuery = props.returnTo !== null ? `?return=${encodeURIComponent(props.returnTo)}` : \'\';');
    expect($meny)->toContain('const taskHref = `${scheduleActionUrl}${returnQuery}`;');
    expect($meny)->toContain('const editHref = `${scheduleActionUrl}/edit${returnQuery}`;');
});

it('har nyckeln item.schedule.back_to_task', function () {
    expect(Lang::get('ui.item.schedule.back_to_task', [], 'en'))->toBe('Back to the task');
});

it('låter ScheduleForm meddela dirty ur form.isDirty med en watch', function () {
    $formular = menyKod('resources/js/components/ScheduleForm.vue');

    expect($formular)->toContain("defineEmits(['dirty'])")
        ->toContain("watch(() => form.isDirty, (dirty) => emit('dirty', dirty), { immediate: true });");
});

it('frågar med inline_create.discard före varje handling när dirty är sann', function () {
    $meny = menyKod('resources/js/components/ScheduleActions.vue');

    expect($meny)->toContain('<UiConfirmDialog')
        ->toContain("t('inline_create.discard.heading')")
        ->toContain("t('inline_create.discard.cancel')")
        ->toContain("t('inline_create.discard.confirm')")
        ->toContain('dirty: { type: Boolean, default: false }');

    // ask() kör direkt utan osparade ändringar, annars öppnas dialogen.
    expect($meny)->toContain("function ask(run, trigger = null) {\n    if (! props.dirty) {\n        run();");
    expect($meny)->toContain('confirming.value = true;');

    // Varje länk och handling går genom ask()/guardLink().
    foreach (['toggle', 'toInbox', 'destroy'] as $handling) {
        expect($meny)->toContain("@click=\"ask({$handling}, \$event.currentTarget)\"");
    }
    expect($meny)->toContain('@click="ask(() => openPicker($event.currentTarget), $event.currentTarget)"');
    foreach (['back', 'editHref', 'taskHref'] as $lank) {
        expect($meny)->toContain("@click.capture=\"guardLink(\$event, {$lank})\"");
    }

    // Länkarna navigerar först efter bekräftelsen; Cancel gör ingenting;
    // Delete behåller sin egen window.confirm.
    expect($meny)->toContain('ask(() => router.visit(href), event.currentTarget);');
    expect($meny)->toContain('function cancelled() {')->toContain('afterConfirm = null;');
    expect($meny)->toContain("window.confirm(t('item.schedule.destroy_confirm'))");
});
