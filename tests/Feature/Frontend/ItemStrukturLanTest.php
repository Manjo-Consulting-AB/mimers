<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\withoutVite;

/*
 * Issue 1249 · Itemets *Loans*-flik med växeln *Include child items*, och
 * ättlingarnas öppna lån och historik. Se [[ADR-0068 Itemets sida och
 * skapandet]] § Uppföljning 2026-11-14 punkt 1–3.
 *
 * **Servern äger svaret.** Propparna `descendantOpenLoans` och
 * `descendantLoanHistory` byggs ur App\Actions\Item\ResolveVisibleDescendants
 * (provad i tests/Feature/Item/SynligaAttlingarTest.php), och de finns bara
 * när fliken är aktiv och växeln är på. Itemets egna `openLoan`,
 * `openLoanOverdue` och `loanHistory` är oförändrade och gäller bara itemet.
 *
 * **Försenad är härledd** på användarens dag ([[ADR-0044 Användarens dag]]) och
 * kommer färdigräknad per rad — vyn har ingen egen klocka. Försenade rader
 * först, sedan nyast utlånade.
 *
 * **Handlingarna på en ättlings rad går mot RADENS item** (punkt 3): *Return*
 * och *Remove the row* postar till ättlingens eget item, och formuläret för en
 * NY utlåning gäller alltid itemet man står på.
 *
 * Hjälparna har prefixet `strukturlan` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/*
 * Klockan nollställs mellan proven: försenad räknas mot användarens dag, och
 * utan en fryst klocka vore proven känsliga för väggklockan.
 */
afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med sin ägare, en container och ett item — i den ordningen.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function strukturlanKontext(): array
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

    return [$konto, $anvandare, $container, $item];
}

function strukturlanItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * En kant skriven DIREKT i tabellen, förbi LinkItems — samma form som i
 * SynligaAttlingarTest och ItemStrukturDokumentTest.
 */
function strukturlanKant(Item $fran, Item $till, string $relation = 'parent'): void
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
 * En utlåningsrad på itemet. Varje fält kan överstyras; grunden är en ÖPPEN
 * utlåning, eftersom det är den vanligaste raden i proven.
 *
 * @param  array<string, mixed>  $attribut
 */
function strukturlanLan(Item $item, array $attribut = []): Loan
{
    return Loan::factory()->for($item, 'item')->create(array_merge([
        'borrower_name' => 'Grannen',
        'borrower_email' => null,
        'lent_at' => '2026-04-01',
        'due_at' => null,
        'returned_at' => null,
        'note' => null,
    ], $attribut));
}

function strukturlanUrl(Container $container, Item $item, string $query = ''): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}".($query === '' ? '' : "?{$query}");
}

/**
 * En gäst utanför ägarkontot med en itemgrant på angiven nivå.
 */
function strukturlanMottagare(Container $container, Item $item, string $niva = 'read'): User
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
function strukturlanKod(string $sokvag): string
{
    $kod = File::get(resource_path($sokvag));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

// --- servern: propparna och de egna raderna --------------------------------

it('ger ättlingarnas öppna lån och historik när växeln är på', function () {
    withoutVite();
    Carbon::setTestNow('2026-06-01 12:00:00');

    [, $anvandare, $container, $motorn] = strukturlanKontext();

    $impellern = strukturlanItem($container, 'Impellern');
    $packningen = strukturlanItem($container, 'Packningen');
    $filtret = strukturlanItem($container, 'Filtret');
    $axeln = strukturlanItem($container, 'Axeln');

    strukturlanKant($motorn, $impellern);
    strukturlanKant($motorn, $packningen);
    strukturlanKant($motorn, $filtret);
    strukturlanKant($motorn, $axeln);

    // En försenad, två i tid (olika utlåningsdag så ordningen syns), och en
    // avslutad.
    $forsenad = strukturlanLan($impellern, ['borrower_name' => 'Grannen', 'lent_at' => '2026-04-01', 'due_at' => '2026-05-01']);
    $nyast = strukturlanLan($filtret, ['borrower_name' => 'Kollegan', 'lent_at' => '2026-05-25', 'due_at' => '2026-07-01']);
    $aldre = strukturlanLan($packningen, ['borrower_name' => 'Systern', 'lent_at' => '2026-05-20']);
    $avslutad = strukturlanLan($axeln, ['borrower_name' => 'Grannen', 'lent_at' => '2026-03-01', 'returned_at' => '2026-03-10']);

    $props = actingAs($anvandare)
        ->get(strukturlanUrl($container, $motorn, 'tab=loans'))
        ->assertOk()
        ->inertiaProps();

    expect($props['includeChildren'])->toBeTrue();

    // Försenade först, sedan nyast utlånade.
    expect(array_column($props['descendantOpenLoans'], 'ulid'))
        ->toBe([$forsenad->ulid, $nyast->ulid, $aldre->ulid]);

    $forsta = $props['descendantOpenLoans'][0];

    // Formen är LoanResource plus `item`, `overdue` och radens `can` — samma
    // grepp som `descendantCosts` (issue 1248).
    expect($forsta['item'])->toBe(['ulid' => $impellern->ulid, 'name' => 'Impellern'])
        ->and($forsta['borrower_name'])->toBe('Grannen')
        ->and($forsta['lent_at'])->toBe('2026-04-01')
        ->and($forsta['due_at'])->toBe('2026-05-01')
        ->and($forsta['is_open'])->toBeTrue()
        ->and($forsta['overdue'])->toBeTrue()
        ->and($props['descendantOpenLoans'][1]['overdue'])->toBeFalse()
        ->and($props['descendantOpenLoans'][2]['overdue'])->toBeFalse()
        ->and($forsta['can']['update'])->toBeTrue()
        ->and($forsta['can']['delete'])->toBeTrue();

    // Historiken: ättlingarnas stängda lån, med `item` och radens `can`.
    expect(array_column($props['descendantLoanHistory'], 'ulid'))->toBe([$avslutad->ulid]);
    expect($props['descendantLoanHistory'][0]['item'])
        ->toBe(['ulid' => $axeln->ulid, 'name' => 'Axeln'])
        ->and($props['descendantLoanHistory'][0]['can']['delete'])->toBeTrue();

    // Itemets egna proppar är oförändrade och bär bara itemets rader.
    expect($props['openLoan'])->toBeNull()
        ->and($props['openLoanOverdue'])->toBeFalse()
        ->and($props['loanHistory'])->toBe([]);
});

it('ger inga ättlingsproppar när växeln är av', function () {
    withoutVite();
    Carbon::setTestNow('2026-06-01 12:00:00');

    [, $anvandare, $container, $motorn] = strukturlanKontext();
    $impellern = strukturlanItem($container, 'Impellern');
    strukturlanKant($motorn, $impellern);

    $eget = strukturlanLan($motorn);
    strukturlanLan($impellern);
    strukturlanLan($impellern, ['lent_at' => '2026-03-01', 'returned_at' => '2026-03-10']);

    $props = actingAs($anvandare)
        ->get(strukturlanUrl($container, $motorn, 'tab=loans&children=0'))
        ->assertOk()
        ->inertiaProps();

    expect($props['includeChildren'])->toBeFalse();

    // Nycklarna saknas helt: frågan ställdes aldrig, och svaret är inte den
    // tomma listan som "itemet saknar ättlingar med lån" ger.
    expect(array_key_exists('descendantOpenLoans', $props))->toBeFalse()
        ->and(array_key_exists('descendantLoanHistory', $props))->toBeFalse();

    // Itemets egna proppar finns kvar.
    expect($props['openLoan']['ulid'])->toBe($eget->ulid);
});

it('ger inga ättlingsproppar utanför Loans-fliken', function () {
    withoutVite();

    [, $anvandare, $container, $motorn] = strukturlanKontext();
    $impellern = strukturlanItem($container, 'Impellern');
    strukturlanKant($motorn, $impellern);
    strukturlanLan($impellern);

    $oversikt = actingAs($anvandare)
        ->get(strukturlanUrl($container, $motorn))
        ->assertOk()
        ->inertiaProps();

    expect(array_key_exists('descendantOpenLoans', $oversikt))->toBeFalse()
        ->and(array_key_exists('descendantLoanHistory', $oversikt))->toBeFalse();
});

it('utesluter en ättling som läsaren inte når', function () {
    withoutVite();
    Carbon::setTestNow('2026-06-01 12:00:00');

    [, $anvandare, $container, $motorn] = strukturlanKontext();
    $impellern = strukturlanItem($container, 'Impellern');
    $packningen = strukturlanItem($container, 'Packningen');

    strukturlanKant($motorn, $impellern);

    // Packningen hänger under impellern bara genom en omvänd `child`-kant.
    // Vandringen läser den; behörighetsomfånget följer bara `parent`-kanter,
    // så gästen når impellern men inte packningen.
    strukturlanKant($packningen, $impellern, 'child');

    strukturlanLan($impellern, ['borrower_name' => 'Impellern']);
    strukturlanLan($packningen, ['borrower_name' => 'Packningen']);

    $gast = strukturlanMottagare($container, $motorn);

    $agarens = actingAs($anvandare)
        ->get(strukturlanUrl($container, $motorn, 'tab=loans'))
        ->assertOk()
        ->inertiaProps();

    $gastens = actingAs($gast)
        ->get(strukturlanUrl($container, $motorn, 'tab=loans'))
        ->assertOk()
        ->inertiaProps();

    expect(collect($agarens['descendantOpenLoans'])->pluck('borrower_name')->sort()->values()->all())
        ->toBe(['Impellern', 'Packningen']);

    // Den rad läsaren inte når ritas aldrig — den finns inte i svaret.
    expect(collect($gastens['descendantOpenLoans'])->pluck('borrower_name')->all())
        ->toBe(['Impellern']);
});

// --- handlingarna går mot radens item --------------------------------------

it('lämnar tillbaka en ättlings lån på dess eget item', function () {
    withoutVite();
    Carbon::setTestNow('2026-06-01 12:00:00');

    [, $anvandare, $container, $motorn] = strukturlanKontext();
    $impellern = strukturlanItem($container, 'Impellern');
    strukturlanKant($motorn, $impellern);

    $lanet = strukturlanLan($impellern, ['lent_at' => '2026-04-01']);

    // Raden bygger sin adress ur `loan.item.ulid` — det är ättlingens item,
    // och det är den grinden servern prövar.
    from(strukturlanUrl($container, $motorn, 'tab=loans'))
        ->actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/items/{$impellern->ulid}/loans/{$lanet->ulid}", [
            'returned_at' => '2026-06-01',
        ])
        ->assertRedirect();

    expect($lanet->fresh()->returned_at->toDateString())->toBe('2026-06-01');

    // Samma lån under SIDANS item ger 404: routen binder `{loan}` genom
    // itemets relation, så en rad som pekade på fel item inte bara vore fel —
    // den hade inte fungerat.
    $annat = strukturlanLan($impellern, ['lent_at' => '2026-04-02']);

    actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/items/{$motorn->ulid}/loans/{$annat->ulid}", [
            'returned_at' => '2026-06-01',
        ])
        ->assertNotFound();

    expect($annat->fresh()->returned_at)->toBeNull();

    // Och sektionen bygger adressen ur radens item, medan formuläret för en
    // NY utlåning går mot sidans item.
    $sektionen = strukturlanKod('js/components/ItemLoanSection.vue');

    expect($sektionen)->toContain('const item = loan?.item?.ulid ?? props.itemUlid;')
        ->toContain('router.patch(loanUrl(loan), { returned_at: props.today }');
});

it('lämnar itemets eget lån och formuläret oförändrade', function () {
    withoutVite();
    Carbon::setTestNow('2026-06-01 12:00:00');

    [, $anvandare, $container, $motorn] = strukturlanKontext();
    $impellern = strukturlanItem($container, 'Impellern');
    strukturlanKant($motorn, $impellern);

    $eget = strukturlanLan($motorn, ['borrower_name' => 'Egen', 'lent_at' => '2026-03-01', 'due_at' => '2026-05-01']);
    $egenHistorik = strukturlanLan($motorn, ['borrower_name' => 'Tidigare', 'lent_at' => '2026-01-01', 'returned_at' => '2026-01-10']);

    $barnets = strukturlanLan($impellern, ['borrower_name' => 'Barnet']);
    $barnetsHistorik = strukturlanLan($impellern, ['borrower_name' => 'Barnets tidigare', 'lent_at' => '2026-02-01', 'returned_at' => '2026-02-10']);

    $props = actingAs($anvandare)
        ->get(strukturlanUrl($container, $motorn, 'tab=loans'))
        ->assertOk()
        ->inertiaProps();

    // Itemets eget lån och historik gäller BARA itemet — itemets öppna lån
    // ligger i `openLoan`, aldrig bland ättlingarnas.
    expect($props['openLoan']['ulid'])->toBe($eget->ulid)
        ->and($props['openLoanOverdue'])->toBeTrue()
        ->and(array_column($props['loanHistory'], 'ulid'))->toBe([$egenHistorik->ulid]);

    expect(array_column($props['descendantOpenLoans'], 'ulid'))->toBe([$barnets->ulid])
        ->and(array_column($props['descendantLoanHistory'], 'ulid'))->toBe([$barnetsHistorik->ulid]);

    $sektionen = strukturlanKod('js/components/ItemLoanSection.vue');

    // Itemets eget lån styrs av itemets `can` och av `openLoan` — aldrig av
    // en rad i ättlingarnas listor.
    expect($sektionen)->toContain('@click="returnToday(openLoan)"')
        ->toContain('@submit.prevent="submitReturn(openLoan)"');
});

it('postar formuläret för en ny utlåning till itemet man står på', function () {
    withoutVite();

    [, $anvandare, $container, $motorn] = strukturlanKontext();
    $impellern = strukturlanItem($container, 'Impellern');
    strukturlanKant($motorn, $impellern);

    // Kroppen bär ingen item-ULID: adressen avgör, och den är sidans item —
    // det som skapar något nytt hör till platsen (punkt 3).
    actingAs($anvandare)
        ->post(strukturlanUrl($container, $motorn).'/loans', [
            'borrower_name' => 'Ny låntagare',
            'lent_at' => '2026-06-01',
        ])
        ->assertRedirect(strukturlanUrl($container, $motorn));

    expect(Loan::query()->where('borrower_name', 'Ny låntagare')->firstOrFail()->item_id)
        ->toBe($motorn->id);

    // Och sektionen skickar sidans item till formuläret, medan radens
    // handlingar bygger sin adress ur radens item.
    $sektionen = strukturlanKod('js/components/ItemLoanSection.vue');

    expect($sektionen)->toContain('loanForm.post(`/containers/${props.containerUlid}/items/${props.itemUlid}/loans`')
        ->toContain('const item = loan?.item?.ulid ?? props.itemUlid;');
});

// --- ytan: växeln, platsen, listan -----------------------------------------

it('ritar växeln överst på fliken med flikens hjälptext', function () {
    $sektionen = strukturlanKod('js/components/ItemLoanSection.vue');

    expect($sektionen)->toContain('<IncludeChildrenSwitch')
        ->toContain(':model-value="onlyChildren"')
        ->toContain(':pending="switchPending"')
        ->toContain(':help="childrenHelp"')
        ->toContain('@update:model-value="toggleChildren"');

    // Växeln står under rubriken och före itemets eget lån.
    expect(strpos($sektionen, '<IncludeChildrenSwitch'))
        ->toBeGreaterThan(strpos($sektionen, "t('item.loan.heading')"))
        ->toBeLessThan(strpos($sektionen, '<div v-if="openLoan"'));

    // Navigeringen är flikens egen adress, så valet gäller per flik.
    expect($sektionen)->toContain('?tab=loans`');

    expect(Lang::get('ui.item.loan.children_help', ['item' => 'Motorn'], 'en'))
        ->toBe('Shows loans of Motorn and its child items.');

    expect(Lang::get('ui.item.loan.children_heading', [], 'en'))->toBe('Lent out in the structure');

    // Etiketten är den delade — samma ord som på *Tasks*, *Documents* och
    // *Costs*, och den bor i IncludeChildrenSwitch.
    expect(Lang::get('ui.item.attachment.include_children', [], 'en'))->toBe('Include child items');
});

it('ritar platsen och radens egna handlingar på en ättlings rad', function () {
    $sektionen = strukturlanKod('js/components/ItemLoanSection.vue');

    // Platsen: itemets namn som länk till dess lånflik, i båda listorna.
    expect($sektionen)->toContain('v-if="loan.item"')
        ->toContain('`/containers/${containerUlid}/items/${loan.item.ulid}?tab=loans`')
        ->toContain('{{ loan.item.name }}');

    // Handlingarna prövas mot RADENS flaggor, inte sidans.
    expect($sektionen)->toContain('function rowCan(loan)')
        ->toContain('return loan.can ?? props.can;')
        ->toContain('v-if="rowCan(loan).update"')
        ->toContain('v-if="rowCan(loan).delete"');

    // Raderna i SAMMA historiklista, i datumordning över båda grupperna.
    expect($sektionen)->toContain('const visibleHistory = computed')
        ->toContain('...props.loanHistory, ...props.descendantLoanHistory')
        ->toContain('a.lent_at < b.lent_at ? 1 : -1')
        ->toContain('v-for="loan in visibleHistory"');

    // Avsnittet med ättlingarnas öppna lån ritas bara med växeln på, och bär
    // sin egen rubrik.
    expect($sektionen)->toContain('v-if="onlyChildren && descendantOpenLoans.length > 0"')
        ->toContain("t('item.loan.children_heading')")
        ->toContain('v-for="loan in descendantOpenLoans"');
});
