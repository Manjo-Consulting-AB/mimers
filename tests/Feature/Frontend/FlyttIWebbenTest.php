<?php

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\withoutVite;

/*
 * M27 · issue 243 — flytten och kopian i webben. Se
 * resources/js/components/ItemAttachmentSection.vue,
 * resources/js/pages/Containers/Documents.vue,
 * resources/js/pages/Containers/Items/Schedules/Show.vue och
 * App\Http\Controllers\ContainerDocumentController, samt [[ADR-0053 Flytt
 * och kopiering]] § 2 och § 6.
 *
 * Filen prövar de fem punkterna i "Klart när":
 *
 * 1. **Bilagans rad** bär *Move…* och *Copy…* i båda ytorna, och flytten står
 *    bakom `can.delete` (§ 2).
 * 2. **Grinden på itemets sida**: en `write`-mottagare får `can.delete`
 *    falskt och kan därför inte flytta, medan ägaren får sant.
 * 3. **Flytten går vägen genom webben**: en POST från itemets sida svarar
 *    302 tillbaka och bilagan ligger på målet (§ 2 och § 5).
 * 4. **Schemats sida** har *Move…* bakom `can.delete` och ritar felet under
 *    knappen (§ 6).
 * 5. **Nycklarna** ur Beslut 3 finns i `lang/en/ui.php`.
 *
 * Hjälparna har prefixet `flyttwebben` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem, och en container med ett item under kontot — samma
 * uppsättning som SchemasidansHandlingarTest::schemasidanKontext().
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function flyttwebbenKontext(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

/**
 * Ett item i containern, under samma konto.
 */
function flyttwebbenItem(Container $container, Account $konto, User $anvandare): Item
{
    return Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);
}

/**
 * En mottagare UTANFÖR ägarkontot, med en itemgrant på angiven nivå.
 */
function flyttwebbenMottagare(Container $container, Item $item, string $niva): User
{
    $mottagare = User::factory()->create(['locale' => 'sv_SE']);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $niva,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/**
 * En bilaga på itemet, med ett känt filnamn och en känd typ.
 */
function flyttwebbenBilaga(Item $item, Account $konto, User $uppladdare): Attachment
{
    $stored = StoredFile::factory()->create(['byte_size' => 1500]);

    return Attachment::factory()->create([
        'item_id' => $item->id,
        'stored_file_id' => $stored->id,
        'filename' => 'manual.pdf',
        'kind' => 'document',
        'uploaded_by_user_id' => $uppladdare->id,
        'billed_account_id' => $konto->id,
    ]);
}

function flyttwebbenItemUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}";
}

// --- bilagans rad ---------------------------------------------------------

/*
 * Klart när: `bilagans rad har move bakom can.delete och copy` —
 * ItemAttachmentSection.vue och Documents.vue innehåller `<ItemTargetPicker`,
 * `/move` och `/copy`, och flyttknappen bär `can.delete`.
 *
 * Källkodsprov: knapparna skärs ut ur filen först, så att nålen hör till just
 * den knappen och inte till raden intill.
 */
it('bilagans rad har move bakom can.delete och copy', function () {
    $komponent = File::get(resource_path('js/components/ItemAttachmentSection.vue'));

    preg_match('/<button[^>]*openPicker\(attachment, \'move\'[^>]*>/s', $komponent, $flytt);
    expect($flytt)->not->toBeEmpty('flyttknappen (Move…) saknas i ItemAttachmentSection.vue');
    expect($flytt[0])->toContain('v-if="can.delete"');

    preg_match('/<button[^>]*openPicker\(attachment, \'copy\'[^>]*>/s', $komponent, $kopia);
    expect($kopia)->not->toBeEmpty('kopieknappen (Copy…) saknas i ItemAttachmentSection.vue');
    // Kopian står på varje rad och har därför ingen egen grind (§ 2).
    expect($kopia[0])->not->toContain('v-if');

    expect($komponent)->toContain('<ItemTargetPicker')
        ->and($komponent)->toContain('/move')
        ->and($komponent)->toContain('/copy');

    $sidan = File::get(resource_path('js/pages/Containers/Documents.vue'));

    preg_match('/<button[^>]*openPicker\(row, \'move\'[^>]*>/s', $sidan, $flytt);
    expect($flytt)->not->toBeEmpty('flyttknappen (Move…) saknas i Documents.vue');
    expect($flytt[0])->toContain('v-if="row.can.delete"');

    preg_match('/<button[^>]*openPicker\(row, \'copy\'[^>]*>/s', $sidan, $kopia);
    expect($kopia)->not->toBeEmpty('kopieknappen (Copy…) saknas i Documents.vue');
    expect($kopia[0])->not->toContain('v-if');

    expect($sidan)->toContain('<ItemTargetPicker')
        ->and($sidan)->toContain('/move')
        ->and($sidan)->toContain('/copy');
});

// --- grinden på itemets sida ----------------------------------------------

/*
 * Klart när: `en write-mottagare får can.delete false på raden` — prop-prov
 * på itemets sida: ägaren får `can.delete` sant, en `write`-mottagare falskt
 * och `can.update` sant (laddern är kumulativ, [[ADR-0028 Åtkomst på
 * itemnivå]]). Flytten ritas efter samma flagga som raderingen.
 */
it('en write-mottagare får can.delete false på raden', function () {
    withoutVite();

    [$konto, $agaren, $container, $item] = flyttwebbenKontext();

    actingAs($agaren)->get(flyttwebbenItemUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('can.update', true)
            ->where('can.delete', true)
    );

    $skrivare = flyttwebbenMottagare($container, $item, 'write');

    actingAs($skrivare)->get(flyttwebbenItemUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('can.update', true)
            ->where('can.delete', false)
    );
});

// --- flytten genom webben -------------------------------------------------

/*
 * Klart när: `flyttar en bilaga från itemets sida` — en POST med `target` från
 * itemets sida svarar 302 tillbaka till itemet, och bilagan ligger på målet
 * (§ 2 och § 5). Flytten är inom containern, så kvoten prövas inte (§ 4).
 */
it('flyttar en bilaga från itemets sida', function () {
    [$konto, $anvandare, $container, $item] = flyttwebbenKontext();
    $mål = flyttwebbenItem($container, $konto, $anvandare);
    $bilaga = flyttwebbenBilaga($item, $konto, $anvandare);

    $svar = from(flyttwebbenItemUrl($container, $item))
        ->actingAs($anvandare)
        ->post(
            flyttwebbenItemUrl($container, $item)."/attachments/{$bilaga->ulid}/move",
            ['target' => $mål->ulid],
        );

    $svar->assertRedirect(flyttwebbenItemUrl($container, $item));
    $svar->assertSessionHas('status', 'attachment-moved');

    // `item_id` byts och ingenting annat — raden är densamma vid en flytt (§ 5).
    $efter = $bilaga->fresh();

    expect($efter->item_id)->toBe($mål->id);
    expect($efter->ulid)->toBe($bilaga->ulid);
});

// --- schemats sida --------------------------------------------------------

/*
 * Klart när: `schemats sida har move bakom can.delete` — Show.vue innehåller
 * `<ItemTargetPicker` och `/move`, flyttknappen bär `v-if="can.delete"`, och
 * felet ritas under knappen (`moveError`).
 */
it('schemats sida har move bakom can.delete', function () {
    $vy = File::get(resource_path('js/pages/Containers/Items/Schedules/Show.vue'));

    preg_match('/<button[^>]*@click="openPicker\(\$event\)"[^>]*>/s', $vy, $knapp);
    expect($knapp)->not->toBeEmpty('flyttknappen (Move…) saknas i Show.vue');
    expect($knapp[0])->toContain('v-if="can.delete"');

    expect($vy)->toContain('<ItemTargetPicker')
        ->and($vy)->toContain('/move')
        ->and($vy)->toContain("t('item.schedule.move_heading')");

    // Felet står under knappen, i en rad med `role="alert"`.
    expect($vy)->toContain('moveError')
        ->and($vy)->toContain('role="alert"');
});

// --- nycklarna (Beslut 3) -------------------------------------------------

/*
 * Klart när: `har nycklarna` — de sex nya nycklarna ur Beslut 3, plus de tre
 * statusmeningar som redan fanns där de andra står (issue 239 och 240).
 */
it('har nycklarna', function () {
    $nycklar = [
        'ui.item.attachment.move',
        'ui.item.attachment.copy',
        'ui.item.attachment.move_heading',
        'ui.item.attachment.copy_heading',
        'ui.item.schedule.move',
        'ui.item.schedule.move_heading',
        'ui.flash.attachment-moved',
        'ui.flash.attachment-copied',
        'ui.flash.schedule-moved',
    ];

    foreach ($nycklar as $nyckel) {
        expect(trim((string) Lang::get($nyckel, [], 'en')))
            ->not->toBe('', "{$nyckel} saknas i lang/en/ui.php");
    }
});
