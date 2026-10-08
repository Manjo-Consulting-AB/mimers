<?php

use App\Actions\Inbox\ResolveInbox;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\from;

/*
 * M28 · issue 775 — raderingen i inboxen. Se [[ADR-0054 Inboxen]] § 2 och § 6,
 * App\Http\Controllers\InboxController, App\Actions\Schedule\DeleteSchedule och
 * App\Actions\Attachment\TrashAttachment.
 *
 * Filen prövar punkterna i "Klart när":
 *
 * 1. **En uppgift i inboxen raderas** — mjukt, genom `DeleteSchedule`, och
 *    svaret är `back()` med `schedule-deleted`.
 * 2. **En fil hamnar i papperskorgen** — `deleted_at` satt, raden kvar, genom
 *    `TrashAttachment`.
 * 3. **Flera filer raderas på en gång** — samma kropp som bearbetningen.
 * 4. **Allt eller inget** — en fil utanför inboxen i listan nekar hela satsen
 *    med 422 `attachment.not_in_inbox`, och ingen fil har rörts.
 * 5. **En uppgift utanför inboxen ger 422** — `schedule.not_in_inbox`.
 * 6. **En annan användares inboxfil nekas** — 422 `attachment.not_in_inbox`,
 *    filen orörd.
 * 7. **Sidan har *Delete* och *Delete selected*** — nycklarna och rutterna
 *    finns i `Inbox/Index.vue`.
 *
 * Den sista punkten — hela testsviten är grön — är CI:s uppgift och ingen egen
 * rad.
 *
 * Hjälparna har prefixet `radera` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs. Storage::fake('files') i beforeEach: en
 * radering får inte röra den riktiga storage/files/.
 */

beforeEach(function () {
    Storage::fake('files');
});

/**
 * En person, hennes personkonto och hennes inbox-item — grunden varje prov
 * bygger på. Inboxen skapas med flit HÄR, så proven om nekanden börjar från en
 * användare som faktiskt har en egen inbox att jämföra med.
 *
 * @return array{0: User, 1: Account, 2: Item}
 */
function raderaKontext(): array
{
    $person = User::factory()->create();
    $konto = Account::factory()->create(['type' => 'personal']);
    $konto->users()->attach($person, ['role' => 'owner']);

    return [$person, $konto, app(ResolveInbox::class)->handle($person)];
}

/**
 * Ett riktigt item i ett eget skapat konto — ett mål som ligger UTANFÖR
 * inboxen. Fabriken går förbi containertaket med flit, så provet inte binds av
 * gratisplanens enda container.
 */
function raderaItem(Account $konto, User $person, string $namn = 'Motorn'): Item
{
    $container = Container::factory()->for($konto, 'account')->create();

    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $person->id,
        'created_by_account_id' => $konto->id,
    ]);
}

/**
 * En uppgift på itemet — i inboxen när itemet är inbox-itemet, annars utanför.
 */
function raderaUppgift(Item $item, string $titel = 'Ring banken'): Schedule
{
    return Schedule::factory()->for($item, 'item')->create(['title' => $titel]);
}

/**
 * En bilaga på itemet, med en känd storlek.
 */
function raderaBilaga(Item $item, Account $agare, User $uppladdare, int $byteSize = 1500): Attachment
{
    $stored = StoredFile::factory()->create(['byte_size' => $byteSize]);

    return Attachment::factory()->create([
        'item_id' => $item->id,
        'stored_file_id' => $stored->id,
        'filename' => 'kvitto.pdf',
        'kind' => 'document',
        'uploaded_by_user_id' => $uppladdare->id,
        'billed_account_id' => $agare->id,
    ]);
}

/**
 * Raden med den ULID:n, läst förbi mjukraderingsscopet — en radering ska kunna
 * skiljas från en rad som aldrig fanns.
 */
function raderaRaden(string $klass, int $id): mixed
{
    return $klass::withTrashed()->find($id);
}

// --- en uppgift -------------------------------------------------------------

/*
 * Klart när: `en uppgift i inboxen raderas` — en DELETE svarar 302 tillbaka
 * till `/inbox`, och uppgiften är mjukraderad genom App\Actions\Schedule\
 * DeleteSchedule: `deleted_at` satt, raden kvar (samma väg som uppgiftens sida).
 */
it('en uppgift i inboxen raderas', function () {
    [$person, $konto, $inbox] = raderaKontext();
    $uppgift = raderaUppgift($inbox);

    $svar = from('/inbox')
        ->actingAs($person)
        ->delete("/inbox/tasks/{$uppgift->ulid}");

    $svar->assertRedirect('/inbox');
    $svar->assertSessionHas('status', 'schedule-deleted');

    $efter = raderaRaden(Schedule::class, $uppgift->id);

    expect($efter)->not->toBeNull('Raderingen skulle vara mjuk — raden finns kvar.');
    expect($efter->deleted_at)->not->toBeNull();
});

// --- en fil ----------------------------------------------------------------

/*
 * Klart när: `en fil i inboxen hamnar i papperskorgen` — `deleted_at` satt och
 * raden INTE borta. Filen går genom App\Actions\Attachment\TrashAttachment, så
 * svaret bär den inbox-egna meningen och inte `attachment-deleted`, vars löfte
 * om återställning inte gäller här (ADR-0054 · Beslut 4).
 */
it('en fil i inboxen hamnar i papperskorgen', function () {
    [$person, $konto, $inbox] = raderaKontext();
    $fil = raderaBilaga($inbox, $konto, $person);

    $svar = from('/inbox')
        ->actingAs($person)
        ->delete("/inbox/attachments/{$fil->ulid}");

    $svar->assertRedirect('/inbox');
    $svar->assertSessionHas('status', 'inbox-attachment-deleted');

    $efter = raderaRaden(Attachment::class, $fil->id);

    expect($efter)->not->toBeNull('Raderingen skulle vara mjuk — raden finns kvar.');
    expect($efter->deleted_at)->not->toBeNull();
});

// --- flera filer ------------------------------------------------------------

/*
 * Klart när: `flera filer raderas på en gång` — samma kropp som
 * `inbox.attachments.process` tar emot (`attachments[]`), och båda raderna
 * hamnar i papperskorgen.
 */
it('flera filer raderas på en gång', function () {
    [$person, $konto, $inbox] = raderaKontext();
    $första = raderaBilaga($inbox, $konto, $person, 100);
    $andra = raderaBilaga($inbox, $konto, $person, 200);

    $svar = from('/inbox')
        ->actingAs($person)
        ->post('/inbox/attachments/delete', [
            'attachments' => [$första->ulid, $andra->ulid],
        ]);

    $svar->assertRedirect('/inbox');
    $svar->assertSessionHas('status', 'inbox-attachments-deleted');

    expect(raderaRaden(Attachment::class, $första->id)->deleted_at)->not->toBeNull();
    expect(raderaRaden(Attachment::class, $andra->id)->deleted_at)->not->toBeNull();
});

/*
 * Klart när: `flera filer raderas alla eller ingen` — en fil utanför inboxen i
 * listan ger 422 `attachment.not_in_inbox`, och INGEN fil har `deleted_at`:
 * inte ens den som faktiskt låg i inboxen. Satsen är en transaktion.
 */
it('flera filer raderas alla eller ingen', function () {
    [$person, $konto, $inbox] = raderaKontext();
    $mål = raderaItem($konto, $person);

    $inne = raderaBilaga($inbox, $konto, $person, 100);
    $ute = raderaBilaga($mål, $konto, $person, 100);

    from('/inbox')
        ->actingAs($person)
        ->post('/inbox/attachments/delete', [
            'attachments' => [$inne->ulid, $ute->ulid],
        ])
        ->assertSessionHasErrors(['attachments' => trans('ui.error.attachment.not_in_inbox')]);

    // Ingen av dem har rörts — inte ens den som låg i inboxen.
    expect(raderaRaden(Attachment::class, $inne->id)->deleted_at)->toBeNull();
    expect(raderaRaden(Attachment::class, $ute->id)->deleted_at)->toBeNull();
    expect($ute->fresh()->item_id)->toBe($mål->id);
});

// --- utanför inboxen --------------------------------------------------------

/*
 * Klart när: `en uppgift utanför inboxen ger 422` — uppgiften ligger på ett
 * riktigt item i användarens EGEN container, så grinden (`delete` på källan)
 * släpper igenom; det är medlemskapsprövningen som nekar, och svaret är 422
 * `schedule.not_in_inbox` — samma svar som bearbetningen ger (ADR-0054 § 6).
 */
it('en uppgift utanför inboxen ger 422', function () {
    [$person, $konto, $inbox] = raderaKontext();
    $mål = raderaItem($konto, $person);
    $uppgift = raderaUppgift($mål);

    from('/inbox')
        ->actingAs($person)
        ->delete("/inbox/tasks/{$uppgift->ulid}")
        ->assertSessionHasErrors(['schedule' => trans('ui.error.schedule.not_in_inbox')]);

    expect(raderaRaden(Schedule::class, $uppgift->id)->deleted_at)->toBeNull();
    expect($uppgift->fresh()->item_id)->toBe($mål->id);
});

/*
 * Klart när: `en annan användares inboxfil nekas` — 422
 * `attachment.not_in_inbox`, och filen är orörd. Den andra personen har en EGEN
 * inbox, så nekandet kommer ur medlemskapsprövningen och inte ur en saknad
 * inbox hos anroparen.
 */
it('en annan användares inboxfil nekas', function () {
    [$person, $konto, $inbox] = raderaKontext();

    $annan = User::factory()->create();
    $annatKonto = Account::factory()->create(['type' => 'personal']);
    $annatKonto->users()->attach($annan, ['role' => 'owner']);
    $annanInbox = app(ResolveInbox::class)->handle($annan);

    $fil = raderaBilaga($annanInbox, $annatKonto, $annan);

    from('/inbox')
        ->actingAs($person)
        ->delete("/inbox/attachments/{$fil->ulid}")
        ->assertSessionHasErrors(['attachment' => trans('ui.error.attachment.not_in_inbox')]);

    expect(raderaRaden(Attachment::class, $fil->id)->deleted_at)->toBeNull();
    expect($fil->fresh()->item_id)->toBe($annanInbox->id);
});

// --- webben ----------------------------------------------------------------

/*
 * Klart när: `sidan har Delete och Delete selected` — `Inbox/Index.vue`
 * innehåller båda nycklarna och rutterna, och nycklarna finns i
 * `lang/en/ui.php`. Radmenyn är en `<details>` per uppgifts- och filrad, och
 * *Delete selected* står bredvid *Move selected…*.
 */
it('sidan har Delete och Delete selected', function () {
    $sida = File::get(resource_path('js/pages/Inbox/Index.vue'));

    expect($sida)->toContain("t('inbox.page.delete')")
        ->and($sida)->toContain("t('inbox.page.delete_selected')")
        ->and($sida)->toContain('<details')
        // Rutterna: en radmeny per rad, och satsen under listan.
        ->and($sida)->toContain('/inbox/tasks/')
        ->and($sida)->toContain('/inbox/attachments/')
        ->and($sida)->toContain('/inbox/attachments/delete');

    foreach (['ui.inbox.page.delete', 'ui.inbox.page.delete_selected'] as $nyckel) {
        expect(trim((string) Lang::get($nyckel, [], 'en')))
            ->not->toBe('', "{$nyckel} saknas i lang/en/ui.php");
    }
});
