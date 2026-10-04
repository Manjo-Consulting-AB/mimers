<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\OccurrenceDependency;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * M24 · issue 227 (GitHub #721) · Itemets uppgiftsflik blir en lista av
 * förekomster med *Include child items*.
 *
 * Fliken *Tasks* (`?tab=schedules`) visar förekomster i samma grupper och med
 * samma rad som containerns flik: *Overdue → Today → This week → Upcoming →
 * Done*. Reglaget *Include child items* avgör om förekomsterna på ättlingarna
 * räknas med, också i *Done*. Regellistan med pausa, redigera och radera bor
 * på schemats egen sida sedan issue 226.
 *
 * Filen bevisar serverns beslut:
 *
 * 1. `App\Actions\Schedule\ListItemTasks` frågar samma mängd som `/tasks`,
 *    avgränsad till itemet — eller itemet och hela underträdet när reglaget är
 *    på. Mängden är id:n, så ett barn med två parents ritas en gång.
 * 2. Behörigheten prövas PER RAD: en `child`-kant ResolveItemDescendants läser
 *    men ResolveItemScope inte gör får en rad som `view`-grinden fäller —
 *    både öppen och i *Done*.
 * 3. Reglaget står i querysträngen (`?children=0`), och förvalet är på.
 * 4. Pausade och blockerade förekomster står kvar, med `paused` respektive
 *    `blocked` satt på raden.
 *
 * Källkodsproven för vyn ligger sist i filen: fliken ritar grupperna och
 * reglaget men inga regelknappar, och `TodoRow` märker pausad och blockerad.
 *
 * Hjälparna har prefixet `itemuppgift` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

beforeEach(function () {
    // En onsdag, mitt på dagen UTC: servern går i UTC och användaren i
    // Europe/Stockholm ([[ADR-0044 Användarens dag]]), och en känd veckodag
    // krävs för att `this_week` ska vara en icke-tom grupp.
    Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett ägarkonto med en medlem, och en container.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function itemuppgiftKontext(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare, Container::factory()->for($konto, 'account')->create()];
}

function itemuppgiftItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * En kant skriven direkt i tabellen. `$från` är föräldern för en `parent`-rad
 * — den kanoniska riktningen — och barnet för en `child`-rad, som
 * ResolveItemDescendants tolkar omvänt.
 */
function itemuppgiftKant(Item $från, Item $till, string $relation = 'parent'): void
{
    ItemLink::query()->insert([
        'from_item_id' => $från->id,
        'to_item_id' => $till->id,
        'relation' => $relation,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $attribut
 */
function itemuppgiftSchema(Item $item, string $titel, array $attribut = []): Schedule
{
    return Schedule::factory()->for($item, 'item')->create(array_merge([
        'title' => $titel,
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2026-01-01',
        'lead_days' => 0,
        'is_active' => true,
    ], $attribut));
}

/**
 * En ÖPPEN förekomst med känt förfallodatum.
 *
 * @param  array<string, mixed>  $attribut
 */
function itemuppgiftOppen(Schedule $schema, string $due, array $attribut = []): ScheduleOccurrence
{
    return ScheduleOccurrence::factory()->create(array_merge([
        'schedule_id' => $schema->id,
        'due_at' => $due,
        'visible_from' => $due,
        'status' => 'open',
    ], $attribut));
}

/**
 * En AVBOCKAD förekomst. `$nar` är när den blev klar — sorteringsnyckeln i
 * *Done*.
 *
 * @param  array<string, mixed>  $attribut
 */
function itemuppgiftKlar(Schedule $schema, ?Carbon $nar = null, array $attribut = []): ScheduleOccurrence
{
    return ScheduleOccurrence::factory()->create(array_merge([
        'schedule_id' => $schema->id,
        'due_at' => '2026-01-01',
        'visible_from' => '2026-01-01',
        'status' => 'completed',
        'completed_at' => $nar ?? Carbon::now(),
    ], $attribut));
}

function itemuppgiftUrl(Container $container, Item $item, ?string $fraga = null): string
{
    $url = "/containers/{$container->ulid}/items/{$item->ulid}";

    return $fraga === null ? $url : "{$url}?{$fraga}";
}

/**
 * En grupp ur svaret.
 *
 * @return list<array<string, mixed>>
 */
function itemuppgiftGrupp(TestResponse $svar, string $namn): array
{
    /** @var array<string, list<array<string, mixed>>> $grupper */
    $grupper = $svar->inertiaProps()['itemTasks']['groups'];

    return $grupper[$namn];
}

/**
 * @param  list<array<string, mixed>>  $rader
 * @return list<string>
 */
function itemuppgiftTitlar(array $rader): array
{
    return array_map(fn (array $rad): string => $rad['schedule']['title'], $rader);
}

/**
 * En itemgrant på angiven nivå. `kind` är `guest`: mottagaren står utanför
 * ägarkontot och når bara det granten pekar på.
 */
function itemuppgiftGrant(Container $container, Item $item, User $mottagare, string $niva): void
{
    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $niva,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * Källkoden med kommentarer borta, ur en sökväg relativt repots rot — samma
 * tre slag som GenomgangTest rensar.
 */
function itemuppgiftKod(string $sokvag): string
{
    $kod = File::get(base_path($sokvag));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

// --- grupperna: itemets egna förekomster -----------------------------------

/*
 * Klart när: en rad i var och en av overdue, today, this_week och upcoming.
 */
it('visar itemets egna förekomster i grupperna', function () {
    withoutVite();

    [, $anvandare, $container] = itemuppgiftKontext();
    $motorn = itemuppgiftItem($container, 'Motorn');

    itemuppgiftOppen(itemuppgiftSchema($motorn, 'Försenad'), '2026-10-06');
    itemuppgiftOppen(itemuppgiftSchema($motorn, 'I dag'), '2026-10-07');
    itemuppgiftOppen(itemuppgiftSchema($motorn, 'I veckan'), '2026-10-10');
    itemuppgiftOppen(itemuppgiftSchema($motorn, 'Kommande'), '2026-10-20');

    $svar = actingAs($anvandare)->get(itemuppgiftUrl($container, $motorn, 'tab=schedules'))->assertOk();

    expect(itemuppgiftTitlar(itemuppgiftGrupp($svar, 'overdue')))->toBe(['Försenad'])
        ->and(itemuppgiftTitlar(itemuppgiftGrupp($svar, 'today')))->toBe(['I dag'])
        ->and(itemuppgiftTitlar(itemuppgiftGrupp($svar, 'this_week')))->toBe(['I veckan'])
        ->and(itemuppgiftTitlar(itemuppgiftGrupp($svar, 'upcoming')))->toBe(['Kommande']);
});

// --- reglaget: ättlingarna, och två parents --------------------------------

/*
 * Klart när: barn och barnbarn syns utan `children`-parameter, och
 * `includeChildren === true`.
 */
it('tar med ättlingarnas förekomster som förval', function () {
    withoutVite();

    [, $anvandare, $container] = itemuppgiftKontext();
    $motorn = itemuppgiftItem($container, 'Motorn');
    $impellern = itemuppgiftItem($container, 'Impellern');
    $packningen = itemuppgiftItem($container, 'Packningen');

    itemuppgiftKant($motorn, $impellern);
    itemuppgiftKant($impellern, $packningen);

    itemuppgiftOppen(itemuppgiftSchema($motorn, 'Egen'), '2026-10-08');
    itemuppgiftOppen(itemuppgiftSchema($impellern, 'Barn'), '2026-10-09');
    itemuppgiftOppen(itemuppgiftSchema($packningen, 'Barnbarn'), '2026-10-10');

    $svar = actingAs($anvandare)->get(itemuppgiftUrl($container, $motorn, 'tab=schedules'))->assertOk();

    expect($svar->inertiaProps()['includeChildren'])->toBeTrue()
        ->and(itemuppgiftTitlar(itemuppgiftGrupp($svar, 'this_week')))->toBe(['Egen', 'Barn', 'Barnbarn']);
});

/*
 * Klart när: samma data med `children=0` ger bara itemets egna rader.
 */
it('children=0 visar bara itemets egna', function () {
    withoutVite();

    [, $anvandare, $container] = itemuppgiftKontext();
    $motorn = itemuppgiftItem($container, 'Motorn');
    $impellern = itemuppgiftItem($container, 'Impellern');

    itemuppgiftKant($motorn, $impellern);

    itemuppgiftOppen(itemuppgiftSchema($motorn, 'Egen'), '2026-10-08');
    itemuppgiftOppen(itemuppgiftSchema($impellern, 'Barn'), '2026-10-09');

    $svar = actingAs($anvandare)->get(itemuppgiftUrl($container, $motorn, 'tab=schedules&children=0'))->assertOk();

    expect($svar->inertiaProps()['includeChildren'])->toBeFalse()
        ->and(itemuppgiftTitlar(itemuppgiftGrupp($svar, 'this_week')))->toBe(['Egen']);
});

/*
 * Klart när: ett barn med parent A och B ritas exakt en gång på A:s flik.
 *
 * Mängden är id:n, så den ANDRA vägen till samma barn ändrar ingenting — det
 * är hela skillnaden mot en lista per kant.
 */
it('visar ett barn med två parents en gång', function () {
    withoutVite();

    [, $anvandare, $container] = itemuppgiftKontext();
    $a = itemuppgiftItem($container, 'Motorn');
    $b = itemuppgiftItem($container, 'Släpet');
    $barnet = itemuppgiftItem($container, 'Impellern');

    itemuppgiftKant($a, $barnet);
    itemuppgiftKant($b, $barnet);

    itemuppgiftOppen(itemuppgiftSchema($barnet, 'Byt rem'), '2026-10-10');

    $svar = actingAs($anvandare)->get(itemuppgiftUrl($container, $a, 'tab=schedules'))->assertOk();

    expect(itemuppgiftTitlar(itemuppgiftGrupp($svar, 'this_week')))->toBe(['Byt rem']);
});

// --- behörigheten: per rad, och efter radens item --------------------------

/*
 * Klart när: en rå `child`-rad från Smyget till Motorn gör Smyget till en
 * ättling i ResolveItemDescendants men inte i ResolveItemScope — mottagarens
 * flik visar varken Smygets öppna rad eller dess avbockade.
 */
it('visar aldrig en förekomst på ett item mottagaren inte når', function () {
    withoutVite();

    [, $agaren, $container] = itemuppgiftKontext();
    $motorn = itemuppgiftItem($container, 'Motorn');
    $impellern = itemuppgiftItem($container, 'Impellern');

    itemuppgiftKant($motorn, $impellern);

    // ResolveItemDescendants tolkar en `child`-rad som sin motsats: Smyget
    // blir ett BARN till Motorn. ResolveItemScope läser bara `parent`-rader,
    // så mottagarens omfång når inte Smyget — `view`-grinden per rad är
    // skyddet, både öppet och i *Done*.
    $smyget = itemuppgiftItem($container, 'Smyget');
    itemuppgiftKant($smyget, $motorn, 'child');

    itemuppgiftOppen(itemuppgiftSchema($smyget, 'Byt lina'), '2026-10-10');
    itemuppgiftKlar(itemuppgiftSchema($smyget, 'Byt lina, klar'));
    itemuppgiftOppen(itemuppgiftSchema($impellern, 'Byt olja'), '2026-10-11');
    itemuppgiftKlar(itemuppgiftSchema($impellern, 'Byt olja, klar'));

    // Ägaren når hela containern och ser allt.
    $agarens = actingAs($agaren)->get(itemuppgiftUrl($container, $motorn, 'tab=schedules'))->assertOk();

    expect(itemuppgiftTitlar(itemuppgiftGrupp($agarens, 'this_week')))->toBe(['Byt lina', 'Byt olja'])
        ->and(itemuppgiftTitlar($agarens->inertiaProps()['itemTasks']['completed']))->toBe(['Byt olja, klar', 'Byt lina, klar']);

    // En read-mottagare på Motorn ser Impellern — men Smyget finns inte i
    // hennes omfång, och raden ritas inte alls.
    $mottagare = User::factory()->create(['locale' => 'sv_SE']);
    itemuppgiftGrant($container, $motorn, $mottagare, 'read');

    $hennes = actingAs($mottagare)->get(itemuppgiftUrl($container, $motorn, 'tab=schedules'))->assertOk();

    expect(itemuppgiftTitlar(itemuppgiftGrupp($hennes, 'this_week')))->toBe(['Byt olja'])
        ->and(itemuppgiftTitlar($hennes->inertiaProps()['itemTasks']['completed']))->toBe(['Byt olja, klar']);

    expect($hennes->getContent())->not->toContain('Byt lina');
});

// --- pausade och blockerade ------------------------------------------------

/*
 * Klart när: `paused === true` och `blocked === true` på sina rader.
 */
it('tar med pausade och blockerade och märker dem', function () {
    withoutVite();

    [, $anvandare, $container] = itemuppgiftKontext();
    $motorn = itemuppgiftItem($container, 'Motorn');
    $impellern = itemuppgiftItem($container, 'Impellern');

    itemuppgiftKant($motorn, $impellern);

    // Pausat schema: förekomsten ligger kvar, märkt.
    itemuppgiftOppen(
        itemuppgiftSchema($impellern, 'Pausad', ['is_active' => false]),
        '2026-10-10',
    );

    // Ett öppet beroende blockerar raden.
    $blockerare = itemuppgiftOppen(itemuppgiftSchema($motorn, 'Byt impeller'), '2026-10-08');
    $blockerad = itemuppgiftOppen(itemuppgiftSchema($impellern, 'Byt rem'), '2026-10-09');

    OccurrenceDependency::factory()->create([
        'occurrence_id' => $blockerad->id,
        'depends_on_occurrence_id' => $blockerare->id,
    ]);

    itemuppgiftOppen(itemuppgiftSchema($motorn, 'Vanlig'), '2026-10-11');

    // Ännu inte synlig: `visible_from` ligger i framtiden, men listan visar en
    // öppen förekomst från att den skapas — inget `visible_from`-filter
    // (Beslut 1, samma regel som `ListTodo` sedan issue 698).
    itemuppgiftOppen(
        itemuppgiftSchema($motorn, 'Osynlig'),
        '2026-10-12',
        ['visible_from' => '2026-11-01'],
    );

    $svar = actingAs($anvandare)->get(itemuppgiftUrl($container, $motorn, 'tab=schedules'))->assertOk();

    $rader = collect(itemuppgiftGrupp($svar, 'this_week'))->keyBy(fn (array $rad): string => $rad['schedule']['title']);

    expect($rader['Pausad']['paused'])->toBeTrue()
        ->and($rader['Pausad']['blocked'])->toBeFalse()
        ->and($rader['Byt rem']['blocked'])->toBeTrue()
        ->and($rader['Byt rem']['paused'])->toBeFalse()
        ->and($rader['Vanlig']['paused'])->toBeFalse()
        ->and($rader['Vanlig']['blocked'])->toBeFalse()
        // Den ännu inte synliga ligger i *Upcoming* och saknar märken.
        ->and(itemuppgiftTitlar(itemuppgiftGrupp($svar, 'upcoming')))->toBe(['Osynlig']);
});

// --- Done ------------------------------------------------------------------

/*
 * Klart när: avbockade på itemet och ättlingarna, nyast först, högst tjugo,
 * utan överhoppade.
 */
it('Done visar avbockade på itemet och ättlingarna, nyast först, högst tjugo, utan överhoppade', function () {
    withoutVite();

    [, $anvandare, $container] = itemuppgiftKontext();
    $motorn = itemuppgiftItem($container, 'Motorn');
    $impellern = itemuppgiftItem($container, 'Impellern');

    itemuppgiftKant($motorn, $impellern);

    // Motorn: en avbockad. Impellern: tjugo — ett över taket.
    itemuppgiftKlar(itemuppgiftSchema($motorn, 'Egen klar'), Carbon::parse('2026-10-07 11:00:00'));

    foreach (range(1, 20) as $i) {
        itemuppgiftKlar(
            itemuppgiftSchema($impellern, "Barn {$i}"),
            Carbon::parse('2026-10-07 10:00:00')->subMinutes($i),
        );
    }

    // En överhoppad förekomst hör i historiken, inte under *Klart*.
    itemuppgiftKlar(
        itemuppgiftSchema($impellern, 'Hoppad'),
        Carbon::parse('2026-10-07 12:30:00'),
        ['status' => 'skipped'],
    );

    $svar = actingAs($anvandare)->get(itemuppgiftUrl($container, $motorn, 'tab=schedules'))->assertOk();
    $klara = $svar->inertiaProps()['itemTasks']['completed'];

    expect($klara)->toHaveCount(20)
        // Nyast först: den egna klara (11:00) ligger före barnen (09:59 och
        // äldre).
        ->and($klara[0]['schedule']['title'])->toBe('Egen klar');

    expect(itemuppgiftTitlar($klara))->not->toContain('Hoppad');
});

/*
 * Klart när: en avbockad förekomst på ett barn finns i `completed` som förval
 * och saknas med `children=0`.
 */
it('children=0 begränsar också Done till itemet', function () {
    withoutVite();

    [, $anvandare, $container] = itemuppgiftKontext();
    $motorn = itemuppgiftItem($container, 'Motorn');
    $impellern = itemuppgiftItem($container, 'Impellern');

    itemuppgiftKant($motorn, $impellern);

    itemuppgiftKlar(itemuppgiftSchema($motorn, 'Egen klar'), Carbon::parse('2026-10-07 11:00:00'));
    itemuppgiftKlar(itemuppgiftSchema($impellern, 'Barn klart'), Carbon::parse('2026-10-07 10:00:00'));

    $med = actingAs($anvandare)->get(itemuppgiftUrl($container, $motorn, 'tab=schedules'))->assertOk();
    $utan = actingAs($anvandare)->get(itemuppgiftUrl($container, $motorn, 'tab=schedules&children=0'))->assertOk();

    expect(itemuppgiftTitlar($med->inertiaProps()['itemTasks']['completed']))->toBe(['Egen klar', 'Barn klart'])
        ->and(itemuppgiftTitlar($utan->inertiaProps()['itemTasks']['completed']))->toBe(['Egen klar']);
});

/*
 * Klart när: ett avbockat `none`-schema ger ingen öppen rad men en rad i
 * *Done*.
 */
it('ett avslutat engångsschema syns i Done', function () {
    withoutVite();

    [, $anvandare, $container] = itemuppgiftKontext();
    $motorn = itemuppgiftItem($container, 'Motorn');

    $schema = itemuppgiftSchema($motorn, 'Byt impeller', [
        'recurrence_type' => 'none',
        'interval_unit' => null,
        'interval_count' => null,
    ]);

    itemuppgiftKlar($schema);

    $svar = actingAs($anvandare)->get(itemuppgiftUrl($container, $motorn, 'tab=schedules'))->assertOk();

    expect(itemuppgiftTitlar(itemuppgiftGrupp($svar, 'overdue')))->toBe([])
        ->and(itemuppgiftTitlar(itemuppgiftGrupp($svar, 'today')))->toBe([])
        ->and(itemuppgiftTitlar(itemuppgiftGrupp($svar, 'this_week')))->toBe([])
        ->and(itemuppgiftTitlar(itemuppgiftGrupp($svar, 'upcoming')))->toBe([])
        ->and(itemuppgiftTitlar($svar->inertiaProps()['itemTasks']['completed']))->toBe(['Byt impeller']);
});

// --- kostnaden -------------------------------------------------------------

/*
 * Klart när: tre respektive tolv rader på ett barn kostar samma antal frågor,
 * mätt med DB::listen.
 */
it('kostar ett konstant antal frågor oavsett antal rader', function () {
    withoutVite();

    [, $anvandare, $container] = itemuppgiftKontext();
    $motorn = itemuppgiftItem($container, 'Motorn');
    $impellern = itemuppgiftItem($container, 'Impellern');

    itemuppgiftKant($motorn, $impellern);

    itemuppgiftOppen(itemuppgiftSchema($impellern, 'Uppgift 1'), '2026-10-20');

    $url = itemuppgiftUrl($container, $motorn, 'tab=schedules');

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt.
    Carbon::setTestNow(now());

    // Värm sessionen så att den första frågan för `last_active_at` inte räknas.
    actingAs($anvandare)->get($url)->assertOk();

    $antal = 0;

    DB::listen(function ($query) use (&$antal) {
        if (! str_contains($query->sql, 'last_active_at')) {
            $antal++;
        }
    });

    $antal = 0;
    actingAs($anvandare)->get($url)->assertOk();
    $medEtt = $antal;

    // Nio uppgifter till på barnet: tolv totalt.
    foreach (range(2, 12) as $i) {
        itemuppgiftOppen(itemuppgiftSchema($impellern, "Uppgift {$i}"), '2026-10-20');
    }

    $antal = 0;
    actingAs($anvandare)->get($url)->assertOk();
    $medTolv = $antal;

    // Förekomsterna, de ivriga laddningarna och grindarna kostar detsamma
    // oavsett antal rader — ingen fråga per rad.
    expect($medTolv)->toBe($medEtt);
});

// --- vyn: grupperna och reglaget, och inga regelknappar --------------------

/*
 * Klart när: `ScheduleListSection.vue` innehåller `<TodoRow`,
 * `item.schedule.include_children` och `children`, och inte `router.delete`,
 * `is_active: !` eller `<OpenOccurrence`.
 */
it('fliken ritar grupperna och reglaget men inga regelknappar', function () {
    $sektionen = itemuppgiftKod('resources/js/components/ScheduleListSection.vue');

    // Den nekande halvan står för sig: ett `not` mitt i en `toContain`-kedja
    // fäller phpstan.
    expect($sektionen)->not->toContain('router.delete');
    expect($sektionen)->not->toContain('is_active: !');
    expect($sektionen)->not->toContain('<OpenOccurrence');

    expect($sektionen)->toContain('<TodoRow')
        ->toContain("t('item.schedule.include_children')")
        ->toContain('children')
        ->toContain('v-for="(entries, group) in groups"')
        ->toContain('v-if="entries.length > 0"')
        ->toContain('t(`todo.group.${group}`)')
        ->toContain("t('item.schedule.empty')");

    // Nycklarna finns i katalogen.
    expect(Lang::get('ui.item.schedule.include_children', [], 'en'))->toBe('Include child items');
});

/*
 * Klart när: `TodoRow.vue` innehåller `entry.paused` och `entry.blocked`, och
 * `trans('ui.todo.blocked')` är inte nyckeln själv.
 *
 * Båda märkenas ord bor i raden: `entry.paused` ritar `item.schedule.paused`
 * och `entry.blocked` ritar `todo.blocked` — raden äger både formen och ordet,
 * och `GenomgangTest` binder den pausade nyckeln vid den här filen.
 */
it('raden märker pausad och blockerad', function () {
    $rad = itemuppgiftKod('resources/js/components/TodoRow.vue');

    expect($rad)->toContain('entry.paused')
        ->toContain("t('item.schedule.paused')")
        ->toContain('entry.blocked')
        ->toContain("t('todo.blocked')");

    expect(Lang::get('ui.todo.blocked', [], 'en'))->not->toBe('ui.todo.blocked');
});
