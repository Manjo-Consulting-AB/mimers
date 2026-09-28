<?php

use App\Actions\Attachment\TrashAttachment;
use App\Actions\Trash\ListTrash;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/*
 * Issue 158 · Containerns bild och papperskorgen — se [[ADR-0047 Containerns
 * bild]] § Beslut, fjärde stycket: en borttagen bild går INTE till
 * papperskorgen, den rensas direkt. Papperskorgen tar emot det man kan vilja
 * ångra, inte egenskaper ([[ADR-0008 Soft delete och papperskorg]]
 * § Uppföljning 2026-09-26).
 *
 * Tre saker bevisas, och de hänger ihop:
 *
 * - **Papperskorgen visar ingen containerbilaga.** Uppslaget i ListTrash
 *   joinar mot `item`, och en containerbilaga har `item_id = NULL` — den
 *   matchar aldrig och kan därför varken listas eller återställas.
 * - **TrashAttachment rensar en containerbilaga direkt** i stället för att
 *   mjukradera den, och släpper containerns pekare. Det är grenen som gör att
 *   nedgraderingen och lagringsrensningen kan välja en bild alls — bytena
 *   räknas mot kontots kvot (ADR-0047 § Beslut), och en bild deras urval inte
 *   kunde röra vore en del av kvoten ingen kunde frigöra.
 * - **En container som gallras tar sin bild med sig**, genom PurgeContainer.
 *   `attachment.container_id` är ON DELETE RESTRICT, så utan den raden hade
 *   hela den nattliga gallringen fallit på containern.
 *
 * Hjälparna har prefixet `bildpapperskorg`. Syskonfilerna i samma katalog
 * prövar papperskorgen för item, kategori och tagg.
 */

beforeEach(function () {
    Queue::fake();
});

/**
 * Ett konto, en medlem och en container med en bild — satt direkt på raden,
 * eftersom den här filen handlar om vad som händer EFTERÅT.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Attachment}
 */
function bildpapperskorgKontext(): array
{
    [$konto, $medlem] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    $stored = StoredFile::factory()->create(['reference_count' => 1]);
    $bild = Attachment::factory()->create([
        'item_id' => null,
        'container_id' => $container->id,
        'stored_file_id' => $stored->id,
        'filename' => 'båten.png',
        'kind' => 'image',
        'uploaded_by_user_id' => $medlem->id,
        'billed_account_id' => $konto->id,
    ]);

    $container->cover_attachment_id = $bild->id;
    $container->save();

    return [$konto, $medlem, $container, $bild];
}

/*
 * Klart när: papperskorgen visar ingen containerbilaga.
 *
 * Provet mjukraderar bilden med flit — ett tillstånd varken
 * SetContainerCover eller RemoveContainerCover kan skapa, eftersom båda
 * RENSAR — och visar att listan ändå inte bär den. Det är joinen mot `item`
 * som håller den borta, inte att raden aldrig kan hamna i det tillståndet.
 */
it('visar ingen containerbilaga i papperskorgen', function () {
    [$konto, $medlem, $container, $bild] = bildpapperskorgKontext();

    $bilaga = Attachment::factory()->for(Item::factory()->for($container, 'container'), 'item')->create([
        'uploaded_by_user_id' => $medlem->id,
        'billed_account_id' => $konto->id,
        'deleted_at' => now(),
    ]);

    $bild->delete();

    $lista = app(ListTrash::class)->handle($medlem, $container);

    $ulids = collect($lista['entries'])->pluck('ulid')->all();

    expect($ulids)->toContain($bilaga->ulid);
    expect($ulids)->not->toContain($bild->ulid);
    expect($lista['subjects'])->not->toHaveKey($bild->ulid);
});

/*
 * Samma sak över API-ytan, som är den väg en klient faktiskt tar.
 */
it('svarar utan containerbilagan på /api', function () {
    [$konto, $medlem, $container, $bild] = bildpapperskorgKontext();

    $bild->delete();

    actingAs($medlem);

    getJson("/api/containers/{$container->ulid}/trash")
        ->assertOk()
        ->assertJsonMissing(['ulid' => $bild->ulid]);
});

/*
 * Klart när: en containerbilaga som väljs för borttagning rensas direkt,
 * pekaren nollställs, och den syns inte i papperskorgen.
 *
 * Vägen dit i drift är två ytor: lagringsrensningen (AccountStorageController
 * och Settings\StorageController, båda genom TrashAttachment) och
 * nedgraderingen (App\Console\EnforcesDowngrades). Bytena räknas mot kontots
 * kvot (ADR-0047 § Beslut), så en bild deras urval inte kunde röra vore en
 * del av kvoten ingen kunde frigöra — därför samma utfall som
 * RemoveContainerCover: raden försvinner på riktigt och pekaren släpps.
 *
 * Att raden INTE får någon `deleted_at` är hela skillnaden mot en itembilaga,
 * och den syns i samma prov: `withTrashed()` hittar den inte, och
 * papperskorgens lista bär den inte. Pekaren nollställs av actionen och inte
 * av `ON DELETE SET NULL` — den räddar bara en pekare som råkar vara satt,
 * och provet faller om nollställningen glöms.
 */
it('rensar en containerbilaga direkt, nollställer pekaren och visar den inte i papperskorgen', function () {
    [, $medlem, $container, $bild] = bildpapperskorgKontext();

    $fil = $bild->storedFile;

    expect(app(TrashAttachment::class)->handle($bild, $medlem))->toBeTrue();

    expect(Attachment::withTrashed()->whereKey($bild->id)->exists())->toBeFalse();
    expect($container->refresh()->cover_attachment_id)->toBeNull();
    expect($fil->refresh()->reference_count)->toBe(0);

    $lista = app(ListTrash::class)->handle($medlem, $container);

    expect(collect($lista['entries'])->pluck('ulid')->all())->not->toContain($bild->ulid);
});

/*
 * Klart när: en container som rensas tar sin bild med sig.
 *
 * Genom den nattliga körningen — samma väg som i drift — så att både
 * PurgeContainer och dess plats i PurgesExpiredTrash prövas. `forceDelete()`
 * på containern faller på `attachment.container_id` om bilden ligger kvar,
 * så ett grönt prov är beviset att raden finns.
 */
it('tar med sig bilden när containern gallras ur papperskorgen', function () {
    [, , $container, $bild] = bildpapperskorgKontext();

    $fil = $bild->storedFile;

    $container->delete();
    $container->forceFill(['deleted_at' => now()->subDays(31)])->save();

    $resultat = gallringKör();

    expect($resultat['container'])->toBe(1);
    expect(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse();
    expect(Attachment::withTrashed()->whereKey($bild->id)->exists())->toBeFalse();
    expect($fil->refresh()->reference_count)->toBe(0);
});
