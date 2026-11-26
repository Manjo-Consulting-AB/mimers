<?php

use App\Actions\Item\DeleteItem;
use App\Actions\Item\LinkItems;
use App\Actions\Notification\CreatesAgentDeletionNotifications;
use App\Actions\Notification\MarkInAppNotificationsRead;
use App\Actions\Trash\ListAllTrash;
use App\Actions\Trash\RestoreAgentDeletionBatch;
use App\Actions\Trash\RestoreContent;
use App\Actions\Trash\RestoreTrashedContainer;
use App\Console\DeliversNotifications;
use App\Mail\NotificationMail;
use App\Mcp\Tools\AttachmentsDelete;
use App\Mcp\Tools\ContainersDelete;
use App\Mcp\Tools\CostsDelete;
use App\Mcp\Tools\FieldsDelete;
use App\Mcp\Tools\ItemsDelete;
use App\Mcp\Tools\SchedulesDelete;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\Schedule;
use App\Models\User;
use App\Support\Mcp\AgentDeletion;
use App\Support\Mcp\ForbiddenDomains;
use App\Support\Mcp\ToolKind;
use App\Support\Notification\NotificationPreferences;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\from;
use function Pest\Laravel\travel;

/*
 * Issue 406 · Radering via MCP, papperskorgen och notisen `agent.deleted`, se
 * [[ADR-0087 MCP-server för agenter]] Riktning 7, 8, 9, 13 och 14.
 *
 * Hjälparna mcpAgentMedToken(), mcpVerktyg(), mcpFel(), mcpResultat() och
 * mcpEgenContainer() bor i tests/Support/McpHjalpare.php, och
 * papperskorgsItem()/papperskorgsBilaga() i tests/Support/Testhjalpare.php.
 * Allt här är prefixat `raderings` — Pest delar global namnrymd mellan
 * testfilerna.
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
    RateLimiter::clear('x');
});

/**
 * En agent med `delete` överallt på kontot och en egen container med ett item.
 *
 * @return array{0: Agent, 1: string, 2: User, 3: Container, 4: Item}
 */
function raderingsMiljo(): array
{
    [$agent, $token, $anvandare] = mcpAgentMedToken();

    $container = mcpEgenContainer($agent);
    $item = Item::factory()->create(['container_id' => $container->id, 'name' => 'Motorn']);

    return [$agent, $token, $anvandare, $container, $item];
}

/**
 * Ett item i containern som agentens användare får radera.
 */
function raderingsItem(Container $container): Item
{
    return Item::factory()->create(['container_id' => $container->id]);
}

it('raderar ett item till papperskorgen med agenten och tillfället', function () {
    [$agent, $token, $anvandare, , $item] = raderingsMiljo();

    $svar = mcpVerktyg($token, 'items_delete', ['item' => $item->ulid]);
    $resultat = mcpResultat($svar);

    expect($resultat['deleted'])->toBeTrue()
        ->and($resultat['batch'])->toBeString()->toHaveLength(26);

    $item->refresh();

    expect($item->trashed())->toBeTrue()
        ->and((int) $item->deleted_by_agent_id)->toBe((int) $agent->id)
        ->and($item->agent_deletion_batch)->toBe($resultat['batch']);

    // Papperskorgen säger *Deleted by {agentens namn}* (Riktning 8).
    $lista = app(ListAllTrash::class)->handle($anvandare, null, null, null);
    $rad = collect($lista['entries'])->firstWhere('ulid', $item->ulid);

    expect($rad)->not->toBeNull()
        ->and($rad['deleted_by_agent'])->toBe($agent->name)
        ->and($rad['agent_deletion_batch'])->toBe($resultat['batch']);
});

it('raderar bilaga, schema och kostnadsrad genom appens actions', function () {
    [$agent, $token, $anvandare, $container, $item] = raderingsMiljo();

    $bilaga = papperskorgsBilaga($item, $agent->account, $anvandare);
    $schema = Schedule::factory()->create(['item_id' => $item->id, 'title' => 'Oljebyte']);
    $kostnad = CostEntry::factory()->create([
        'item_id' => $item->id,
        'container_id' => $container->id,
        'currency' => 'SEK',
    ]);

    expect(mcpResultat(mcpVerktyg($token, 'attachments_delete', ['attachment' => $bilaga->ulid]))['deleted'])->toBeTrue();
    expect(mcpResultat(mcpVerktyg($token, 'schedules_delete', ['schedule' => $schema->ulid]))['deleted'])->toBeTrue();
    expect(mcpResultat(mcpVerktyg($token, 'costs_delete', ['cost' => $kostnad->ulid]))['deleted'])->toBeTrue();

    expect($bilaga->refresh()->trashed())->toBeTrue()
        ->and($schema->refresh()->trashed())->toBeTrue()
        ->and($kostnad->refresh()->trashed())->toBeTrue();

    expect($bilaga->deleted_by_agent_id)->toBe($agent->id);
});

it('släpper igenom containers_delete på den egna containern', function () {
    [$agent, $token, , $container] = raderingsMiljo();

    $resultat = mcpResultat(mcpVerktyg($token, 'containers_delete', ['container' => $container->ulid]));

    expect($resultat['deleted'])->toBeTrue();

    $container->refresh();

    expect($container->trashed())->toBeTrue()
        ->and((int) $container->deleted_by_agent_id)->toBe((int) $agent->id)
        ->and($container->agent_deletion_batch)->toBe($resultat['batch']);
});

it('avvisar containers_delete på någon annans container', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();

    [$agarkonto] = mcpKonto('pro');
    $annan = Container::factory()->for($agarkonto, 'account')->create();
    $item = Item::factory()->create(['container_id' => $annan->id]);

    $rad = beviljaAccess($annan, $anvandare, 'delete', 'member');
    $rad->agent_level = 'delete';
    $rad->save();
    mcpSlaPaI($agent, $annan);

    // Innehållet i den delade containern får agenten radera …
    expect(mcpResultat(mcpVerktyg($token, 'items_delete', ['item' => $item->ulid]))['deleted'])->toBeTrue();

    // … men aldrig containern själv: ContainerPolicy::delete() kräver
    // medlemskap i ägarkontot (Riktning 7).
    expect(mcpFel(mcpVerktyg($token, 'containers_delete', ['container' => $annan->ulid]))['code'])
        ->toBe('agent.forbidden');

    expect($annan->refresh()->trashed())->toBeFalse();
});

it('säger i beskrivningen att innehållet följer med till papperskorgen och ber agenten bekräfta först', function () {
    [, $token] = mcpAgentMedToken();

    $verktyg = collect(mcpAnropa($token, 'tools/list', ['per_page' => 50])->json('result.tools'))
        ->firstWhere('name', 'containers_delete');

    $beskrivning = $verktyg['description'];

    expect($beskrivning)->toBeString();
    expect($beskrivning)->not->toContain('not deleted');

    expect($beskrivning)
        ->toContain('everything in it')
        ->toContain('The container and its content go to the trash and can be restored together')
        ->toContain('Confirm with the user before deleting')
        ->toContain('in the trash and can be restored')
        ->toContain('do not list its content');
});

it('delar tillfälle inom 15 minuter och börjar ett nytt efteråt', function () {
    [, $token, , $container] = raderingsMiljo();

    $a = raderingsItem($container);
    $b = raderingsItem($container);
    $c = raderingsItem($container);
    $d = raderingsItem($container);

    $forsta = mcpResultat(mcpVerktyg($token, 'items_delete', ['item' => $a->ulid]))['batch'];
    $andra = mcpResultat(mcpVerktyg($token, 'items_delete', ['item' => $b->ulid]))['batch'];

    // Exakt 15 minuter är fortfarande samma tillfälle — "högst 15".
    travel(15)->minutes();
    $tredje = mcpResultat(mcpVerktyg($token, 'items_delete', ['item' => $c->ulid]))['batch'];

    // Sedan 16 minuter efter den förra: ett nytt tillfälle.
    travel(16)->minutes();
    $fjarde = mcpResultat(mcpVerktyg($token, 'items_delete', ['item' => $d->ulid]))['batch'];

    expect($andra)->toBe($forsta)
        ->and($tredje)->toBe($forsta)
        ->and($fjarde)->not->toBe($forsta);
});

it('väntar med notisen till gränsen är strikt passerad och låter en radering på gränsen höra till tillfället', function () {
    [, $token, $anvandare, $container] = raderingsMiljo();

    $a = raderingsItem($container);
    $b = raderingsItem($container);

    $batch = mcpResultat(mcpVerktyg($token, 'items_delete', ['item' => $a->ulid]))['batch'];

    // Exakt 15 minuter: tillfället är fortfarande öppet, så notisen får
    // inte ha skapats — den hade aldrig kunnat omnämna en radering som
    // fortfarande hör hit (granskningsfynd 3).
    travel(15)->minutes();
    app(CreatesAgentDeletionNotifications::class)->handle();

    expect(Notification::query()->where('type', Notification::TYPE_AGENT_DELETED)->count())->toBe(0);

    // En radering på exakt gränsen hör till samma tillfälle.
    $andra = mcpResultat(mcpVerktyg($token, 'items_delete', ['item' => $b->ulid]))['batch'];

    expect($andra)->toBe($batch);

    // Ytterligare exakt 15 minuter efter den sista: fortfarande inte mogen.
    travel(15)->minutes();
    app(CreatesAgentDeletionNotifications::class)->handle();

    expect(Notification::query()->where('type', Notification::TYPE_AGENT_DELETED)->count())->toBe(0);

    travel(1)->minutes();
    app(CreatesAgentDeletionNotifications::class)->handle();

    $notis = Notification::query()
        ->where('type', Notification::TYPE_AGENT_DELETED)
        ->where('user_id', $anvandare->id)
        ->sole();

    expect($notis->payload['total'])->toBe(2)
        ->and($notis->payload['batch'])->toBe($batch);
});

it('återställer hela tillfället och ingenting annat', function () {
    [$agent, $token, $anvandare, $container] = raderingsMiljo();

    $a = raderingsItem($container);
    $b = raderingsItem($container);

    $batch = mcpResultat(mcpVerktyg($token, 'items_delete', ['item' => $a->ulid]))['batch'];
    mcpResultat(mcpVerktyg($token, 'items_delete', ['item' => $b->ulid]));

    // Ett item användaren själv raderat, och ett från ett senare tillfälle:
    // ingendera hör till batchen.
    $sjalv = raderingsItem($container);
    app(DeleteItem::class)->handle($sjalv, $anvandare);

    travel(16)->minutes();
    $senare = raderingsItem($container);
    mcpResultat(mcpVerktyg($token, 'items_delete', ['item' => $senare->ulid]));

    $action = app(RestoreAgentDeletionBatch::class);
    $action->handle($anvandare, $action->models($batch));

    expect($a->refresh()->trashed())->toBeFalse()
        ->and($b->refresh()->trashed())->toBeFalse()
        ->and($sjalv->refresh()->trashed())->toBeTrue()
        ->and($senare->refresh()->trashed())->toBeTrue();
});

it('ärver inte agentens märkning när användaren återställer och sedan raderar själv', function () {
    [, $token, $anvandare, $container] = raderingsMiljo();

    $item = raderingsItem($container);
    $batch = mcpResultat(mcpVerktyg($token, 'items_delete', ['item' => $item->ulid]))['batch'];

    app(RestoreContent::class)->handle('item', $item->refresh(), $anvandare);

    expect($item->refresh()->trashed())->toBeFalse()
        ->and($item->deleted_by_agent_id)->toBeNull()
        ->and($item->agent_deletion_batch)->toBeNull();

    // Användaren raderar själv: raden bär fortfarande ingen agentmärkning,
    // och batchåterställningen rör den inte (granskningsfynd 1).
    app(DeleteItem::class)->handle($item->refresh(), $anvandare);

    expect($item->refresh()->trashed())->toBeTrue()
        ->and($item->deleted_by_agent_id)->toBeNull()
        ->and($item->agent_deletion_batch)->toBeNull();

    $lista = app(ListAllTrash::class)->handle($anvandare, null, null, null);
    $rad = collect($lista['entries'])->firstWhere('ulid', $item->ulid);

    expect($rad['deleted_by_agent'])->toBeNull()
        ->and($rad['agent_deletion_batch'])->toBeNull();

    $action = app(RestoreAgentDeletionBatch::class);
    $action->handle($anvandare, $action->models($batch));

    expect($item->refresh()->trashed())->toBeTrue();
});

it('nollar agentmärkningen när en container återställs', function () {
    [, $token, $anvandare, $container] = raderingsMiljo();

    mcpResultat(mcpVerktyg($token, 'containers_delete', ['container' => $container->ulid]));

    expect($container->refresh()->agent_deletion_batch)->toBeString();

    app(RestoreTrashedContainer::class)->handle($container, $anvandare);

    $container->refresh();

    expect($container->trashed())->toBeFalse()
        ->and($container->deleted_by_agent_id)->toBeNull()
        ->and($container->agent_deletion_batch)->toBeNull();
});

it('hoppar över en rad som hör till en annan batch och visar antalet', function () {
    [$agent, $token, $anvandare, $container] = raderingsMiljo();

    $item = raderingsItem($container);
    $bilaga = papperskorgsBilaga($item, $agent->account, $anvandare);

    // Bilagan raderas först, itemet i ett senare tillfälle: bilagan kan
    // inte återställas medan itemet ligger i papperskorgen.
    $batch = mcpResultat(mcpVerktyg($token, 'attachments_delete', ['attachment' => $bilaga->ulid]))['batch'];

    travel(16)->minutes();
    mcpResultat(mcpVerktyg($token, 'items_delete', ['item' => $item->ulid]));

    from('/trash')
        ->actingAs($anvandare)
        ->post(route('trash.batch.restore'), ['batch' => $batch])
        ->assertRedirect('/trash')
        ->assertSessionHas('status', 'trash-restored')
        ->assertSessionHasErrors([
            'trash' => __('ui.trash.overview.agent_batch_skipped', ['count' => 1]),
        ]);

    expect($bilaga->refresh()->trashed())->toBeTrue()
        ->and($item->refresh()->trashed())->toBeTrue();
});

it('återställer tillfället via ruttens bekräftelse', function () {
    [, $token, $anvandare, $container] = raderingsMiljo();

    $item = raderingsItem($container);
    $batch = mcpResultat(mcpVerktyg($token, 'items_delete', ['item' => $item->ulid]))['batch'];

    from('/trash')
        ->actingAs($anvandare)
        ->post(route('trash.batch.restore'), ['batch' => $batch])
        ->assertRedirect('/trash');

    expect($item->refresh()->trashed())->toBeFalse();
});

it('skapar agent.deleted en gång per tillfälle, 15 minuter efter sista raderingen', function () {
    [$agent, $token, $anvandare, $container] = raderingsMiljo();

    $a = raderingsItem($container);
    $b = raderingsItem($container);

    $batch = mcpResultat(mcpVerktyg($token, 'items_delete', ['item' => $a->ulid]))['batch'];
    mcpResultat(mcpVerktyg($token, 'items_delete', ['item' => $b->ulid]));

    travel(14)->minutes();
    app(CreatesAgentDeletionNotifications::class)->handle();

    expect(Notification::query()->where('type', Notification::TYPE_AGENT_DELETED)->count())->toBe(0);

    // Exakt 15 minuter är fortfarande samma tillfälle — notisen väntar
    // tills gränsen är strikt passerad (granskningsfynd 3).
    travel(1)->minutes();
    app(CreatesAgentDeletionNotifications::class)->handle();

    expect(Notification::query()->where('type', Notification::TYPE_AGENT_DELETED)->count())->toBe(0);

    travel(1)->minutes();
    app(CreatesAgentDeletionNotifications::class)->handle();

    $notis = Notification::query()
        ->where('type', Notification::TYPE_AGENT_DELETED)
        ->where('user_id', $anvandare->id)
        ->sole();

    expect(Notification::query()->where('type', Notification::TYPE_AGENT_DELETED)->count())->toBe(1)
        ->and($notis->payload['agent'])->toBe($agent->name)
        ->and($notis->payload['batch'])->toBe($batch)
        ->and($notis->payload['total'])->toBe(2)
        ->and($notis->payload['items']['item'])->toBe(2)
        ->and($notis->dedupe_key)->toBe('agent.deleted:'.$agent->ulid.':'.$batch.':'.$anvandare->ulid);

    // En andra körning skapar ingen andra notis.
    app(CreatesAgentDeletionNotifications::class)->handle();

    expect(Notification::query()->where('type', Notification::TYPE_AGENT_DELETED)->count())->toBe(1);

    expect(NotificationDelivery::query()
        ->where('notification_id', $notis->id)
        ->where('channel', NotificationDelivery::CHANNEL_IN_APP)
        ->exists())->toBeTrue();
});

it('skickar agent.deleted som mejl när mejlet är påslaget', function () {
    Mail::fake();

    [, $token, , $container] = raderingsMiljo();

    $item = raderingsItem($container);
    mcpResultat(mcpVerktyg($token, 'items_delete', ['item' => $item->ulid]));

    travel(16)->minutes();
    app(CreatesAgentDeletionNotifications::class)->handle();
    app(DeliversNotifications::class)->handle();

    Mail::assertSent(
        NotificationMail::class,
        fn (NotificationMail $mail): bool => $mail->type === Notification::TYPE_AGENT_DELETED,
    );
});

it('ger containerns ägare notisen när någon annans agent raderar', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();

    [$agarkonto, $agare] = mcpKonto('pro');
    $container = Container::factory()->for($agarkonto, 'account')->create();
    $item = Item::factory()->create(['container_id' => $container->id]);

    $rad = beviljaAccess($container, $anvandare, 'delete', 'member');
    $rad->agent_level = 'delete';
    $rad->save();
    mcpSlaPaI($agent, $container);

    mcpResultat(mcpVerktyg($token, 'items_delete', ['item' => $item->ulid]));

    travel(16)->minutes();
    app(CreatesAgentDeletionNotifications::class)->handle();

    $agarens = Notification::query()
        ->where('type', Notification::TYPE_AGENT_DELETED)
        ->where('user_id', $agare->id)
        ->sole();

    expect($agarens->payload['total'])->toBe(1)
        ->and($agarens->payload['items']['item'])->toBe(1);

    expect(Notification::query()
        ->where('type', Notification::TYPE_AGENT_DELETED)
        ->where('user_id', $anvandare->id)
        ->exists())->toBeTrue();
});

it('anger hur många items en raderad container innehöll', function () {
    [, $token, $anvandare, $container] = raderingsMiljo();

    foreach (range(1, 3) as $nummer) {
        raderingsItem($container);
    }

    $antal = Item::query()->where('container_id', $container->id)->count();

    mcpResultat(mcpVerktyg($token, 'containers_delete', ['container' => $container->ulid]));

    travel(16)->minutes();
    app(CreatesAgentDeletionNotifications::class)->handle();

    $notis = Notification::query()
        ->where('type', Notification::TYPE_AGENT_DELETED)
        ->where('user_id', $anvandare->id)
        ->sole();

    expect($notis->payload['total'])->toBe(1)
        ->and($notis->payload['items']['container'])->toBe(1)
        ->and($notis->payload['container_items'])->toBe($antal)
        ->and($notis->payload['containers'][0]['name'])->toBe($container->name)
        ->and($notis->payload['containers'][0]['items'])->toBe($antal);
});

it('har appkanalen på även när användaren stängt av den, och mejlet av', function () {
    [, $token, $anvandare, $container] = raderingsMiljo();
    foreach ([NotificationDelivery::CHANNEL_IN_APP, NotificationDelivery::CHANNEL_EMAIL] as $kanal) {
        NotificationPreference::factory()->create([
            'user_id' => $anvandare->id,
            'type' => Notification::TYPE_AGENT_DELETED,
            'channel' => $kanal,
            'enabled' => false,
            'digest' => false,
        ]);
    }

    $preferenser = app(NotificationPreferences::class);

    expect($preferenser->isEnabled($anvandare, Notification::TYPE_AGENT_DELETED, NotificationDelivery::CHANNEL_IN_APP))->toBeTrue()
        ->and($preferenser->isEnabled($anvandare, Notification::TYPE_AGENT_DELETED, NotificationDelivery::CHANNEL_EMAIL))->toBeFalse();

    $item = raderingsItem($container);
    mcpResultat(mcpVerktyg($token, 'items_delete', ['item' => $item->ulid]));

    travel(16)->minutes();
    app(CreatesAgentDeletionNotifications::class)->handle();

    $notis = Notification::query()
        ->where('type', Notification::TYPE_AGENT_DELETED)
        ->where('user_id', $anvandare->id)
        ->sole();

    expect(NotificationDelivery::query()
        ->where('notification_id', $notis->id)
        ->where('channel', NotificationDelivery::CHANNEL_IN_APP)
        ->exists())->toBeTrue()
        ->and(NotificationDelivery::query()
            ->where('notification_id', $notis->id)
            ->where('channel', NotificationDelivery::CHANNEL_EMAIL)
            ->exists())->toBeFalse();
});

it('ger agent.delete_limit på den 21:a raderingen och släpper taket först när notisen lästs', function () {
    [, $token, $anvandare, $container] = raderingsMiljo();

    $items = [];

    foreach (range(1, 21) as $nummer) {
        $items[] = raderingsItem($container);
    }

    for ($nummer = 0; $nummer < 20; $nummer++) {
        mcpResultat(mcpVerktyg($token, 'items_delete', ['item' => $items[$nummer]->ulid]));
    }

    // Den 21:a — och varje försök efter den — avvisas.
    $fel = mcpFel(mcpVerktyg($token, 'items_delete', ['item' => $items[20]->ulid]));

    expect($fel['code'])->toBe('agent.delete_limit')
        ->and($fel['data']['limit'])->toBe(20);

    // Det avvisade försöket förlänger inte tillfället: notisen ska komma
    // 15 minuter efter den SISTA lyckade raderingen, inte 15 minuter efter
    // försöket.
    travel(10)->minutes();
    mcpFel(mcpVerktyg($token, 'items_delete', ['item' => $items[20]->ulid]));

    // Notisen kräver strikt mer än 15 minuter efter sista lyckade raderingen.
    travel(6)->minutes();
    app(CreatesAgentDeletionNotifications::class)->handle();

    $notis = Notification::query()
        ->where('type', Notification::TYPE_AGENT_DELETED)
        ->where('user_id', $anvandare->id)
        ->sole();

    expect($notis->payload['total'])->toBe(20);

    // Spärren står kvar också när timmen gått: det är läsningen som släpper
    // taket, inte tiden (issue 406 § 5).
    travel(45)->minutes();

    expect(mcpFel(mcpVerktyg($token, 'items_delete', ['item' => $items[20]->ulid]))['code'])
        ->toBe('agent.delete_limit');

    app(MarkInAppNotificationsRead::class)->handle($anvandare, null);

    expect(mcpResultat(mcpVerktyg($token, 'items_delete', ['item' => $items[20]->ulid]))['deleted'])->toBeTrue();
});

it('släpper inte taket för att en äldre agent.deleted är läst', function () {
    [$agent, $token, $anvandare, $container] = raderingsMiljo();

    $items = [];

    foreach (range(1, 21) as $nummer) {
        $items[] = raderingsItem($container);
    }

    for ($nummer = 0; $nummer < 20; $nummer++) {
        mcpResultat(mcpVerktyg($token, 'items_delete', ['item' => $items[$nummer]->ulid]));
    }

    // En äldre notis för samma agent och användare, redan läst: den hör
    // till ett annat tillfälle och får inte nollställa räkningen
    // (granskningsfynd 6).
    $gammal = Notification::factory()->create([
        'account_id' => $agent->account_id,
        'user_id' => $anvandare->id,
        'type' => Notification::TYPE_AGENT_DELETED,
        'payload' => ['agent' => $agent->name, 'batch' => '01AAAAAAAAAAAAAAAAAAAAAAAA', 'total' => 5],
        'dedupe_key' => 'agent.deleted:'.$agent->ulid.':01AAAAAAAAAAAAAAAAAAAAAAAA:'.$anvandare->ulid,
    ]);

    NotificationDelivery::factory()->create([
        'notification_id' => $gammal->id,
        'channel' => NotificationDelivery::CHANNEL_IN_APP,
        'status' => NotificationDelivery::STATUS_SENT,
        'attempts' => 0,
        'sent_at' => now(),
        'read_at' => now(),
    ]);

    expect(mcpFel(mcpVerktyg($token, 'items_delete', ['item' => $items[20]->ulid]))['code'])
        ->toBe('agent.delete_limit');
});

it('märker alla sex raderande verktygen som radering med destructiveHint', function () {
    foreach ([ItemsDelete::class, AttachmentsDelete::class, SchedulesDelete::class, CostsDelete::class, ContainersDelete::class, FieldsDelete::class] as $klass) {
        $verktyg = app($klass);

        expect($verktyg->kind())->toBe(ToolKind::Delete)
            ->and($verktyg->annotations()['destructiveHint'])->toBeTrue()
            ->and($verktyg->annotations()['readOnlyHint'])->toBeFalse();
    }
});

it('når aldrig tömningen eller rensningen av papperskorgen', function () {
    foreach ([ItemsDelete::class, AttachmentsDelete::class, SchedulesDelete::class, CostsDelete::class, ContainersDelete::class] as $klass) {
        $verktyg = app($klass);

        expect(ForbiddenDomains::offence($verktyg->name(), $verktyg->actions()))->toBeNull();

        foreach ($verktyg->actions() as $action) {
            expect(class_basename($action))
                ->not->toBeIn(['EmptyTrash', 'PurgeContent', 'PurgeContainer']);
        }
    }
});

it('mjukraderar en relation med relations_remove, märker den med agenten och räknar den mot taket', function () {
    [$agent, $token, $anvandare, $container, $a] = raderingsMiljo();
    $b = raderingsItem($container);
    app(LinkItems::class)->handle($a, $b, 'related', $anvandare);

    $resultat = mcpResultat(mcpVerktyg($token, 'relations_remove', ['item' => $a->ulid, 'other' => $b->ulid]));

    expect($resultat['removed'])->toBeTrue();

    $rad = ItemLink::withTrashed()->sole();
    expect($rad->deleted_at)->not->toBeNull()
        ->and((int) $rad->deleted_by_agent_id)->toBe((int) $agent->id)
        ->and($rad->agent_deletion_batch)->toBeString()->toHaveLength(26);

    // Händelsen bär agenten och tillfället, och är en av raderingarna som taket räknar.
    $logg = AuditLog::query()->where('action', 'item_link.deleted')->sole();
    expect((int) $logg->agent_id)->toBe((int) $agent->id)
        ->and($logg->meta['agent_deletion_batch'])->toBe($rad->agent_deletion_batch)
        ->and(AgentDeletion::DELETE_ACTIONS)->toContain('item_link.deleted');
});
