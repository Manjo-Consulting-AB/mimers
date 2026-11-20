<?php

use App\Actions\Inbox\ResolveInbox;
use App\Actions\Item\SearchAccessibleItems;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\CalendarFeed;
use App\Models\Category;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\ImageDerivative;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\Loan;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\StoredFile;
use App\Models\Tag;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Support\Mcp\AttachmentDownloadLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

use function Pest\Laravel\get;
use function Pest\Laravel\travel;

/*
 * Issue 403 · Läsverktygen i MCP-servern, se [[ADR-0087 MCP-server för
 * agenter]] Riktning 7, 9 och 16. Hjälparna mcpAgentMedToken(), mcpVerktyg()
 * och mcpEgenContainer() bor i tests/Support/McpHjalpare.php.
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
    Storage::fake('files');
});

function lasItem(Container $container, string $namn, array $attribut = []): Item
{
    return Item::factory()->create(['container_id' => $container->id, 'name' => $namn] + $attribut);
}

/**
 * @return list<string> namnen i sökträffarna
 */
function sokNamn(string $token, array $argument): array
{
    return collect(mcpResultat(mcpVerktyg($token, 'items_search', $argument))['items'])->pluck('name')->all();
}

// ─── items_search ────────────────────────────────────────────────────────────

it('söker som appen, minus containrar där agenten saknar read i items', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();

    $pastagen = mcpEgenContainer($agent, 'Påslagen');
    $avslagen = Container::factory()->for($agent->account, 'account')->create(['name' => 'Avslagen']);
    lasItem($pastagen, 'Batteri A');
    lasItem($avslagen, 'Batteri B');

    // Appens sökning ser båda.
    expect(app(SearchAccessibleItems::class)->handle($anvandare, 'Batteri')->pluck('name')->sort()->values()->all())
        ->toBe(['Batteri A', 'Batteri B']);

    expect(sokNamn($token, ['q' => 'Batteri']))->toBe(['Batteri A']);

    // Påslag bara i en annan grupp än items räcker inte.
    DB::table('agent_container_grant')->where('agent_id', $agent->id)->where('container_id', $pastagen->id)->where('group', 'items')->delete();
    expect(sokNamn($token, ['q' => 'Batteri']))->toBe([]);
});

it('visar aldrig ett item användaren inte når, i någon sökning, väg eller antal', function () {
    [$agent, $token] = mcpAgentMedToken();

    $egen = mcpEgenContainer($agent);
    lasItem($egen, 'Hemligt batteri synligt');

    // En annans container, med påslag på agenten — användaren når den inte.
    $annans = Container::factory()->create(['name' => 'Annans']);
    mcpSlaPaI($agent, $annans);
    $dolt = lasItem($annans, 'Hemligt batteri dolt');
    $barn = lasItem($annans, 'Hemligt barn');

    $svar = mcpResultat(mcpVerktyg($token, 'items_search', ['q' => 'Hemligt']));

    expect(collect($svar['items'])->pluck('name')->all())->toBe(['Hemligt batteri synligt'])
        ->and($svar['next_cursor'])->toBeNull();

    $text = json_encode($svar);
    expect($text)->not->toContain($dolt->ulid);
    expect($text)->not->toContain('Annans');
    expect($text)->not->toContain($barn->ulid);

    mcpFel(mcpVerktyg($token, 'items_search', ['q' => 'Hemligt', 'container' => $annans->ulid]));
    mcpFel(mcpVerktyg($token, 'items_get', ['item' => $dolt->ulid]));
});

it('lämnar aldrig ut ett grannitem som ägartaket gömmer, varken i path, parents, children, relations eller sökning', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    [$agarkonto] = mcpKonto('pro');
    $container = Container::factory()->for($agarkonto, 'account')->create(['name' => 'Ägarens']);
    mcpSlaPaI($agent, $container);

    $bred = beviljaAccess($container, $anvandare, 'delete', 'member');
    $bred->agent_level = 'delete';
    $bred->save();

    $rot = lasItem($container, 'Fynd rot');
    $synlig = lasItem($container, 'Fynd synlig');
    $syskon = lasItem($container, 'Fynd syskon');
    $dold = lasItem($container, 'Fynd dold');
    $doltBarn = lasItem($container, 'Fynd dolt barn');

    // Ägaren stänger ute agenten från `dold` (och därmed dess ättlingar).
    $dold_rad = beviljaAccess($container, $anvandare, 'delete', 'member');
    $dold_rad->item_id = $dold->id;
    $dold_rad->agent_level = null;
    $dold_rad->save();

    $lank = fn (Item $fran, Item $till, string $relation) => DB::table('item_link')->insert([
        'from_item_id' => $fran->id, 'to_item_id' => $till->id, 'relation' => $relation,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $lank($rot, $synlig, 'parent');
    $lank($synlig, $syskon, 'parent');
    $lank($synlig, $dold, 'parent');
    $lank($dold, $doltBarn, 'parent');
    $lank($synlig, $dold, 'related');
    // Kedjan förbi det dolda itemet: rot → dold → dolt barn.
    $lank($rot, $dold, 'parent');

    $svar = [
        'get' => mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $synlig->ulid])),
        'relations' => mcpResultat(mcpVerktyg($token, 'relations_list', ['item' => $synlig->ulid])),
        'sok' => mcpResultat(mcpVerktyg($token, 'items_search', ['q' => 'Fynd'])),
    ];

    expect(collect($svar['get']['children'])->pluck('name')->all())->toBe(['Fynd syskon'])
        ->and(collect($svar['get']['parents'])->pluck('name')->all())->toBe(['Fynd rot'])
        ->and($svar['get']['relations'])->toBe([])
        ->and(collect($svar['sok']['items'])->pluck('name')->sort()->values()->all())->toBe(['Fynd rot', 'Fynd synlig', 'Fynd syskon'])
        ->and(collect($svar['sok']['items'])->firstWhere('name', 'Fynd synlig')['path'])->toBe('Fynd rot');

    $text = json_encode($svar);

    foreach ([$dold, $doltBarn] as $dolt) {
        expect(str_contains($text, $dolt->ulid))->toBeFalse();
        expect(str_contains($text, $dolt->name))->toBeFalse();
    }

    // Det dolda itemet och dess barn nås inte direkt heller.
    mcpFel(mcpVerktyg($token, 'items_get', ['item' => $dold->ulid]));
    mcpFel(mcpVerktyg($token, 'items_get', ['item' => $doltBarn->ulid]));
});

it('ger fulla sidor när agenten saknar påslag i några av användarens containrar', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();

    // De avslagna containrarna har namn som sorterar FÖRE de påslagna, så ett
    // filter på svaret efteråt hade gett tomma första sidor.
    $avslagen = Container::factory()->for($agent->account, 'account')->create();
    $pastagen = mcpEgenContainer($agent);

    foreach (range(1, 30) as $i) {
        lasItem($avslagen, sprintf('Aaa lampa %02d', $i));
        lasItem($pastagen, sprintf('Bbb lampa %02d', $i));
    }

    $sidor = [];
    $cursor = null;

    do {
        $svar = mcpResultat(mcpVerktyg($token, 'items_search', array_filter(['q' => 'lampa', 'limit' => 10, 'cursor' => $cursor])));
        $sidor[] = collect($svar['items'])->pluck('name')->all();
        $cursor = $svar['next_cursor'];
    } while ($cursor !== null);

    expect(array_map('count', $sidor))->toBe([10, 10, 10])
        ->and(collect($sidor)->flatten()->unique()->count())->toBe(30)
        ->and(collect($sidor)->flatten()->every(fn (string $n): bool => str_starts_with($n, 'Bbb')))->toBeTrue();

    // Appens sökning är oförändrad: den ser alla sextio.
    expect(app(SearchAccessibleItems::class)->handle($anvandare, 'lampa'))->toHaveCount(60);
});

it('filtrerar på taggar med OCH, kategori med underkategorier, uppgifter, förfallodatum, utlåning och container', function () {
    [$agent, $token] = mcpAgentMedToken();
    $container = mcpEgenContainer($agent);
    $annan = mcpEgenContainer($agent);

    $tagg1 = Tag::factory()->create(['container_id' => $container->id]);
    $tagg2 = Tag::factory()->create(['container_id' => $container->id]);
    $a = lasItem($container, 'Fil A');
    $b = lasItem($container, 'Fil B');
    $a->tags()->attach([$tagg1->id, $tagg2->id]);
    $b->tags()->attach([$tagg1->id]);

    expect(sokNamn($token, ['tags' => [$tagg1->ulid]]))->toEqualCanonicalizing(['Fil A', 'Fil B'])
        ->and(sokNamn($token, ['tags' => [$tagg1->ulid, $tagg2->ulid]]))->toBe(['Fil A'])
        ->and(sokNamn($token, ['tags' => ['01JAAAAAAAAAAAAAAAAAAAAAAA']]))->toBe([]);

    $foralder = Category::factory()->create(['container_id' => $container->id]);
    $barnkat = Category::factory()->create(['container_id' => $container->id, 'parent_id' => $foralder->id]);
    $c = lasItem($container, 'Fil C', ['category_id' => $barnkat->id]);
    expect(sokNamn($token, ['category' => $foralder->ulid]))->toBe(['Fil C'])
        ->and(sokNamn($token, ['category' => $barnkat->ulid]))->toBe(['Fil C']);

    $schema = Schedule::factory()->create(['item_id' => $a->id]);
    ScheduleOccurrence::factory()->create(['schedule_id' => $schema->id, 'status' => 'open', 'due_at' => '2026-12-01', 'visible_from' => '2026-11-01']);
    expect(sokNamn($token, ['task_status' => 'open']))->toBe(['Fil A'])
        ->and(sokNamn($token, ['task_status' => 'completed']))->toBe([])
        ->and(sokNamn($token, ['due_before' => '2026-12-31']))->toBe(['Fil A'])
        ->and(sokNamn($token, ['due_before' => '2026-11-30']))->toBe([])
        ->and(sokNamn($token, ['due_after' => '2026-12-01']))->toBe(['Fil A']);

    Loan::factory()->create(['item_id' => $b->id, 'returned_at' => null, 'due_at' => '2020-01-01', 'lent_at' => '2019-12-01']);
    expect(sokNamn($token, ['loan_status' => 'lent']))->toBe(['Fil B'])
        ->and(sokNamn($token, ['loan_status' => 'overdue']))->toBe(['Fil B'])
        ->and(sokNamn($token, ['loan_status' => 'available', 'q' => 'Fil']))->toEqualCanonicalizing(['Fil A', 'Fil C']);

    lasItem($annan, 'Fil D');
    expect(sokNamn($token, ['q' => 'Fil', 'container' => $annan->ulid]))->toBe(['Fil D']);
});

it('ger sammanfattningsrader med container, kategori, status och föräldrarnas väg', function () {
    [$agent, $token] = mcpAgentMedToken();
    [$container, $i] = mcpTrad($agent);

    $rad = mcpResultat(mcpVerktyg($token, 'items_search', ['q' => 'Kolv']))['items'][0];

    expect($rad)->toMatchArray([
        'ulid' => $i['kolv']->ulid,
        'name' => 'Kolv',
        'container' => ['ulid' => $container->ulid, 'name' => $container->name],
        'category' => null,
        'status' => 'ok',
        'path' => 'Båten › Motor',
    ]);
});

it('beskriver på engelska att sökningen inte böjer svenska ord', function () {
    [, $token] = mcpAgentMedToken();

    $verktyg = collect(mcpAnropa($token, 'tools/list', ['per_page' => 50])->json('result.tools'))->firstWhere('name', 'items_search');

    expect($verktyg['description'])->toContain('does not inflect Swedish')->toContain('try other forms');
});

// ─── items_get ───────────────────────────────────────────────────────────────

it('klipper beskrivningen vid 4000 tecken och ger resten med offset', function () {
    [$agent, $token] = mcpAgentMedToken();
    $container = mcpEgenContainer($agent);
    $text = str_repeat('åäö0123456789', 650); // 9 100 tecken
    $item = lasItem($container, 'Lång', ['description' => $text, 'notes' => 'Kort anteckning']);

    $forsta = mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $item->ulid]));

    expect(mb_strlen($forsta['content']['description']))->toBe(4000)
        ->and($forsta['content']['truncated'])->toBeTrue()
        ->and($forsta['content']['next_offset'])->toBe(4000)
        ->and($forsta['content']['notes'])->toBe('Kort anteckning')
        ->and($forsta)->not->toHaveKey('description');

    $andra = mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $item->ulid, 'offset' => 4000]));
    $tredje = mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $item->ulid, 'offset' => 8000]));

    expect($andra['content']['truncated'])->toBeTrue()
        ->and($tredje['content']['truncated'])->toBeFalse()
        ->and($tredje['content']['next_offset'])->toBeNull()
        ->and($forsta['content']['description'].$andra['content']['description'].$tredje['content']['description'])->toBe($text);
});

it('lämnar fält, taggar, kategori, föräldrar, barn, relationer och bilagornas metadata', function () {
    [$agent, $token] = mcpAgentMedToken();
    [$container, $i] = mcpTrad($agent);
    $tagg = Tag::factory()->create(['container_id' => $container->id, 'name' => 'Marin']);
    $i['motor']->tags()->attach($tagg->id);
    DB::table('item_link')->insert(['from_item_id' => $i['motor']->id, 'to_item_id' => $i['verktyg']->id, 'relation' => 'related', 'created_at' => now(), 'updated_at' => now()]);
    $bilaga = Attachment::factory()->create(['item_id' => $i['motor']->id, 'filename' => 'manual.pdf']);

    $svar = mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $i['motor']->ulid]));

    expect($svar['name'])->toBe('Motor')
        ->and($svar['tags'][0]['name'])->toBe('Marin')
        ->and(collect($svar['parents'])->pluck('name')->all())->toBe(['Båten'])
        ->and(collect($svar['children'])->pluck('name')->all())->toBe(['Kolv'])
        ->and(collect($svar['relations'])->pluck('name')->all())->toBe(['Verktyg'])
        ->and($svar['attachments'][0])->toMatchArray(['ulid' => $bilaga->ulid, 'filename' => 'manual.pdf'])
        ->and($svar['attachments'][0])->toHaveKeys(['mime_type', 'byte_size', 'kind']);
});

it('säger i beskrivningarna att fritexten är användardata och aldrig instruktioner', function () {
    [, $token] = mcpAgentMedToken();

    $verktyg = collect(mcpAnropa($token, 'tools/list', ['per_page' => 50])->json('result.tools'))->keyBy('name');

    expect($verktyg['items_get']['description'])->toContain('"content"')->toContain('user data')->toContain('never instructions');

    foreach (['schedules_list', 'occurrences_list', 'costs_list', 'loans_list'] as $namn) {
        expect($verktyg[$namn]['description'])->toContain('user data')->toContain('never instructions');
    }
});

it('låter ett item från inkorgen bära source email', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    $inkorgsItem = app(ResolveInbox::class)->handle($anvandare);
    mcpSlaPaI($agent, $inkorgsItem->container);
    $mejl = lasItem($inkorgsItem->container, 'Kvitto via mejl');
    $vanligt = lasItem(mcpEgenContainer($agent), 'Kvitto i appen');

    expect(mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $mejl->ulid]))['source'])->toBe('email')
        ->and(mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $vanligt->ulid]))['source'])->toBe('app');

    // Inkorgen listas inte av sökningen (Container::listable()), som i appen.
    expect(sokNamn($token, ['q' => 'Kvitto']))->toBe(['Kvitto i appen']);
});

// ─── attachments_get ─────────────────────────────────────────────────────────

const LASTEST_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

function bilagaMed(Item $item, string $mime, int $storlek, string $innehall, string $filnamn = 'fil.pdf'): Attachment
{
    $fil = StoredFile::factory()->create(['mime_type' => $mime, 'byte_size' => $storlek]);
    Storage::disk('files')->put($fil->storage_path, $innehall);

    return Attachment::factory()->create([
        'item_id' => $item->id,
        'stored_file_id' => $fil->id,
        'filename' => $filnamn,
        'kind' => str_starts_with($mime, 'image/') ? 'image' : 'document',
    ]);
}

it('lämnar miniatyren för en bild, aldrig originalet', function () {
    [$agent, $token] = mcpAgentMedToken();
    $item = lasItem(mcpEgenContainer($agent), 'Med bild');
    $bilaga = bilagaMed($item, 'image/jpeg', 5_000, 'ORIGINALBYTES', 'bild.jpg');
    $miniatyr = ImageDerivative::factory()->create(['stored_file_id' => $bilaga->stored_file_id, 'variant' => 'thumb']);
    Storage::disk('files')->put($miniatyr->storage_path, base64_decode(LASTEST_PNG));

    $svar = mcpVerktyg($token, 'attachments_get', ['attachment' => $bilaga->ulid])->assertOk();
    $bild = collect($svar->json('result.content'))->firstWhere('type', 'image');

    expect($bild['mimeType'])->toBe('image/png')
        ->and(base64_decode($bild['data']))->toBe(base64_decode(LASTEST_PNG))
        ->and(json_encode($svar->json()))->not->toContain(base64_encode('ORIGINALBYTES'));
});

it('lämnar en PDF under 10 MB som inbäddad resurs', function () {
    [$agent, $token] = mcpAgentMedToken();
    $item = lasItem(mcpEgenContainer($agent), 'Med pdf');
    $bilaga = bilagaMed($item, 'application/pdf', 1_000, '%PDF-1.4 innehåll');

    $resurs = collect(mcpVerktyg($token, 'attachments_get', ['attachment' => $bilaga->ulid])->assertOk()->json('result.content'))->firstWhere('type', 'resource');

    expect($resurs['resource']['mimeType'])->toBe('application/pdf')
        ->and(base64_decode($resurs['resource']['blob']))->toBe('%PDF-1.4 innehåll');
});

it('lämnar en signerad adress för en större fil, som fungerar en gång och inte efter 15 minuter', function () {
    [$agent, $token] = mcpAgentMedToken();
    $item = lasItem(mcpEgenContainer($agent), 'Med stor fil');
    $bilaga = bilagaMed($item, 'application/pdf', 11 * 1024 * 1024, 'STORA BYTES');

    $svar = mcpResultat(mcpVerktyg($token, 'attachments_get', ['attachment' => $bilaga->ulid]));

    expect($svar['delivery'])->toBe('download_url')
        ->and($svar['expires_in_minutes'])->toBe(15)
        ->and($svar['single_use'])->toBeTrue();

    $adress = $svar['download_url'];

    // Första användningen lyckas; den andra är förbrukad.
    get($adress)->assertOk();
    get($adress)->assertStatus(410);

    // En ny adress dör efter 15 minuter.
    $ny = mcpResultat(mcpVerktyg($token, 'attachments_get', ['attachment' => $bilaga->ulid]))['download_url'];
    travel(16)->minutes();
    get($ny)->assertForbidden();
});

it('binder adressen till agenten och bilagan', function () {
    [$agent, $token] = mcpAgentMedToken();
    $item = lasItem(mcpEgenContainer($agent), 'Bunden');
    $bilaga = bilagaMed($item, 'application/pdf', 11 * 1024 * 1024, 'BYTES');
    $annanBilaga = bilagaMed($item, 'application/pdf', 11 * 1024 * 1024, 'ANNAT');
    $adress = mcpResultat(mcpVerktyg($token, 'attachments_get', ['attachment' => $bilaga->ulid]))['download_url'];

    // Byts bilagan eller agenten i adressen bryts signaturen.
    get(str_replace($bilaga->ulid, $annanBilaga->ulid, $adress))->assertForbidden();

    // Ett annat agent-ULID i adressen: nekas, som okänd agent eller som bruten signatur.
    get(str_replace($agent->ulid, (string) Str::ulid(), $adress))->assertNotFound();

    // En pausad agent når inte längre filen med en redan utlämnad adress.
    $agent->forceFill(['paused_at' => now()])->save();
    get($adress)->assertNotFound();
});

it('nekar en bilaga agenten saknar files-påslag för', function () {
    [$agent, $token] = mcpAgentMedToken();
    $container = mcpEgenContainer($agent);
    $item = lasItem($container, 'Utan files');
    $bilaga = bilagaMed($item, 'application/pdf', 100, 'x');
    DB::table('agent_container_grant')->where('agent_id', $agent->id)->where('container_id', $container->id)->where('group', 'files')->delete();

    expect(mcpFel(mcpVerktyg($token, 'attachments_get', ['attachment' => $bilaga->ulid]))['code'])->toBe('resource.not_found');
});

// ─── övriga läsverktyg och hemligheter ───────────────────────────────────────

it('listar relationer, kategorier, taggar, scheman, förekomster, kostnader, lån och historik med sidindelning', function () {
    [$agent, $token] = mcpAgentMedToken();
    [$container, $i] = mcpTrad($agent);
    $item = $i['batan'];

    foreach (range(1, 3) as $n) {
        Tag::factory()->create(['container_id' => $container->id]);
        Category::factory()->create(['container_id' => $container->id]);
        CostEntry::factory()->create(['item_id' => $item->id, 'container_id' => $container->id]);
        Loan::factory()->create(['item_id' => $item->id, 'returned_at' => '2026-01-01', 'lent_at' => '2025-12-01']);
        $schema = Schedule::factory()->create(['item_id' => $item->id]);
        ScheduleOccurrence::factory()->create(['schedule_id' => $schema->id]);
    }

    $kalla = [
        'relations_list' => ['item', $item->ulid, 'relations', 2],
        'categories_list' => ['container', $container->ulid, 'categories', 3],
        'tags_list' => ['container', $container->ulid, 'tags', 3],
        'schedules_list' => ['item', $item->ulid, 'schedules', 3],
        'occurrences_list' => ['item', $item->ulid, 'occurrences', 3],
        'costs_list' => ['item', $item->ulid, 'costs', 3],
        'loans_list' => ['item', $item->ulid, 'loans', 3],
    ];

    foreach ($kalla as $verktyg => [$nyckel, $ulid, $lista, $antal]) {
        $forsta = mcpResultat(mcpVerktyg($token, $verktyg, [$nyckel => $ulid, 'limit' => 2]));
        expect($forsta[$lista])->toHaveCount(min(2, $antal), $verktyg);

        if ($antal > 2) {
            expect($forsta['next_cursor'])->not->toBeNull($verktyg);
            $andra = mcpResultat(mcpVerktyg($token, $verktyg, [$nyckel => $ulid, 'limit' => 2, 'cursor' => $forsta['next_cursor']]));
            expect($andra[$lista])->toHaveCount($antal - 2)->and($andra['next_cursor'])->toBeNull();
        }
    }

    // Historiken ur audit_log, med personer som namn.
    $handelser = mcpResultat(mcpVerktyg($token, 'history_list', ['item' => $item->ulid]));
    expect($handelser)->toHaveKeys(['events', 'next_cursor']);
});

it('lämnar aldrig en hemlighet eller en annan persons e-postadress i något läsverktyg', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    [$container, $i] = mcpTrad($agent);
    $item = $i['batan'];

    $hemligheter = [];
    $hemligheter[] = $token;
    $hemligheter[] = WebhookEndpoint::factory()->create(['account_id' => $agent->account_id, 'url' => 'https://example.com/hemlig-webhook'])->secret;
    $hemligheter[] = 'https://example.com/hemlig-webhook';

    $flode = CalendarFeed::factory()->create(['container_id' => $container->id, 'user_id' => $anvandare->id]);
    $hemligheter[] = $flode->token_hash;

    $inbjudan = Invitation::factory()->create(['container_id' => $container->id]);
    $hemligheter[] = (string) $inbjudan->token_hash;

    DB::table('magic_link_token')->insert(['email' => $anvandare->email, 'token_hash' => $magisk = hash('sha256', 'magisk'), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
    $hemligheter[] = $magisk;

    $annan = User::factory()->create(['email' => 'annan.person@example.org', 'name' => 'Annika Annan']);
    $hemligheter[] = 'annan.person@example.org';
    Loan::factory()->create(['item_id' => $item->id, 'borrower_name' => 'Lasse Lånare', 'borrower_email' => 'lasse.lanare@example.org', 'lent_at' => '2026-01-01']);
    $hemligheter[] = 'lasse.lanare@example.org';

    AuditLog::factory()->create([
        'action' => 'item.updated', 'account_id' => $agent->account_id, 'user_id' => $annan->id,
        'container_id' => $container->id, 'item_id' => $item->id, 'subject_type' => 'item', 'subject_id' => $item->ulid,
    ]);

    $anrop = [
        ['items_search', ['q' => '']],
        ['items_get', ['item' => $item->ulid]],
        ['relations_list', ['item' => $item->ulid]],
        ['categories_list', ['container' => $container->ulid]],
        ['tags_list', ['container' => $container->ulid]],
        ['schedules_list', ['item' => $item->ulid]],
        ['occurrences_list', ['item' => $item->ulid]],
        ['costs_list', ['item' => $item->ulid]],
        ['loans_list', ['item' => $item->ulid]],
        ['history_list', ['item' => $item->ulid]],
        ['containers_list', []],
        ['containers_tree', ['container' => $container->ulid]],
    ];

    foreach ($anrop as [$verktyg, $argument]) {
        $svar = mcpVerktyg($token, $verktyg, $argument)->assertOk();
        expect($svar->json('result.isError'))->toBeFalse($verktyg);
        $text = json_encode($svar->json());

        foreach ($hemligheter as $hemlighet) {
            expect($text)->not->toContain($hemlighet, "$verktyg läckte $hemlighet");
        }

        // Ingen e-postadress till någon annan än användaren själv.
        preg_match_all('/[\w.+-]+@[\w-]+\.[\w.]+/', $text, $traffar);
        foreach ($traffar[0] as $adress) {
            expect($adress)->toBe($anvandare->email, "$verktyg lämnade ut $adress");
        }
    }

    // Andra personer visas med namn.
    $handelser = mcpResultat(mcpVerktyg($token, 'history_list', ['item' => $item->ulid]))['events'];
    expect(collect($handelser)->pluck('user')->all())->toContain('Annika Annan');
    expect(collect(mcpResultat(mcpVerktyg($token, 'loans_list', ['item' => $item->ulid]))['loans'])->pluck('borrower_name')->all())->toContain('Lasse Lånare');
});

it('har de nya läsverktygen med readOnlyHint i verktygslistan', function () {
    [, $token] = mcpAgentMedToken();
    $verktyg = collect(mcpAnropa($token, 'tools/list', ['per_page' => 50])->json('result.tools'))->keyBy('name');

    foreach (['items_search', 'items_get', 'attachments_get', 'relations_list', 'categories_list', 'tags_list', 'schedules_list', 'occurrences_list', 'costs_list', 'loans_list', 'history_list'] as $namn) {
        expect($verktyg)->toHaveKey($namn)
            ->and($verktyg[$namn]['annotations']['readOnlyHint'])->toBeTrue($namn)
            ->and($verktyg[$namn]['annotations']['destructiveHint'])->toBeFalse($namn);
    }
});

it('bygger adressen på filoriginen när en sådan är konfigurerad', function () {
    expect(AttachmentDownloadLink::TTL_MINUTES)->toBe(15)
        ->and(AttachmentDownloadLink::host())->toBeNull();
});
