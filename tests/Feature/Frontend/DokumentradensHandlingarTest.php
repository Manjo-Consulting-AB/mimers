<?php

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * M57 · issue 373: dokumentradens två knappar, *Download* och *More*.
 *
 * Komponenten resources/js/components/AttachmentRowActions.vue ritas på
 * *Documents* och på itemets bilageflik. Provet är ett källkodsprov (ingen
 * webbläsare i sviten): menyraderna skärs ut ur filen först, så att en nål
 * hör till just den knappen. Serverprovet visar att `DELETE` med en `Referer`
 * på dokumentfliken landar där (`back()`).
 *
 * Hjälparna har prefixet `dokradens`.
 */

function dokradensKomponent(): string
{
    return File::get(resource_path('js/components/AttachmentRowActions.vue'));
}

/** Menyraden (knappen) vars klick är `choose('namn')`, med sina attribut. */
function dokradensMenyrad(string $namn): string
{
    preg_match('/<button[^>]*choose\(\''.$namn.'\'\)[^>]*>/s', dokradensKomponent(), $traffar);

    return $traffar[0] ?? '';
}

it('ger Download och More samma klasser', function () {
    $kod = dokradensKomponent();

    // Båda bär en och samma konstant, och den har texten, storleken och vikten.
    expect($kod)->toContain(':class="BUTTON_CLASSES"');
    expect($kod)->toContain(':class="[BUTTON_CLASSES, \'cursor-pointer list-none\']"');
    expect($kod)->toContain(
        "const BUTTON_CLASSES = 'inline-flex min-h-11 items-center gap-1 rounded-control border border-border px-3 text-sm font-medium text-ink hover:bg-surface-sunken';"
    );
    expect($kod)->toContain('`/files/${attachment.ulid}?download=1`');
    expect($kod)->toContain("t('item.attachment.download')");
    expect($kod)->toContain("t('item.attachment.more')");
    expect($kod)->toContain('<UiIcon name="chevron-down"');
});

it('ritar menyn som en högerställd absolut panel i en details', function () {
    $kod = dokradensKomponent();

    expect($kod)->toContain('<details');
    expect($kod)->toContain('class="relative"');
    expect($kod)->toContain('class="absolute right-0 z-10 mt-2 w-48 rounded-card border border-border bg-surface p-1 shadow-sm"');
});

it('visar menyns rader efter can.delete och containerIsInbox', function () {
    $flytt = dokradensMenyrad('move');
    $inbox = dokradensMenyrad('inbox');
    $kopia = dokradensMenyrad('copy');
    $radera = dokradensMenyrad('destroy');

    expect($flytt)->toContain('v-if="canDelete"')->toContain('min-h-11');
    expect($inbox)->toContain('v-if="canDelete && ! containerIsInbox"')->toContain('min-h-11');
    // Kopian står på varje rad och har ingen grind.
    expect($kopia)->toContain('type="button"')->toContain('min-h-11');
    expect($kopia)->not->toContain('v-if');
    expect($radera)->toContain('v-if="canDelete"')->toContain('text-danger')->toContain('border-t');

    expect(dokradensKomponent())->toContain('props.attachment.can?.delete === true');

    foreach ([$flytt, $inbox, $kopia, $radera] as $rad) {
        expect($rad)->toContain('type="button"');
    }
});

it('låter en läsare utan can.delete se bara Copy… i menyn', function () {
    $kod = dokradensKomponent();

    // Tre av fyra rader är grindade av `canDelete`; den fjärde är kopian.
    expect(substr_count($kod, 'v-if="canDelete'))->toBe(3);
    expect(substr_count($kod, '<button'))->toBe(4);
    expect(dokradensMenyrad('copy'))->not->toContain('v-if');
});

it('stänger menyn vid ett val och vid Escape och skickar händelsen', function () {
    $kod = dokradensKomponent();

    expect($kod)->toContain("defineEmits(['move', 'copy', 'inbox', 'destroy'])");
    // Valet stänger först och skickar sedan, med More som utlösare.
    expect($kod)->toMatch('/function choose\(name\) \{.*?open\.value = false;.*?emit\(name, \{ currentTarget: trigger \}\);/s');
    // Escape stänger och lämnar fokus på More.
    expect($kod)->toMatch("/event\.key !== 'Escape'.*?open\.value = false;\s*summary\(\)\?\.focus\(\);/s");
    expect($kod)->toContain('@keydown="onKeydown"');
    // Komponenten postar ingenting själv.
    expect($kod)->not->toContain('router.');
});

it('ritar komponenten på Documents och på bilagefliken med sina skrivvägar', function () {
    $sidan = File::get(resource_path('js/pages/Containers/Documents.vue'));
    $flik = File::get(resource_path('js/components/ItemAttachmentSection.vue'));

    expect($sidan)->toContain('<AttachmentRowActions');
    expect($flik)->toContain('<AttachmentRowActions');
    expect($sidan)->toContain('@inbox="toInbox(row)"');
    expect($flik)->toContain('@inbox="toInbox(attachment)"');
    expect($flik)->toContain('@destroy="destroy(attachment)"');
    expect($sidan)->toContain("t('container.documents.actions')");
    expect(Lang::get('ui.container.documents.actions', [], 'en'))->toBe('Actions');
    expect(Lang::get('ui.item.attachment.more', [], 'en'))->toBe('More');
});

it('frågar med UiConfirmDialog innan Delete raderar på Documents', function () {
    $sidan = File::get(resource_path('js/pages/Containers/Documents.vue'));

    expect($sidan)->toContain("import UiConfirmDialog from '../../components/UiConfirmDialog.vue'");
    expect($sidan)->toContain('@destroy="askDestroy(row, $event)"');
    expect($sidan)->toContain(":heading=\"t('item.attachment.destroy_confirm')\"");
    expect($sidan)->toContain('@confirm="destroy"');

    // Raderingen sker i `destroy()`, som bara nås ur dialogens bekräftelse, och
    // `askDestroy()` rör ingen rutt.
    preg_match('/function askDestroy\(.*?\n\}/s', $sidan, $fraga);
    expect($fraga[0])->not->toContain('router.');
    expect($sidan)->toContain('router.delete(`/containers/${props.container.ulid}/items/${row.item.ulid}/attachments/${row.ulid}`');
    expect($sidan)->toMatch('/router\.delete\(.*?preserveScroll: true/s');
    expect($sidan)->toMatch('/function destroy\(\).*?actionError\.value = \{ ulid: row\.ulid/s');
});

it('landar DELETE med Referer på dokumentfliken där', function () {
    withoutVite();

    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);
    $bilaga = Attachment::factory()->create([
        'item_id' => $item->id,
        'stored_file_id' => StoredFile::factory()->create(['byte_size' => 1500])->id,
        'filename' => 'manual.pdf',
        'kind' => 'document',
        'uploaded_by_user_id' => $anvandare->id,
        'billed_account_id' => $konto->id,
    ]);

    $flik = "/containers/{$container->ulid}/documents";

    actingAs($anvandare)
        ->withHeader('Referer', url($flik))
        ->delete("/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$bilaga->ulid}")
        ->assertRedirect(url($flik));

    expect($bilaga->fresh()->deleted_at)->not->toBeNull();
});
