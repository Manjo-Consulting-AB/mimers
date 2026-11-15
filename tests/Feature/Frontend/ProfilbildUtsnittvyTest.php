<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/*
 * Issue 382 · Profilbildens utsnitt — ytan. Se [[ADR-0076 Profilbilden]] § 7
 * och [[M59 Profilbilden]] § 382.
 *
 * **Källkodsprov, som GenomgangTest.** Utsnittet är klientsidans räkning och
 * markup: avataren är en ny komponent, redigeraren är ett ark i
 * Settings/Profile.vue, och texten bor i lang/en/ui.php. Det som går att
 * avgöra på serversidan är formen på källkoden — att skalet och profilsidan
 * ritar `UserAvatar`, att redigeraren PATCH:ar rätt adress, att reglaget har
 * gränserna 100 och 300, och att katalogen har nycklarna.
 *
 * **Räkningen prövas på riktigt.** `avatarCrop`, `avatarFocusRange`,
 * `avatarDragFocus` och `avatarNudgeFocus` i
 * resources/js/composables/useAvatarCrop.js körs i node med kända fall, samma
 * grepp som DatumregelTest använder för useRelativeDate.js. En andra upplaga
 * av formeln i provet hade kunnat vara grön medan ytan ritade fel — och
 * redigerarens drag och piltangenter hade kunnat räkna på ett värde som
 * ritningen klamrar.
 *
 * Kommentarerna rensas före varje kontroll, som i GenomgangTest: docblocken
 * förklarar `?variant=thumb` och initialerna, och en regel som letar efter dem
 * ska inte kunna nöjas av en mening.
 *
 * Hjälparna har prefixet `utsnittvy` — Pest lägger alla testfiler i samma
 * namnrymd, och ProfilbildvyTest.php äger redan `profilbildvy*`.
 */

/**
 * Källkoden med kommentarer borta: blockkommentarer, HTML-kommentarer och
 * radkommentarer.
 */
function utsnittvyKod(string $sokvag): string
{
    $kod = File::get(resource_path('js/'.$sokvag));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Kör ett uttryck mot `avatarCrop` i node och returnerar svaret som en array.
 *
 * @return array<string, mixed>
 */
function utsnittvyKör(string $anrop): array
{
    $kod = implode("\n", [
        "const { pathToFileURL } = await import('node:url');",
        'const m = await import(pathToFileURL('
            .json_encode(resource_path('js/composables/useAvatarCrop.js'), JSON_UNESCAPED_SLASHES).').href);',
        'process.stdout.write(JSON.stringify('.$anrop.'));',
    ]);

    $rader = [];
    $status = 0;

    exec('node --input-type=module -e '.escapeshellarg($kod).' 2>&1', $rader, $status);

    expect($status)->toBe(0, implode("\n", $rader));

    /** @var array<string, mixed> $svar */
    $svar = json_decode(implode("\n", $rader), true);

    return $svar;
}

/*
 * Klart när: `AppLayout.vue` och `Profile.vue` ritar `UserAvatar`.
 *
 * Skalet ritar den lilla cirkeln i toppraden, profilsidan förhandsvisningen —
 * och redigerarens yta ritas av SAMMA komponent med `shape="square"` och
 * storleken som ger `?variant=medium`. En andra formulering av utsnittet hade
 * glidit isär från avatarens.
 */
it('ritar UserAvatar i skalet och på profilsidan', function () {
    $skal = utsnittvyKod('layouts/AppLayout.vue');
    $profil = utsnittvyKod('pages/Settings/Profile.vue');

    expect($skal)->toContain("import UserAvatar from '../components/UserAvatar.vue'")
        ->toContain('<UserAvatar :user="user" :size="44" />');

    expect($profil)->toContain("import UserAvatar from '../../components/UserAvatar.vue'")
        ->toContain('<UserAvatar :user="currentUser" :size="64" />')
        ->toContain('shape="square"')
        ->toContain('@load="onEditorImageLoad"');
});

/*
 * Klart när: `UserAvatar` ritar med rätt variant och faller tillbaka på
 * initialerna.
 *
 * Små rutor ber om `?variant=thumb`, redigerarens stora yta om
 * `?variant=medium`; en begärd variant som saknas faller tillbaka på
 * miniatyren, och `@error` är vägen tillbaka till initialerna. `alt=""` med
 * flit — ytan runt bilden bär personens namn.
 */
it('ritar rätt variant och faller tillbaka på initialerna', function () {
    $avatar = utsnittvyKod('components/UserAvatar.vue');

    expect($avatar)->toContain('?variant=thumb')
        ->toContain('?variant=medium')
        ->toContain('has_thumb')
        ->toContain('@error')
        ->toContain('{{ initials }}')
        ->toContain('avatarCrop');

    preg_match('/<img\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*?>/s', $avatar, $bild);

    expect($bild)->toHaveCount(1, 'UserAvatar.vue ritar ingen bild');
    expect($bild[0])->toContain('alt=""')
        ->toContain('@load=')
        ->toContain('@error=');
});

/*
 * Klart när: redigeraren postar till `/settings/profile/avatar/crop`.
 *
 * `router.patch` mot ruttens adress, med punkt och zoom ur utkastet, och
 * `onStart`/`onFinish` runt anropet (GenomgangTest § vänteläge). Ytan lyssnar
 * på pekaren och tangentbordet, och bär ett `aria-label` ur katalogen.
 */
it('låter redigeraren spara utsnittet med en PATCH', function () {
    $profil = utsnittvyKod('pages/Settings/Profile.vue');

    expect($profil)->toContain("router.patch('/settings/profile/avatar/crop'")
        ->toContain('x: editorFocus.value.x')
        ->toContain('y: editorFocus.value.y')
        ->toContain('zoom: editorZoom.value')
        ->toContain('onStart:')
        ->toContain('onFinish:')
        ->toContain('@pointerdown="startDrag"')
        ->toContain('@pointermove="dragMove"')
        ->toContain('@keydown="onEditorKeydown"')
        ->toContain('tabindex="0"')
        ->toContain("t('settings.profile.avatar.crop_label')");
});

/*
 * Klart när: reglaget har gränserna 100 och 300.
 *
 * Samma intervall som kolumnen och requesten: 100 % är hela bilden, 300 %
 * skär bort allt utom en tredjedel. `type="range"` och etiketten knyts med
 * `for`/`id`, som GenomgangTest kräver.
 */
it('ger zoomreglaget gränserna 100 och 300', function () {
    $profil = utsnittvyKod('pages/Settings/Profile.vue');

    expect($profil)->toContain('type="range"')
        ->toContain('min="100"')
        ->toContain('max="300"')
        ->toContain(':for="zoomId"')
        ->toContain(':id="zoomId"');
});

/*
 * Klart när: redigeraren visar exakt vad avataren visar.
 *
 * Maskens cirkel ska ha ytans radie — halva `EDITOR_SIZE` — så att den kant
 * som mörkläggs ligger på avatarens kant och inte innanför den. Ett `circle`
 * utan storleksangivelse får `farthest-corner`, som på en kvadratisk yta ger
 * radien `EDITOR_SIZE / √2` och en ring av maskad bild innanför cirkeln.
 * `closest-side` ger i stället halva sidan. Både `maskImage` och
 * `WebkitMaskImage` bär samma sträng, eftersom webbläsaren annars faller
 * tillbaka på den ena.
 */
it('låter masken följa ytans kant så att den matchar avatarens cirkel', function () {
    $profil = utsnittvyKod('pages/Settings/Profile.vue');
    $mask = 'radial-gradient(circle closest-side at center, transparent 99%, black 100%)';

    expect($profil)->toContain("maskImage: '{$mask}'")
        ->toContain("WebkitMaskImage: '{$mask}'");
});

/*
 * Klart när: katalogen har nycklarna.
 *
 * Redigerarens texter ligger under `settings.profile.avatar`, som resten av
 * avsnittet, och kvittensen `avatar-cropped` ligger bland de andra
 * flash-koderna — FlashMessage läser `flash.<kod>` och en kod utan nyckel
 * renderas rå.
 */
it('bär redigerarens texter i katalogen', function () {
    /** @var array<string, string> $avatar */
    $avatar = Lang::get('ui.settings.profile.avatar', [], 'en');

    expect($avatar)->toBeArray()
        ->toHaveKeys([
            'heading', 'upload', 'remove', 'uploading',
            'adjust', 'crop_heading', 'crop_label', 'zoom', 'save', 'reset', 'cancel',
        ]);

    foreach ($avatar as $nyckel => $varde) {
        expect(trim((string) $varde))->not->toBe('', "ui.settings.profile.avatar.{$nyckel} är tom");
    }

    expect(Lang::get('ui.flash.avatar-cropped', [], 'en'))->not->toBe('ui.flash.avatar-cropped');
});

/*
 * Klart när: räkningen stämmer i kända fall.
 *
 * 1. **Mitten och 100 % ger ingen förskjutning.** Bilden ligger centrerad och
 *    hela bilden syns.
 * 2. **En punkt i hörnet stannar vid bildens kant.** En 200×100-bild i en
 *    100-ruta har 100 px överflöd i sidled; fokus (0,0) vill flytta bilden
 *    100 px, men klamringen stannar vid 50 px — då ligger bildens vänsterkant
 *    i rutans, utan tom kant.
 * 3. **200 % ger halva bilden.** Den synliga andelen är 1/zoom.
 */
it('räknar utsnittet med kända fall', function () {
    $mitten = utsnittvyKör(
        'm.avatarCrop({ imageWidth: 100, imageHeight: 100, box: 100, focusX: 50, focusY: 50, zoom: 100 })',
    );

    expect($mitten['offsetX'])->toBe(0)
        ->and($mitten['offsetY'])->toBe(0)
        ->and($mitten['visibleX'])->toBe(1)
        ->and($mitten['visibleY'])->toBe(1);

    $horn = utsnittvyKör(
        'm.avatarCrop({ imageWidth: 200, imageHeight: 100, box: 100, focusX: 0, focusY: 0, zoom: 100 })',
    );

    expect($horn['offsetX'])->toBe(50)
        ->and($horn['left'])->toBe(0)
        ->and($horn['offsetY'])->toBe(0);

    $zoom = utsnittvyKör(
        'm.avatarCrop({ imageWidth: 100, imageHeight: 100, box: 100, focusX: 50, focusY: 50, zoom: 200 })',
    );

    expect($zoom['visibleX'])->toBe(0.5)
        ->and($zoom['visibleY'])->toBe(0.5)
        ->and($zoom['width'])->toBe(200);
});

/*
 * Klart när: redigeraren flyttar punkten med drag och piltangenter.
 *
 * `avatarFocusRange` ger den punkt som FAKTISKT syns och intervallet som går
 * att nå. Utan den började ett drag i det sparade talet, och en punkt nära
 * kanten hade först ätit upp ett dött område — bilden står stilla medan
 * talet rör sig, och nästa zoom hoppar till en punkt personen aldrig såg.
 *
 * Kända fall, alla i en 100-ruta med en 200×100-bild vid 100 %:
 * 1. **Sparad punkt 0 ritas som 25.** Överflödet är 100 px, alltså klamras
 *    förskjutningen till 50 px, och 50 av 200 px är 25 %.
 * 2. **Intervallet är 25–75 i x och en enda punkt i y**, där höjden redan
 *    täcker rutan.
 * 3. **Ett drag börjar i 25**, inte i 0: 50 px åt vänster ger 50. Ett drag
 *    för långt åt höger stannar på 25 i stället för att lämna bilden.
 * 4. **Piltangenterna börjar också i 25**: +2 ger 27, och ett stort steg
 *    stannar vid 75.
 * 5. **En kvadratisk bild vid 100 % kan inte flyttas alls** — intervallet är
 *    50–50, så ett drag ändrar ingenting och ett sparande kan inte hoppa vid
 *    nästa zoom.
 */
it('räknar drag och piltangenter ur den effektiva punkten', function () {
    $bred = ['imageWidth' => 200, 'imageHeight' => 100, 'box' => 100, 'zoom' => 100];

    $omrade = utsnittvyKör('m.avatarFocusRange('.json_encode(
        $bred + ['focusX' => 0, 'focusY' => 0],
    ).')');

    expect($omrade['focusX'])->toBe(25)
        ->and($omrade['focusY'])->toBe(50)
        ->and($omrade['minX'])->toBe(25)
        ->and($omrade['maxX'])->toBe(75)
        ->and($omrade['minY'])->toBe(50)
        ->and($omrade['maxY'])->toBe(50);

    $vanster = utsnittvyKör('m.avatarDragFocus('.json_encode(
        $bred + ['focusX' => 0, 'focusY' => 0, 'deltaX' => -50],
    ).')');

    expect($vanster)->toBe(['x' => 50, 'y' => 50]);

    $forlangt = utsnittvyKör('m.avatarDragFocus('.json_encode(
        $bred + ['focusX' => 0, 'focusY' => 0, 'deltaX' => 200],
    ).')');

    expect($forlangt)->toBe(['x' => 25, 'y' => 50]);

    $steg = utsnittvyKör('m.avatarNudgeFocus('.json_encode(
        $bred + ['focusX' => 0, 'focusY' => 0, 'stepX' => 2],
    ).')');

    expect($steg)->toBe(['x' => 27, 'y' => 50]);

    $bort = utsnittvyKör('m.avatarNudgeFocus('.json_encode(
        $bred + ['focusX' => 50, 'focusY' => 50, 'stepX' => 50],
    ).')');

    expect($bort)->toBe(['x' => 75, 'y' => 50]);

    $kvadrat = utsnittvyKör('m.avatarDragFocus('.json_encode(
        ['imageWidth' => 100, 'imageHeight' => 100, 'box' => 100, 'zoom' => 100,
            'focusX' => 0, 'focusY' => 0, 'deltaX' => 50, 'deltaY' => 50],
    ).')');

    expect($kvadrat)->toBe(['x' => 50, 'y' => 50]);
});

/*
 * Klart när: återställer och avbryter.
 *
 * Reset sätter mitten och 100 % — inget annat — och Cancel stänger arket utan
 * att skriva. Källprovet fäller en Reset som glömmer zoomen, och en Cancel
 * som anropar något annat än `closeEditor` (till exempel `saveCrop`, som
 * hade sparat ett utkast personen valde bort).
 *
 * Ytan frågar SAMMA rena funktioner som provet ovan: ett källprov som bara
 * letade efter `router.patch` hade varit grönt även om drag och piltangenter
 * räknade på det sparade talet i stället för den effektiva punkten.
 */
it('återställer utsnittet med Reset och stänger med Cancel utan att spara', function () {
    $profil = utsnittvyKod('pages/Settings/Profile.vue');

    preg_match('/function resetCrop\(\)\s*\{(.*?)\n\}/s', $profil, $reset);

    expect($reset)->toHaveCount(2, 'resetCrop finns inte i Profile.vue');

    expect($reset[1])->toContain('editorFocus.value = { x: 50, y: 50 }')
        ->toContain('editorZoom.value = 100');

    preg_match(
        '/@click="([^"]+)"[^>]*>\s*\{\{\s*t\(\'settings\.profile\.avatar\.cancel\'\)\s*\}\}/s',
        $profil,
        $cancel,
    );

    expect($cancel)->toHaveCount(2, 'Cancel-knappen finns inte i Profile.vue');
    expect($cancel[1])->toBe('closeEditor');

    expect($profil)->toContain('avatarFocusRange(')
        ->toContain('avatarDragFocus(')
        ->toContain('avatarNudgeFocus(');
});
