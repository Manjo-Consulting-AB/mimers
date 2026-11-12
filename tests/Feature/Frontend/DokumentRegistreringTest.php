<?php

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 1167 · M55 Registrering i vyn: Documents, se [[ADR-0074 Registrering
 * i vyn]] § 1–3 och 7, resources/js/pages/Containers/Documents.vue och
 * app/Http/Controllers/ContainerDocumentController.php.
 *
 * **Källkodsprov för klienten, som grannfilerna.** Vue körs inte i Pest; fokus,
 * markeringens tid och dragningen av en fil går inte att avgöra utan en
 * webbläsare (handprovet står i PR-kroppen). Kommentarer rensas före varje
 * kontroll, så ett ord i en förklaring aldrig räknas som kod. Servern prövas
 * på riktigt: `maxUploadBytes` i svaret och uppladdningens återvändo.
 *
 * Hjälparna har prefixet `dokreg` — Pest lägger alla testfiler i samma namnrymd.
 */

function dokregKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function dokregKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

it('ritar InlineCreate mellan filterraden och listan och ingen meny i huvudet', function () {
    $vy = dokregKod('pages/Containers/Documents.vue');

    expect($vy)->not->toContain('<details v-if="items.length > 0"')
        ->and($vy)->not->toContain('absolute right-0')
        ->and($vy)->toContain('<StorageBar');

    $filter = strpos($vy, '<DocumentFilterBar');
    $rad = strpos($vy, '<InlineCreate');
    $lista = strpos($vy, '<tbody>');

    expect($filter)->not->toBeFalse()
        ->and($rad)->toBeGreaterThan((int) $filter)
        ->and($lista)->toBeGreaterThan((int) $rad);

    expect($vy)->toContain('v-if="items.length > 0"')
        ->and($vy)->toContain(":label=\"t('container.documents.add')\"")
        ->and($vy)->toContain(':dirty="uploading"')
        ->and($vy)->toContain('@busy="uploading = $event"');
});

it('ritar itemväljaren som en enda details-knapp med absolut panel över items', function () {
    $vy = dokregKod('pages/Containers/Documents.vue');

    expect(substr_count($vy, '<details'))->toBe(1)
        ->and($vy)->toContain('<details ref="itemMenu" class="relative"')
        ->and($vy)->toContain('absolute left-0 z-10')
        ->and($vy)->toContain('shadow-sm')
        ->and($vy)->toContain('v-for="option in items"')
        ->and($vy)->toContain("t('container.documents.add_choose_item')")
        ->and($vy)->toContain('selectedItem.name');
});

it('låter ett val sätta itemet och stänga panelen utan att byta sida', function () {
    $vy = dokregKod('pages/Containers/Documents.vue');

    preg_match('/function chooseItem\(option\) \{(.*?)\n\}/s', $vy, $funktion);

    expect($funktion)->not->toBeEmpty();
    $kropp = $funktion[1];

    expect($kropp)->toContain('selectedItem.value = option')
        ->and($kropp)->toContain('itemMenu.value.open = false')
        ->and($kropp)->not->toContain('router')
        ->and($kropp)->not->toContain('visit');

    // Valet är en `<button>` och ingen länk.
    expect($vy)->toContain('@click="chooseItem(option)"');
});

it('förväljer itemet när containern har exakt ett', function () {
    $vy = dokregKod('pages/Containers/Documents.vue');

    expect($vy)->toContain('ref(props.items.length === 1 ? props.items[0] : null)');
});

it('ger kön det valda itemets ULID, taket, kontot och inboxflaggan', function () {
    $vy = dokregKod('pages/Containers/Documents.vue');

    expect($vy)->toContain('<AttachmentUploadQueue')
        ->and($vy)->toContain(':container-ulid="container.ulid"')
        ->and($vy)->toContain(':item-ulid="selectedItem?.ulid ?? null"')
        ->and($vy)->toContain(':max-upload-bytes="maxUploadBytes"')
        ->and($vy)->toContain(':container-account="container.account"')
        ->and($vy)->toContain(':container-is-inbox="containerIsInbox"');

    // Kön väljer och släpper filer bara när ett item är valt (issue 1166).
    $ko = dokregKod('components/AttachmentUploadQueue.vue');

    expect($ko)->toContain('props.itemUlid === null')
        ->and($ko)->toContain(':disabled="disabled"')
        ->and($ko)->toContain('router.post(`${itemUrl()}/attachments`');
});

it('låter formuläret stå kvar efter uppladdningen och frågar före stängning', function () {
    $vy = dokregKod('pages/Containers/Documents.vue');

    preg_match('/async function onUploaded\(\) \{(.*?)\n\}/s', $vy, $funktion);

    expect($funktion)->not->toBeEmpty()
        ->and($funktion[1])->not->toContain('createOpen');

    // Stängningsfrågan ägs av InlineCreate och styrs av `dirty`.
    $rad = dokregKod('components/InlineCreate.vue');

    expect($rad)->toContain('if (props.dirty)')
        ->and($rad)->toContain('confirming.value = true');
});

it('markerar nya dokument med bg-accent-soft i 2000 ms och säger till när de är dolda', function () {
    $vy = dokregKod('pages/Containers/Documents.vue');

    expect($vy)->toContain("'bg-accent-soft': highlight.includes(row.ulid)")
        ->and(substr_count($vy, "'bg-accent-soft': highlight.includes(row.ulid)"))->toBe(2)
        ->and($vy)->toContain('}, 2000);')
        ->and($vy)->toContain('@uploaded="onUploaded"')
        ->and($vy)->toContain('.filter((ulid) => ! before.has(ulid))')
        ->and($vy)->toContain('<p v-if="hiddenNotice" role="status"')
        ->and($vy)->toContain("t('container.documents.uploaded_hidden')");

    expect(Lang::get('ui.container.documents.uploaded_hidden', [], 'en'))
        ->toBe('The document was uploaded, but the current filter or page hides it.');
});

it('ger fliken maxUploadBytes ur config', function () {
    withoutVite();

    [, $anvandare, $container, $item] = dokregKontext();

    config(['files.max_upload_bytes' => 12345]);

    $svar = actingAs($anvandare)->get("/containers/{$container->ulid}/documents")->assertOk();

    expect($svar->inertiaProps()['maxUploadBytes'])->toBe(12345)
        ->and(array_column($svar->inertiaProps()['items'], 'ulid'))->toBe([$item->ulid]);
});

// vakt: den befintliga POST-rutten svarar redan back(); provet låser att den fortsätter göra det.
it('låter en uppladdning med Documents som Referer landa på Documents', function () {
    withoutVite();
    Storage::fake('files');

    [$konto, $anvandare, $container, $item] = dokregKontext();

    $flik = "/containers/{$container->ulid}/documents";

    actingAs($anvandare)
        ->from($flik)
        ->post("/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('manual.pdf', 'PDF-innehåll'),
            'account' => $konto->ulid,
        ])
        ->assertRedirect($flik)
        ->assertSessionHasNoErrors();

    expect(Attachment::query()->where('item_id', $item->id)->count())->toBe(1);
});
