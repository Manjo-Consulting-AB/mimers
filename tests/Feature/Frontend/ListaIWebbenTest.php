<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

use function Pest\Laravel\actingAs;

/*
 * M26 · issue 236 — listan, statusen och det frivilliga datumet i webben, se
 * [[ADR-0052 Uppgifternas listor och uppgifter utan datum]], förlagan
 * docs/Design/task-new.png och reglerna på servern från issue 235.
 *
 * Filen bevisar de nio "Klart när"-punkterna:
 *
 * 1. En engångsuppgift utan datum går att skapa från webben (`none` utan
 *    `anchor_date`), och förekomsten hamnar i `inbox`.
 * 2. Ett återkommande schema kräver fortfarande ett datum (422 på
 *    `anchor_date`).
 * 3. En uppgift kan skapas direkt i `next`.
 * 4. Formuläret bär *List* och *Reminder*; påminnelsen ritas bara med ett
 *    datum.
 * 5. Raden bär väljaren för listan och växeln *In progress*, båda bakom
 *    `entry.can.update`.
 * 6. Listan går att byta från raden — PATCH:en från `/tasks` svarar 302
 *    tillbaka och förekomsten ligger i den nya listan.
 * 7. En rad utan datum säger *No date* (`TodoRow` och `OpenOccurrence`).
 * 8. De tolv nya nycklarna finns i katalogen.
 * 9. (Hela sviten är grön — den sista punkten är svitens, inte filens.)
 *
 * Formvakterna (44 px, `id` på varje kontroll, `role="alert"` på felrutan)
 * ägs av tests/Feature/Frontend/GenomgangTest.php; här prövas BETEENDET och de
 * nya nycklarna.
 *
 * Hjälparna har prefixet `listaIWebben` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-14 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett ägarkonto med en medlem, en container och ett item.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function listaIWebbenKontext(): array
{
    [$konto, $anvandare] = kontoMedMedlem();

    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

function listaIWebbenSchemaUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}/schedules";
}

function listaIWebbenItemUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}";
}

/**
 * En förekomst i vald lista. Schemat är `none`, samma form produktionen ger
 * en engångsuppgift.
 */
function listaIWebbenUppgift(Item $item, string $lista = 'inbox'): ScheduleOccurrence
{
    $schema = Schedule::factory()->for($item, 'item')->create([
        'title' => 'Byt olja',
        'recurrence_type' => 'none',
        'interval_unit' => null,
        'interval_count' => null,
        'anchor_date' => '2027-05-05',
    ]);

    return ScheduleOccurrence::factory()->create([
        'schedule_id' => $schema->id,
        'due_at' => '2027-05-05',
        'visible_from' => '2027-05-05',
        'status' => 'open',
        'gtd_list' => $lista,
    ]);
}

function listaIWebbenFörekomstUrl(Container $container, Item $item, ScheduleOccurrence $forekomst): string
{
    return listaIWebbenSchemaUrl($container, $item)
        ."/{$forekomst->schedule->ulid}/occurrences/{$forekomst->ulid}";
}

// --- formuläret och valideringen -------------------------------------------

/*
 * Klart när: en engångsuppgift utan datum går att skapa från webben —
 * `anchor_date` är frivillig för `none` (Beslut 1, ADR-0052 § 3).
 */
it('skapar en engångsuppgift utan datum från webben', function () {
    [$konto, $anvandare, $container, $item] = listaIWebbenKontext();

    actingAs($anvandare)->post(listaIWebbenSchemaUrl($container, $item), [
        'title' => 'Kontrollera brandsläckaren',
        'recurrence_type' => 'none',
    ])
        ->assertRedirect(listaIWebbenItemUrl($container, $item))
        ->assertSessionHas('status', 'schedule-created');

    $schema = Schedule::query()->sole();

    expect($schema->anchor_date)->toBeNull();

    // Förekomsten öppnas utan datum i `next` — förvalet på ett riktigt item
    // när ingen lista valdes (M27 · issue 244, ADR-0054 § 5).
    $förekomst = $schema->openOccurrence()->sole();

    expect($förekomst->due_at)->toBeNull()
        ->and($förekomst->visible_from)->toBeNull()
        ->and($förekomst->gtd_list)->toBe('next');
});

/*
 * Klart när: ett återkommande schema kräver fortfarande datum (Beslut 1) —
 * `required_if` gäller `fixed` och `interval`.
 */
it('ett återkommande schema kräver fortfarande datum', function () {
    [$konto, $anvandare, $container, $item] = listaIWebbenKontext();

    actingAs($anvandare)->post(listaIWebbenSchemaUrl($container, $item), [
        'title' => 'Byt olja',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
    ])->assertSessionHasErrors('anchor_date');

    expect(Schedule::query()->count())->toBe(0);
});

/*
 * Klart när: en uppgift kan skapas direkt i `next` — listan i formuläret
 * skickas som `gtd_list` (Beslut 2).
 */
it('skapar en uppgift direkt i next', function () {
    [$konto, $anvandare, $container, $item] = listaIWebbenKontext();

    actingAs($anvandare)->post(listaIWebbenSchemaUrl($container, $item), [
        'title' => 'Byt impeller',
        'recurrence_type' => 'none',
        'gtd_list' => 'next',
    ])->assertRedirect(listaIWebbenItemUrl($container, $item));

    expect(Schedule::query()->sole()->openOccurrence()->sole()->gtd_list)->toBe('next');
});

// --- formulärets form (källkodsprov) ---------------------------------------

/*
 * Klart när: formuläret bär *List* och *Reminder* (Beslut 2 och 3). Ett
 * källkodsprov och inte en rendering: formen — väljarens två fältnamn och
 * påminnelsens `v-if` — går att avgöra ur markupen, som i UppgiftsradTest.
 */
it('formuläret har fälten list och reminder', function () {
    $vy = File::get(resource_path('js/components/ScheduleForm.vue'));

    // Skapande skickar `gtd_list`, redigering `default_gtd_list`.
    expect($vy)->toContain('gtd_list')
        ->toContain('default_gtd_list')
        ->toContain('item.schedule.form.reminder');

    // Påminnelsen ligger i ett `v-if` på datumet: utan `due_at` gör
    // `lead_days` ingenting, och fältet får inte stå kvar och lova en notis.
    expect($vy)->toMatch('/v-if="form\.anchor_date"[\s\S]*item\.schedule\.form\.reminder/');
});

// --- raden (källkodsprov) --------------------------------------------------

/*
 * Klart när: raden bär väljaren och växeln bakom `can.update` (Beslut 4).
 */
it('raden har väljaren och växeln bakom can.update', function () {
    $rad = File::get(resource_path('js/components/TodoRow.vue'));

    expect($rad)->toContain('aria-pressed')
        ->toContain('todo.list.')
        ->toContain('entry.can.update');

    // Båda kontrollerna står inuti samma grind — en `<template>` som kräver
    // både `can.update` och att raden inte är *Done*. Växelns `aria-pressed`
    // ligger innanför den.
    expect($rad)->toMatch('/v-if="! isDone && entry\.can\.update"[\s\S]*aria-pressed/');
});

// --- listan byts från raden (beteende) -------------------------------------

/*
 * Klart när: listan går att byta från raden (Beslut 4) — PATCH:en från
 * `/tasks` svarar 302 tillbaka och förekomsten ligger i den nya listan.
 */
it('byter lista från raden', function () {
    [$konto, $anvandare, $container, $item] = listaIWebbenKontext();

    $förekomst = listaIWebbenUppgift($item, 'inbox');

    actingAs($anvandare)
        ->from('/tasks')
        ->patch(listaIWebbenFörekomstUrl($container, $item, $förekomst), [
            'gtd_list' => 'waiting',
        ])
        ->assertRedirect('/tasks');

    expect($förekomst->fresh()->gtd_list)->toBe('waiting');
});

// --- raden utan datum ------------------------------------------------------

/*
 * Klart när: en rad utan datum säger *No date* (Beslut 5) — på båda ytorna
 * som ritar den, `/tasks`-raden och schemats sida.
 */
it('raden utan datum säger No date', function () {
    expect(File::get(resource_path('js/components/TodoRow.vue')))
        ->toContain('todo.no_date');

    expect(File::get(resource_path('js/components/OpenOccurrence.vue')))
        ->toContain('todo.no_date');
});

// --- katalogen -------------------------------------------------------------

/*
 * Klart när: de nya nycklarna finns (Beslut 6). Ordagrant ur beslutet — samma
 * lista binder orden och inte bara nyckelnamnen.
 *
 * **M27 · issue 244** ändrade två av dem: meningen under förvalsfältet
 * beskriver den nya regeln (förvalet sätts när uppgiften skapas eller
 * bearbetas ur inboxen), och `item.schedule.form.default_gtd_list_none` är
 * BORTA — alternativet *Not set* ritas inte längre (ADR-0054 § 5).
 */
it('har varje ny nyckel', function () {
    $nycklar = [
        'todo.list.inbox' => 'Inbox',
        'todo.list.next' => 'Next',
        'todo.list.waiting' => 'Waiting',
        'todo.list.someday' => 'Someday',
        'todo.list.label' => 'List',
        'todo.in_progress' => 'In progress',
        'todo.no_date' => 'No date',
        'item.schedule.form.gtd_list' => 'List',
        'item.schedule.form.default_gtd_list' => 'Default list for new occurrences',
        'item.schedule.form.default_gtd_list_hint' => 'The default is set when the task is created, or when it is processed from the inbox.',
        'item.schedule.form.reminder' => 'Reminder',
    ];

    expect($nycklar)->toHaveCount(11);

    foreach ($nycklar as $nyckel => $mening) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->toBe($mening, "{$nyckel} saknas eller har fel ord");
    }

    expect(Lang::has('ui.item.schedule.form.default_gtd_list_none', 'en'))->toBeFalse();
});
