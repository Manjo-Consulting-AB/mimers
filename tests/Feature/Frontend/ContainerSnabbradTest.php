<?php

use App\Actions\Inbox\ResolveInbox;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 358 (GitHub #1126) · Snabbraden på containerns To do-flik, se
 * [[ADR-0074 Registrering i vyn]] § 2–4 och 6, M52,
 * resources/js/components/ContainerTaskQuickCreate.vue och
 * resources/js/pages/Containers/Tasks.vue.
 *
 * Delvis KÄLLKODSPROV (vyns form går att avgöra ur markupen; fokus och
 * 2000 ms-timern kräver en webbläsare och står i PR-kroppen), delvis sidprov
 * mot de två skrivvägarna som redan finns.
 *
 * Hjälparna har prefixet `snabbrad` — Pest delar namnrymd mellan filerna.
 */

function snabbradKod(string $sokvag): string
{
    $kod = File::get(resource_path($sokvag));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * @return array{0: User, 1: Container, 2: Item}
 */
function snabbradKontext(): array
{
    $person = User::factory()->create();
    $konto = Account::factory()->create(['type' => 'personal']);
    $konto->users()->attach($person, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $person->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$person, $container, $item];
}

it('flikens vy har lämnat knappen New task', function () {
    $vy = snabbradKod('js/pages/Containers/Tasks.vue');

    expect($vy)->not->toContain('/tasks/create')
        ->and($vy)->not->toContain("t('todo.new')");
});

it('flikens vy ritar snabbraden före listan och det tomma läget, men inte på done', function () {
    $vy = snabbradKod('js/pages/Containers/Tasks.vue');

    expect($vy)->toContain('<ContainerTaskQuickCreate')
        ->and($vy)->toContain("v-if=\"list !== 'done'\"")
        ->and($vy)->toContain(':container-ulid="container.ulid"')
        ->and($vy)->toContain('Object.values(props.groups).flat()');

    expect(strpos($vy, '<ContainerTaskQuickCreate'))
        ->toBeLessThan(strpos($vy, '<UiEmptyState'))
        ->toBeLessThan(strpos($vy, '<TaskGroup'));
});

it('postar till inboxen när platsen är null och till itemet annars, med return', function () {
    $kod = snabbradKod('js/components/ContainerTaskQuickCreate.vue');

    expect($kod)->toContain("'/inbox/tasks'")
        ->and($kod)->toContain('/containers/${props.containerUlid}/items/${item.ulid}/schedules')
        ->and($kod)->toContain('preserveScroll: true')
        ->and($kod)->toContain('preserveState: true')
        ->and($kod)->toContain("useForm({ title: '' })");

    // Båda grenarna bär `return`.
    expect(substr_count($kod, 'return: page.url'))->toBe(2);
});

it('skickar gtd_list bara mot ett item: flikens lista för de tre lagrade, annars next', function () {
    $kod = snabbradKod('js/components/ContainerTaskQuickCreate.vue');

    expect($kod)->toContain("['next', 'waiting', 'someday'].includes(props.list) ? props.list : 'next'");

    // Inboxens gren bär ingen gtd_list.
    $inboxgren = substr($kod, (int) strpos($kod, 'if (item === null) {'), 80);

    expect($inboxgren)->not->toContain('gtd_list');
});

it('platsen är null vid montering och sparas inte i localStorage', function () {
    $kod = snabbradKod('js/components/ContainerTaskQuickCreate.vue');

    expect($kod)->toContain('const place = ref(null)')
        ->and($kod)->not->toContain('localStorage');
});

it('ger väljaren containerns ULID', function () {
    expect(snabbradKod('js/components/ContainerTaskQuickCreate.vue'))
        ->toContain(':container="containerUlid"')
        ->toContain(":heading=\"t('todo.create.place')\"");
});

it('återställer title och sätter fokus efter en sparning', function () {
    $kod = snabbradKod('js/components/ContainerTaskQuickCreate.vue');

    expect($kod)->toContain("form.reset('title')")
        ->and($kod)->toContain('quick.value?.focus()');
});

it('visar hidden med role status när ingen ny ULID kom med, och skickar created annars', function () {
    $kod = snabbradKod('js/components/ContainerTaskQuickCreate.vue');

    expect($kod)->toContain('role="status"')
        ->and($kod)->toContain("t('container.tasks.quick.hidden')")
        ->and($kod)->toContain('props.entryUlids.filter((ulid) => ! before.has(ulid))')
        ->and($kod)->toContain("emit('created', added)");
});

it('markerar nya rader med bg-accent-soft i 2000 ms', function () {
    $vy = snabbradKod('js/pages/Containers/Tasks.vue');

    expect($vy)->toContain(":class=\"{ 'bg-accent-soft': highlighted.has(entry.ulid) }\"")
        ->and($vy)->toContain('ref(new Set())')
        ->and($vy)->toContain('}, 2000)');
});

it('ger snabbraden fältets ord ur katalogen', function () {
    $kod = snabbradKod('js/components/ContainerTaskQuickCreate.vue');

    expect($kod)->toContain("t('container.tasks.quick.label')")
        ->and($kod)->toContain("t('container.tasks.quick.placeholder')")
        ->and($kod)->toContain("t('container.tasks.quick.submit')")
        ->and($kod)->toContain(':error="form.errors.title ?? null"');
});

// vakt: skrivvägen finns sedan issue 245/63a och ska fortsätta ta emot return; ingen serverändring i issuen.
it('en POST till /inbox/tasks med return till fliken omdirigerar dit med flashen', function () {
    withoutVite();

    [$person, $container] = snabbradKontext();

    actingAs($person)->post('/inbox/tasks', [
        'title' => 'Ring leverantören',
        'return' => "/containers/{$container->ulid}/tasks",
    ])->assertRedirect("/containers/{$container->ulid}/tasks")
        ->assertSessionHas('status', 'inbox-task-captured');

    $inbox = app(ResolveInbox::class)->handle($person);

    expect(Schedule::query()->where('item_id', $inbox->id)->count())->toBe(1);
});

// vakt: skrivvägen finns sedan issue 245/63a och ska fortsätta ta emot return; ingen serverändring i issuen.
it('en POST mot itemets schedules med gtd_list waiting omdirigerar till fliken', function () {
    withoutVite();

    [$person, $container, $item] = snabbradKontext();
    $retur = "/containers/{$container->ulid}/tasks?list=waiting";

    actingAs($person)->post("/containers/{$container->ulid}/items/{$item->ulid}/schedules", [
        'title' => 'Vänta på delen',
        'recurrence_type' => 'none',
        'gtd_list' => 'waiting',
        'return' => $retur,
    ])->assertRedirect($retur);

    $schema = Schedule::query()->where('item_id', $item->id)->firstOrFail();

    expect($schema->openOccurrence()->firstOrFail()->gtd_list)->toBe('waiting');
});

it('har de fyra nycklarna container.tasks.quick.*', function () {
    $texter = [
        'label' => 'New task title',
        'placeholder' => 'Add a task…',
        'submit' => 'Add',
        'hidden' => 'The task was added, but the current tab or filter hides it.',
    ];

    foreach ($texter as $nyckel => $text) {
        expect(Lang::get("ui.container.tasks.quick.{$nyckel}", [], 'en'))->toBe($text);
    }
});
