<?php

use App\Actions\Inbox\ResolveInbox;
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
 * M27 · issue 246 (GitHub #759) — knappen *New task* på de tre ytorna och
 * formuläret `/tasks/create`, se [[ADR-0054 Inboxen]] § 5 och § 8,
 * App\Http\Controllers\TaskCreateController,
 * resources/js/pages/Tasks/Create.vue och
 * resources/js/components/ScheduleForm.vue.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet prov här. Den sista
 * — hela testsviten är grön — är CI:s uppgift och ingen egen rad.
 *
 * Filen är delvis ett KÄLLKODSPROV och delvis ett sidprov: knappen på de tre
 * ytorna går att avgöra ur markupen, medan platsen, 403:an, skrivningarna och
 * `return` kräver en riktig begäran. Samma uppdelning som UppgiftsgruppTest.
 *
 * Hjälparna har prefixet `nyuppgift` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * En källfil med kommentarer borta. Samma tre slag som GenomgangTest rensar —
 * blockkommentarer, HTML-kommentarer och radkommentarer — så att docblocken
 * (svenska med flit, AGENTS.md § Språk i koden) inte kan läsas som kod.
 */
function nyuppgiftKod(string $sokvag): string
{
    $kod = File::get(resource_path($sokvag));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * En person, hennes personkonto och en container kontot äger.
 *
 * @return array{0: User, 1: Account, 2: Container}
 */
function nyuppgiftKontext(): array
{
    $person = User::factory()->create();
    $konto = Account::factory()->create(['type' => 'personal']);
    $konto->users()->attach($person, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$person, $konto, $container];
}

/**
 * Ett item i containern, med sammanhängande `created_by_*`.
 */
function nyuppgiftItem(Container $container, string $namn = 'Motorn'): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

// --- de tre ytorna ----------------------------------------------------------

/*
 * Klart när: de tre ytorna har knappen New task.
 *
 * Beslut 2. Knappen ritas på itemets flik, containerns flik och `/tasks`, och
 * alla tre leder till samma formulär. Itemets flik bär sitt item i adressen
 * (`?item=`), de två andra leder dit utan — en container är inte ett item, och
 * på `/tasks` finns ingen plats alls (Beslut 1).
 */
it('de tre ytorna har knappen New task', function () {
    $ytor = [
        'js/components/ScheduleListSection.vue',
        'js/pages/Containers/Tasks.vue',
        'js/pages/Tasks/Index.vue',
    ];

    foreach ($ytor as $fil) {
        expect(nyuppgiftKod($fil))
            ->toContain("t('todo.new')")
            ->toContain('/tasks/create');
    }

    // Itemets flik förväljer itemet (Beslut 2), och de två andra gör det inte.
    expect(nyuppgiftKod('js/components/ScheduleListSection.vue'))->toContain('?item=');

    foreach ([
        'js/pages/Containers/Tasks.vue',
        'js/pages/Tasks/Index.vue',
    ] as $fil) {
        expect(nyuppgiftKod($fil))->not->toContain('?item=');
    }

    expect(trans('ui.todo.new', [], 'en'))->toBe('New task');
});

/*
 * Klart när: formuläret postar till inboxen när platsen är Inbox, och
 * nycklarna finns (Beslut 1 och 4).
 *
 * Sidan är den enda som vet var POST:en ska — ScheduleForm får sin adress av
 * anroparen — så kontrollen hör hit och inte till komponenten.
 */
it('formuläret postar till inboxen utan plats', function () {
    $sida = nyuppgiftKod('js/pages/Tasks/Create.vue');

    expect($sida)->toContain("'/inbox/tasks'")
        ->toContain('ItemTargetPicker')
        ->toContain("t('todo.create.place')")
        ->toContain("t('todo.create.inbox')")
        ->toContain("t('todo.create.change')");

    expect(trans('ui.todo.create.title', [], 'en'))->toBe('New task');
    expect(trans('ui.todo.create.place', [], 'en'))->toBe('Place');
    expect(trans('ui.todo.create.inbox', [], 'en'))->toBe('Inbox');
    expect(trans('ui.todo.create.change', [], 'en'))->toBe('Change…');
});

// --- platsen ----------------------------------------------------------------

/*
 * Klart när: itemets knapp förväljer itemet.
 *
 * Beslut 1: `/tasks/create?item=<ulid>` skickar itemet OCH containern.
 * Platsfältet visar båda, och POST-adressen byggs ur dem — itemets egen rutt.
 */
it('itemets knapp förväljer itemet', function () {
    withoutVite();

    [$person, , $container] = nyuppgiftKontext();
    $item = nyuppgiftItem($container);

    actingAs($person)->get("/tasks/create?item={$item->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tasks/Create')
            ->where('item.ulid', $item->ulid)
            ->where('item.name', 'Motorn')
            ->where('item.container.ulid', $container->ulid)
            ->where('item.container.name', $container->name));
});

/*
 * Klart när: utan item är inboxen förvald.
 *
 * Beslut 1. Sidan får ingen plats av servern, och vyn väljer inboxen — det är
 * samma svar som containerns flik och `/tasks` får, och det är där en uppgift
 * utan sammanhang hör (ADR-0054 § 8).
 */
it('utan item är inboxen förvald', function () {
    withoutVite();

    [$person] = nyuppgiftKontext();

    actingAs($person)->get('/tasks/create')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tasks/Create')
            ->where('item', null));

    // Vyn ritar platsfältet ur `item`, och utan item är valet inboxen.
    expect(nyuppgiftKod('js/pages/Tasks/Create.vue'))
        ->toContain('const place = ref(props.item)')
        ->toContain("'inbox'");
});

/*
 * Arkitektsvaret punkt 2: *List*-fältet döljs när platsen är *Inbox*.
 *
 * En uppgift i inboxen är obearbetad och har ingen lista (ADR-0054 § 5), så
 * ett fält som inte får någon verkan ska varken ritas eller skickas. Sidan
 * binder `showList` till platsen — false för inboxen, true för ett item — och
 * då ritar formuläret fältet igen, med *Next* förvalt (§ 5).
 *
 * Ett källkodsprov, som de andra vy-kontrollerna: `showList` är en propp och
 * en `v-if`, och formen går att avgöra ur markupen. Hjälparen stryker
 * kommentarerna först, så bara koden kan fälla provet.
 */
it('listväljaren döljs för inboxen', function () {
    expect(nyuppgiftKod('js/pages/Tasks/Create.vue'))
        ->toContain(':show-list="place !== null"');

    expect(nyuppgiftKod('js/components/ScheduleForm.vue'))
        ->toContain('showList')
        ->toContain('schedule === null && showList');
});

/*
 * Klart när: ett item användaren inte får skapa i ger 403.
 *
 * Grinden är ITEMETS `create` — samma pinne som målväljaren filtrerar sin lista
 * med (App\Actions\Item\ListCreatableItems) och som
 * ScheduleController::create() prövar. En `read`-mottagare når itemet men får
 * inte lägga något på det, och då är platsen ingen plats.
 */
it('ett item användaren inte får skapa i ger 403', function () {
    withoutVite();

    [, , $container] = nyuppgiftKontext();
    $item = nyuppgiftItem($container);

    $lasare = User::factory()->create();
    beviljaAccess($container, $lasare, 'read', 'member');

    actingAs($lasare)->get("/tasks/create?item={$item->ulid}")->assertForbidden();

    // En ULID som inte finns är 404 och inte 403 — objektet först, grinden
    // sedan, samma ordning som ScheduleController::move().
    actingAs($lasare)->get('/tasks/create?item=01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
});

/*
 * Fynd i granskningen: ett inbox-item är ingen plats.
 *
 * Inboxen nås bara genom sina egna ytor (ADR-0054 § 2), och
 * App\Actions\Item\ListCreatableItems listar den aldrig — målväljaren erbjuder
 * den aldrig som mål. Ägaren FÅR skapa på sitt inbox-item, så grinden nekar
 * inte; platsen nekas därför som 404, samma svar som en ULID utan träff.
 */
it('ett inbox-item är ingen plats på /tasks/create', function () {
    withoutVite();

    [$person] = nyuppgiftKontext();

    $inbox = app(ResolveInbox::class)->handle($person);

    actingAs($person)->get("/tasks/create?item={$inbox->ulid}")->assertNotFound();
});

/*
 * Fynd i granskningen: en mjukraderad container ger 404 och inte 500.
 *
 * Itemet finns, men containern är mjukraderad: den eager-laddade raden blir
 * null, och ett senare `$item->container->ulid` hade kastat. Objektet först,
 * grinden sedan — samma 404 som en ULID utan träff.
 */
it('ett item i en mjukraderad container ger 404', function () {
    withoutVite();

    [$person, , $container] = nyuppgiftKontext();
    $item = nyuppgiftItem($container);

    $container->delete();

    actingAs($person)->get("/tasks/create?item={$item->ulid}")->assertNotFound();
});

// --- skrivningarna ----------------------------------------------------------

/*
 * Klart när: en uppgift utan plats hamnar i inboxen utan lista.
 *
 * Beslut 1 och ADR-0054 § 5. Formuläret postar HELA sin kropp till
 * `POST /inbox/tasks`, och uppgiften är obearbetad: `gtd_list` finns inte i
 * reglerna, och `CreateSchedule` nollar både förekomstens lista och schemats
 * förval. Kroppen bär `gtd_list` med flit — den ska tappas tyst och inte fälla
 * anropet.
 */
it('en uppgift utan plats hamnar i inboxen utan lista', function () {
    withoutVite();

    [$person] = nyuppgiftKontext();

    actingAs($person)->post('/inbox/tasks', [
        'title' => 'Ring leverantören',
        'notes' => 'Kvittot ligger i bilen',
        'recurrence_type' => 'none',
        'anchor_date' => '2026-11-01',
        'gtd_list' => 'next',
        'return' => '/tasks',
    ])->assertRedirect('/tasks');

    $inbox = app(ResolveInbox::class)->handle($person);
    $schema = Schedule::query()->where('item_id', $inbox->id)->firstOrFail();

    expect($schema->title)->toBe('Ring leverantören');
    expect($schema->notes)->toBe('Kvittot ligger i bilen');
    expect($schema->recurrence_type)->toBe('none');
    expect($schema->default_gtd_list)->toBeNull();

    $förekomst = $schema->openOccurrence()->firstOrFail();

    expect($förekomst->gtd_list)->toBeNull();
    expect($förekomst->due_at?->toDateString())->toBe('2026-11-01');
});

/*
 * Klart när: en uppgift med plats hamnar på itemet med next.
 *
 * Beslut 1 och ADR-0054 § 5: den befintliga ruttens svar är oförändrat, och
 * `return` flyttar bara vart 302:an går. Utan `return` landar samma POST på
 * itemet — den gamla ytans beteende, som issue 63a:s sida lever på.
 */
it('en uppgift med plats hamnar på itemet med next', function () {
    withoutVite();

    [$person, , $container] = nyuppgiftKontext();
    $item = nyuppgiftItem($container);

    actingAs($person)->post("/containers/{$container->ulid}/items/{$item->ulid}/schedules", [
        'title' => 'Byt impeller',
        'recurrence_type' => 'none',
        'gtd_list' => 'next',
        'return' => '/tasks?list=next',
    ])->assertRedirect('/tasks?list=next');

    $schema = Schedule::query()->where('item_id', $item->id)->firstOrFail();

    expect($schema->default_gtd_list)->toBe('next');
    expect($schema->openOccurrence()->firstOrFail()->gtd_list)->toBe('next');

    // Utan `return` är svaret itemets detaljvy — den gamla ytans väg.
    actingAs($person)->post("/containers/{$container->ulid}/items/{$item->ulid}/schedules", [
        'title' => 'Byt olja',
        'recurrence_type' => 'none',
    ])->assertRedirect("/containers/{$container->ulid}/items/{$item->ulid}");
});

/*
 * Klart när: `return` till en extern adress ignoreras.
 *
 * Beslut 3. Bara en relativ adress duger: `//evil.example` är
 * protokollrelativ och läses av webbläsaren som en annan värd, och en
 * omdirigering dit är ett öppet mål. Båda skrivrutterna prövar samma regel —
 * App\Http\Controllers\TaskCreateController::safeReturn() — så provet gör det
 * med.
 */
it('return till en extern adress ignoreras', function () {
    withoutVite();

    [$person, , $container] = nyuppgiftKontext();
    $item = nyuppgiftItem($container);

    $externa = [
        'https://evil.example',
        '//evil.example',
        '/\\evil.example',
        'evil.example',
        // WHATWG-parsern tar bort tab, LF och CR var som helst i adressen, så
        // dessa blir `//evil.example` i webbläsaren om de släpps igenom.
        "/\t/evil.example",
        "/\n/evil.example",
        "/\t\\evil.example",
    ];

    foreach ($externa as $extern) {
        actingAs($person)->post("/containers/{$container->ulid}/items/{$item->ulid}/schedules", [
            'title' => 'Byt impeller',
            'recurrence_type' => 'none',
            'return' => $extern,
        ])->assertRedirect('/tasks');
    }

    foreach ($externa as $extern) {
        actingAs($person)->post('/inbox/tasks', [
            'title' => 'Ring leverantören',
            'recurrence_type' => 'none',
            'return' => $extern,
        ])->assertRedirect('/tasks');
    }

    // Och den relativa adressen släpps igenom, med sin querysträng.
    actingAs($person)->post("/containers/{$container->ulid}/items/{$item->ulid}/schedules", [
        'title' => 'Byt olja',
        'recurrence_type' => 'none',
        'return' => '/tasks?list=next',
    ])->assertRedirect('/tasks?list=next');
});
