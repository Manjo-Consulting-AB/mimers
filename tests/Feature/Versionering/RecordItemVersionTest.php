<?php

use App\Actions\Container\CreateContainer;
use App\Actions\Field\CreateField;
use App\Actions\Item\CreateItem;
use App\Actions\Item\UpdateItem;
use App\Actions\Item\UpdateItemNotes;
use App\Actions\Notification\NotifyVersioningSkipped;
use App\Actions\Tag\CreateTag;
use App\Actions\Usage\AdjustUsage;
use App\Console\ReconcilesUsageCounters;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemFieldValue;
use App\Models\ItemVersion;
use App\Models\LegalHold;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\Usage\CountedRows;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Issue 412 · En ändring av ett item sparar läget FÖRE ändringen som en
 * version, se [[ADR-0088 Versionshistorik för items]] Riktning 2, 3, 4 och 6.
 *
 * Allt skapas genom actionerna och inte med fabriker: versionerna bokförs i
 * `usage_counter.metadata_bytes` genom App\Support\Usage\MetadataLedger, och
 * proven jämför räknaren med avstämningen efter varje fall. Fabrikerna rör
 * aldrig räknaren.
 *
 * Klockan flyttas med `Carbon::setTestNow()` — skurvens tio minuter är
 * väggklocka, och utan den hade varje skrivning i ett prov hamnat i samma
 * arbetspass.
 *
 * Hjälparna har prefixet `version` — Pest lägger alla testfiler i samma
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
function versionKonto(int $tak = 3): array
{
    $konto = Account::factory()->create(['versioning_max_versions' => $tak]);
    $användare = User::factory()->create();
    $konto->users()->attach($användare, ['role' => 'owner']);

    return [$konto, $användare];
}

/**
 * En container (skapad genom CreateContainer) i kontot.
 */
function versionContainer(Account $konto, User $användare, ?int $tak = null): Container
{
    $container = app(CreateContainer::class)->handle($användare, $konto, 'Båten', 'boat', null);

    if ($tak !== null) {
        $container->versioning_max_versions = $tak;
        $container->save();
    }

    return $container;
}

/**
 * Ett item skapat genom CreateItem, så att räknaren följer med.
 *
 * @param  array<string, mixed>  $attribut
 */
function versionItem(
    Container $container,
    Account $konto,
    User $användare,
    array $attribut = ['name' => 'Motor'],
    array $taggar = [],
): Item {
    return app(CreateItem::class)->handle(
        $container,
        $konto,
        $användare,
        new Item($attribut),
        null,
        collect($taggar),
        null,
    );
}

/**
 * En inbjuden med `write` på containern, i ett eget konto med gott om plats.
 *
 * @return array{0: Account, 1: User}
 */
function versionInbjuden(Container $container): array
{
    [$konto, $användare] = versionKonto(3);
    beviljaAccess($container, $användare, 'write', 'member');

    return [$konto, $användare];
}

/**
 * En vanlig ändring av itemets namn, genom UpdateItem.
 */
function versionSkriv(Item $item, User $aktör, string $namn): void
{
    $item->name = $namn;
    app(UpdateItem::class)->handle($item, $aktör, false, collect());
}

/**
 * Räknaren är lika med avstämningens tal, och avstämningen rapporterar ingen
 * drift — samma vakt som i tests/Feature/Kvot/MetadataLopandeTest.php.
 */
function versionStammer(Account ...$konton): void
{
    $räknat = CountedRows::bytesPerAccount();

    foreach ($konton as $konto) {
        expect((int) DB::table('usage_counter')->where('account_id', $konto->id)->value('metadata_bytes'))
            ->toBe($räknat[$konto->id] ?? 0);
    }

    expect((new ReconcilesUsageCounters)->handle())->toBe(0);
}

it('sparar en version med de gamla fälten och taggarna, betald av containerns ägare', function () {
    [$ägare, $ägarUser] = versionKonto();
    $container = versionContainer($ägare, $ägarUser);

    $röd = app(CreateTag::class)->handle($container, 'Röd', null, $ägarUser);
    $blå = app(CreateTag::class)->handle($container, 'Blå', null, $ägarUser);

    $item = versionItem(
        $container,
        $ägare,
        $ägarUser,
        ['name' => 'Motor', 'description' => 'Gammal beskrivning'],
        [$röd, $blå],
    );

    [$skrivarKonto, $skrivare] = versionInbjuden($container);

    $item->description = 'Ny beskrivning';
    app(UpdateItem::class)->handle($item, $skrivare, false, collect());

    $version = ItemVersion::query()->where('item_id', $item->id)->sole();

    expect($version->name)->toBe('Motor')
        ->and($version->description)->toBe('Gammal beskrivning')
        ->and($version->tag_ids)->toBe([$röd->id, $blå->id])
        // Ägaren betalar, inte den inbjudna som skrev (Riktning 3).
        ->and($version->billed_account_id)->toBe($ägare->id)
        ->and($version->replaced_by_user_id)->toBe($skrivare->id)
        ->and($version->replaced_by_agent_id)->toBeNull();

    expect(Item::query()->find($item->id)->description)->toBe('Ny beskrivning');

    versionStammer($ägare, $skrivarKonto);
});

it('sparar ingen version när ingenting ändras', function () {
    [$ägare, $användare, $container, $item] = (function () {
        [$konto, $user] = versionKonto();
        $container = versionContainer($konto, $user);
        $tagg = app(CreateTag::class)->handle($container, 'Röd', null, $user);
        $item = versionItem($container, $konto, $user, ['name' => 'Motor'], [$tagg]);

        return [$konto, $user, $container, $item];
    })();

    $tagg = $container->tags()->firstOrFail();

    // En färsk modell, som den kontrollern läser ur databasen — CreateItem
    // lämnar `notes` utanför attributen, och en modell som aldrig har sett
    // kolumnen ser den som smutsig när den sätts till null.
    $item = Item::query()->findOrFail($item->id);

    // Samma namn och samma taggmängd: ingen rad, ingen version.
    $item->name = 'Motor';
    app(UpdateItem::class)->handle($item, $användare, true, collect([$tagg]));

    // Samma anteckning som förut.
    $item->notes = null;
    app(UpdateItemNotes::class)->handle($item, $användare, null);

    expect(ItemVersion::query()->count())->toBe(0);
});

it('sparar ingen version när taket är 0 på kontot eller containern', function () {
    // Kontots tak 0 stänger av.
    [$konto, $user] = versionKonto(0);
    $container = versionContainer($konto, $user);
    $item = versionItem($container, $konto, $user);

    versionSkriv($item, $user, 'Motorn');

    expect(ItemVersion::query()->count())->toBe(0);

    // Containerns 0 stänger av även när kontot tillåter.
    [$konto, $user] = versionKonto(3);
    $container = versionContainer($konto, $user, tak: 0);
    $item = versionItem($container, $konto, $user);

    versionSkriv($item, $user, 'Motorn');

    expect(ItemVersion::query()->count())->toBe(0);
});

it('gör tre skrivningar inom tio minuter till en version och bryter skurven först efter elfte minuten', function () {
    [$ägare, $användare, $container, $item] = (function () {
        [$konto, $user] = versionKonto();
        $container = versionContainer($konto, $user);

        return [$konto, $user, $container, versionItem($container, $konto, $user)];
    })();

    versionSkriv($item, $användare, 'A');

    Carbon::setTestNow(now()->addMinutes(5));
    versionSkriv($item, $användare, 'B');

    Carbon::setTestNow(now()->addMinutes(5));
    versionSkriv($item, $användare, 'C');

    $versioner = ItemVersion::query()->where('item_id', $item->id)->get();

    expect($versioner)->toHaveCount(1)
        // Läget före den FÖRSTA skrivningen.
        ->and($versioner[0]->name)->toBe('Motor');

    // Elva minuter efter den sista skrivningen: skurven är bruten.
    Carbon::setTestNow(now()->addMinutes(11));
    versionSkriv($item, $användare, 'D');

    $versioner = ItemVersion::query()
        ->where('item_id', $item->id)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();

    expect($versioner)->toHaveCount(2)
        ->and($versioner[1]->name)->toBe('C');

    versionStammer($ägare);
});

it('bryter skurven när en annan användare skriver inom fönstret', function () {
    [$ägare, $användare] = versionKonto();
    $container = versionContainer($ägare, $användare);
    $item = versionItem($container, $ägare, $användare);

    versionSkriv($item, $användare, 'A');

    Carbon::setTestNow(now()->addMinutes(5));

    [, $skrivare] = versionInbjuden($container);
    versionSkriv($item, $skrivare, 'B');

    $versioner = ItemVersion::query()
        ->where('item_id', $item->id)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();

    expect($versioner)->toHaveCount(2)
        ->and($versioner[0]->name)->toBe('Motor')
        ->and($versioner[1]->name)->toBe('A')
        ->and($versioner[1]->replaced_by_user_id)->toBe($skrivare->id);
});

it('behåller högst taket antal versioner och tar bort de äldsta', function () {
    [$ägare, $användare] = versionKonto(3);
    $container = versionContainer($ägare, $användare);
    $item = versionItem($container, $ägare, $användare);

    foreach (['A', 'B', 'C', 'D', 'E'] as $namn) {
        Carbon::setTestNow(now()->addMinutes(11));
        versionSkriv($item, $användare, $namn);
    }

    $namn = ItemVersion::query()
        ->where('item_id', $item->id)
        ->orderBy('created_at')
        ->orderBy('id')
        ->pluck('name')
        ->all();

    // Före A, B, C, D och E — de två äldsta (Motor och A) är borta.
    expect($namn)->toBe(['B', 'C', 'D']);
});

it('gör anteckningens autosparning till en version per arbetspass', function () {
    [$ägare, $användare] = versionKonto();
    $container = versionContainer($ägare, $användare);
    $item = versionItem($container, $ägare, $användare);

    app(UpdateItemNotes::class)->handle($item, $användare, 'första');

    Carbon::setTestNow(now()->addMinutes(5));
    app(UpdateItemNotes::class)->handle($item, $användare, 'andra');

    Carbon::setTestNow(now()->addMinutes(5));
    app(UpdateItemNotes::class)->handle($item, $användare, 'tredje');

    $version = ItemVersion::query()->where('item_id', $item->id)->sole();

    expect($version->notes)->toBeNull()
        ->and($version->replaced_by_user_id)->toBe($användare->id);

    // Ett nytt arbetspass efter fönstret blir en ny version.
    Carbon::setTestNow(now()->addMinutes(11));
    app(UpdateItemNotes::class)->handle($item, $användare, 'fjärde');

    expect(ItemVersion::query()->where('item_id', $item->id)->count())->toBe(2);

    versionStammer($ägare);
});

it('sparar en version för ett taggbyte utan andra ändringar', function () {
    [$ägare, $användare] = versionKonto();
    $container = versionContainer($ägare, $användare);
    $item = versionItem($container, $ägare, $användare);

    $tagg = app(CreateTag::class)->handle($container, 'Röd', null, $användare);

    app(UpdateItem::class)->handle($item, $användare, true, collect([$tagg]));

    $version = ItemVersion::query()->where('item_id', $item->id)->sole();

    expect($version->tag_ids)->toBe([])
        ->and(array_map('intval', DB::table('item_tag')->where('item_id', $item->id)->pluck('tag_id')->all()))
        ->toBe([$tagg->id]);
});

it('sparar en version när bara ett fältvärde ändras, med det gamla värdet', function () {
    [$ägare, $användare] = versionKonto();
    $container = versionContainer($ägare, $användare);
    $item = versionItem($container, $ägare, $användare);

    // Fältet skapas genom actionen, så att `metadata_bytes` bokförs som
    // avstämningen räknar (versionStammer).
    $fält = app(CreateField::class)->handle($container, null, 'Serienummer', 'text', 1, $användare);

    // Första skrivningen ger fältet ett värde. Ett tomt värde har ingen rad
    // ([[ADR-0089 Egna fält på items]] § Riktning 9), så det är en ändring
    // från "inget värde" — och versionen bär det tomma läget.
    app(UpdateItem::class)->handle($item, $användare, false, collect(), [
        ['field' => $fält->ulid, 'value' => 'Gammalt'],
    ]);

    expect(ItemVersion::query()->where('item_id', $item->id)->sole()->field_values)->toBe([]);

    // Bara fältvärdet ändras: itemets kolumner och taggar står stilla. Utan
    // jämförelsen i RecordItemVersion hade detta inte gett någon version.
    Carbon::setTestNow(now()->addMinutes(11));
    $item = Item::query()->findOrFail($item->id);

    app(UpdateItem::class)->handle($item, $användare, false, collect(), [
        ['field' => $fält->ulid, 'value' => 'Nytt'],
    ]);

    $senaste = ItemVersion::query()
        ->where('item_id', $item->id)
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->firstOrFail();

    expect($senaste->field_values)->toBe([$fält->id => 'Gammalt'])
        ->and(ItemFieldValue::query()->where('item_id', $item->id)->value('value_text'))->toBe('Nytt');

    versionStammer($ägare);
});

it('tar bort de äldsta versionerna för att ge plats när ägarens kvot är full', function () {
    [$ägare, $användare] = versionKonto(3);
    $container = versionContainer($ägare, $användare);

    // Itemet skapas av den inbjudna: den inbjudnas skrivning belastar henne,
    // versionen belastar ägaren (Riktning 3).
    [$skrivarKonto, $skrivare] = versionInbjuden($container);
    $item = versionItem($container, $skrivarKonto, $skrivare, ['name' => 'Motor']);

    versionSkriv($item, $skrivare, 'A');
    Carbon::setTestNow(now()->addMinutes(11));
    versionSkriv($item, $skrivare, 'B');

    expect(ItemVersion::query()->where('item_id', $item->id)->count())->toBe(2);

    // Ägaren har fyllt sin kvot: den nya versionen ryms inte förrän en
    // äldre har tagits bort.
    (new AdjustUsage)->handle($ägare->id, metadataDelta: 5000);
    $tak = UsageCounter::usedBytes($ägare->id);
    sättPlangräns('free', 'storage_bytes', $tak);

    Carbon::setTestNow(now()->addMinutes(11));
    versionSkriv($item, $skrivare, 'C');

    $namn = ItemVersion::query()
        ->where('item_id', $item->id)
        ->orderBy('created_at')
        ->orderBy('id')
        ->pluck('name')
        ->all();

    // Den äldsta (Motor) gav plats; A och ögonblicksbilden före C är kvar.
    expect($namn)->toBe(['A', 'B'])
        ->and(Item::query()->find($item->id)->name)->toBe('C');

    expect(Notification::query()->where('type', Notification::TYPE_VERSIONING_SKIPPED)->count())->toBe(0);
});

it('hoppar över versionen och noterar ägaren en gång per container och dygn när den inte ryms', function () {
    // Klockan frystas mitt på dagen: provet flyttar den elva minuter för att
    // pröva dygnsspärren, och en körning strax före midnatt bytte annars dygn
    // och fick en andra notis av misstag.
    Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));

    [$ägare, $användare] = versionKonto(3);
    $container = versionContainer($ägare, $användare);

    [$skrivarKonto, $skrivare] = versionInbjuden($container);
    $item = versionItem($container, $skrivarKonto, $skrivare, ['name' => 'Motor']);

    // Ägaren ligger på taket, och itemet har ingen tidigare version att ta
    // bort. Den inbjudnas egen kvot har plats.
    (new AdjustUsage)->handle($ägare->id, metadataDelta: 5000);
    sättPlangräns('free', 'storage_bytes', UsageCounter::usedBytes($ägare->id));

    // En sparad rad som slår PÅ mejl ska inte bita: typen är ingen mejltyp
    // ([[Notiser]] § notification och issue 412).
    NotificationPreference::query()->create([
        'user_id' => $användare->id,
        'type' => Notification::TYPE_VERSIONING_SKIPPED,
        'channel' => NotificationDelivery::CHANNEL_EMAIL,
        'enabled' => true,
        'digest' => false,
    ]);

    versionSkriv($item, $skrivare, 'Motorn');

    // Skrivningen gick igenom, men ingen version sparades.
    expect(Item::query()->find($item->id)->name)->toBe('Motorn')
        ->and(ItemVersion::query()->where('item_id', $item->id)->count())->toBe(0);

    $notis = Notification::query()
        ->where('type', Notification::TYPE_VERSIONING_SKIPPED)
        ->sole();

    expect($notis->user_id)->toBe($användare->id)
        ->and($notis->container_id)->toBe($container->id)
        ->and($notis->payload['container'])->toBe('Båten')
        ->and($notis->dedupe_key)->toContain($container->ulid);

    // Bara appkanalen: ingen mejlleverans skapades.
    expect(NotificationDelivery::query()->where('notification_id', $notis->id)->pluck('channel')->all())
        ->toBe([NotificationDelivery::CHANNEL_IN_APP]);

    // En andra skrivning samma dygn ger ingen andra notis.
    Carbon::setTestNow(now()->addMinutes(11));
    versionSkriv($item, $skrivare, 'Motorn igen');

    expect(Notification::query()->where('type', Notification::TYPE_VERSIONING_SKIPPED)->count())->toBe(1);
});

it('skapar ingen notis när kontot saknar en owner', function () {
    // Ett konto har en användare (Tonys regel 2026-11-18). Finns ingen
    // `owner` finns ingen mottagare — och då ska ingen rad skapas alls.
    $konto = Account::factory()->create(['versioning_max_versions' => 3]);
    $medlem = User::factory()->create();
    $konto->users()->attach($medlem, ['role' => 'member']);

    $container = versionContainer($konto, $medlem);

    app(NotifyVersioningSkipped::class)->handle($container);

    expect(Notification::query()->count())->toBe(0);
});

it('tar inte bort någon version under rättslig spärr, och sparar utan kvotprövning', function () {
    [$ägare, $användare] = versionKonto(3);
    $container = versionContainer($ägare, $användare);

    [$skrivarKonto, $skrivare] = versionInbjuden($container);
    $item = versionItem($container, $skrivarKonto, $skrivare, ['name' => 'Motor']);

    versionSkriv($item, $skrivare, 'A');
    Carbon::setTestNow(now()->addMinutes(11));
    versionSkriv($item, $skrivare, 'B');

    LegalHold::factory()->create(['account_id' => $ägare->id]);

    // Kvoten är full: utan spärr hade den äldsta versionen tagits bort.
    (new AdjustUsage)->handle($ägare->id, metadataDelta: 5000);
    sättPlangräns('free', 'storage_bytes', UsageCounter::usedBytes($ägare->id));

    Carbon::setTestNow(now()->addMinutes(11));
    versionSkriv($item, $skrivare, 'C');

    $namn = ItemVersion::query()
        ->where('item_id', $item->id)
        ->orderBy('created_at')
        ->orderBy('id')
        ->pluck('name')
        ->all();

    // Ingenting togs bort, och versionen sparades ändå.
    expect($namn)->toBe(['Motor', 'A', 'B'])
        ->and(Item::query()->find($item->id)->name)->toBe('C')
        ->and(Notification::query()->where('type', Notification::TYPE_VERSIONING_SKIPPED)->count())->toBe(0);
});

it('räknar versionerna i metadata_bytes i takt med avstämningen genom hela livscykeln', function () {
    [$ägare, $användare] = versionKonto(2);
    $container = versionContainer($ägare, $användare);
    $item = versionItem($container, $ägare, $användare);

    versionSkriv($item, $användare, 'A');
    versionStammer($ägare);

    Carbon::setTestNow(now()->addMinutes(11));
    $tagg = app(CreateTag::class)->handle($container, 'Röd', null, $användare);
    app(UpdateItem::class)->handle($item, $användare, true, collect([$tagg]));
    versionStammer($ägare);

    Carbon::setTestNow(now()->addMinutes(11));
    app(UpdateItemNotes::class)->handle($item, $användare, 'en anteckning');
    versionStammer($ägare);

    // Taket 2: den äldsta versionen försvinner och räknaren följer med.
    Carbon::setTestNow(now()->addMinutes(11));
    versionSkriv($item, $användare, 'B');
    versionStammer($ägare);

    expect(ItemVersion::query()->where('item_id', $item->id)->count())->toBe(2);
});
