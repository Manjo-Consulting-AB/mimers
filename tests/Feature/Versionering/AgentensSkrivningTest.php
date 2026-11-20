<?php

use App\Actions\Container\CreateContainer;
use App\Actions\Item\CreateItem;
use App\Actions\Item\RestoreItemVersion;
use App\Actions\Item\UpdateItem;
use App\Actions\Item\UpdateItemNotes;
use App\Models\Account;
use App\Models\Agent;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemVersion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
 * Issue 415 · Agenten som aktör i versionshistoriken, se [[ADR-0088
 * Versionshistorik för items]] Riktning 4 och [[ADR-0087 MCP-server för
 * agenter]] Riktning 3.
 *
 * Provet prövar actionkedjan direkt: agentens id följer med från
 * `UpdateItem`/`UpdateItemNotes`/`RestoreItemVersion` till
 * `RecordItemVersion`, sparas som `replaced_by_agent_id`, och skurven skiljer
 * agentens skrivning från användarens EGEN — samma användare, olika aktör,
 * alltså två versioner.
 *
 * Hjälparna har prefixet `agentversion` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en medlem och ett valt tak för versionshistoriken.
 *
 * @return array{0: Account, 1: User}
 */
function agentversionKonto(int $tak = 3): array
{
    $konto = Account::factory()->create(['versioning_max_versions' => $tak]);
    $användare = User::factory()->create();
    $konto->users()->attach($användare, ['role' => 'owner']);

    return [$konto, $användare];
}

/**
 * En container i kontot.
 */
function agentversionContainer(Account $konto, User $användare): Container
{
    return app(CreateContainer::class)->handle($användare, $konto, 'Båten', 'boat', null);
}

/**
 * Ett item skapat genom CreateItem.
 */
function agentversionItem(Container $container, Account $konto, User $användare, string $namn = 'Motor'): Item
{
    return app(CreateItem::class)->handle(
        $container,
        $konto,
        $användare,
        new Item(['name' => $namn]),
        null,
        collect(),
        null,
    );
}

/**
 * En ansluten agent för användarens räkning.
 */
function agentversionAgent(Account $konto, User $användare): Agent
{
    $agent = new Agent;
    $agent->user_id = $användare->id;
    $agent->account_id = $konto->id;
    $agent->oauth_client_id = (string) Str::uuid();
    $agent->name = 'Claude';
    $agent->save();

    return $agent;
}

it('sparar agenten som aktör på versionen när UpdateItem får agentens id', function () {
    [$ägare, $användare] = agentversionKonto();
    $container = agentversionContainer($ägare, $användare);
    $item = agentversionItem($container, $ägare, $användare);
    $agent = agentversionAgent($ägare, $användare);

    $item->name = 'Motorn II';
    app(UpdateItem::class)->handle($item, $användare, false, collect(), agentId: $agent->id);

    $version = ItemVersion::query()->where('item_id', $item->id)->sole();

    expect($version->name)->toBe('Motor')
        ->and((int) $version->replaced_by_user_id)->toBe($användare->id)
        ->and((int) $version->replaced_by_agent_id)->toBe($agent->id);
});

it('gör agentens skrivningar inom fönstret till en version men bryter skurven när användaren skriver', function () {
    [$ägare, $användare] = agentversionKonto();
    $container = agentversionContainer($ägare, $användare);
    $item = agentversionItem($container, $ägare, $användare);
    $agent = agentversionAgent($ägare, $användare);

    // Två agentanrop inom tio minuter är samma arbetspass.
    $item->name = 'A';
    app(UpdateItem::class)->handle($item, $användare, false, collect(), agentId: $agent->id);

    $item->name = 'B';
    app(UpdateItem::class)->handle($item, $användare, false, collect(), agentId: $agent->id);

    $versioner = ItemVersion::query()->where('item_id', $item->id)->get();

    expect($versioner)->toHaveCount(1)
        ->and($versioner[0]->name)->toBe('Motor')
        ->and((int) $versioner[0]->replaced_by_agent_id)->toBe($agent->id);

    // Användarens egen skrivning direkt efter, samma användare men utan
    // agenten, är en annan aktör och blir en ny version — trots att det går
    // noll minuter mellan skrivningarna.
    $item->name = 'C';
    app(UpdateItem::class)->handle($item, $användare, false, collect());

    $versioner = ItemVersion::query()
        ->where('item_id', $item->id)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();

    expect($versioner)->toHaveCount(2)
        // Läget före den första agentanropet, med agenten som ersatte det.
        ->and($versioner[0]->name)->toBe('Motor')
        ->and((int) $versioner[0]->replaced_by_agent_id)->toBe($agent->id)
        // Läget före användarens skrivning, med användaren som ersatte det.
        ->and($versioner[1]->name)->toBe('B')
        ->and($versioner[1]->replaced_by_agent_id)->toBeNull()
        ->and((int) $versioner[1]->replaced_by_user_id)->toBe($användare->id);
});

it('sparar anteckningen med agenten som aktör', function () {
    [$ägare, $användare] = agentversionKonto();
    $container = agentversionContainer($ägare, $användare);
    $item = agentversionItem($container, $ägare, $användare);
    $agent = agentversionAgent($ägare, $användare);

    app(UpdateItemNotes::class)->handle($item, $användare, 'Servad i höstas', $agent->id);

    $version = ItemVersion::query()->where('item_id', $item->id)->sole();

    expect($version->notes)->toBeNull()
        ->and((int) $version->replaced_by_agent_id)->toBe($agent->id);
});

it('bär agenten på versionen som återställningen sparar', function () {
    [$ägare, $användare] = agentversionKonto();
    $container = agentversionContainer($ägare, $användare);
    $item = agentversionItem($container, $ägare, $användare);
    $agent = agentversionAgent($ägare, $användare);

    $item->name = 'Motorn II';
    app(UpdateItem::class)->handle($item, $användare, false, collect(), agentId: $agent->id);

    $version = ItemVersion::query()->where('item_id', $item->id)->sole();

    app(RestoreItemVersion::class)->handle($item, $version, $användare, $agent->id);

    expect(Item::query()->findOrFail($item->id)->name)->toBe('Motor');

    // Nuläget FÖRE återställningen är en egen version, och agenten är
    // aktören även när det är användarens konto som återställer.
    $förra = ItemVersion::query()
        ->where('item_id', $item->id)
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->firstOrFail();

    expect($förra->name)->toBe('Motorn II')
        ->and((int) $förra->replaced_by_agent_id)->toBe($agent->id);
});
