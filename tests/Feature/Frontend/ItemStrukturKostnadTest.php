<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\withoutVite;

/*
 * Issue 1248 · Itemets *Costs*-flik med växeln *Include child items*, och
 * ättlingarnas kostnader i samma lista. Se [[ADR-0068 Itemets sida och
 * skapandet]] § Uppföljning 2026-11-14 punkt 1–4.
 *
 * **Servern äger svaret.** Proppen `descendantCosts` byggs ur
 * App\Actions\Item\ResolveVisibleDescendants (provad i
 * tests/Feature/Item/SynligaAttlingarTest.php), och den finns bara när fliken
 * är aktiv och växeln är på. Itemets egna `costs` är oförändrad.
 *
 * **Summorna räknar det som visas** (punkt 4). `costTotals` kommer
 * färdigsummerad ur ItemController::show() — en post per valuta över exakt de
 * rader fliken ritar — så en ättling läsaren inte når varken syns eller räknas.
 *
 * **Handlingarna på en ättlings rad går mot RADENS item** (punkt 3): *Change*
 * och *Remove the row* postar till ättlingens eget item, och formuläret för en
 * NY kostnad gäller alltid itemet man står på.
 *
 * Hjälparna har prefixet `strukturkostnad` — Pest lägger alla testfiler i
 * samma namnrymd när hela sviten körs.
 */

/**
 * Ett konto med sin ägare, en container och ett item — i den ordningen.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function strukturkostnadKontext(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create(['currency' => 'SEK']);

    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

function strukturkostnadItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * En kant skriven DIREKT i tabellen, förbi LinkItems — samma form som i
 * SynligaAttlingarTest och ItemStrukturDokumentTest.
 */
function strukturkostnadKant(Item $fran, Item $till, string $relation = 'parent'): void
{
    ItemLink::query()->insert([
        'from_item_id' => $fran->id,
        'to_item_id' => $till->id,
        'relation' => $relation,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * En kostnadsrad på itemet. Varje fält kan överstyras — beloppet är heltalet
 * i minsta enhet, som CostEntryResource bär det.
 *
 * @param  array<string, mixed>  $attribut
 */
function strukturkostnadRad(Item $item, array $attribut = []): CostEntry
{
    return CostEntry::factory()->for($item, 'item')->create(array_merge([
        'incurred_on' => '2026-04-12',
        'amount' => 100000,
        'currency' => 'SEK',
        'description' => 'Impeller',
        'counterparty' => null,
        'created_by_account_id' => $item->container->account_id,
    ], $attribut));
}

function strukturkostnadUrl(Container $container, Item $item, string $query = ''): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}".($query === '' ? '' : "?{$query}");
}

/**
 * En gäst utanför ägarkontot med en itemgrant på angiven nivå.
 */
function strukturkostnadMottagare(Container $container, Item $item, string $niva = 'read'): User
{
    $mottagare = User::factory()->create(['locale' => 'sv_SE']);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $niva,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/** Källkoden med kommentarer borta, så en nål inte kan nöjas av ett docblock. */
function strukturkostnadKod(string $sokvag): string
{
    $kod = File::get(resource_path($sokvag));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

// --- servern: proppen och de egna raderna ----------------------------------

it('ger ättlingarnas kostnader när växeln är på', function () {
    withoutVite();

    [$konto, $anvandare, $container, $motorn] = strukturkostnadKontext();
    $impellern = strukturkostnadItem($container, 'Impellern');
    strukturkostnadKant($motorn, $impellern);

    $egen = strukturkostnadRad($motorn, ['description' => 'Egen service']);
    $barnets = strukturkostnadRad($impellern, [
        'incurred_on' => '2026-05-01',
        'amount' => 50000,
        'description' => 'Impellerns packning',
    ]);

    $props = actingAs($anvandare)
        ->get(strukturkostnadUrl($container, $motorn, 'tab=costs'))
        ->assertOk()
        ->inertiaProps();

    expect($props['includeChildren'])->toBeTrue();

    $rader = collect($props['descendantCosts']);

    expect($rader->pluck('ulid')->all())->toBe([$barnets->ulid]);

    $barnetsRad = $rader->firstWhere('ulid', $barnets->ulid);

    // Formen är CostEntryResource plus `item` och radens `can` — samma grepp
    // som `descendantAttachments` (issue 1247).
    expect($barnetsRad['item'])->toBe(['ulid' => $impellern->ulid, 'name' => 'Impellern'])
        ->and($barnetsRad['amount'])->toBe(50000)
        ->and($barnetsRad['currency'])->toBe('SEK')
        ->and($barnetsRad['incurred_on'])->toBe('2026-05-01')
        ->and($barnetsRad['can']['update'])->toBeTrue()
        ->and($barnetsRad['can']['delete'])->toBeTrue();

    // Itemets EGNA rader är oförändrade — översikten och räknaren läser dem.
    expect(array_column($props['costs'], 'ulid'))->toBe([$egen->ulid]);
});

it('ger ingen descendantCosts när växeln är av', function () {
    withoutVite();

    [$konto, $anvandare, $container, $motorn] = strukturkostnadKontext();
    $impellern = strukturkostnadItem($container, 'Impellern');
    strukturkostnadKant($motorn, $impellern);

    strukturkostnadRad($motorn);
    strukturkostnadRad($impellern);

    $props = actingAs($anvandare)
        ->get(strukturkostnadUrl($container, $motorn, 'tab=costs&children=0'))
        ->assertOk()
        ->inertiaProps();

    expect($props['includeChildren'])->toBeFalse();

    // Nyckeln saknas helt: frågan ställdes aldrig, och svaret är inte den
    // tomma listan som "itemet saknar ättlingar med kostnader" ger.
    expect(array_key_exists('descendantCosts', $props))->toBeFalse();

    // Itemets egna rad finns kvar.
    expect($props['costs'])->toHaveCount(1);
});

it('ger ingen descendantCosts eller summa utanför Costs-fliken', function () {
    withoutVite();

    [, $anvandare, $container, $motorn] = strukturkostnadKontext();
    $impellern = strukturkostnadItem($container, 'Impellern');
    strukturkostnadKant($motorn, $impellern);
    strukturkostnadRad($impellern);

    $oversikt = actingAs($anvandare)
        ->get(strukturkostnadUrl($container, $motorn))
        ->assertOk()
        ->inertiaProps();

    expect(array_key_exists('descendantCosts', $oversikt))->toBeFalse()
        ->and(array_key_exists('costTotals', $oversikt))->toBeFalse();
});

it('utesluter en ättling som läsaren inte når och räknar den inte i summan', function () {
    withoutVite();

    [$konto, $anvandare, $container, $motorn] = strukturkostnadKontext();
    $impellern = strukturkostnadItem($container, 'Impellern');
    $packningen = strukturkostnadItem($container, 'Packningen');

    strukturkostnadKant($motorn, $impellern);

    // Packningen hänger under impellern bara genom en omvänd `child`-kant.
    // Vandringen läser den; behörighetsomfånget följer bara `parent`-kanter,
    // så gästen når impellern men inte packningen.
    strukturkostnadKant($packningen, $impellern, 'child');

    strukturkostnadRad($motorn, ['amount' => 100000]);
    strukturkostnadRad($impellern, ['amount' => 50000, 'description' => 'Impellern']);
    strukturkostnadRad($packningen, ['amount' => 25000, 'description' => 'Packningen']);

    $gast = strukturkostnadMottagare($container, $motorn);

    $agarens = actingAs($anvandare)
        ->get(strukturkostnadUrl($container, $motorn, 'tab=costs'))
        ->assertOk()
        ->inertiaProps();

    $gastens = actingAs($gast)
        ->get(strukturkostnadUrl($container, $motorn, 'tab=costs'))
        ->assertOk()
        ->inertiaProps();

    expect(collect($agarens['descendantCosts'])->pluck('description')->sort()->values()->all())
        ->toBe(['Impellern', 'Packningen']);

    expect(collect($gastens['descendantCosts'])->pluck('description')->all())
        ->toBe(['Impellern']);

    // Summan byggs ur samma rader som delas, så den osynliga raden räknas
    // aldrig in: 100 000 + 50 000, aldrig 175 000.
    expect($agarens['costTotals'])->toBe([['currency' => 'SEK', 'amount' => 175000, 'count' => 3]])
        ->and($gastens['costTotals'])->toBe([['currency' => 'SEK', 'amount' => 150000, 'count' => 2]]);
});

// --- summorna: det som visas, per valuta ----------------------------------

it('räknar summorna över de rader som visas', function () {
    withoutVite();

    [, $anvandare, $container, $motorn] = strukturkostnadKontext();
    $impellern = strukturkostnadItem($container, 'Impellern');
    strukturkostnadKant($motorn, $impellern);

    strukturkostnadRad($motorn, ['amount' => 100000, 'currency' => 'SEK']);
    strukturkostnadRad($motorn, ['amount' => 20000, 'currency' => 'EUR', 'incurred_on' => '2026-02-01']);
    strukturkostnadRad($impellern, ['amount' => 50000, 'currency' => 'SEK', 'incurred_on' => '2026-05-01']);

    $med = actingAs($anvandare)
        ->get(strukturkostnadUrl($container, $motorn, 'tab=costs'))
        ->assertOk()
        ->inertiaProps();

    // Växeln på: egna plus ättlingarnas, grupperat per valuta och aldrig
    // summerat över två ([[ADR-0040 Underträdets summor]] § Konsekvenser).
    expect($med['costTotals'])->toBe([
        ['currency' => 'EUR', 'amount' => 20000, 'count' => 1],
        ['currency' => 'SEK', 'amount' => 150000, 'count' => 2],
    ]);

    $utan = actingAs($anvandare)
        ->get(strukturkostnadUrl($container, $motorn, 'tab=costs&children=0'))
        ->assertOk()
        ->inertiaProps();

    // Växeln av: bara itemets egna rader — samma räkning, mindre mängd.
    expect($utan['costTotals'])->toBe([
        ['currency' => 'EUR', 'amount' => 20000, 'count' => 1],
        ['currency' => 'SEK', 'amount' => 100000, 'count' => 1],
    ]);
});

// --- handlingarna går mot radens item --------------------------------------

it('ändrar och raderar en ättlings rad på dess eget item', function () {
    withoutVite();

    [, $anvandare, $container, $motorn] = strukturkostnadKontext();
    $impellern = strukturkostnadItem($container, 'Impellern');
    strukturkostnadKant($motorn, $impellern);

    $barnets = strukturkostnadRad($impellern, ['description' => 'Impellern']);

    // Raden bygger sin adress ur `cost.item.ulid` — det är ättlingens item,
    // och det är den grinden servern prövar.
    from(strukturkostnadUrl($container, $motorn, 'tab=costs'))
        ->actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/items/{$impellern->ulid}/costs/{$barnets->ulid}", [
            'description' => 'Ny impeller',
        ])
        ->assertRedirect();

    expect($barnets->fresh()->description)->toBe('Ny impeller');

    $annan = strukturkostnadRad($impellern, ['description' => 'Packningen']);

    from(strukturkostnadUrl($container, $motorn, 'tab=costs'))
        ->actingAs($anvandare)
        ->delete("/containers/{$container->ulid}/items/{$impellern->ulid}/costs/{$annan->ulid}")
        ->assertRedirect();

    expect($annan->fresh()->deleted_at)->not->toBeNull();

    // Samma rad under SIDANS item ger 404: routen binder `{cost}` genom
    // itemets relation, så en rad som pekade på fel item inte bara vore
    // fel — den hade inte fungerat.
    $tredje = strukturkostnadRad($impellern);

    actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/items/{$motorn->ulid}/costs/{$tredje->ulid}", [
            'description' => 'Nej',
        ])
        ->assertNotFound();

    actingAs($anvandare)
        ->delete("/containers/{$container->ulid}/items/{$motorn->ulid}/costs/{$tredje->ulid}")
        ->assertNotFound();

    expect($tredje->fresh()->description)->not->toBe('Nej')
        ->and($tredje->fresh()->deleted_at)->toBeNull();
});

it('postar formuläret för en ny kostnad till itemet man står på', function () {
    withoutVite();

    [, $anvandare, $container, $motorn] = strukturkostnadKontext();
    $impellern = strukturkostnadItem($container, 'Impellern');
    strukturkostnadKant($motorn, $impellern);

    // Kroppen bär ingen item-ULID: adressen avgör, och den är sidans item —
    // det som skapar något nytt hör till platsen (punkt 3).
    actingAs($anvandare)
        ->post(strukturkostnadUrl($container, $motorn).'/costs', [
            'incurred_on' => '2026-06-01',
            'amount' => '1200',
            'currency' => 'SEK',
            'description' => 'Nytt inköp',
            'counterparty' => null,
        ])
        ->assertRedirect(strukturkostnadUrl($container, $motorn, 'tab=costs'));

    $kostnad = CostEntry::query()->where('description', 'Nytt inköp')->firstOrFail();

    expect($kostnad->item_id)->toBe($motorn->id);

    // Och sektionen skickar sidans item till formuläret, medan radens
    // handlingar bygger sin adress ur radens item.
    $sektionen = strukturkostnadKod('js/components/ItemCostSection.vue');

    expect($sektionen)->toContain(':item-ulid="itemUlid"')
        ->toContain('cost?.item?.ulid ?? props.itemUlid');
});

// --- ytan: växeln, platsen, summorna ---------------------------------------

it('ritar växeln överst på fliken med flikens hjälptext', function () {
    $sektionen = strukturkostnadKod('js/components/ItemCostSection.vue');

    expect($sektionen)->toContain('<IncludeChildrenSwitch')
        ->toContain(':model-value="onlyChildren"')
        ->toContain(':pending="switchPending"')
        ->toContain(':help="childrenHelp"')
        ->toContain('@update:model-value="toggleChildren"');

    // Växeln står under rubriken och före formuläret och listan.
    expect(strpos($sektionen, '<IncludeChildrenSwitch'))
        ->toBeGreaterThan(strpos($sektionen, "t('item.cost.heading')"))
        ->toBeLessThan(strpos($sektionen, '<InlineCreate'));

    // Navigeringen är flikens egen adress, så valet gäller per flik.
    expect($sektionen)->toContain('?tab=costs`');

    expect(Lang::get('ui.item.cost.children_help', ['item' => 'Motorn'], 'en'))
        ->toBe('Shows costs from Motorn and its child items.');

    // Etiketten är den delade — samma ord som på *Tasks* och *Documents*.
    expect(Lang::get('ui.item.attachment.include_children', [], 'en'))->toBe('Include child items');
});

it('ritar platsen och radens egna handlingar på en ättlings rad', function () {
    $sektionen = strukturkostnadKod('js/components/ItemCostSection.vue');

    // Platsen: itemets namn som länk till dess kostnadsflik.
    expect($sektionen)->toContain('v-if="cost.item"')
        ->toContain('`/containers/${containerUlid}/items/${cost.item.ulid}?tab=costs`')
        ->toContain('{{ cost.item.name }}');

    // Handlingarna prövas mot RADENS flaggor, inte sidans.
    expect($sektionen)->toContain('function rowCan(cost)')
        ->toContain('return cost.can ?? props.can;')
        ->toContain('v-if="rowCan(cost).update"')
        ->toContain('v-if="rowCan(cost).delete"');

    // Raderna i SAMMA lista, i datumordning över båda grupperna.
    expect($sektionen)->toContain('const visibleCosts = computed')
        ->toContain('...props.costs, ...props.descendantCosts')
        ->toContain('a.incurred_on < b.incurred_on ? 1 : -1')
        ->toContain('v-for="cost in visibleCosts"');

    // Summorna ritas ur serverns tal och aldrig ur en egen uträkning.
    expect($sektionen)->toContain('v-for="total in costTotals"')
        ->toContain('formatAmount(total.amount, total.currency)');

    expect($sektionen)->not->toContain('reduce(');
});
