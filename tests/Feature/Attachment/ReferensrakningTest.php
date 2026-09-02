<?php

use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Attachment\StoreAttachment;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * Issue 17a · Referensräkningen och markeringen för gallring. Se
 * App\Actions\Attachment\PurgeAttachment, [[Filer och lagring]] § Radering
 * och [[ADR-0008 Soft delete och papperskorg]].
 *
 * 16b mjukraderade bilagan utan att röra räknaren. Den här issuen bygger det
 * som händer när bilagan LÄMNAR papperskorgen: raden försvinner på riktigt,
 * stored_file.reference_count minskas, och när räknaren når noll markeras
 * bytena för fysisk radering — tidigast 30 dagar senare (kolumnen
 * purge_after). Själva raderingen av bytena är 17b; den här issuen tar aldrig
 * bort en fil från disken.
 *
 * Ingen API-yta — actionen anropas direkt, precis som
 * App\Console\PrunesExpiredMagicLinkTokens testas i GallraMagicLinkTokensTest.
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 */

beforeEach(function () {
    Storage::fake('files');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En stored_file med `reference_count` satt för hand. Fabrikerna räknar
 * inte — räknaren är domänkodens ansvar (StoreAttachment/PurgeAttachment),
 * så antalet bilagor måste stämmas av mot räknaren i testet.
 */
function referensrakningStoredFil(int $referenceCount): StoredFile
{
    return StoredFile::factory()->create(['reference_count' => $referenceCount]);
}

/**
 * En bilaga som pekar på `$storedFile`.
 */
function referensrakningBilaga(StoredFile $storedFile): Attachment
{
    return Attachment::factory()->create(['stored_file_id' => $storedFile->id]);
}

it('gallring tar bort attachment-raden på riktigt', function () {
    $storedFile = referensrakningStoredFil(1);
    $bilaga = referensrakningBilaga($storedFile);
    // Bilagan ligger i papperskorgen — så skulle 20b skicka den.
    $bilaga->delete();

    (new PurgeAttachment)->handle($bilaga);

    // Borta på riktigt, inte mjukraderad: inte ens med withTrashed går den
    // att hitta igen (Beslut 2 steg 1).
    expect(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeFalse();
    expect(DB::table('attachment')->where('id', $bilaga->id)->exists())->toBeFalse();
});

it('gallring minskar reference_count med ett', function () {
    $storedFile = referensrakningStoredFil(2);
    $bilaga = referensrakningBilaga($storedFile);
    referensrakningBilaga($storedFile);

    (new PurgeAttachment)->handle($bilaga);

    expect($storedFile->refresh()->reference_count)->toBe(1);
    // Den andra bilagan ligger kvar och pekar fortfarande på bytena.
    expect(Attachment::withTrashed()->where('stored_file_id', $storedFile->id)->count())->toBe(1);
});

it('purge_after sätts när räknaren når noll', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');

    $storedFile = referensrakningStoredFil(1);
    $bilaga = referensrakningBilaga($storedFile);

    (new PurgeAttachment)->handle($bilaga);

    $storedFile->refresh();
    expect($storedFile->reference_count)->toBe(0);
    expect($storedFile->purge_after)->not->toBeNull();
    // 30 dagar fram, inte ett ögonblick tidigare (Beslut 1).
    expect($storedFile->purge_after->toDateTimeString())->toBe('2026-08-31 12:00:00');
});

it('purge_after är null så länge någon annan refererar bytena', function () {
    $storedFile = referensrakningStoredFil(2);
    $bilaga = referensrakningBilaga($storedFile);
    referensrakningBilaga($storedFile);

    (new PurgeAttachment)->handle($bilaga);

    $storedFile->refresh();
    expect($storedFile->reference_count)->toBe(1);
    expect($storedFile->purge_after)->toBeNull();
});

it('bytena ligger kvar efter gallring', function () {
    $storedFile = referensrakningStoredFil(1);
    Storage::disk('files')->put($storedFile->storage_path, 'kvittot som ska ligga kvar');
    $bilaga = referensrakningBilaga($storedFile);

    (new PurgeAttachment)->handle($bilaga);

    // Ingen fil har tagits bort från disken (Beslut 4) — det är 17b som tar
    // hand om bytena, tidigast när purge_after har passerats.
    expect(Storage::disk('files')->exists($storedFile->storage_path))->toBeTrue();
    expect(Storage::disk('files')->get($storedFile->storage_path))->toBe('kvittot som ska ligga kvar');
});

it('stored_file-raden ligger kvar efter gallring', function () {
    $storedFile = referensrakningStoredFil(1);
    $bilaga = referensrakningBilaga($storedFile);

    (new PurgeAttachment)->handle($bilaga);

    // Raden tas bort av 17b, inte av den här actionen (Beslut 4).
    expect(StoredFile::query()->whereKey($storedFile->id)->exists())->toBeTrue();
    expect($storedFile->refresh()->reference_count)->toBe(0);
});

it('en ny uppladdning av samma innehåll nollställer purge_after', function () {
    $konto = Account::factory()->create();
    $användare = User::factory()->create();
    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $användare->id,
        'created_by_account_id' => $konto->id,
    ]);
    $innehåll = 'samma victron-manual';

    $första = (new StoreAttachment)->handle(
        $item,
        UploadedFile::fake()->createWithContent('manual.pdf', $innehåll),
        $användare,
        $konto,
    );
    $storedFile = $första->storedFile;

    // Första bilagan gallras: räknaren når noll och purge_after sätts.
    (new PurgeAttachment)->handle($första);
    expect($storedFile->refresh()->reference_count)->toBe(0);
    expect($storedFile->purge_after)->not->toBeNull();

    // Samma innehåll laddas upp igen (Beslut 5). Utan nollställningen skulle
    // den gamla markeringen överleva och 17b radera bytena under fötterna på
    // den nya bilagan — en fil som försvinner utan att någon raderat den.
    (new StoreAttachment)->handle(
        $item,
        UploadedFile::fake()->createWithContent('manual.pdf', $innehåll),
        $användare,
        $konto,
    );

    $storedFile->refresh();
    expect($storedFile->reference_count)->toBe(1);
    expect($storedFile->purge_after)->toBeNull();
    expect(StoredFile::count())->toBe(1);
    // Den gamla bilagan gallrades bort på riktigt; bara den nya finns kvar.
    expect(Attachment::count())->toBe(1);
    expect(Storage::disk('files')->exists($storedFile->storage_path))->toBeTrue();
});

it('räknaren går aldrig under noll', function () {
    // stored_file står redan på noll — ett förväntat läge, inte ett fel
    // (Beslut 3). Minskningen villkoras på reference_count > 0.
    $storedFile = referensrakningStoredFil(0);
    $bilaga = referensrakningBilaga($storedFile);

    (new PurgeAttachment)->handle($bilaga);

    expect($storedFile->refresh()->reference_count)->toBe(0);
    expect($storedFile->reference_count)->not->toBe(-1);
});

it('ett dubbelt anrop minskar räknaren en gång', function () {
    $storedFile = referensrakningStoredFil(1);
    $bilaga = referensrakningBilaga($storedFile);

    $gallra = new PurgeAttachment;
    $gallra->handle($bilaga);
    // Andra anropet med samma instans: raden finns inte längre och räknaren
    // får inte minskas igen (Beslut 7).
    $gallra->handle($bilaga);

    expect($storedFile->refresh()->reference_count)->toBe(0);
    expect(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeFalse();
});

it('itemet rörs inte av att en bilaga gallras', function () {
    $item = Item::factory()->create();
    $storedFile = referensrakningStoredFil(1);
    $bilaga = Attachment::factory()->for($item, 'item')->create(['stored_file_id' => $storedFile->id]);

    (new PurgeAttachment)->handle($bilaga);

    // Ingen kaskad till itemet (Beslut 6) — den motsatta riktningen, att
    // gallra ett item tar med sig bilagorna, är 20b:s beslut.
    expect(Item::query()->whereKey($item->id)->exists())->toBeTrue();
    expect(DB::table('item')->where('id', $item->id)->value('deleted_at'))->toBeNull();
});

it('en gallring som avbryts lämnar ingenting halvt', function () {
    $storedFile = referensrakningStoredFil(1);
    $bilaga = referensrakningBilaga($storedFile);
    Storage::disk('files')->put($storedFile->storage_path, 'bytena');

    try {
        DB::transaction(function () use ($bilaga): void {
            (new PurgeAttachment)->handle($bilaga);

            // Simulerar att den som anropar actionen (20b, 20c, issue 28)
            // avbryts efter gallringen: raden och räknaren hänger ihop och
            // måste rullas tillbaka tillsammans (Beslut 2).
            throw new RuntimeException('avbryt efter gallringen');
        });
    } catch (RuntimeException) {
        // Förväntat — transaktionen avbröts.
    }

    expect(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeTrue();
    expect(DB::table('attachment')->where('id', $bilaga->id)->value('deleted_at'))->toBeNull();
    expect($storedFile->refresh()->reference_count)->toBe(1);
    expect($storedFile->purge_after)->toBeNull();
    expect(Storage::disk('files')->exists($storedFile->storage_path))->toBeTrue();
});
