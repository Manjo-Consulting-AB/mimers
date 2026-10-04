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
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * M24 · Uppgifterna på items UNDER det här itemet — testarnas fynd
 * 2026-10-03. Se App\Http\Controllers\ItemController::show() och
 * `descendantOccurrences()`, resources/js/components/ScheduleListSection.vue
 * och resources/js/pages/Containers/Items/Show.vue.
 *
 * Fliken *Tasks* ritar, efter itemets EGNA scheman, de öppna förekomsterna på
 * varje ättling — barn, barnbarn, utan djuptak. Varje rad säger vilket item
 * den hör till, länkar dit och bär sin egen avbockning.
 *
 * Filen bevisar de fyra besluten:
 *
 * 1. Ättlingarna kommer ur App\Actions\Item\ResolveItemDescendants, och
 *    itemet SJÄLVT räknas inte — den egna listan ritas redan.
 * 2. Behörigheten prövas PER RAD: en `child`-kant ResolveItemDescendants
 *    läser men ResolveItemScope inte gör får en rad som gaten fäller, och
 *    `can.update` följer radens item och inte sidans.
 * 3. Synligheten är varje ÖPPEN förekomst — pausade scheman, blockerade
 *    förekomster och ännu inte synliga datum följer med.
 * 4. Proppen finns bara på uppgiftsfliken, och kostar ett konstant antal
 *    frågor oavsett antal ättlingar och uppgifter.
 *
 * Hjälparna har prefixet `underliggande` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem, och en container. Båda på svenska, så meningarna
 * nedan kan jämföras mot `Lang::get(…, 'en')`.
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
 * En kant skriven direkt i tabellen. `$från` är föräldern för en `parent`-rad
 * — den kanoniska riktningen, samma som LinkItems skriver — och barnet för en
 * `child`-rad, som ResolveItemDescendants tolkar omvänt.
 */
function underliggandeKant(Item $från, Item $till, string $relation = 'parent'): void
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
 * Trädet issuen ritar: Motorn → Impellern → Packningen, med syskonet Släpet
 * (samma förälder som Motorn) och ett `related`-kopplat item vid sidan.
 *
 * Båten ligger ovanför och ritas aldrig: den är ingen ättling till Motorn.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item, 4: Item, 5: Item, 6: Item, 7: Item}
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

    // En `related`-kant bär ingenting och filtreras bort i FRÅGAN
    // ([[ADR-0035 Relationen mellan objekt]]).
    $filtret = underliggandeItem($container, 'Filtret');
    underliggandeKant($motorn, $filtret, 'related');

    return [$konto, $anvandare, $container, $motorn, $slapet, $impellern, $packningen, $filtret];
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

function underliggandeStangUrl(Container $container, Item $item, Schedule $schema, ScheduleOccurrence $förekomst, string $action): string
{
    return underliggandeUrl($container, $item)."/schedules/{$schema->ulid}/occurrences/{$förekomst->ulid}/{$action}";
}

/**
 * En itemgrant på angiven nivå. `kind` är `guest`: mottagaren står utanför
 * ägarkontot och når bara det granten pekar på.
 */
function underliggandeGrant(Container $container, Item $item, User $mottagare, string $niva): void
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

function underliggandeMottagare(Container $container, Item $item, string $niva): User
{
    $mottagare = User::factory()->create(['locale' => 'sv_SE']);
    underliggandeGrant($container, $item, $mottagare, $niva);

    return $mottagare;
}

// --- listan: barn och barnbarn, sorterade på förfallodatum -----------------

/*
 * Klart när: Motorns flik visar Packningens och Impellerns rader i
 * due_at-ordning med rätt item.ulid/item.name — och varken Motorns egen
 * uppgift, en stängd förekomst, ett mjukraderat schema, syskonet eller det
 * related-kopplade itemet.
 */
it('visar öppna uppgifter på barn och barnbarn med itemets namn, stigande på förfallodatum', function () {
    withoutVite();

    [, $anvandare, $container, $motorn, $slapet, $impellern, $packningen, $filtret] = underliggandeTrad();

    // Motorns EGEN uppgift ligger i `schedules` och ska inte upprepas här.
    underliggandeForekomst(underliggandeSchema($motorn, ['title' => 'Byt olja på motorn']), '2026-06-01');

    // Packningen förfaller FÖRST och Impellern sedan — ordningen prövas mot
    // titlarna, som sorterar tvärtom ("Byt olja" < "Byt rem").
    underliggandeForekomst(underliggandeSchema($packningen, ['title' => 'Byt rem']), '2026-11-01');
    underliggandeForekomst(underliggandeSchema($impellern, ['title' => 'Byt olja']), '2027-05-05');

    // En stängd förekomst är ingen öppen uppgift.
    underliggandeForekomst(
        underliggandeSchema($packningen, ['title' => 'Byt filter']),
        '2026-01-01',
        ['status' => 'completed'],
    );

    // Ett mjukraderat schema faller bort genom SoftDeletes' globala scope.
    $raderad = underliggandeSchema($impellern, ['title' => 'Byt rem']);
    underliggandeForekomst($raderad, '2026-02-01');
    $raderad->delete();

    // Varken syskonet eller det related-kopplade itemet hänger under Motorn.
    underliggandeForekomst(underliggandeSchema($slapet, ['title' => 'Byt lina']), '2026-03-01');
    underliggandeForekomst(underliggandeSchema($filtret, ['title' => 'Byt filter']), '2026-04-01');

    actingAs($anvandare)->get(underliggandeUrl($container, $motorn, 'schedules'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('descendantOccurrences', 2)
            ->where('descendantOccurrences.0.item.ulid', $packningen->ulid)
            ->where('descendantOccurrences.0.item.name', 'Packningen')
            ->where('descendantOccurrences.0.schedule.title', 'Byt rem')
            ->where('descendantOccurrences.0.occurrence.due_at', '2026-11-01')
            ->where('descendantOccurrences.1.item.ulid', $impellern->ulid)
            ->where('descendantOccurrences.1.item.name', 'Impellern')
            ->where('descendantOccurrences.1.schedule.title', 'Byt olja')
            ->where('descendantOccurrences.1.occurrence.due_at', '2027-05-05')
    );
});

/*
 * Klart när: ett pausat schema, en förekomst med öppet beroende och en
 * förekomst vars `visible_from` ligger i framtiden finns alla med.
 */
it('tar med pausade, blockerade och ännu inte synliga förekomster', function () {
    withoutVite();

    [, $anvandare, $container, $motorn, , $impellern, $packningen] = underliggandeTrad();

    $pausad = underliggandeForekomst(
        underliggandeSchema($impellern, ['title' => 'Byt olja', 'is_active' => false]),
        '2027-05-05',
    );

    // Blockeraren ligger på Motorn — den egna listan — och den blockerade på
    // Packningen. Beroendet är öppet, så förekomsten är blockerad och hade
    // nekats en avbockning. Den ritas ändå: synligheten är varje öppen rad
    // och inget annat.
    $blockerare = underliggandeForekomst(underliggandeSchema($motorn, ['title' => 'Byt impeller']), '2027-01-01');
    $blockerad = underliggandeForekomst(underliggandeSchema($packningen, ['title' => 'Byt rem']), '2027-06-01');

    OccurrenceDependency::factory()->create([
        'occurrence_id' => $blockerad->id,
        'depends_on_occurrence_id' => $blockerare->id,
    ]);

    // Ännu inte synlig: uppgiften dyker upp först 2027-07-01.
    $osynlig = underliggandeForekomst(
        underliggandeSchema($packningen, ['title' => 'Byt filter']),
        '2027-08-01',
        ['visible_from' => '2027-07-01'],
    );

    expect(OccurrenceDependency::query()->count())->toBe(1);

    actingAs($anvandare)->get(underliggandeUrl($container, $motorn, 'schedules'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('descendantOccurrences', 3)
            ->where('descendantOccurrences.0.item.name', 'Impellern')
            ->where('descendantOccurrences.0.schedule.title', 'Byt olja')
            ->where('descendantOccurrences.0.schedule.is_active', false)
            ->where('descendantOccurrences.0.occurrence.ulid', $pausad->ulid)
            ->where('descendantOccurrences.1.item.name', 'Packningen')
            ->where('descendantOccurrences.1.occurrence.ulid', $blockerad->ulid)
            ->where('descendantOccurrences.2.item.name', 'Packningen')
            ->where('descendantOccurrences.2.occurrence.ulid', $osynlig->ulid)
    );
});

// --- behörigheten: per rad, och efter radens item --------------------------

/*
 * Klart när: en rå `child`-rad från Smyget till Motorn gör Smyget till en
 * ättling i ResolveItemDescendants men inte i ResolveItemScope — ägaren ser
 * Smygets rad, en read-mottagare på Motorn gör det inte.
 */
it('visar aldrig en uppgift på ett item mottagaren inte når', function () {
    withoutVite();

    [, $agaren, $container, $motorn, , $impellern] = underliggandeTrad();

    // ResolveItemDescendants tolkar en `child`-rad som sin motsats: Smyget
    // blir ett BARN till Motorn. ResolveItemScope läser bara `parent`-rader,
    // så mottagarens omfång når inte Smyget — de två mängderna skiljer sig,
    // och `view`-grinden per rad är skyddet.
    $smyget = underliggandeItem($container, 'Smyget');
    underliggandeKant($smyget, $motorn, 'child');

    underliggandeForekomst(underliggandeSchema($smyget, ['title' => 'Byt lina']), '2027-01-01');
    underliggandeForekomst(underliggandeSchema($impellern, ['title' => 'Byt olja']), '2027-02-01');

    // Ägaren når hela containern och ser båda raderna.
    actingAs($agaren)->get(underliggandeUrl($container, $motorn, 'schedules'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('descendantOccurrences', 2)
            ->where('descendantOccurrences.0.item.name', 'Smyget')
            ->where('descendantOccurrences.1.item.name', 'Impellern')
    );

    // En read-mottagare på Motorn ser Impellern — men Smyget finns inte i
    // hennes omfång, och raden ritas inte alls.
    actingAs(underliggandeMottagare($container, $motorn, 'read'))
        ->get(underliggandeUrl($container, $motorn, 'schedules'))->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('descendantOccurrences', 1)
                ->where('descendantOccurrences.0.item.name', 'Impellern')
        );
});

/*
 * Klart när: en mottagare med `read` på Motorn och `write` på Packningen får
 * `can.update` sant på Packningens rad och falskt på Impellerns.
 */
it('ger avbockningen efter radens eget item', function () {
    withoutVite();

    [, , $container, $motorn, , $impellern, $packningen] = underliggandeTrad();

    underliggandeForekomst(underliggandeSchema($impellern, ['title' => 'Byt olja']), '2027-01-01');
    underliggandeForekomst(underliggandeSchema($packningen, ['title' => 'Byt rem']), '2027-02-01');

    // Läs på Motorn, skriv på Packningen: nivån på ett barn kan vara högre än
    // förälderns, och flaggan är per rad.
    $mottagare = underliggandeMottagare($container, $motorn, 'read');
    underliggandeGrant($container, $packningen, $mottagare, 'write');

    actingAs($mottagare)->get(underliggandeUrl($container, $motorn, 'schedules'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('descendantOccurrences', 2)
            ->where('descendantOccurrences.0.item.name', 'Impellern')
            ->where('descendantOccurrences.0.can.update', false)
            ->where('descendantOccurrences.1.item.name', 'Packningen')
            ->where('descendantOccurrences.1.can.update', true)
    );
});

/*
 * Klart när: ägaren postar avbockningen med PACKNINGENS ulid från Motorns
 * flik, omdirigeras tillbaka dit och förekomsten är stängd.
 */
it('bockar av ett barns uppgift från förälderns flik och kommer tillbaka dit', function () {
    withoutVite();

    [$konto, $anvandare, $container, $motorn, , , $packningen] = underliggandeTrad();

    $schema = underliggandeSchema($packningen, ['title' => 'Byt rem']);
    $oppen = underliggandeForekomst($schema, '2027-06-01');

    $motornsFlik = underliggandeUrl($container, $motorn, 'schedules');

    // `scopeBindings()` kräver att `{item}` är schemats item — därför barnets
    // ulid och inte sidans — och kontrollern svarar `back()`.
    actingAs($anvandare)->from($motornsFlik)
        ->post(underliggandeStangUrl($container, $packningen, $schema, $oppen, 'complete'), ['account' => $konto->ulid])
        ->assertRedirect($motornsFlik)
        ->assertSessionHas('status', 'occurrence-completed');

    expect($oppen->fresh()->status)->toBe('completed');
});

// --- proppen: bara på uppgiftsfliken, och till konstant kostnad ------------

/*
 * Klart när: utan `tab` och med `?tab=history` saknas proppen helt.
 */
it('hämtar underliggande uppgifter bara på uppgiftsfliken', function () {
    withoutVite();

    [, $anvandare, $container, $motorn, , $impellern] = underliggandeTrad();

    underliggandeForekomst(underliggandeSchema($impellern, ['title' => 'Byt olja']), '2027-01-01');

    // Översikten: ingen `tab`, ingen fråga.
    actingAs($anvandare)->get(underliggandeUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->missing('descendantOccurrences')
    );

    // En annan flik frågar inte heller.
    actingAs($anvandare)->get(underliggandeUrl($container, $motorn, 'history'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->missing('descendantOccurrences')
    );

    // Och uppgiftsfliken bär den.
    actingAs($anvandare)->get(underliggandeUrl($container, $motorn, 'schedules'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->has('descendantOccurrences', 1)
    );
});

/*
 * Klart när: ett barn med en uppgift mot tre nivåer med tio uppgifter kostar
 * samma antal frågor, mätt med DB::listen.
 */
it('kostar ett konstant antal frågor oavsett antal underliggande uppgifter', function () {
    withoutVite();

    [, $anvandare, $container, $motorn, , $impellern] = underliggandeTrad();

    underliggandeForekomst(underliggandeSchema($impellern, ['title' => 'Uppgift 1']), '2027-01-01');

    $url = underliggandeUrl($container, $motorn, 'schedules');

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 477): annars kan en sekundgräns lägga en UPDATE mellan de två
    // mätningarna och göra ett konstant tal olikt sig självt.
    Carbon::setTestNow(now());

    // Värm sessionen så att den första frågan för `last_active_at` inte räknas
    // med.
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

    // Tre nivåer under Motorn och tio uppgifter totalt: Nivå 2 och Nivå 3
    // tillkommer, och nio uppgifter fördelas på dem.
    $niva2 = underliggandeItem($container, 'Nivå 2');
    underliggandeKant($impellern, $niva2);
    $niva3 = underliggandeItem($container, 'Nivå 3');
    underliggandeKant($niva2, $niva3);

    foreach (range(2, 6) as $i) {
        underliggandeForekomst(underliggandeSchema($niva2, ['title' => "Uppgift {$i}"]), '2027-01-01');
    }

    foreach (range(7, 10) as $i) {
        underliggandeForekomst(underliggandeSchema($niva3, ['title' => "Uppgift {$i}"]), '2027-01-01');
    }

    $antal = 0;
    actingAs($anvandare)->get($url)->assertOk();
    $medTio = $antal;

    // Ättlingarnas kanter, scheman, förekomster och items hämtas i fyra fasta
    // frågor, och grindarna läser den memoiserade ResolveItemScope — ingen
    // fråga per nivå och ingen per uppgift.
    expect($medTio)->toBe($medEtt);

    Carbon::setTestNow();
});

// --- vyn: itemets namn som länk och avbockning mot radens item -------------

/*
 * Klart när: sektionen ritar itemets namn som länk och avbockningen mot
 * radens item, sidan skickar proppen vidare, och meningen bakom nyckeln är
 * "on X".
 */
it('ritar raderna med itemets namn som länk och avbockning mot radens item', function () {
    $sektionen = File::get(resource_path('js/components/ScheduleListSection.vue'));
    $sidan = File::get(resource_path('js/pages/Containers/Items/Show.vue'));

    expect($sektionen)->toContain("t('item.schedule.on_item'")
        ->toContain('scheduleUrl(containerUlid, row.item.ulid, row.schedule.ulid)')
        ->toContain(':item-ulid="row.item.ulid"')
        ->toContain(':can="row.can"');

    expect($sidan)->toContain(':descendant-occurrences="descendantOccurrences"');

    expect(Lang::get('ui.item.schedule.on_item', ['item' => 'X'], 'en'))->toBe('on X');
});
