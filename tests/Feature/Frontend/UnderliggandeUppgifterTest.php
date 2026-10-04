<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * M24 · Uppgifterna på items UNDER det här itemet — testarnas fynd 2026-10-03,
 * vidareutvecklat av issue 227 (GitHub #721).
 *
 * Fliken *Tasks* visar förekomsterna på itemet och, med reglaget *Include child
 * items* på, på varje ättling — barn, barnbarn, utan djuptak. Varje rad bär sitt
 * eget item och sin egen avbockning, så ett barns uppgift kan stängas från
 * förälderns flik och `back()` landar här igen.
 *
 * **Fram till issue 227 var ättlingarna en EGEN sektion** efter itemets egna
 * scheman (`descendantOccurrences`), med en egen rad och en egen rubrik. Sedan
 * issue 227 är de en del av samma grupperade lista som containerns flik
 * (`itemTasks`), och raden är `TodoRow`s. Proven för listan, reglaget,
 * behörigheten per rad, pausade och blockerade och den konstanta frågekostnaden
 * bor i tests/Feature/Frontend/ItemetsUppgiftslistaTest.php; den här filen bär
 * kvar HTTP-vägen — avbockningen mot barnets egen rutt och omdirigeringen
 * tillbaka till förälderns flik.
 *
 * Hjälparna har prefixet `underliggande` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem, och en container.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function underliggandeKontext(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare, Container::factory()->for($konto, 'account')->create()];
}

function underliggandeItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * En `parent`-kant — den kanoniska riktningen, samma som LinkItems skriver.
 */
function underliggandeKant(Item $foralder, Item $barn): void
{
    ItemLink::query()->insert([
        'from_item_id' => $foralder->id,
        'to_item_id' => $barn->id,
        'relation' => 'parent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Trädet issuen ritar: Motorn → Impellern → Packningen, med syskonet Släpet.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item, 4: Item, 5: Item, 6: Item}
 */
function underliggandeTrad(): array
{
    [$konto, $anvandare, $container] = underliggandeKontext();

    $baten = underliggandeItem($container, 'Båten');
    $motorn = underliggandeItem($container, 'Motorn');
    $slapet = underliggandeItem($container, 'Släpet');
    $impellern = underliggandeItem($container, 'Impellern');
    $packningen = underliggandeItem($container, 'Packningen');

    underliggandeKant($baten, $motorn);
    underliggandeKant($baten, $slapet);
    underliggandeKant($motorn, $impellern);
    underliggandeKant($impellern, $packningen);

    return [$konto, $anvandare, $container, $motorn, $slapet, $impellern, $packningen];
}

/**
 * @param  array<string, mixed>  $attribut
 */
function underliggandeSchema(Item $item, array $attribut = []): Schedule
{
    return Schedule::factory()->for($item, 'item')->create(array_merge([
        'title' => 'Byt olja',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2027-05-05',
        'lead_days' => 0,
        'is_active' => true,
    ], $attribut));
}

/**
 * Den ÖPPNA förekomsten, byggd direkt så att förfallodatumet är känt.
 *
 * @param  array<string, mixed>  $attribut
 */
function underliggandeForekomst(Schedule $schedule, string $due, array $attribut = []): ScheduleOccurrence
{
    return ScheduleOccurrence::factory()->create(array_merge([
        'schedule_id' => $schedule->id,
        'due_at' => $due,
        'visible_from' => $due,
        'status' => 'open',
    ], $attribut));
}

function underliggandeUrl(Container $container, Item $item, ?string $tab = null): string
{
    $url = "/containers/{$container->ulid}/items/{$item->ulid}";

    return $tab === null ? $url : "{$url}?tab={$tab}";
}

function underliggandeStangUrl(Container $container, Item $item, Schedule $schema, ScheduleOccurrence $forekomst, string $action): string
{
    return underliggandeUrl($container, $item)."/schedules/{$schema->ulid}/occurrences/{$forekomst->ulid}/{$action}";
}

/*
 * Klart när: ägaren postar avbockningen med PACKNINGENS ulid från Motorns
 * flik, omdirigeras tillbaka dit och förekomsten är stängd — och barnets rad
 * ligger i den nya grupperade listan på förälderns flik.
 */
it('bockar av ett barns uppgift från förälderns flik och kommer tillbaka dit', function () {
    withoutVite();

    [$konto, $anvandare, $container, $motorn, , , $packningen] = underliggandeTrad();

    $schema = underliggandeSchema($packningen, ['title' => 'Byt rem']);
    $oppen = underliggandeForekomst($schema, '2027-06-01');

    $motornsFlik = underliggandeUrl($container, $motorn, 'schedules');

    // Den nya raden: barnets förekomst ligger i grupperna, med barnets eget
    // item — den enda vägen till avbockningen mot barnets rutt.
    actingAs($anvandare)->get($motornsFlik)->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('itemTasks.groups.upcoming.0.schedule.title', 'Byt rem')
            ->where('itemTasks.groups.upcoming.0.item.ulid', $packningen->ulid)
    );

    // `scopeBindings()` kräver att `{item}` är schemats item — därför barnets
    // ulid och inte sidans — och kontrollern svarar `back()`.
    actingAs($anvandare)->from($motornsFlik)
        ->post(underliggandeStangUrl($container, $packningen, $schema, $oppen, 'complete'), ['account' => $konto->ulid])
        ->assertRedirect($motornsFlik)
        ->assertSessionHas('status', 'occurrence-completed');

    expect($oppen->fresh()->status)->toBe('completed');
});
