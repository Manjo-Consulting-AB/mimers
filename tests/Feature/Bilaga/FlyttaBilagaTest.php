<?php

use App\Actions\Attachment\MoveAttachment;
use App\Actions\Usage\AdjustUsage;
use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\withoutVite;

/*
 * M27 · issue 242 — flytten av en bilaga. Se
 * App\Actions\Attachment\MoveAttachment,
 * App\Support\Account\ActingAccount och
 * App\Http\Controllers\AttachmentController::move(),
 * samt [[ADR-0053 Flytt och kopiering]] § 2, 3, 4, 5 och 7.
 *
 * Filen prövar de fyra gränserna:
 *
 * 1. **Inom samma container** byts `item_id` och ingenting annat — ägaren,
 *    räknaren, ULID:n och `reference_count` står stilla (§ 4).
 * 2. **Till en annan container** blir handlingens konto ägaren: målcontainerns
 *    ägarkonto när användaren är medlem i det, annars hennes personkonto
 *    (§ 3) — och bytena flyttar mellan räknarna.
 * 3. **Ägaren går aldrig tillbaka** bara för att den gamla ägaren åter når
 *    filen (§ 3).
 * 4. **Grindarna är `delete` på källan och `create` på målet** (§ 2), och
 *    kvotfelet ritas som ett fältfel på `attachment`.
 *
 * Hjälparna har prefixet `flytta` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 *
 * Räknarna sätts med App\Actions\Usage\AdjustUsage och inte med
 * UsageCounter::factory(), så testet läser samma tal som produktionen skriver
 * — och bilagehjälparna rör dem INTE, så varje test börjar från ett tal det
 * själv har satt.
 */

function flyttaKonto(string $typ = 'personal'): array
{
    $konto = Account::factory()->create(['type' => $typ]);
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare];
}

function flyttaItem(Container $container, Account $konto, User $anvandare): Item
{
    return Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);
}

/**
 * Ett konto, en medlem, en container och ett item under den.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function flyttaKontoMedItem(string $typ = 'personal'): array
{
    [$konto, $anvandare] = flyttaKonto($typ);
    $container = Container::factory()->for($konto, 'account')->create();
    $item = flyttaItem($container, $konto, $anvandare);

    return [$konto, $anvandare, $container, $item];
}

/**
 * En bilaga på itemet med känd storlek. Räknaren rörs INTE här — testet
 * sätter den själv, så utgångsläget är läsbart i varje test.
 */
function flyttaBilaga(Item $item, Account $konto, User $uppladdare, int $byteSize): Attachment
{
    $stored = StoredFile::factory()->create(['byte_size' => $byteSize]);

    return Attachment::factory()->create([
        'item_id' => $item->id,
        'stored_file_id' => $stored->id,
        'filename' => 'manual.pdf',
        'kind' => 'document',
        'uploaded_by_user_id' => $uppladdare->id,
        'billed_account_id' => $konto->id,
    ]);
}

function flyttaForbrukning(Account $konto): int
{
    return (int) UsageCounter::query()->where('account_id', $konto->id)->value('storage_bytes');
}

function flyttaUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}";
}

/**
 * POST:en, med `from()` satt så `back()` går att läsa i svaret. Målet står i
 * kroppen — det är ett item-ULID och kan ligga i en annan container.
 */
function flyttaPost(Container $container, Item $item, Attachment $bilaga, Item $mål, User $anvandare)
{
    return from(flyttaUrl($container, $item))
        ->actingAs($anvandare)
        ->post(
            flyttaUrl($container, $item)."/attachments/{$bilaga->ulid}/move",
            ['target' => $mål->ulid],
        );
}

it('flyttar inom containern utan att röra kvoten', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = flyttaKontoMedItem();
    $mål = flyttaItem($container, $konto, $anvandare);
    $bilaga = flyttaBilaga($item, $konto, $anvandare, 1500);
    (new AdjustUsage)->handle($konto->id, bytesDelta: 1500);

    $svar = flyttaPost($container, $item, $bilaga, $mål, $anvandare);

    $svar->assertRedirect(flyttaUrl($container, $item));
    $svar->assertSessionHas('status', 'attachment-moved');

    $efter = $bilaga->fresh();

    // `item_id` byts — och det är allt (§ 5). ULID:n står kvar, så en länk
    // till filen fungerar fortfarande.
    expect($efter->item_id)->toBe($mål->id);
    expect($efter->ulid)->toBe($bilaga->ulid);
    expect($efter->stored_file_id)->toBe($bilaga->stored_file_id);
    expect($efter->billed_account_id)->toBe($konto->id);

    // Ingen kvot prövas och ingen räknare ändras inom samma container (§ 4).
    expect(flyttaForbrukning($konto))->toBe(1500);
    expect(StoredFile::query()->whereKey($bilaga->stored_file_id)->value('reference_count'))->toBe(1);
});

it('flyttar till en container i ett annat konto där användaren är medlem', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = flyttaKontoMedItem('organisation');
    $bilaga = flyttaBilaga($item, $konto, $anvandare, 1500);
    (new AdjustUsage)->handle($konto->id, bytesDelta: 1500);

    [$annatKonto] = flyttaKonto('organisation');
    $annatKonto->users()->attach($anvandare, ['role' => 'member']);
    $annanContainer = Container::factory()->for($annatKonto, 'account')->create();
    $mål = flyttaItem($annanContainer, $annatKonto, $anvandare);

    flyttaPost($container, $item, $bilaga, $mål, $anvandare)
        ->assertSessionHas('status', 'attachment-moved');

    // Handlingens konto är målcontainerns ägarkonto, eftersom hon är medlem i
    // det (§ 3) — och bytena flyttar mellan räknarna i samma transaktion (§ 4).
    expect($bilaga->fresh()->billed_account_id)->toBe($annatKonto->id);
    expect($bilaga->fresh()->item_id)->toBe($mål->id);
    expect(flyttaForbrukning($konto))->toBe(0);
    expect(flyttaForbrukning($annatKonto))->toBe(1500);
});

it('flyttar till en främmande container och användarens personkonto blir ägare', function () {
    withoutVite();

    // Källkontot är en organisation, så användarens personkonto är ett ANNAT
    // konto än källans — annars vore provet inte ett prov på § 3.
    [$organisation, $anvandare, $container, $item] = flyttaKontoMedItem('organisation');
    $bilaga = flyttaBilaga($item, $organisation, $anvandare, 1500);
    (new AdjustUsage)->handle($organisation->id, bytesDelta: 1500);

    $personligt = Account::factory()->create(['type' => 'personal']);
    $personligt->users()->attach($anvandare, ['role' => 'owner']);

    // En främmande container där hon har en write-grant men inte är medlem.
    [$frammandeKonto, $frammandeAgare] = flyttaKonto('organisation');
    $frammande = Container::factory()->for($frammandeKonto, 'account')->create();
    $mål = flyttaItem($frammande, $frammandeKonto, $frammandeAgare);
    beviljaAccess($frammande, $anvandare, 'write', 'guest');

    flyttaPost($container, $item, $bilaga, $mål, $anvandare)
        ->assertSessionHas('status', 'attachment-moved');

    expect($bilaga->fresh()->billed_account_id)->toBe($personligt->id);
    expect(flyttaForbrukning($organisation))->toBe(0);
    expect(flyttaForbrukning($personligt))->toBe(1500);
    expect(flyttaForbrukning($frammandeKonto))->toBe(0);
});

it('flyttar tillbaka utan att ägaren återgår', function () {
    withoutVite();

    [$aKonto, $aAgare, $aContainer, $aItem] = flyttaKontoMedItem('organisation');
    $bilaga = flyttaBilaga($aItem, $aKonto, $aAgare, 1500);
    (new AdjustUsage)->handle($aKonto->id, bytesDelta: 1500);

    // B är en privatperson (fabrikens default), så hennes konto är både
    // kontot hon är medlem i och hennes personkonto.
    [$bKonto, $bAnvandare, $bContainer, $bItem] = flyttaKontoMedItem();

    // B når A:s container genom en grant på `delete` — hon är inte medlem i
    // A:s konto. `create` på målet ingår i `delete` i laddern.
    beviljaAccess($aContainer, $bAnvandare, 'delete', 'guest');

    flyttaPost($aContainer, $aItem, $bilaga, $bItem, $bAnvandare)
        ->assertSessionHas('status', 'attachment-moved');

    expect($bilaga->fresh()->billed_account_id)->toBe($bKonto->id);
    expect(flyttaForbrukning($aKonto))->toBe(0);
    expect(flyttaForbrukning($bKonto))->toBe(1500);

    // Tillbaka: handlingens konto är fortfarande B:s, för B är inte medlem i
    // A:s konto — ägaren ändras inte av att A åter når filen (§ 3).
    flyttaPost($bContainer, $bItem, $bilaga->fresh(), $aItem, $bAnvandare)
        ->assertSessionHas('status', 'attachment-moved');

    expect($bilaga->fresh()->item_id)->toBe($aItem->id);
    expect($bilaga->fresh()->billed_account_id)->toBe($bKonto->id);
    expect(flyttaForbrukning($aKonto))->toBe(0);
    expect(flyttaForbrukning($bKonto))->toBe(1500);
});

it('nekar när den nya ägarens kvot är full', function () {
    withoutVite();

    sättPlangräns('free', 'storage_bytes', 2000);

    [$aKonto, $aAnvandare, $aContainer, $aItem] = flyttaKontoMedItem('organisation');
    $bilaga = flyttaBilaga($aItem, $aKonto, $aAnvandare, 1500);
    (new AdjustUsage)->handle($aKonto->id, bytesDelta: 1500);

    [$bKonto, $bAnvandare, $bContainer, $bItem] = flyttaKontoMedItem();
    $bKonto->users()->attach($aAnvandare, ['role' => 'member']);
    (new AdjustUsage)->handle($bKonto->id, bytesDelta: 1000); // 1000 + 1500 > 2000

    $url = flyttaUrl($aContainer, $aItem)."/attachments/{$bilaga->ulid}/move";

    // JSON-vägen: kvotfelet blir ett fältfel på `attachment`, och svaret är
    // 422 med meningen ur `ui.error.quota.storage_exceeded` — samma kod och
    // samma data som `/api` svarar (ADR-0053 § 4, issue 60 § Beslut 5).
    $svar = actingAs($aAnvandare)->postJson($url, ['target' => $bItem->ulid]);

    $svar->assertStatus(422);
    expect($svar->json('errors.attachment.0'))->toBe(Lang::get('ui.error.quota.storage_exceeded', [
        'limit_bytes' => Number::fileSize(2000),
        'used_bytes' => Number::fileSize(1000),
        'file_bytes' => Number::fileSize(1500),
    ], 'en'));

    // Kastet rullade tillbaka allt: bilagan ligger kvar på källan, och båda
    // räknarna står orörda.
    expect($bilaga->fresh()->item_id)->toBe($aItem->id);
    expect($bilaga->fresh()->billed_account_id)->toBe($aKonto->id);
    expect(flyttaForbrukning($aKonto))->toBe(1500);
    expect(flyttaForbrukning($bKonto))->toBe(1000);

    // Och kastet bär själva KODEN, inte bara meningen: webben visar texten,
    // men kontraktet mot `/api` är `quota.storage_exceeded` (issue 247).
    $undantag = null;

    try {
        app(MoveAttachment::class)->handle($bilaga->fresh(), $bItem, $aAnvandare);
    } catch (ApiException $e) {
        $undantag = $e;
    }

    expect($undantag)->not->toBeNull();
    expect($undantag->errorCode())->toBe('quota.storage_exceeded');
});

it('nekar en flytt till samma item', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = flyttaKontoMedItem();
    $bilaga = flyttaBilaga($item, $konto, $anvandare, 1500);

    // Källan och målet får inte vara samma item (§ Beslut 2). Väljaren
    // kommer att utesluta det egna itemet (issue 243), men koden nås av en
    // handgjord begäran och av `/api` (issue 247) — och felet blir ett
    // fältfel på `attachment`, som kvotfelet.
    $svar = flyttaPost($container, $item, $bilaga, $item, $anvandare);

    $svar->assertSessionHasErrors('attachment');
    expect(session('errors')->get('attachment')[0])
        ->toBe(Lang::get('ui.error.attachment.same_item', [], 'en'));

    expect($bilaga->fresh()->item_id)->toBe($item->id);
});

it('svarar 404 för ett mål som inte finns eller är mjukraderat', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = flyttaKontoMedItem();
    $bilaga = flyttaBilaga($item, $konto, $anvandare, 1500);
    $url = flyttaUrl($container, $item)."/attachments/{$bilaga->ulid}/move";

    // En ULID som inte finns alls.
    actingAs($anvandare)
        ->post($url, ['target' => (string) Str::ulid()])
        ->assertNotFound();

    // Och ett mjukraderat item: samma svar, för SoftDeletes-scopet ligger i
    // uppslaget (TargetItemRequest) och inte i en egen kontroll (§ 8).
    $raderat = flyttaItem($container, $konto, $anvandare);
    $raderat->delete();

    actingAs($anvandare)
        ->post($url, ['target' => $raderat->ulid])
        ->assertNotFound();

    expect($bilaga->fresh()->item_id)->toBe($item->id);
});

it('kräver delete på källan', function () {
    withoutVite();

    [$konto, $agare, $container, $item] = flyttaKontoMedItem('organisation');
    $bilaga = flyttaBilaga($item, $konto, $agare, 1500);

    // En write-mottagare på KÄLLAN: `create` < `write` < `delete` i laddern,
    // så hon får lägga till men inte flytta bort (§ 2). Målet ligger i samma
    // container, där hon alltså också får skapa.
    [$mottagareKonto, $mottagare] = flyttaKonto();
    beviljaAccess($container, $mottagare, 'write', 'guest');
    $mål = flyttaItem($container, $konto, $agare);

    flyttaPost($container, $item, $bilaga, $mål, $mottagare)->assertForbidden();

    expect($bilaga->fresh()->item_id)->toBe($item->id);
    expect(flyttaForbrukning($mottagareKonto))->toBe(0);
});

it('kräver create på målet', function () {
    withoutVite();

    [$konto, $agare, $container, $item] = flyttaKontoMedItem();
    $bilaga = flyttaBilaga($item, $konto, $agare, 1500);

    // Ägaren får flytta bort (delete på källan är hennes), men målet ligger i
    // en främmande container där hon bara får LÄSA — `create` krävs (§ 2).
    [$frammandeKonto, $frammandeAgare] = flyttaKonto('organisation');
    $frammande = Container::factory()->for($frammandeKonto, 'account')->create();
    $mål = flyttaItem($frammande, $frammandeKonto, $frammandeAgare);
    beviljaAccess($frammande, $agare, 'read', 'guest');

    flyttaPost($container, $item, $bilaga, $mål, $agare)->assertForbidden();

    expect($bilaga->fresh()->item_id)->toBe($item->id);
});

it('tömmer källans omslag', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = flyttaKontoMedItem();
    $mål = flyttaItem($container, $konto, $anvandare);
    $bilaga = flyttaBilaga($item, $konto, $anvandare, 1500);

    $item->cover_attachment_id = $bilaga->id;
    $item->save();

    flyttaPost($container, $item, $bilaga, $mål, $anvandare)
        ->assertSessionHas('status', 'attachment-moved');

    // Pekaren töms, och itemets omslag faller tillbaka på ResolveItemCover
    // (§ 5). Målet får inget omslag av flytten.
    expect($item->fresh()->cover_attachment_id)->toBeNull();
    expect($mål->fresh()->cover_attachment_id)->toBeNull();
});

it('loggarna nämner inte den andra containern', function () {
    withoutVite();

    [$aKonto, $aAnvandare, $aContainer, $aItem] = flyttaKontoMedItem('organisation');
    $aContainer->update(['name' => 'Varvet Norr']);
    $bilaga = flyttaBilaga($aItem, $aKonto, $aAnvandare, 1500);

    [$bKonto, $bAnvandare, $bContainer, $bItem] = flyttaKontoMedItem('organisation');
    $bContainer->update(['name' => 'Kunden Syd']);
    $bKonto->users()->attach($aAnvandare, ['role' => 'member']);

    flyttaPost($aContainer, $aItem, $bilaga, $bItem, $aAnvandare)
        ->assertSessionHas('status', 'attachment-moved');

    // Två rader, en i vardera loggen (§ 7).
    $rader = AuditLog::query()
        ->where('action', AuditLog::ACTION_ATTACHMENT_MOVED)
        ->orderBy('id')
        ->get();

    expect($rader)->toHaveCount(2);

    $ut = $rader->firstWhere('container_id', $aContainer->id);
    $in = $rader->firstWhere('container_id', $bContainer->id);

    expect($ut)->not->toBeNull();
    expect($in)->not->toBeNull();

    // `direction` är det enda som skiljer raderna åt — ingen ULID och inget
    // namn för motparten (§ 7).
    expect($ut->meta)->toBe(['direction' => 'out']);
    expect($in->meta)->toBe(['direction' => 'in']);

    // Raden hör till sitt eget items container, och SUBJEKTET är bilagan —
    // aldrig motpartens item.
    expect($ut->item_id)->toBe($aItem->id);
    expect($in->item_id)->toBe($bItem->id);
    expect($ut->subject_id)->toBe($bilaga->ulid);
    expect($in->subject_id)->toBe($bilaga->ulid);

    foreach ([$ut, $in] as $rad) {
        $text = json_encode($rad->meta).$rad->subject_id;

        expect($text)->not->toContain($aContainer->ulid);
        expect($text)->not->toContain($bContainer->ulid);
        expect($text)->not->toContain('Varvet Norr');
        expect($text)->not->toContain('Kunden Syd');
    }
});
