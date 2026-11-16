<?php

use App\Actions\Account\DeleteAccount;
use App\Actions\Plan\StartDowngrade;
use App\Console\EnforcesDowngrades;
use App\Mail\NotificationMail;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\LegalHold;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Support\Notification\NotificationPreferences;
use App\Support\Usage\CountedRows;
use App\Support\Usage\MetadataSize;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * Issue 1304 · Nedgraderingen följer ADR-0081 § 4 och § 6
 * ([[ADR-0081 All lagrad data räknas mot kvoten]]). Steg 1 (StartDowngrade)
 * varnar ägarna av de containrar kontot lagt innehåll i; steg 4
 * (EnforcesDowngrades) flyttar det främmande till ägaren, raderar ingenting
 * där, och tar i kontots egna containrar filerna före items.
 *
 * Räknarna byggs om ur raderna med nedRäkna(): fabrikerna skriver inte genom
 * MetadataLedger, och provet behöver exakta tal att sätta gränsen mot.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en användare. `$frist`: null utan prenumeration, annars
 * fristen. `$skrivskyddat` ger kontot det tillstånd steg 4 gäller.
 *
 * @return array{0: Account, 1: User}
 */
function nedKonto(bool $skrivskyddat = true, ?Carbon $frist = null): array
{
    $konto = Account::factory()->create($skrivskyddat
        ? ['status' => 'read_only', 'read_only_reason' => 'over_quota']
        : []);
    $användare = User::factory()->create();
    $konto->users()->attach($användare->id, ['role' => 'owner']);

    if ($frist !== null) {
        Subscription::factory()->for($konto, 'account')->create([
            'plan_id' => Plan::factory()->create()->id,
            'status' => 'past_due',
            'grace_until' => $frist,
        ]);
    }

    return [$konto, $användare];
}

function nedItem(Container $container, Account $betalare, string $namn, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'name' => $namn,
        'description' => null,
        'notes' => null,
        'manufacturer' => null,
        'model' => null,
        'serial_number' => null,
        'position_note' => null,
        'created_by_account_id' => $betalare->id,
        'billed_account_id' => $betalare->id,
    ], $attribut));
}

function nedBilaga(Item $item, Account $betalare, int $bytes, array $attribut = []): Attachment
{
    return Attachment::factory()->for($item, 'item')->create(array_merge([
        'stored_file_id' => StoredFile::factory()->create(['byte_size' => $bytes])->id,
        'billed_account_id' => $betalare->id,
    ], $attribut));
}

/**
 * Bygger om kontots räknare ur raderna: filer ur bilagorna, text ur
 * CountedRows (samma mängd som nattens avstämning).
 */
function nedRäkna(Account ...$konton): void
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
            'container_count' => 0,
        ]);
    }
}

function nedKör(): void
{
    app(EnforcesDowngrades::class)->handle();
}

/** Ett item väger lika mycket oavsett namn om namnen är lika långa. */
function nedItemVikt(): int
{
    return MetadataSize::ofAttributes('item', ['name' => 'Item-A']);
}

function nedFinns(Item $item): bool
{
    return Item::withTrashed()->whereKey($item->id)->exists();
}

it('steg 1 skapar en notis per främmande container med rätt byte och datum', function () {
    Carbon::setTestNow('2026-09-01 12:00:00');
    [$gäst] = nedKonto(skrivskyddat: false, frist: Carbon::parse('2026-09-01 12:00:00'));
    [$ägare, $ägarensPerson] = nedKonto(skrivskyddat: false);
    $andraPerson = User::factory()->create();
    $ägare->users()->attach($andraPerson->id, ['role' => 'member']);

    $främmande = Container::factory()->for($ägare, 'account')->create(['name' => 'Stugan']);
    $item = nedItem($främmande, $gäst, 'Item-A');
    nedBilaga($item, $gäst, 500);
    $egen = Container::factory()->for($gäst, 'account')->create();
    nedBilaga(nedItem($egen, $gäst, 'Item-B'), $gäst, 900);

    (new StartDowngrade)->handle($gäst, 'over_quota');

    $notiser = Notification::query()->where('type', Notification::TYPE_CONTENT_MOVING)->get();

    expect($notiser)->toHaveCount(2)
        ->and($notiser->pluck('account_id')->unique()->all())->toBe([$ägare->id])
        ->and($notiser->pluck('user_id')->sort()->values()->all())->toBe(collect([$ägarensPerson->id, $andraPerson->id])->sort()->values()->all())
        ->and($notiser->pluck('container_id')->unique()->all())->toBe([$främmande->id]);

    $grace = Subscription::query()->where('account_id', $gäst->id)->first()->grace_until->toDateString();
    $text = nedItemVikt();
    $nyckel = "container.content_moving:{$främmande->ulid}:{$gäst->ulid}:{$grace}:".$ägarensPerson->ulid;
    $första = $notiser->firstWhere('dedupe_key', $nyckel);

    expect(Notification::TYPE_CONTENT_MOVING)->toBe('container.content_moving')
        ->and($första)->not->toBeNull()
        ->and($första->payload)->toBe([
            'container' => 'Stugan',
            'account' => $gäst->name,
            'bytes' => 500 + $text,
            'moves_on' => $grace,
        ]);
});

it('steg 1 ger ingen notis åt ett konto utan främmande innehåll', function () {
    [$gäst] = nedKonto(skrivskyddat: false, frist: Carbon::parse('2027-01-01'));
    $egen = Container::factory()->for($gäst, 'account')->create();
    nedBilaga(nedItem($egen, $gäst, 'Item-A'), $gäst, 500);

    (new StartDowngrade)->handle($gäst, 'over_quota');

    expect(Notification::query()->where('type', Notification::TYPE_CONTENT_MOVING)->count())->toBe(0);
});

it('steg 1 utan frist utelämnar datumet, och mejlet har en variant utan det', function () {
    [$gäst] = nedKonto(skrivskyddat: false);
    [$ägare, $person] = nedKonto(skrivskyddat: false);
    $container = Container::factory()->for($ägare, 'account')->create(['name' => 'Stugan']);
    nedBilaga(nedItem($container, $gäst, 'Item-A'), $gäst, 500);

    (new StartDowngrade)->handle($gäst, 'over_quota');

    $notis = Notification::query()->where('type', Notification::TYPE_CONTENT_MOVING)->sole();

    expect($notis->payload)->not->toHaveKey('moves_on')
        ->and($notis->dedupe_key)->toBe("container.content_moving:{$container->ulid}:{$gäst->ulid}:{$person->ulid}");

    $utanDatum = (new NotificationMail($person, $notis->type, $notis->payload))->render();
    expect($utanDatum)->toContain('is being downgraded')
        ->and($utanDatum)->not->toContain(':moves_on')
        ->and($utanDatum)->not->toContain('On ');

    $stor = (new NotificationMail($person, $notis->type, [...$notis->payload, 'bytes' => 12900000]))->render();
    expect($stor)->toContain('12.3 MB')->and($stor)->not->toContain('12900000');

    $medDatum = (new NotificationMail($person, $notis->type, [...$notis->payload, 'moves_on' => '2026-12-01']))->render();
    expect($medDatum)->toContain('On 2026-12-01');
});

it('webhooks får inte typen och förvalet är på utan sammandrag', function () {
    expect(WebhookEndpoint::EVENT_TYPES)->not->toContain(Notification::TYPE_CONTENT_MOVING);

    $person = User::factory()->create();
    $förval = app(NotificationPreferences::class);

    expect($förval->channelsFor($person, Notification::TYPE_CONTENT_MOVING))->toContain('email')
        ->and($förval->digest($person, Notification::TYPE_CONTENT_MOVING, 'mail'))->toBeFalse();
});

it('steg 4 flyttar bilagor och rader i någon annans container till ägaren och raderar ingenting där', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    sättPlangräns('free', 'storage_bytes', 100000);
    [$gäst] = nedKonto(frist: Carbon::parse('2026-09-01 12:00:00'));
    [$ägare] = nedKonto(skrivskyddat: false);
    $container = Container::factory()->for($ägare, 'account')->create();
    $item = nedItem($container, $gäst, 'Item-A');
    $bilaga = nedBilaga($item, $gäst, 700);
    $slängd = nedBilaga($item, $gäst, 300, ['deleted_at' => Carbon::parse('2026-08-01')]);
    $kostnad = CostEntry::factory()->for($item, 'item')->create([
        'created_by_account_id' => $gäst->id,
        'billed_account_id' => $gäst->id,
        'description' => 'Reservdel',
        'supplier' => null,
    ]);
    nedRäkna($gäst, $ägare);
    $text = (int) UsageCounter::query()->where('account_id', $gäst->id)->value('metadata_bytes');
    $ägarensEgen = (int) UsageCounter::query()->where('account_id', $ägare->id)->value('metadata_bytes');

    nedKör();

    expect(Attachment::withTrashed()->whereKey([$bilaga->id, $slängd->id])->pluck('billed_account_id')->unique()->all())->toBe([$ägare->id])
        ->and($item->refresh()->billed_account_id)->toBe($ägare->id)
        ->and(CostEntry::withTrashed()->find($kostnad->id)->billed_account_id)->toBe($ägare->id)
        ->and(nedFinns($item))->toBeTrue()
        ->and(DB::table('attachment')->whereIn('id', [$bilaga->id, $slängd->id])->count())->toBe(2);

    $gästRäknare = UsageCounter::query()->where('account_id', $gäst->id)->first();
    $ägarRäknare = UsageCounter::query()->where('account_id', $ägare->id)->first();

    expect($gästRäknare->storage_bytes)->toBe(0)
        ->and($gästRäknare->metadata_bytes)->toBe(0)
        ->and($ägarRäknare->storage_bytes)->toBe(1000)
        ->and($ägarRäknare->metadata_bytes)->toBe($ägarensEgen + $text)
        ->and($gäst->refresh()->status)->toBe('active');
});

it('steg 4 tar slängda och levande bilagor före items, och ett konto som blir under gränsen med bara bilagor behåller alla items', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$gäst] = nedKonto(frist: Carbon::parse('2026-09-01 12:00:00'));
    $container = Container::factory()->for($gäst, 'account')->create();
    $a = nedItem($container, $gäst, 'Item-A');
    $b = nedItem($container, $gäst, 'Item-B');
    $slängd = nedBilaga($a, $gäst, 400, ['deleted_at' => Carbon::parse('2026-08-01')]);
    $äldre = nedBilaga($a, $gäst, 400, ['created_at' => Carbon::parse('2026-06-01')]);
    $nyare = nedBilaga($b, $gäst, 400, ['created_at' => Carbon::parse('2026-07-01')]);
    nedRäkna($gäst);
    $text = (int) UsageCounter::query()->where('account_id', $gäst->id)->value('metadata_bytes');
    // Räcker att ta den slängda och den nyaste levande.
    sättPlangräns('free', 'storage_bytes', $text + 400);

    nedKör();

    expect(Attachment::withTrashed()->whereKey($slängd->id)->exists())->toBeFalse()
        ->and(Attachment::withTrashed()->whereKey($nyare->id)->exists())->toBeFalse()
        ->and(Attachment::withTrashed()->whereKey($äldre->id)->exists())->toBeTrue()
        ->and(nedFinns($a))->toBeTrue()
        ->and(nedFinns($b))->toBeTrue()
        ->and(UsageCounter::usedBytes($gäst->id))->toBe($text + 400);
});

it('steg 4 raderar items, slängda först och sedan nyast först, när filerna inte räcker', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$gäst] = nedKonto(frist: Carbon::parse('2026-09-01 12:00:00'));
    $container = Container::factory()->for($gäst, 'account')->create();
    $äldst = nedItem($container, $gäst, 'Item-A', ['created_at' => Carbon::parse('2026-01-01')]);
    $nyast = nedItem($container, $gäst, 'Item-B', ['created_at' => Carbon::parse('2026-05-01')]);
    $slängd = nedItem($container, $gäst, 'Item-C', ['created_at' => Carbon::parse('2026-03-01')]);
    $slängd->delete();
    $bilaga = nedBilaga($äldst, $gäst, 300);
    nedRäkna($gäst);
    $totalt = UsageCounter::usedBytes($gäst->id);
    $vikt = nedItemVikt();

    // Bilagan först, sedan det slängda itemet — det levande nyaste står kvar.
    sättPlangräns('free', 'storage_bytes', $totalt - 300 - $vikt);
    nedKör();

    expect(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeFalse()
        ->and(nedFinns($slängd))->toBeFalse()
        ->and(nedFinns($nyast))->toBeTrue()
        ->and(nedFinns($äldst))->toBeTrue();

    // En gång till, nu över gränsen med ett levande item: nyast först.
    $gäst->refresh()->update(['status' => 'read_only', 'read_only_reason' => 'over_quota']);
    Subscription::query()->where('account_id', $gäst->id)->update(['status' => 'past_due', 'grace_until' => Carbon::parse('2026-09-01')]);
    sättPlangräns('free', 'storage_bytes', UsageCounter::usedBytes($gäst->id) - $vikt);
    nedKör();

    expect(nedFinns($nyast))->toBeFalse()
        ->and(nedFinns($äldst))->toBeTrue()
        ->and(Container::query()->whereKey($container->id)->exists())->toBeTrue();
});

it('steg 4 raderar inte ett item med en annan medlems bilaga eller kostnadsrad i underträdet', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    $logg = Log::spy();
    [$gäst] = nedKonto(frist: Carbon::parse('2026-09-01 12:00:00'));
    [$medlem] = nedKonto(skrivskyddat: false);
    $container = Container::factory()->for($gäst, 'account')->create();
    $friTillSlut = nedItem($container, $gäst, 'Item-A', ['created_at' => Carbon::parse('2026-01-01')]);
    $medBilaga = nedItem($container, $gäst, 'Item-B', ['created_at' => Carbon::parse('2026-02-01')]);
    $medKostnad = nedItem($container, $gäst, 'Item-C', ['created_at' => Carbon::parse('2026-03-01')]);
    $förälder = nedItem($container, $gäst, 'Item-D', ['created_at' => Carbon::parse('2026-04-01')]);
    $barn = nedItem($container, $medlem, 'Item-E', ['created_at' => Carbon::parse('2026-04-02')]);
    DB::table('item_link')->insert([
        'from_item_id' => $förälder->id, 'to_item_id' => $barn->id, 'relation' => 'parent',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $deras = nedBilaga($medBilaga, $medlem, 200);
    $dinKostnad = CostEntry::factory()->for($medKostnad, 'item')->create([
        'created_by_account_id' => $medlem->id,
        'billed_account_id' => $medlem->id,
        'description' => 'Deras',
        'supplier' => null,
    ]);
    nedRäkna($gäst);
    sättPlangräns('free', 'storage_bytes', 0);

    nedKör();

    expect(nedFinns($friTillSlut))->toBeFalse()
        ->and(nedFinns($medBilaga))->toBeTrue()
        ->and(nedFinns($medKostnad))->toBeTrue()
        ->and(nedFinns($förälder))->toBeTrue()
        ->and(nedFinns($barn))->toBeTrue()
        ->and(Attachment::withTrashed()->whereKey($deras->id)->exists())->toBeTrue()
        ->and(CostEntry::withTrashed()->whereKey($dinKostnad->id)->exists())->toBeTrue()
        ->and($gäst->refresh()->status)->toBe('active');

    $logg->shouldHaveReceived('warning')->once()->withArgs(
        fn (string $meddelande, array $kontext) => $meddelande === 'downgrade.content_exhausted'
            && ($kontext['account_ulid'] ?? null) === $gäst->ulid
            && ($kontext['storage_bytes'] ?? null) === 0
            && ($kontext['metadata_bytes'] ?? 0) > 0
            && ($kontext['limit_bytes'] ?? null) === 0,
    );
});

it('en rättslig spärr stoppar både flytten och raderingen', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    sättPlangräns('free', 'storage_bytes', 0);
    [$gäst] = nedKonto(frist: Carbon::parse('2026-09-01 12:00:00'));
    [$ägare] = nedKonto(skrivskyddat: false);
    $främmande = Container::factory()->for($ägare, 'account')->create();
    $egen = Container::factory()->for($gäst, 'account')->create();
    $främmandeItem = nedItem($främmande, $gäst, 'Item-A');
    $egetItem = nedItem($egen, $gäst, 'Item-B');
    $borta = nedBilaga($främmandeItem, $gäst, 100);
    $kvar = nedBilaga($egetItem, $gäst, 100);
    nedRäkna($gäst, $ägare);
    LegalHold::factory()->create(['account_id' => $gäst->id]);

    nedKör();

    expect($borta->refresh()->billed_account_id)->toBe($gäst->id)
        ->and($främmandeItem->refresh()->billed_account_id)->toBe($gäst->id)
        ->and(Attachment::withTrashed()->whereKey($kvar->id)->exists())->toBeTrue()
        ->and(nedFinns($egetItem))->toBeTrue()
        ->and($gäst->refresh()->status)->toBe('read_only')
        ->and(UsageCounter::query()->where('account_id', $ägare->id)->value('storage_bytes'))->toBe(0);
});

it('kontoraderingen flyttar fortfarande främmande bilagor och rader, nu genom den delade actionen', function () {
    [$gäst] = nedKonto(skrivskyddat: false);
    [$ägare] = nedKonto(skrivskyddat: false);
    $container = Container::factory()->for($ägare, 'account')->create();
    $item = nedItem($container, $gäst, 'Item-A');
    $bilaga = nedBilaga($item, $gäst, 600);
    nedRäkna($gäst, $ägare);
    $text = (int) UsageCounter::query()->where('account_id', $gäst->id)->value('metadata_bytes');
    $ägarensEgen = (int) UsageCounter::query()->where('account_id', $ägare->id)->value('metadata_bytes');

    app(DeleteAccount::class)->handle($gäst, DeleteAccount::REASON_OWNER_DELETED);

    expect($bilaga->refresh()->billed_account_id)->toBe($ägare->id)
        ->and($item->refresh()->billed_account_id)->toBe($ägare->id)
        ->and(Account::query()->whereKey($gäst->id)->exists())->toBeFalse();

    $räknare = UsageCounter::query()->where('account_id', $ägare->id)->first();
    expect($räknare->storage_bytes)->toBe(600)
        ->and($räknare->metadata_bytes)->toBe($ägarensEgen + $text);
});
