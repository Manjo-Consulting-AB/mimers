<?php

use App\Actions\Access\RevokeContainerAccess;
use App\Console\ReconcilesUsageCounters;
use App\Console\TransfersOrphanedBilling;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\Usage\CountedRows;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * Issue 1305 · Betalarbytet när ett konto förlorar sin sista giltiga åtkomst
 * till en container ([[ADR-0081 All lagrad data räknas mot kvoten]] § 4).
 * RevokeContainerAccess flyttar direkt; TransfersOrphanedBilling tar det som
 * gått ut. Räknarna byggs om ur raderna med bbRäkna(), som i
 * NedgraderingTextTest.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett personkonto (gäst) med en användare, och ett ägarkonto med en container.
 * Gästen har lagt en bilaga (`$bytes`) och ett item i containern.
 *
 * @return array{gast: Account, person: User, agare: Account, container: Container, item: Item, bilaga: Attachment}
 */
function bbUppsättning(int $bytes = 500): array
{
    $gäst = Account::factory()->create();
    $person = User::factory()->create();
    $gäst->users()->attach($person->id, ['role' => 'owner']);

    $ägare = Account::factory()->create();
    $ägarePerson = User::factory()->create();
    $ägare->users()->attach($ägarePerson->id, ['role' => 'owner']);

    $container = Container::factory()->for($ägare, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Item-A',
        'description' => null,
        'notes' => null,
        'manufacturer' => null,
        'model' => null,
        'serial_number' => null,
        'position_note' => null,
        'created_by_account_id' => $gäst->id,
        'billed_account_id' => $gäst->id,
    ]);
    $bilaga = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => StoredFile::factory()->create(['byte_size' => $bytes])->id,
        'billed_account_id' => $gäst->id,
    ]);

    bbRäkna($gäst, $ägare);

    return ['gast' => $gäst, 'person' => $person, 'agare' => $ägare, 'container' => $container, 'item' => $item, 'bilaga' => $bilaga];
}

function bbRäkna(Account ...$konton): void
{
    $text = CountedRows::bytesPerAccount();

    foreach ($konton as $konto) {
        $filer = (int) DB::table('attachment')
            ->join('stored_file', 'stored_file.id', '=', 'attachment.stored_file_id')
            ->where('attachment.billed_account_id', $konto->id)
            ->sum('stored_file.byte_size');

        UsageCounter::query()->where('account_id', $konto->id)->delete();
        UsageCounter::factory()->create([
            'account_id' => $konto->id,
            'storage_bytes' => $filer,
            'metadata_bytes' => $text[$konto->id] ?? 0,
            'container_count' => DB::table('container')->where('account_id', $konto->id)->whereNull('deleted_at')->count(),
        ]);
    }
}

function bbÅtkomst(Container $container, string $typ, int $id, array $attribut = []): ContainerAccess
{
    $åtkomst = ContainerAccess::factory()->create(array_merge([
        'container_id' => $container->id,
        'grantee_type' => $typ,
        'grantee_id' => $id,
    ], $attribut));

    return $åtkomst;
}

function bbÅterkalla(Container $container, ContainerAccess $åtkomst): void
{
    app(RevokeContainerAccess::class)->handle(User::factory()->create(), $container, $åtkomst);
}

function bbRäknare(Account $konto): array
{
    $rad = UsageCounter::query()->where('account_id', $konto->id)->first();

    return [$rad === null ? 0 : (int) $rad->storage_bytes, $rad === null ? 0 : (int) $rad->metadata_bytes];
}

it('en återkallad åtkomst flyttar bilagor och rader till ägaren, och bytena flyttar med', function () {
    $u = bbUppsättning(500);
    $slängd = Attachment::factory()->for($u['item'], 'item')->create([
        'stored_file_id' => StoredFile::factory()->create(['byte_size' => 200])->id,
        'billed_account_id' => $u['gast']->id,
        'deleted_at' => now(),
    ]);
    bbRäkna($u['gast'], $u['agare']);
    $text = CountedRows::bytesPerAccount()[$u['gast']->id];
    $ägarens = bbRäknare($u['agare']);
    $åtkomst = bbÅtkomst($u['container'], 'user', $u['person']->id);

    expect(bbRäknare($u['gast']))->toBe([700, $text]);

    bbÅterkalla($u['container'], $åtkomst);

    expect($u['bilaga']->fresh()->billed_account_id)->toBe($u['agare']->id)
        ->and(Attachment::withTrashed()->find($slängd->id)->billed_account_id)->toBe($u['agare']->id)
        ->and($u['item']->fresh()->billed_account_id)->toBe($u['agare']->id)
        ->and(bbRäknare($u['gast']))->toBe([0, 0])
        ->and(bbRäknare($u['agare']))->toBe([700 + $ägarens[0], $text + $ägarens[1]])
        ->and(Attachment::withTrashed()->count())->toBe(2)
        ->and(Item::query()->count())->toBe(1);
});

it('en åtkomst via kontot flyttar också när den återkallas', function () {
    $u = bbUppsättning();
    $åtkomst = bbÅtkomst($u['container'], 'account', $u['gast']->id);

    bbÅterkalla($u['container'], $åtkomst);

    expect($u['bilaga']->fresh()->billed_account_id)->toBe($u['agare']->id);
});

it('en person med två åtkomster i samma container behåller betalningen när den ena återkallas', function () {
    $u = bbUppsättning();
    $container = bbÅtkomst($u['container'], 'user', $u['person']->id);
    bbÅtkomst($u['container'], 'user', $u['person']->id, ['item_id' => $u['item']->id]);

    bbÅterkalla($u['container'], $container);

    expect($u['bilaga']->fresh()->billed_account_id)->toBe($u['gast']->id)
        ->and($u['item']->fresh()->billed_account_id)->toBe($u['gast']->id)
        ->and(bbRäknare($u['gast'])[0])->toBe(500);
});

it('en utgången åtkomst flyttas av nattjobbet, och en giltig rörs inte', function () {
    Carbon::setTestNow('2026-11-16 12:00:00');
    $logg = Log::spy();
    $u = bbUppsättning();
    bbÅtkomst($u['container'], 'user', $u['person']->id, ['expires_at' => now()->addDay()]);

    expect(app(TransfersOrphanedBilling::class)->handle())->toBe(0)
        ->and($u['bilaga']->fresh()->billed_account_id)->toBe($u['gast']->id);

    Carbon::setTestNow('2026-11-18 12:00:00');

    expect(app(TransfersOrphanedBilling::class)->handle())->toBe(1)
        ->and($u['bilaga']->fresh()->billed_account_id)->toBe($u['agare']->id)
        ->and(bbRäknare($u['gast']))->toBe([0, 0]);

    $logg->shouldHaveReceived('info')->withArgs(fn ($msg, $ctx = []) => $msg === 'billing.transferred'
        && $ctx['account'] === $u['gast']->ulid
        && $ctx['container'] === $u['container']->ulid
        && $ctx['bytes'] > 500
        && ! array_key_exists('name', $ctx))->once();
});

it('nattjobbet fångar en åtkomst som aldrig fanns', function () {
    $u = bbUppsättning();

    app(TransfersOrphanedBilling::class)->handle();

    expect($u['bilaga']->fresh()->billed_account_id)->toBe($u['agare']->id);
});

it('en medlem i ägarkontot berörs inte', function () {
    $u = bbUppsättning();
    $medlem = User::factory()->create();
    $u['agare']->users()->attach($medlem->id, ['role' => 'member']);
    $egen = Item::factory()->for($u['container'], 'container')->create([
        'name' => 'Item-B',
        'created_by_account_id' => $u['agare']->id,
        'billed_account_id' => $u['agare']->id,
    ]);
    bbRäkna($u['gast'], $u['agare']);
    $före = bbRäknare($u['agare']);
    bbÅtkomst($u['container'], 'user', $u['person']->id);
    $åtkomst = bbÅtkomst($u['container'], 'user', $medlem->id);

    bbÅterkalla($u['container'], $åtkomst);
    app(TransfersOrphanedBilling::class)->handle();

    expect($egen->fresh()->billed_account_id)->toBe($u['agare']->id)
        ->and(bbRäknare($u['agare']))->toBe($före)
        ->and($u['bilaga']->fresh()->billed_account_id)->toBe($u['gast']->id);
});

it('ägaren som hamnar över gränsen får ändå innehållet och ingenting raderas', function () {
    $u = bbUppsättning(5_000_000_000);
    $åtkomst = bbÅtkomst($u['container'], 'user', $u['person']->id);

    bbÅterkalla($u['container'], $åtkomst);

    expect($u['bilaga']->fresh()->billed_account_id)->toBe($u['agare']->id)
        ->and(bbRäknare($u['agare'])[0])->toBe(5_000_000_000)
        ->and(Attachment::withTrashed()->count())->toBe(1)
        ->and(Item::withTrashed()->count())->toBe(1);
});

it('avstämningen rapporterar ingen drift efter flytten', function () {
    $u = bbUppsättning();
    $åtkomst = bbÅtkomst($u['container'], 'user', $u['person']->id);

    bbÅterkalla($u['container'], $åtkomst);

    expect(app(ReconcilesUsageCounters::class)->handle())->toBe(0);

    $utgången = bbUppsättning(300);
    bbÅtkomst($utgången['container'], 'user', $utgången['person']->id, ['expires_at' => now()->subDay()]);
    app(TransfersOrphanedBilling::class)->handle();

    expect(app(ReconcilesUsageCounters::class)->handle())->toBe(0);
});

it('en medlems personkonto behåller betalningen via en kvarvarande kontogrant, och flyttas när den återkallas', function () {
    $u = bbUppsättning();
    $företag = Account::factory()->organisation()->create();
    $företag->users()->attach($u['person']->id, ['role' => 'member']);
    $grant = bbÅtkomst($u['container'], 'account', $företag->id);
    $annan = bbÅtkomst($u['container'], 'account', $företag->id, ['item_id' => $u['item']->id]);

    expect(app(TransfersOrphanedBilling::class)->handle())->toBe(0)
        ->and($u['bilaga']->fresh()->billed_account_id)->toBe($u['gast']->id);

    bbÅterkalla($u['container'], $grant);
    expect($u['bilaga']->fresh()->billed_account_id)->toBe($u['gast']->id);

    bbÅterkalla($u['container'], $annan);
    expect($u['bilaga']->fresh()->billed_account_id)->toBe($u['agare']->id);
});

it('nattjobbet flyttar betalare i en mjukraderad container', function () {
    $u = bbUppsättning();
    $u['container']->delete();

    app(TransfersOrphanedBilling::class)->handle();

    expect($u['bilaga']->fresh()->billed_account_id)->toBe($u['agare']->id);
});
