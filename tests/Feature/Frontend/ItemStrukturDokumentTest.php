<?php

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\ImageDerivative;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\withoutVite;

/*
 * Issue 1247 · Itemets *Documents*-flik med växeln *Include child items*, och
 * ättlingarnas dokument i samma lista. Se [[ADR-0068 Itemets sida och
 * skapandet]] § Uppföljning 2026-11-14 punkt 1–5.
 *
 * **Servern äger svaret.** Proppen `descendantAttachments` byggs ur
 * App\Actions\Item\ResolveVisibleDescendants (provad i
 * tests/Feature/Item/SynligaAttlingarTest.php), och den finns bara när fliken
 * är aktiv och växeln är på. Itemets egna `attachments` är oförändrad —
 * översikten och omslaget läser den.
 *
 * **Växeln gäller per flik.** `?children=0` rör bara den aktiva fliken, och
 * flikradens `?tab=`-länkar bär aldrig parametern: ett klick på *Costs* ger
 * växeln på igen. Det prövas både i svaret och i källkoden som bygger
 * länkarna — servern kan inte se en `<Link>`.
 *
 * **Handlingarna på en ättlings rad går mot RADENS item** (punkt 3): *Move*
 * och *Delete* postar till ättlingens eget item, och *Use as cover* står bara
 * på itemets egna bilder (punkt 5).
 *
 * Hjälparna har prefixet `struktur` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med sin ägare, en container och ett item — i den ordningen.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function dokstrukturKontext(): array
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

function dokstrukturItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * En kant skriven DIREKT i tabellen, förbi LinkItems — samma form som i
 * AttlingsupplosningTest och SynligaAttlingarTest.
 */
function dokstrukturKant(Item $fran, Item $till, string $relation = 'parent'): void
{
    ItemLink::query()->insert([
        'from_item_id' => $fran->id,
        'to_item_id' => $till->id,
        'relation' => $relation,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function dokstrukturBilaga(
    Item $item,
    Account $konto,
    User $uppladdare,
    string $namn,
    string $mime = 'application/pdf',
    string $kind = 'document',
): Attachment {
    return Attachment::factory()->create([
        'item_id' => $item->id,
        'stored_file_id' => StoredFile::factory()->create(['mime_type' => $mime])->id,
        'filename' => $namn,
        'kind' => $kind,
        'uploaded_by_user_id' => $uppladdare->id,
        'billed_account_id' => $konto->id,
    ]);
}

function dokstrukturUrl(Container $container, Item $item, string $query = ''): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}".($query === '' ? '' : "?{$query}");
}

/**
 * En gäst utanför ägarkontot med en itemgrant på angiven nivå.
 */
function dokstrukturMottagare(Container $container, Item $item, string $niva = 'read'): User
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
function dokstrukturKod(string $sokvag): string
{
    $kod = File::get(resource_path($sokvag));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

// --- servern: proppen och de egna bilagorna --------------------------------

it('ger ättlingarnas dokument när växeln är på', function () {
    withoutVite();

    [$konto, $anvandare, $container, $motorn] = dokstrukturKontext();
    $impellern = dokstrukturItem($container, 'Impellern');
    dokstrukturKant($motorn, $impellern);

    $egen = dokstrukturBilaga($motorn, $konto, $anvandare, 'Egen manual.pdf');

    $barnets = dokstrukturBilaga($impellern, $konto, $anvandare, 'Impellerns manual.pdf');

    // En bild på ättlingen med derivat: miniatyren läses ur samma
    // `variants`-uppslag som itemets egna rader (punkt 3).
    $bild = dokstrukturBilaga($impellern, $konto, $anvandare, 'Impellern.png', 'image/png', 'image');

    foreach (['thumb', 'medium'] as $variant) {
        ImageDerivative::factory()->create([
            'stored_file_id' => $bild->stored_file_id,
            'variant' => $variant,
        ]);
    }

    $props = actingAs($anvandare)
        ->get(dokstrukturUrl($container, $motorn, 'tab=attachments'))
        ->assertOk()
        ->inertiaProps();

    expect($props['includeChildren'])->toBeTrue();

    $rader = collect($props['descendantAttachments']);

    // Nyast först, som `attachments` — bilden skapades sist.
    expect($rader->pluck('ulid')->all())->toBe([$bild->ulid, $barnets->ulid]);

    $barnetsRad = $rader->firstWhere('ulid', $barnets->ulid);

    expect($barnetsRad['item'])->toBe(['ulid' => $impellern->ulid, 'name' => 'Impellern'])
        ->and($barnetsRad['can']['delete'])->toBeTrue()
        ->and($barnetsRad['can']['update'])->toBeTrue()
        ->and($props['variants'][$bild->ulid])->toBe(['medium', 'thumb']);

    // Itemets EGNA bilagor är oförändrade — översikten och omslaget läser dem.
    expect(array_column($props['attachments'], 'ulid'))->toBe([$egen->ulid]);
});

it('ger ingen descendantAttachments när växeln är av', function () {
    withoutVite();

    [$konto, $anvandare, $container, $motorn] = dokstrukturKontext();
    $impellern = dokstrukturItem($container, 'Impellern');
    dokstrukturKant($motorn, $impellern);

    dokstrukturBilaga($motorn, $konto, $anvandare, 'Egen manual.pdf');
    dokstrukturBilaga($impellern, $konto, $anvandare, 'Impellerns manual.pdf');

    $props = actingAs($anvandare)
        ->get(dokstrukturUrl($container, $motorn, 'tab=attachments&children=0'))
        ->assertOk()
        ->inertiaProps();

    expect($props['includeChildren'])->toBeFalse();

    // Nyckeln saknas helt: frågan ställdes aldrig, och svaret är inte den
    // tomma listan som "itemet saknar ättlingar med bilagor" ger.
    expect(array_key_exists('descendantAttachments', $props))->toBeFalse();

    // Itemets egna rad finns kvar.
    expect($props['attachments'])->toHaveCount(1);
});

it('gäller per flik: children=0 på Documents stänger inte av Costs', function () {
    withoutVite();

    [, $anvandare, $container, $motorn] = dokstrukturKontext();

    $dokument = actingAs($anvandare)
        ->get(dokstrukturUrl($container, $motorn, 'tab=attachments&children=0'))
        ->assertOk()
        ->inertiaProps();

    // Flikradens *Costs*-länk är `?tab=costs` och bär aldrig `children` (se
    // `tabs` i Show.vue) — klicket är alltså det här besöket.
    $kostnader = actingAs($anvandare)
        ->get(dokstrukturUrl($container, $motorn, 'tab=costs'))
        ->assertOk()
        ->inertiaProps();

    expect($dokument['includeChildren'])->toBeFalse()
        ->and($kostnader['includeChildren'])->toBeTrue();

    // Och växeln lever bara på de fyra flikar som bär den.
    $oversikt = actingAs($anvandare)
        ->get(dokstrukturUrl($container, $motorn))
        ->assertOk()
        ->inertiaProps();

    expect(array_key_exists('includeChildren', $oversikt))->toBeFalse();
});

it('bygger flikradens länkar utan children', function () {
    $vy = dokstrukturKod('js/pages/Containers/Items/Show.vue');

    preg_match('/const tabHref = .*;\n/', $vy, $traffar);

    expect($traffar[0] ?? '')->not->toBe('')
        ->and($traffar[0])->toContain('?tab=${key}')
        ->and($traffar[0])->not->toContain('children');
});

it('utesluter en ättling som läsaren inte når', function () {
    withoutVite();

    [$konto, $anvandare, $container, $motorn] = dokstrukturKontext();
    $impellern = dokstrukturItem($container, 'Impellern');
    $packningen = dokstrukturItem($container, 'Packningen');

    dokstrukturKant($motorn, $impellern);

    // Packningen hänger under impellern bara genom en omvänd `child`-kant.
    // Vandringen läser den; behörighetsomfånget följer bara `parent`-kanter,
    // så gästen når impellern men inte packningen.
    dokstrukturKant($packningen, $impellern, 'child');

    dokstrukturBilaga($impellern, $konto, $anvandare, 'Impellerns manual.pdf');
    dokstrukturBilaga($packningen, $konto, $anvandare, 'Packningens manual.pdf');

    $gast = dokstrukturMottagare($container, $motorn);

    $agarens = collect(actingAs($anvandare)
        ->get(dokstrukturUrl($container, $motorn, 'tab=attachments'))
        ->assertOk()
        ->inertiaProps()['descendantAttachments']);

    $gastens = collect(actingAs($gast)
        ->get(dokstrukturUrl($container, $motorn, 'tab=attachments'))
        ->assertOk()
        ->inertiaProps()['descendantAttachments']);

    expect($agarens->pluck('filename')->sort()->values()->all())
        ->toBe(['Impellerns manual.pdf', 'Packningens manual.pdf']);

    expect($gastens->pluck('filename')->all())->toBe(['Impellerns manual.pdf']);
});

// --- handlingarna går mot radens item --------------------------------------

it('postar Delete till ättlingens eget item', function () {
    withoutVite();

    [$konto, $anvandare, $container, $motorn] = dokstrukturKontext();
    $impellern = dokstrukturItem($container, 'Impellern');
    dokstrukturKant($motorn, $impellern);

    $bilaga = dokstrukturBilaga($impellern, $konto, $anvandare, 'Impellerns manual.pdf');

    // Raden bygger sin adress ur `attachment.item.ulid` — det är ättlingens
    // item, och det är den grinden servern prövar.
    from(dokstrukturUrl($container, $motorn, 'tab=attachments'))
        ->actingAs($anvandare)
        ->delete("/containers/{$container->ulid}/items/{$impellern->ulid}/attachments/{$bilaga->ulid}")
        ->assertRedirect();

    expect($bilaga->fresh()->deleted_at)->not->toBeNull();

    // Samma bilaga under SIDANS item ger 404: routen binder bilagan genom
    // itemets relation, så en rad som pekade på fel item inte bara vore
    // fel — den hade inte fungerat.
    $annan = dokstrukturBilaga($impellern, $konto, $anvandare, 'En till.pdf');

    actingAs($anvandare)
        ->delete("/containers/{$container->ulid}/items/{$motorn->ulid}/attachments/{$annan->ulid}")
        ->assertNotFound();
});

it('postar Move till ättlingens eget item', function () {
    withoutVite();

    [$konto, $anvandare, $container, $motorn] = dokstrukturKontext();
    $impellern = dokstrukturItem($container, 'Impellern');
    dokstrukturKant($motorn, $impellern);

    $bilaga = dokstrukturBilaga($impellern, $konto, $anvandare, 'Impellerns manual.pdf');

    from(dokstrukturUrl($container, $motorn, 'tab=attachments'))
        ->actingAs($anvandare)
        ->post(
            "/containers/{$container->ulid}/items/{$impellern->ulid}/attachments/{$bilaga->ulid}/move",
            ['target' => $motorn->ulid],
        )
        ->assertRedirect(dokstrukturUrl($container, $motorn, 'tab=attachments'));

    // Bilagan ligger nu på målet, och raden var källans item — inte sidans.
    expect($bilaga->fresh()->item_id)->toBe($motorn->id);
});

// --- ytan: växeln, platsen, knapparna --------------------------------------

it('ritar växeln överst på fliken med flikens hjälptext', function () {
    $sektionen = dokstrukturKod('js/components/ItemAttachmentSection.vue');

    expect($sektionen)->toContain('<IncludeChildrenSwitch')
        ->toContain(':model-value="onlyChildren"')
        ->toContain(':pending="switchPending"')
        ->toContain(':help="childrenHelp"')
        ->toContain('@update:model-value="toggleChildren"');

    // Växeln står under rubriken och före uppladdningen.
    expect(strpos($sektionen, '<IncludeChildrenSwitch'))
        ->toBeGreaterThan(strpos($sektionen, "t('item.attachment.heading')"))
        ->toBeLessThan(strpos($sektionen, '<InlineCreate'));

    expect(Lang::get('ui.item.attachment.include_children', [], 'en'))->toBe('Include child items');
    expect(Lang::get('ui.item.attachment.children_help', ['item' => 'Motorn'], 'en'))
        ->toBe('Shows documents from Motorn and its child items.');
});

it('ritar platsen och radens egna handlingar på en ättlings rad', function () {
    $sektionen = dokstrukturKod('js/components/ItemAttachmentSection.vue');

    // Platsen: itemets namn som länk till dess dokumentflik.
    expect($sektionen)->toContain('v-if="attachment.item"')
        ->toContain('`/containers/${containerUlid}/items/${attachment.item.ulid}?tab=attachments`')
        ->toContain('{{ attachment.item.name }}');

    // Handlingarna går mot radens item och prövas mot radens `can`.
    expect($sektionen)->toContain('function rowItemUrl(attachment)')
        ->toContain('attachment.item')
        ->toContain('router.delete(`${rowItemUrl(attachment)}/attachments/${attachment.ulid}`')
        ->toContain('${rowItemUrl(attachment)}/attachments/${attachment.ulid}${action === \'copy\' ? \'/copy\' : \'/move\'}')
        ->toContain('router.post(`${rowItemUrl(attachment)}/attachments/${attachment.ulid}/inbox`')
        ->toContain('can: attachment.can ?? can');

    // Målväljaren utesluter radens eget item.
    expect($sektionen)->toContain('const pickerExclude = computed(() => picked.value?.attachment?.item?.ulid ?? props.itemUlid);')
        ->toContain(':exclude-item="pickerExclude"');
});

it('ritar Use as cover bara på itemets egna bilder', function () {
    $sektionen = dokstrukturKod('js/components/ItemAttachmentSection.vue');

    preg_match('/<button[^>]*useAsCover\(attachment\)[^>]*>/s', $sektionen, $traffar);

    expect($traffar[0] ?? '')->toContain('attachment.own')
        ->and($traffar[0])->toContain("attachment.kind === 'image'");

    // Ättlingens rad bär ingen egen omslagsväg — omslaget är itemets.
    expect(substr_count($sektionen, '@click="useAsCover(attachment)"'))->toBe(1);
});

it('delar växeln och navigeringen med Tasks', function () {
    $komponent = dokstrukturKod('js/components/IncludeChildrenSwitch.vue');
    $composable = dokstrukturKod('js/composables/useIncludeChildren.js');
    $sektionen = dokstrukturKod('js/components/ScheduleListSection.vue');

    // Komponenten är ren form: `role="switch"`, hjälptexten som propp och
    // inget eget läge.
    expect($komponent)->toContain('role="switch"')
        ->toContain(':aria-checked=')
        ->toContain(':disabled="pending"')
        ->toContain("t('item.attachment.include_children')")
        ->toContain('{{ help }}')
        ->toContain("emit('update:modelValue'");

    // Navigeringen bor i composablen: samma rutt, samma parameter och samma
    // vänteläge som växeln hade i ScheduleListSection före utbrytningen.
    expect($composable)->toContain('router.get(tabUrl()')
        ->toContain('children: 0')
        ->toContain('preserveState: true')
        ->toContain('preserveScroll: true')
        ->toContain('onStart: () => { pending.value = true; }')
        ->toContain('onFinish: () => { pending.value = false; }');

    // Och Tasks använder båda, med sin egen hjälptext.
    expect($sektionen)->toContain("import IncludeChildrenSwitch from './IncludeChildrenSwitch.vue'")
        ->toContain("import { useIncludeChildren } from '../composables/useIncludeChildren.js'")
        ->toContain('<IncludeChildrenSwitch')
        ->toContain("t('item.schedule.children_help', { item: itemName })");
});
