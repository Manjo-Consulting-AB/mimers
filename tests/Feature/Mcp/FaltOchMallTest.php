<?php

use App\Actions\Field\CreateField;
use App\Actions\Notification\CreatesAgentDeletionNotifications;
use App\Actions\Notification\MarkInAppNotificationsRead;
use App\Actions\Template\SaveContainerTemplate;
use App\Models\Agent;
use App\Models\AgentAccountGrant;
use App\Models\AgentContainerGrant;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerTemplate;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\travel;

/*
 * Issue 423 · Fälten och mallarna för agenter, se [[ADR-0089 Egna fält på
 * items]] § Riktning 8 och [[ADR-0087 MCP-server för agenter]] Riktning 1, 7,
 * 8, 10, 13 och 15.
 *
 * Hjälparna mcpAgentMedToken(), mcpVerktyg(), mcpFel(), mcpResultat() och
 * mcpEgenContainer() bor i tests/Feature/Mcp/McpEndpointTest.php. Allt här är
 * prefixat `falt` — Pest delar global namnrymd mellan testfilerna.
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
    RateLimiter::clear('x');
});

/**
 * En agent med `delete` överallt på kontot och en egen container.
 *
 * @return array{0: Agent, 1: string, 2: User, 3: Container}
 */
function faltMiljo(): array
{
    [$agent, $token, $anvandare] = mcpAgentMedToken();

    return [$agent, $token, $anvandare, mcpEgenContainer($agent)];
}

function faltFalt(Container $container, ?Item $item, string $label, string $type, User $user): ItemField
{
    return app(CreateField::class)->handle($container, $item, $label, $type, null, $user);
}

/**
 * Sänker eller höjer agentens nivå i gruppen `items` — både konto- och
 * containerraden, eftersom ResolveAgentAccess tar den lägsta av dem.
 */
function faltNiva(Agent $agent, Container $container, string $niva): void
{
    AgentAccountGrant::query()
        ->where('agent_id', $agent->id)
        ->where('group', 'items')
        ->update(['level' => $niva]);

    AgentContainerGrant::query()
        ->where('agent_id', $agent->id)
        ->where('container_id', $container->id)
        ->where('group', 'items')
        ->update(['level' => $niva]);
}

// ─── items_get, items_create och items_update ────────────────────────────────

it('låter items_get visa containerns och itemets egna fält med rubrik, typ och värde', function () {
    [, $token, $anvandare, $container] = faltMiljo();

    $item = Item::factory()->create(['container_id' => $container->id, 'name' => 'Motorn']);

    $containerField = faltFalt($container, null, 'Färg', 'text', $anvandare);
    $ownField = faltFalt($container, $item, 'Årgång', 'number', $anvandare);

    mcpResultat(mcpVerktyg($token, 'items_update', [
        'item' => $item->ulid,
        'fields' => [
            ['field' => $containerField->ulid, 'value' => 'Blå'],
            ['field' => $ownField->ulid, 'value' => 2019],
        ],
    ]));

    $fields = mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $item->ulid]))['fields'];

    expect($fields)->toHaveCount(2)
        ->and($fields[0])->toMatchArray([
            'id' => $containerField->ulid,
            'label' => 'Färg',
            'type' => 'text',
            'scope' => 'container',
            'value' => 'Blå',
        ])
        ->and($fields[1])->toMatchArray([
            'id' => $ownField->ulid,
            'label' => 'Årgång',
            'type' => 'number',
            'scope' => 'item',
            'value' => '2019.000000',
        ]);
});

it('sparar fields vid items_create och ger samma felkod som API:et vid fel typ, med hint', function () {
    [, $token, $anvandare, $container] = faltMiljo();

    $farg = faltFalt($container, null, 'Färg', 'text', $anvandare);
    $nummer = faltFalt($container, null, 'Vikt', 'number', $anvandare);

    $skapat = mcpResultat(mcpVerktyg($token, 'items_create', [
        'container' => $container->ulid,
        'name' => 'Motorn',
        'fields' => [['field' => $farg->ulid, 'value' => 'Röd']],
    ]));

    $item = Item::query()->where('ulid', $skapat['ulid'])->sole();
    $visat = mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $item->ulid]));

    expect($visat['fields'][0]['value'])->toBe('Röd');

    $fel = mcpFel(mcpVerktyg($token, 'items_update', [
        'item' => $item->ulid,
        'fields' => [['field' => $nummer->ulid, 'value' => 'inte ett tal']],
    ]));

    $koder = array_column($fel['data']['fields']['fields.0.value'], 'code');

    expect($fel['code'])->toBe('validation.failed')
        ->and($koder)->toContain('validation.numeric')
        ->and($fel['hint'])->toBeString();
});

it('söker i fältens textvärden genom samma action som API:et', function () {
    [, $token, $anvandare, $container] = faltMiljo();

    $item = Item::factory()->create(['container_id' => $container->id, 'name' => 'Motorn']);
    $farg = faltFalt($container, null, 'Färg', 'text', $anvandare);

    mcpResultat(mcpVerktyg($token, 'items_update', [
        'item' => $item->ulid,
        'fields' => [['field' => $farg->ulid, 'value' => 'Blåmetallic']],
    ]));

    $traffar = mcpResultat(mcpVerktyg($token, 'items_search', ['q' => 'Blåmetallic']));

    expect(collect($traffar['items'])->pluck('ulid')->all())->toBe([$item->ulid]);
});

it('har tappat de sex fasta kolumnerna ur items_create och items_update', function () {
    [, $token, , $container] = faltMiljo();

    $sex = ['manufacturer', 'model', 'serial_number', 'purchased_at', 'warranty_until', 'position_note'];

    $verktyg = collect(mcpAnropa($token, 'tools/list', ['per_page' => 50])->json('result.tools'))
        ->keyBy('name');

    foreach (['items_create', 'items_update'] as $namn) {
        $parametrar = array_keys($verktyg[$namn]['inputSchema']['properties']);

        expect(array_values(array_intersect($parametrar, $sex)))->toBe([], "$namn bär fortfarande de sex kolumnerna");
    }

    // Kroppen tar emot dem utan att skriva något: de finns inte i `validated()`.
    $skapat = mcpResultat(mcpVerktyg($token, 'items_create', [
        'container' => $container->ulid,
        'name' => 'Motorn',
        'manufacturer' => 'Acme',
        'position_note' => 'Hyllan',
    ]));

    $rad = Item::query()->where('ulid', $skapat['ulid'])->sole();

    expect($rad->name)->toBe('Motorn');
});

// ─── fältverktygen ───────────────────────────────────────────────────────────

it('listar containerns fält och ett items egna fält', function () {
    [, $token, $anvandare, $container] = faltMiljo();

    $item = Item::factory()->create(['container_id' => $container->id]);
    $containerField = faltFalt($container, null, 'Färg', 'text', $anvandare);
    $ownField = faltFalt($container, $item, 'Årgång', 'number', $anvandare);

    $containerns = mcpResultat(mcpVerktyg($token, 'fields_list', ['container' => $container->ulid]))['fields'];

    expect(collect($containerns)->pluck('ulid')->all())->toBe([$containerField->ulid]);

    $itemets = mcpResultat(mcpVerktyg($token, 'fields_list', [
        'container' => $container->ulid,
        'item' => $item->ulid,
    ]))['fields'];

    expect(collect($itemets)->pluck('ulid')->all())->toBe([$ownField->ulid])
        ->and($itemets[0]['item'])->toBe($item->ulid);
});

it('avvisar fields_create för en agent utan skrivrätt i items eller utan påslag i containern', function () {
    [$agent, $token, , $container] = faltMiljo();

    faltNiva($agent, $container, 'read');

    expect(mcpFel(mcpVerktyg($token, 'fields_create', [
        'container' => $container->ulid,
        'label' => 'Färg',
        'type' => 'text',
    ]))['code'])->toBe('agent.forbidden');

    faltNiva($agent, $container, 'delete');

    mcpResultat(mcpVerktyg($token, 'fields_create', [
        'container' => $container->ulid,
        'label' => 'Färg',
        'type' => 'text',
    ]));

    // Utan containerraden finns ingen nivå alls, och svaret avslöjar inte att
    // containern finns.
    AgentContainerGrant::query()
        ->where('agent_id', $agent->id)
        ->where('container_id', $container->id)
        ->where('group', 'items')
        ->delete();

    expect(mcpFel(mcpVerktyg($token, 'fields_create', [
        'container' => $container->ulid,
        'label' => 'Modell',
        'type' => 'text',
    ]))['code'])->toBe('resource.not_found');
});

it('prövar containern som mål för fältverktygen och för en tillämpad mall', function () {
    [$agent, $token, $anvandare, $privat] = faltMiljo();

    $delad = mcpEgenContainer($agent, 'Delad');
    beviljaAccess($delad, User::factory()->create(), 'read', 'guest');

    $privatItem = Item::factory()->create(['container_id' => $privat->id, 'name' => 'Privat']);
    mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $privatItem->ulid]));

    // Lässnittet är {användaren}; den delade containern når fler.
    $fel = mcpFel(mcpVerktyg($token, 'fields_create', [
        'container' => $delad->ulid,
        'label' => 'Färg',
        'type' => 'text',
    ]));

    expect($fel['code'])->toBe('agent.copy_widens_access')
        ->and(ItemField::query()->where('container_id', $delad->id)->exists())->toBeFalse();

    [, $mall] = faltMall($agent, $anvandare);

    $fel = mcpFel(mcpVerktyg($token, 'containers_apply_template', [
        'container' => $delad->ulid,
        'template' => $mall->ulid,
    ]));

    expect($fel['code'])->toBe('agent.copy_widens_access')
        ->and($delad->fields()->count())->toBe(0)
        ->and($delad->categories()->count())->toBe(0);
});

it('raderar ett fält till papperskorgen, räknar det mot taket och visar det i notisen', function () {
    [$agent, $token, $anvandare, $container] = faltMiljo();

    $field = faltFalt($container, null, 'Färg', 'text', $anvandare);

    $resultat = mcpResultat(mcpVerktyg($token, 'fields_delete', [
        'container' => $container->ulid,
        'field' => $field->ulid,
    ]));

    $field->refresh();

    expect($resultat['deleted'])->toBeTrue()
        ->and($resultat['batch'])->toBeString()->toHaveLength(26)
        ->and($field->trashed())->toBeTrue()
        ->and((int) $field->deleted_by_agent_id)->toBe((int) $agent->id)
        ->and($field->agent_deletion_batch)->toBe($resultat['batch']);

    expect(AuditLog::query()
        ->where('action', AuditLog::ACTION_FIELD_DELETED)
        ->where('agent_id', $agent->id)
        ->where('subject_id', $field->ulid)
        ->exists())->toBeTrue();

    travel(16)->minutes();
    app(CreatesAgentDeletionNotifications::class)->handle();

    $notis = Notification::query()
        ->where('type', Notification::TYPE_AGENT_DELETED)
        ->where('user_id', $anvandare->id)
        ->sole();

    expect($notis->payload['total'])->toBe(1)
        ->and($notis->payload['items']['field'])->toBe(1);
});

it('raderar ett items eget fält och märker loggraden med itemet', function () {
    [$agent, $token, $anvandare, $container] = faltMiljo();

    $item = Item::factory()->create(['container_id' => $container->id]);
    $ownField = faltFalt($container, $item, 'Årgång', 'number', $anvandare);

    $resultat = mcpResultat(mcpVerktyg($token, 'fields_delete', [
        'container' => $container->ulid,
        'item' => $item->ulid,
        'field' => $ownField->ulid,
    ]));

    expect($ownField->refresh()->trashed())->toBeTrue();

    $logg = AuditLog::query()
        ->where('action', AuditLog::ACTION_FIELD_DELETED)
        ->where('agent_id', $agent->id)
        ->where('subject_id', $ownField->ulid)
        ->sole();

    expect((int) $logg->item_id)->toBe((int) $item->id)
        ->and($resultat['batch'])->toBe($logg->meta['agent_deletion_batch']);
});

it('ger agent.delete_limit på den 21:a fältraderingen och släpper taket när notisen lästs', function () {
    [$agent, $token, $anvandare, $container] = faltMiljo();

    $fields = [];

    foreach (range(1, 21) as $nummer) {
        $fields[] = faltFalt($container, null, "Fält {$nummer}", 'text', $anvandare);
    }

    for ($nummer = 0; $nummer < 20; $nummer++) {
        mcpResultat(mcpVerktyg($token, 'fields_delete', [
            'container' => $container->ulid,
            'field' => $fields[$nummer]->ulid,
        ]));
    }

    $fel = mcpFel(mcpVerktyg($token, 'fields_delete', [
        'container' => $container->ulid,
        'field' => $fields[20]->ulid,
    ]));

    expect($fel['code'])->toBe('agent.delete_limit')
        ->and($fel['data']['limit'])->toBe(20);

    travel(16)->minutes();
    app(CreatesAgentDeletionNotifications::class)->handle();

    $notis = Notification::query()
        ->where('type', Notification::TYPE_AGENT_DELETED)
        ->where('user_id', $anvandare->id)
        ->sole();

    expect($notis->payload['total'])->toBe(20)
        ->and($notis->payload['items']['field'])->toBe(20);

    app(MarkInAppNotificationsRead::class)->handle($anvandare, null);

    expect(mcpResultat(mcpVerktyg($token, 'fields_delete', [
        'container' => $container->ulid,
        'field' => $fields[20]->ulid,
    ]))['deleted'])->toBeTrue();
});

// ─── mallarna ────────────────────────────────────────────────────────────────

/**
 * En källa med ett kategoriträd och två fält, sparad som en mall på
 * agentens konto.
 *
 * @return array{0: Container, 1: ContainerTemplate}
 */
function faltMall(Agent $agent, User $anvandare): array
{
    $kalla = mcpEgenContainer($agent, 'Källan');

    $rot = Category::factory()->create(['container_id' => $kalla->id, 'name' => 'Verktyg']);
    Category::factory()->create([
        'container_id' => $kalla->id,
        'name' => 'Skruvdragare',
        'parent_id' => $rot->id,
    ]);

    faltFalt($kalla, null, 'Serienummer', 'text', $anvandare);
    faltFalt($kalla, null, 'Inköpsdatum', 'date', $anvandare);

    return [$kalla, app(SaveContainerTemplate::class)->handle($anvandare, $kalla, 'Verktygsmallen')];
}

it('listar bara kontots mallar', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    [, $mall] = faltMall($agent, $anvandare);

    // En mall på ett annat konto syns inte.
    [$annatKonto, $annanAnvandare] = mcpKonto('pro');
    $annansKalla = Container::factory()->for($annatKonto, 'account')->create();
    app(SaveContainerTemplate::class)->handle($annanAnvandare, $annansKalla, 'Annans mall');

    $svar = mcpResultat(mcpVerktyg($token, 'templates_list'));

    expect(collect($svar['templates'])->pluck('name')->all())->toBe([$mall->name])
        ->and($svar['templates'][0]['ulid'])->toBe($mall->ulid);
});

it('ger en ny container mallens kategorier och fält', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    [, $mall] = faltMall($agent, $anvandare);

    $agent->create_containers = true;
    $agent->save();

    $svar = mcpResultat(mcpVerktyg($token, 'containers_create', [
        'name' => 'Verkstaden',
        'template' => $mall->ulid,
    ]));

    $container = Container::query()->where('ulid', $svar['ulid'])->sole();

    expect($container->categories()->pluck('name')->sort()->values()->all())
        ->toBe(['Skruvdragare', 'Verktyg'])
        ->and($container->fields()->pluck('label')->all())
        ->toBe(['Serienummer', 'Inköpsdatum']);

    // Agenten fick kontots nivåer och kan arbeta i den nya containern direkt.
    expect(AgentContainerGrant::query()
        ->where('agent_id', $agent->id)
        ->where('container_id', $container->id)
        ->where('group', 'items')
        ->where('level', 'delete')
        ->exists())->toBeTrue();
});

it('tillämpar en mall på en befintlig container', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    [, $mall] = faltMall($agent, $anvandare);

    $mal = mcpEgenContainer($agent, 'Målet');

    $resultat = mcpResultat(mcpVerktyg($token, 'containers_apply_template', [
        'container' => $mal->ulid,
        'template' => $mall->ulid,
    ]));

    expect($resultat['categories'])->toBe(2)
        ->and($resultat['fields'])->toBe(2)
        ->and($mal->categories()->pluck('name')->sort()->values()->all())
        ->toBe(['Skruvdragare', 'Verktyg'])
        ->and($mal->fields()->pluck('label')->all())
        ->toBe(['Serienummer', 'Inköpsdatum']);
});

it('tillämpar inte någon annans mall och ändrar ingenting när gränsen slås i taket', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    $mal = mcpEgenContainer($agent, 'Målet');

    [$annatKonto, $annanAnvandare] = mcpKonto('pro');
    $annansKalla = Container::factory()->for($annatKonto, 'account')->create();
    $annansMall = app(SaveContainerTemplate::class)->handle($annanAnvandare, $annansKalla, 'Annans mall');

    expect(mcpFel(mcpVerktyg($token, 'containers_apply_template', [
        'container' => $mal->ulid,
        'template' => $annansMall->ulid,
    ]))['code'])->toBe('resource.not_found');

    // En mall med fler fält än containern har plats för avvisas i sin helhet.
    // Målet har ett fält sedan förut, och mallen bär de 25 som får plats i
    // källcontainern — tillsammans 26 och därmed över gränsen.
    faltFalt($mal, null, 'Befintligt', 'text', $anvandare);

    $stor = mcpEgenContainer($agent, 'Stor');
    foreach (range(1, 25) as $nummer) {
        faltFalt($stor, null, "Källfält {$nummer}", 'text', $anvandare);
    }
    $storMall = app(SaveContainerTemplate::class)->handle($anvandare, $stor, 'Stor mall');

    $fel = mcpFel(mcpVerktyg($token, 'containers_apply_template', [
        'container' => $mal->ulid,
        'template' => $storMall->ulid,
    ]));

    expect($fel['code'])->toBe('field.limit_reached')
        ->and($fel['hint'])->toBeString()
        ->and($mal->fields()->count())->toBe(1)
        ->and($mal->categories()->count())->toBe(0);
});
