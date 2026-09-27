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
 * Två saker bevisas, och de hänger ihop:
 *
 * - **Papperskorgen visar ingen containerbilaga.** Uppslaget i ListTrash
 *   joinar mot `item`, och en containerbilaga har `item_id = NULL` — den
 *   matchar aldrig och kan därför varken listas eller återställas.
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
 * FYND — en containerbilaga kan inte mjukraderas.
 *
 * `TrashAttachment::handle()` slår upp bilagans item med
 * `Item::withTrashed()->findOrFail($rad->item_id)`. För en containerbilaga är
 * `item_id` NULL, och anropet kastar ModelNotFoundException i stället för att
 * mjukradera.
 *
 * Vägen dit finns i dag i två ytor utanför den här issuen omfång:
 * `App\Console\EnforcesDowngrades` väljer rader ur `attachment` på
 * `billed_account_id` (via App\Actions\Plan\ReadPlanUsage) och skickar dem
 * till TrashAttachment, och `Settings\StorageController::destroy` gör samma
 * sak för det användaren kryssar för. Ingen av dem kan träffa en
 * containerbilaga förrän § 159 ger ytan som skapar en — därför är det här ett
 * `->todo()` och inte en röd svit.
 *
 * Provet är skrivet och inte lagat: [[Testplan filer]] § Ingressen säger att
 * ett fel ett prov hittar inte lagas i samma PR, och båda filerna ligger
 * utanför omfångsrutan. Se `## Frågor och antaganden` i PR:en.
 */
it('kan mjukradera en containerbilaga genom TrashAttachment', function () {
    [$konto, $medlem, , $bild] = bildpapperskorgKontext();

    app(TrashAttachment::class)->handle($bild, $medlem);

    expect($bild->refresh()->deleted_at)->not->toBeNull();
})->todo('TrashAttachment::handle() gör findOrFail($rad->item_id) och item_id är NULL för en containerbilaga — ModelNotFoundException. Vägen dit: EnforcesDowngrades och StorageController::destroy, båda utanför omfångsrutan.');

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
