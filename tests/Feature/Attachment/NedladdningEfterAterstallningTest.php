<?php

// rott-pa-basen: proven är gröna på basen med flit - radering och återställning rör inte leveransvägen, och issue 167 slår fast att "kan inte återskapas i koden" är ett giltigt svar. Filen är en regressionsvakt för den rapporterade vägen (radera, hämta, återställ, hämta), inte ett bevis för en kodändring.

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\StoredFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/*
 * Issue 167 · Leveransvägen efter en radera-och-återställ-runda.
 *
 * Issuen pekar ut två ställen där ett 404 kan uppstå just för en rad som
 * varit i papperskorgen: rutbindningen (`#[RouteKey('ulid')]` med
 * SoftDeletes ser en mjukraderad rad som frånvarande) och
 * AttachmentDownloadController (`Attachment::accessSubject()` väljer på
 * `container_id !== null`, och en rad som kommit tillbaka fel hade kunnat
 * välja fel gren).
 *
 * Provet bygger raden på det sätt användaren gör det — genom
 * uppladdningsrutten, inte med `Attachment::factory()` — och går sedan
 * hela vägen: hämtning, radering, hämtning igen, återställning, hämtning.
 * Raderingen och återställningen sker genom papperskorgens egna rutter
 * (`containers.items.attachments.destroy` och `containers.trash.restore`);
 * ingen kod i TrashAttachment eller RestoreContent rörs av det här provet,
 * och det är avsiktligt — frågan är vad raden SER UT SOM efteråt, inte vad
 * raderingen gör.
 *
 * `Storage::fake('files')` i beforeEach — inga bytes får hamna i den
 * riktiga storage/files/ när sviten körs, samma regel som NedladdningTest.
 */

beforeEach(function () {
    Storage::fake('files');
    config([
        'files.internal_redirect' => false,
        'files.url' => null,
    ]);
});

/**
 * Laddar upp en bilaga på ett item genom POST-rutten och ger tillbaka raden
 * så som StoreAttachment skrev den.
 *
 * Eget namn och inte `laddaUppGenomRutten()`: den hjälparen bor i
 * NedladdningTest.php och är global i hela sviten, så två definitioner hade
 * kolliderat. Hjälparna är identiska med flit — båda bygger raden genom
 * rutten, och det är förutsättningen proven vilar på.
 *
 * @return array{0: Attachment, 1: StoredFile}
 */
function laddaUppBilagaGenomRutten(
    Container $container,
    Item $item,
    Account $account,
    array $headers,
    string $filnamn,
    string $innehåll,
): array {
    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent($filnamn, $innehåll),
        'account' => $account->ulid,
    ], $headers)->assertCreated();

    $attachment = Attachment::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();

    return [$attachment, $attachment->storedFile];
}

it('en itembilaga kan hämtas efter radera och återställ', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $innehåll = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF";

    [$attachment] = laddaUppBilagaGenomRutten($container, $item, $account, $headers, 'manual.pdf', $innehåll);

    $före = actingAs($user)->get("/files/{$attachment->ulid}");
    $före->assertOk();
    expect($före->streamedContent())->toBe($innehåll);

    // Raderingen går genom containerns bilagerutt — samma väg användaren
    // klickar. Den rör aldrig /files/{ulid}.
    actingAs($user)
        ->delete("/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$attachment->ulid}")
        ->assertRedirect();

    // Bindningen ska inte se raden medan den ligger i papperskorgen.
    actingAs($user)->get("/files/{$attachment->ulid}")->assertNotFound();

    actingAs($user)
        ->post("/containers/{$container->ulid}/trash/restore", [
            'type' => 'attachment',
            'ulid' => $attachment->ulid,
        ])
        ->assertRedirect();

    // Raden efter återställningen: `deleted_at` nollad, och fortfarande en
    // itembilaga — `container_id` är NULL och `item_id` satt. Det är den
    // formen Attachment::accessSubject() väljer gren på, och en återställd
    // rad som fick `container_id` satt hade grindats mot containern i
    // stället för mot itemet.
    $rad = Attachment::withTrashed()->where('ulid', $attachment->ulid)->firstOrFail();

    expect($rad->trashed())->toBeFalse();
    expect($rad->deleted_at)->toBeNull();
    expect($rad->item_id)->not->toBeNull();
    expect($rad->container_id)->toBeNull();

    $efter = actingAs($user)->get("/files/{$attachment->ulid}");
    $efter->assertOk();
    expect($efter->streamedContent())->toBe($innehåll);
});

it('en mjukraderad bilaga ger 404 också när den raderats genom rutten', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    [$attachment] = laddaUppBilagaGenomRutten(
        $container, $item, $account, $headers,
        'manual.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF",
    );

    actingAs($user)
        ->delete("/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$attachment->ulid}")
        ->assertRedirect();

    // Andra hälften av "404 medan den är raderad": raden finns kvar i
    // databasen (mjukraderad), så 404:an kommer ur bindningen och inte ur en
    // saknad rad.
    expect(Attachment::withTrashed()->where('ulid', $attachment->ulid)->exists())->toBeTrue();

    actingAs($user)->get("/files/{$attachment->ulid}")->assertNotFound();
    actingAs($user)->get("/files/{$attachment->ulid}?variant=thumb")->assertNotFound();
});
