<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 1209 · Itemets Documents-flik registrerar i vyn, som containerns.
 * Se [[ADR-0074 Registrering i vyn]] § 1–3 och resources/js/components/
 * ItemAttachmentSection.vue. Proven läser källan, som resten av ytans prov:
 * komponenten renderas i webbläsaren.
 */

function itemDokumentKod(): string
{
    return File::get(resource_path('js/components/ItemAttachmentSection.vue'));
}

function itemDokumentMall(): string
{
    $kod = itemDokumentKod();

    return substr($kod, strpos($kod, '<template>'));
}

it('ritar InlineCreate före listan med kön inuti och ingen Upload files-rubrik', function () {
    $mall = itemDokumentMall();

    $formular = strpos($mall, '<InlineCreate');
    $ko = strpos($mall, '<AttachmentUploadQueue');
    $stang = strpos($mall, '</InlineCreate>');
    $lista = strpos($mall, '<ul v-else');

    expect($formular)->not->toBeFalse();
    expect($ko)->toBeGreaterThan($formular);
    expect($ko)->toBeLessThan($stang);
    expect($stang)->toBeLessThan($lista);
    expect($mall)->not->toContain('item.attachment.upload_heading');
    expect($mall)->not->toContain('<h3');
    expect(File::get(base_path('lang/en/ui.php')))->not->toContain("'upload_heading' =>");
});

it('ger formuläret etiketten Add document och låter köns busy styra dirty', function () {
    $mall = itemDokumentMall();

    expect($mall)->toContain(":label=\"t('container.documents.add')\"");
    expect($mall)->toContain(':dirty="uploading"');
    expect($mall)->toContain('@busy="uploading = $event"');
    expect($mall)->toContain('@uploaded="onUploaded"');
    expect(substr($mall, strpos($mall, '<AttachmentUploadQueue'), 400))->toContain(':item-ulid="itemUlid"');
});

it('låter tomt lägets knapp fälla ut formuläret', function () {
    $kod = itemDokumentKod();

    expect($kod)->toMatch('/function focusForm\(\) \{\s*createOpen\.value = true;\s*\}/');
    expect($kod)->not->toContain("getElementById('attachment-file')");
    expect($kod)->toContain('v-model:open="createOpen"');
});

it('markerar nya rader med bg-accent-soft i 2000 ms', function () {
    $kod = itemDokumentKod();

    expect($kod)->toContain("'bg-accent-soft': highlight.includes(attachment.ulid)");
    expect($kod)->toContain('async function onUploaded()');
    expect($kod)->toContain('await nextTick();');
    expect($kod)->toContain('current.filter((ulid) => ! before.has(ulid))');
    expect($kod)->toMatch('/setTimeout\(\(\) => \{\s*highlight\.value = \[\];\s*\}, 2000\)/');
});

it('ritar formuläret och kön bara bakom can.create', function () {
    $mall = itemDokumentMall();

    expect($mall)->toMatch('/<InlineCreate\s+v-if="can\.create"/');
    expect(substr_count($mall, '<AttachmentUploadQueue'))->toBe(1);
});

it('stänger med Escape och frågar först vid pågående uppladdning via InlineCreate', function () {
    $inline = File::get(resource_path('js/components/InlineCreate.vue'));

    expect($inline)->toContain("event.key !== 'Escape'");
    expect($inline)->toContain('if (props.dirty)');
    expect(itemDokumentMall())->toContain(':dirty="uploading"');
});
