<?php

use App\Actions\Account\DeleteAccount;
use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Attachment\StoreAttachment;
use App\Actions\Attachment\TrashAttachment;
use App\Actions\Item\DeleteItem;
use App\Actions\Trash\PurgeContainer;
use App\Actions\Trash\PurgeContent;
use App\Actions\Trash\RestoreContent;
use App\Console\EnforcesDowngrades;
use App\Console\PurgesExpiredStoredFiles;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ImageDerivative;
use App\Models\Item;
use App\Models\Plan;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * Issue 148 · Referensräkningen bevisas över hela livscykeln — proven R1–R8 i
 * [[Testplan filer]] § Del 1. Se [[Filer och lagring]] § stored_file och
 * § Radering samt [[ADR-0006 Innehållsadresserad lagring]].
 *
 * Syskonfilen ReferensrakningTest prövar actionen PurgeAttachment för sig. Den
 * här filen prövar invarianten genom de VÄGAR som leder fram till den: två
 * konton, två containers, papperskorgen, containergallringen, kontoraderingen,
 * nedgraderingen och den fysiska raderingen. Invariant 2 — att
 * `reference_count` är antalet ogallrade `attachment`-rader — är det R7 mäter
 * efter varje steg i en fast följd, och som de övriga proven mäter var för sig.
 *
 * Ingen produktionskod rörs. Visar ett prov ett fel märks det `->todo()` med
 * en rad om vad det visar, och felet beskrivs i PR-kroppen — det blir en egen
 * issue. Ingenting i den här filen "lagar" alltså det den hittar.
 *
 * Hjälparna har prefixet livscykelprov* för att inte krocka med de globala
 * hjälparna i andra Feature-filer (gallring* i Trash, radering* i Kvot,
 * referensrakning* i syskonfilen). Prefixet håller filen körbar för sig:
 * `php artisan test tests/Feature/Attachment` laddar bara den här katalogen,
 * och en hjälpare som bor i en annan fil vore då odefinierad.
 */

beforeEach(function () {
    Storage::fake('files');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en medlem, en container och ett item — den minsta kontext ett
 * prov behöver. Itemets författarkolumner sätts till medlemmen och kontot, så
 * raderna hänger ihop.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function livscykelprovKontext(string $containerNamn = 'Vindil'): array
{
    $konto = Account::factory()->create();
    $medlem = User::factory()->create();
    $konto->users()->attach($medlem, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create(['name' => $containerNamn]);
    $item = livscykelprovItem($container, $konto, $medlem);

    return [$konto, $medlem, $container, $item];
}

/**
 * Ett item direkt i containern, med $konto/$medlem som skapare.
 *
 * @param  array<string, mixed>  $attribut
 */
function livscykelprovItem(Container $container, Account $konto, User $medlem, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'created_by_user_id' => $medlem->id,
        'created_by_account_id' => $konto->id,
    ], $attribut));
}

/**
 * Laddar upp ett innehåll genom StoreAttachment — samma väg som API:et, så
 * hash, dedup och räknare sköts av domänkoden och inte av provet.
 */
function livscykelprovLaddaUpp(
    Item $item,
    User $medlem,
    Account $konto,
    string $innehåll,
    string $filnamn = 'manual.pdf',
): Attachment {
    return app(StoreAttachment::class)->handle(
        $item,
        UploadedFile::fake()->createWithContent($filnamn, $innehåll),
        $medlem,
        $konto,
    );
}

/**
 * Gallrar en container med hela sitt innehåll, som gallringsjobbet gör.
 */
function livscykelprovGallraContainer(Container $container): void
{
    (new PurgeContainer(new PurgeContent(new PurgeAttachment)))->handle($container);
}

/**
 * Kontots förbrukning, läst ur räknaren (issue 26a).
 */
function livscykelprovFörbrukning(int $accountId): int
{
    return (int) DB::table('usage_counter')->where('account_id', $accountId)->value('storage_bytes');
}

/**
 * Invariant 2 som ett påstående: för varje `stored_file` är
 * `reference_count` lika med antalet `attachment`-rader som pekar på den och
 * ännu inte gallrats. `withTrashed()` — en mjukraderad bilaga räknas, den kan
 * återställas ([[Testplan filer]] § Invarianterna, punkt 2).
 *
 * Provet läser om raden ur databasen varje varv: det är räknaren i tabellen
 * som prövas, inte en modellinstans som råkar ligga i minnet.
 */
function livscykelprovInvarianten(): void
{
    foreach (StoredFile::query()->get() as $storedFile) {
        $rader = Attachment::withTrashed()->where('stored_file_id', $storedFile->id)->count();

        expect($storedFile->refresh()->reference_count)->toBe($rader);
    }
}

/**
 * Sänker en gräns i en plans limits-JSON för det här provet. Samma form som
 * sättPlangräns() i Kvot-filerna, men med eget namn och egen definition så att
 * den här filen kan köras för sig.
 */
function livscykelprovSättPlangräns(string $kod, string $nyckel, int|bool $värde): void
{
    $plan = Plan::where('code', $kod)->firstOrFail();
    $limits = $plan->limits;
    $limits[$nyckel] = $värde;
    $plan->update(['limits' => $limits]);
}

it('R1 · samma bytes från två olika konton delar stored_file och båda räknar hela bytena', function () {
    [$aKonto, $aMedlem, , $aItem] = livscykelprovKontext('Vindil');
    [$bKonto, $bMedlem, , $bItem] = livscykelprovKontext('Mimer');

    $innehåll = 'samma victron-manual';

    $frånA = livscykelprovLaddaUpp($aItem, $aMedlem, $aKonto, $innehåll);
    $frånB = livscykelprovLaddaUpp($bItem, $bMedlem, $bKonto, $innehåll);

    // En uppsättning bytes i hela systemet, två bilagor — invariant 1.
    expect(StoredFile::count())->toBe(1);

    $storedFile = StoredFile::query()->firstOrFail();
    expect($frånA->stored_file_id)->toBe($storedFile->id);
    expect($frånB->stored_file_id)->toBe($storedFile->id);
    expect($storedFile->reference_count)->toBe(2);

    // Den andra uppladdningen tog incrementsvägen: bytena skrevs en gång.
    expect(count(Storage::disk('files')->allFiles()))->toBe(1);

    // Invariant 4: kvoten mäter vad användaren upplever, oberoende av dedupen.
    // Båda kontona räknar hela byte_size, inte en delad kostnad.
    expect(livscykelprovFörbrukning($aKonto->id))->toBe($storedFile->byte_size);
    expect(livscykelprovFörbrukning($bKonto->id))->toBe($storedFile->byte_size);

    livscykelprovInvarianten();
});

it('R2 · samma bytes i två containers delar stored_file, och den enas gallring lämnar räknaren på ett', function () {
    [$konto, $medlem, $containerA, $itemA] = livscykelprovKontext('Vindil');

    $containerB = Container::factory()->for($konto, 'account')->create(['name' => 'Mimer']);
    $itemB = livscykelprovItem($containerB, $konto, $medlem);

    $innehåll = 'samma garmin-handbok';

    livscykelprovLaddaUpp($itemA, $medlem, $konto, $innehåll);
    $kvar = livscykelprovLaddaUpp($itemB, $medlem, $konto, $innehåll);

    $storedFile = StoredFile::query()->firstOrFail();
    expect(StoredFile::count())->toBe(1);
    expect($storedFile->reference_count)->toBe(2);

    livscykelprovGallraContainer($containerA);

    // Containern är borta, men bytena lever: den andra containern refererar
    // dem fortfarande, så räknaren står på ett och ingen markering sätts.
    expect(Container::withTrashed()->whereKey($containerA->id)->exists())->toBeFalse();
    expect(StoredFile::query()->whereKey($storedFile->id)->exists())->toBeTrue();

    $storedFile->refresh();
    expect($storedFile->reference_count)->toBe(1);
    expect($storedFile->purge_after)->toBeNull();
    expect(Storage::disk('files')->exists($storedFile->storage_path))->toBeTrue();
    expect(Attachment::withTrashed()->whereKey($kvar->id)->exists())->toBeTrue();

    livscykelprovInvarianten();
});

it('R3 · återställning ur papperskorgen rör inte räknaren, varken för en bilaga eller för ett item med bilagor', function () {
    // En bilaga: mjukraderas och återställs.
    [$konto, $medlem, , $item] = livscykelprovKontext();
    $bilaga = livscykelprovLaddaUpp($item, $medlem, $konto, 'kvittot från varvet');
    $storedFile = $bilaga->storedFile;

    app(TrashAttachment::class)->handle($bilaga, $medlem);

    // Mjukraderingen rör inte räknaren — bilagan kan återställas, så bytena
    // får inte försvinna ([[Filer och lagring]] § Radering).
    expect($storedFile->refresh()->reference_count)->toBe(1);

    app(RestoreContent::class)->handle('attachment', $bilaga, $medlem);

    $storedFile->refresh();
    expect($storedFile->reference_count)->toBe(1);
    expect($storedFile->purge_after)->toBeNull();

    livscykelprovInvarianten();

    // Ett item med bilagor: hela grenen i papperskorgen samtidigt, och
    // återställningen tar tillbaka den utan att räknaren rörs. Itemet först —
    // en bilaga vars item ligger i papperskorgen kan inte återställas för sig
    // (RestoreContent § Beslut 8).
    [$kontoB, $medlemB, , $itemB] = livscykelprovKontext('Mimer');
    $bilagaB = livscykelprovLaddaUpp($itemB, $medlemB, $kontoB, 'protokollet');
    $storedFileB = $bilagaB->storedFile;

    app(DeleteItem::class)->handle($itemB, $medlemB);
    $bilagaB->delete();

    expect($storedFileB->refresh()->reference_count)->toBe(1);

    app(RestoreContent::class)->handle('item', $itemB, $medlemB);
    app(RestoreContent::class)->handle('attachment', $bilagaB, $medlemB);

    $storedFileB->refresh();
    expect($storedFileB->reference_count)->toBe(1);
    expect($storedFileB->purge_after)->toBeNull();
    expect(DB::table('attachment')->where('id', $bilagaB->id)->value('deleted_at'))->toBeNull();

    livscykelprovInvarianten();
});

it('R4 · containergallringen minskar räknaren en gång per bilaga, också för ett mjukraderat item', function () {
    [$konto, $medlem, $container, $levandeItem] = livscykelprovKontext();

    // Ett item som redan ligger i papperskorgen. Containerns deleted_at avgör
    // vad som gallras, inte innehållets — bilagan på det här itemet ska med.
    $raderatItem = livscykelprovItem($container, $konto, $medlem);
    $raderatItem->delete();

    // Två bilagor på SAMMA byten: räknaren ska minska två gånger, inte en.
    $storedFile = StoredFile::factory()->create(['reference_count' => 2, 'byte_size' => 2048]);

    $påLevande = Attachment::factory()->for($levandeItem, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'uploaded_by_user_id' => $medlem->id,
        'billed_account_id' => $konto->id,
    ]);

    $påRaderat = Attachment::factory()->for($raderatItem, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'uploaded_by_user_id' => $medlem->id,
        'billed_account_id' => $konto->id,
    ]);

    livscykelprovGallraContainer($container);

    expect(Attachment::withTrashed()->whereKey($påLevande->id)->exists())->toBeFalse();
    expect(Attachment::withTrashed()->whereKey($påRaderat->id)->exists())->toBeFalse();

    // Två minskningar: hade den mjukraderade bilagans item hoppats över hade
    // räknaren stått på ett och ingen markering satts.
    $storedFile->refresh();
    expect($storedFile->reference_count)->toBe(0);
    expect($storedFile->purge_after)->not->toBeNull();

    livscykelprovInvarianten();
});

it('R5 · kontoraderingen lämnar räknaren rätt för bytes som ett annat konto också refererar', function () {
    [$aKonto, $aMedlem, $aContainer, $aItem] = livscykelprovKontext('Varvet');
    [$bKonto, $bMedlem, $bContainer, $bItem] = livscykelprovKontext('Kunden');

    // Tre referenser till samma bytes: kontots egen bilaga, kontots bilaga i
    // kundens container (den flyttas av 143 och lever vidare) och kundens egen.
    $storedFile = StoredFile::factory()->create(['reference_count' => 3, 'byte_size' => 4096]);

    $egenBilaga = Attachment::factory()->for($aItem, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'uploaded_by_user_id' => $aMedlem->id,
        'billed_account_id' => $aKonto->id,
    ]);

    $kundensItem = livscykelprovItem($bContainer, $bKonto, $bMedlem);
    $kundensBilaga = Attachment::factory()->for($kundensItem, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'uploaded_by_user_id' => $bMedlem->id,
        'billed_account_id' => $bKonto->id,
    ]);

    $frammandeItem = livscykelprovItem($bContainer, $bKonto, $aMedlem, ['created_by_account_id' => $aKonto->id]);
    $frammandeBilaga = Attachment::factory()->for($frammandeItem, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'uploaded_by_user_id' => $aMedlem->id,
        'billed_account_id' => $aKonto->id,
    ]);

    (new DeleteAccount(new PurgeContainer(new PurgeContent(new PurgeAttachment))))->handle($aKonto);

    expect(Account::query()->whereKey($aKonto->id)->exists())->toBeFalse();
    expect(Container::withTrashed()->whereKey($aContainer->id)->exists())->toBeFalse();
    expect(Attachment::withTrashed()->whereKey($egenBilaga->id)->exists())->toBeFalse();

    // De två som står kvar refererar fortfarande bytena — räknaren är två, och
    // ingen markering för fysisk radering sätts. Flytten av betalaren rör
    // aldrig räknaren: bilagan byter konto, inte sinnebild.
    $storedFile->refresh();
    expect($storedFile->reference_count)->toBe(2);
    expect($storedFile->purge_after)->toBeNull();

    expect(Attachment::query()->whereKey($kundensBilaga->id)->exists())->toBeTrue();
    expect($frammandeBilaga->refresh()->billed_account_id)->toBe($bKonto->id);

    livscykelprovInvarianten();
});

it('R6 · nedgraderingens radering går genom papperskorgen och räknaren minskar först vid gallringen', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    livscykelprovSättPlangräns('free', 'storage_bytes', 100);

    $konto = Account::factory()->create(['status' => 'read_only', 'read_only_reason' => 'over_quota']);
    Subscription::factory()->for($konto, 'account')->create([
        'plan_id' => Plan::factory()->create()->id,
        'status' => 'past_due',
        'grace_until' => Carbon::parse('2026-09-01 12:00:00'),
    ]);

    $medlem = User::factory()->create();
    $container = Container::factory()->for($konto, 'account')->create();
    $item = livscykelprovItem($container, $konto, $medlem);

    $storedFile = StoredFile::factory()->create(['byte_size' => 800, 'reference_count' => 1]);
    $bilaga = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'uploaded_by_user_id' => $medlem->id,
        'billed_account_id' => $konto->id,
    ]);

    // Bilagorna är byggda med fabriker, ingen väg genom StoreAttachment, så
    // räknaren ställs för hand till bytenas summa.
    UsageCounter::factory()->create(['account_id' => $konto->id, 'storage_bytes' => 800, 'container_count' => 0]);

    (new EnforcesDowngrades(app(TrashAttachment::class)))->handle();

    // Steg 4 går genom TrashAttachment — samma väg som varje annan radering i
    // systemet. Bilagan hamnar i papperskorgen, och DÄR rörs räknaren inte:
    // den kan återställas, så bytena får inte försvinna.
    expect(DB::table('attachment')->where('id', $bilaga->id)->value('deleted_at'))->not->toBeNull();
    expect($storedFile->refresh()->reference_count)->toBe(1);
    expect($storedFile->purge_after)->toBeNull();

    // Räknaren minskar först när bilagan lämnar papperskorgen, 30 dagar
    // senare — samma steg som för varje annan gallring.
    (new PurgeAttachment)->handle($bilaga);

    $storedFile->refresh();
    expect($storedFile->reference_count)->toBe(0);
    expect($storedFile->purge_after)->not->toBeNull();

    livscykelprovInvarianten();
});

it('R7 · invarianten håller efter varje steg i en fast följd över två konton', function () {
    [$aKonto, $aMedlem, $aContainer, $aItem] = livscykelprovKontext('Vindil');
    [$bKonto, $bMedlem, , $bItem] = livscykelprovKontext('Mimer');

    // Följden är fast, inte slumpad: ett rött prov går att köra om och att
    // resonera om. Efter varje steg prövas invarianten för ALLA stored_file —
    // det är provet som fångar en väg ingen har tänkt på.

    // 1. A laddar upp X.
    $aX = livscykelprovLaddaUpp($aItem, $aMedlem, $aKonto, 'X-manualen');
    livscykelprovInvarianten();

    // 2. B laddar upp samma bytes — dedup, ingen andra stored_file.
    $bX = livscykelprovLaddaUpp($bItem, $bMedlem, $bKonto, 'X-manualen');
    expect(StoredFile::count())->toBe(1);
    livscykelprovInvarianten();

    // 3. B laddar upp Y — en andra uppsättning bytes.
    $bY = livscykelprovLaddaUpp($bItem, $bMedlem, $bKonto, 'Y-protokollet');
    expect(StoredFile::count())->toBe(2);
    livscykelprovInvarianten();

    // 4. A mjukraderar sin bilaga på X.
    app(TrashAttachment::class)->handle($aX, $aMedlem);
    livscykelprovInvarianten();

    // 5. A återställer den.
    app(RestoreContent::class)->handle('attachment', $aX, $aMedlem);
    livscykelprovInvarianten();

    // 6. A mjukraderar itemet — bilagan ligger kvar, itemet är i papperskorgen.
    app(DeleteItem::class)->handle($aItem, $aMedlem);
    livscykelprovInvarianten();

    // 7. B gallrar sin bilaga på X på riktigt.
    (new PurgeAttachment)->handle($bX);
    livscykelprovInvarianten();
    expect($aX->storedFile->refresh()->reference_count)->toBe(1);

    // 8. A gallrar containern: itemet, dess bilaga och allt annat i den.
    livscykelprovGallraContainer($aContainer);
    livscykelprovInvarianten();

    $x = StoredFile::query()->where('content_hash', $aX->storedFile->content_hash)->firstOrFail();
    expect($x->reference_count)->toBe(0);
    expect($x->purge_after)->not->toBeNull();

    // 9. Markeringen passerar — bytena raderas fysiskt. X har tappat båda sina
    // referenser (steg 7 och 8) och försvinner; Y har kvar sin och står kvar.
    Carbon::setTestNow($x->purge_after->copy()->addDay());
    (new PurgesExpiredStoredFiles)->handle();
    livscykelprovInvarianten();

    expect(StoredFile::query()->whereKey($x->id)->exists())->toBeFalse();
    expect(Storage::disk('files')->exists($x->storage_path))->toBeFalse();

    $y = StoredFile::query()->where('content_hash', $bY->storedFile->content_hash)->firstOrFail();
    expect($y->reference_count)->toBe(1);
    expect($y->purge_after)->toBeNull();
    expect(Storage::disk('files')->exists($y->storage_path))->toBeTrue();
});

it('R8 · när bytena raderas fysiskt försvinner miniatyrens rad och fil', function () {
    [$konto, $medlem, , $item] = livscykelprovKontext();

    // En riktig bild genom uppladdningen: kön är synkron i sviten, så
    // miniatyrjobbet kör i samma anrop och skriver både rader och filer.
    $bilaga = app(StoreAttachment::class)->handle(
        $item,
        UploadedFile::fake()->image('båten.jpg', 800, 600),
        $medlem,
        $konto,
    );

    $storedFile = $bilaga->storedFile;
    $derivat = ImageDerivative::where('stored_file_id', $storedFile->id)->get();

    expect($derivat->count())->toBeGreaterThan(0);

    foreach ($derivat as $rad) {
        expect(Storage::disk('files')->exists($rad->storage_path))->toBeTrue();
    }

    // Bilagan gallras: räknaren når noll och bytena märks för radering.
    (new PurgeAttachment)->handle($bilaga);

    $storedFile->refresh();
    expect($storedFile->reference_count)->toBe(0);
    expect($storedFile->purge_after)->not->toBeNull();

    // Markeringen passerar. Derivaten följer originalet: deras rader och
    // filer försvinner i samma körning, utan en egen väg (invariant 5).
    Carbon::setTestNow($storedFile->purge_after->copy()->addDay());
    (new PurgesExpiredStoredFiles)->handle();

    expect(StoredFile::query()->whereKey($storedFile->id)->exists())->toBeFalse();
    expect(ImageDerivative::where('stored_file_id', $storedFile->id)->count())->toBe(0);
    expect(Storage::disk('files')->exists($storedFile->storage_path))->toBeFalse();

    foreach ($derivat as $rad) {
        expect(Storage::disk('files')->exists($rad->storage_path))->toBeFalse();
    }
});
