<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 356 · Skalen för registrering i vyn, se [[ADR-0074 Registrering i vyn]]
 * § 1–3 och M52.
 *
 * **Källkodsprov, som grannfilerna.** Fokus, Escape och övergången går inte att
 * avgöra utan en webbläsare; handprovet står i PR-kroppen. Det som går att
 * avgöra ur källkoden är att formen finns. Kommentarer rensas före varje
 * kontroll så att ett docblock inte kan nöja en regel.
 *
 * Hjälparna har prefixet `skal` — Pest lägger alla testfiler i samma namnrymd.
 */

function skalKod(string $fil): string
{
    $kod = File::get(resource_path("js/components/{$fil}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

it('ger QuickCreate sina props och händelser', function () {
    $kod = skalKod('QuickCreate.vue');

    foreach (['modelValue', 'label', 'placeholder', 'submitLabel', 'processing', 'error'] as $prop) {
        expect($kod)->toMatch('/\b'.$prop.': \{ type:/');
    }

    expect($kod)->toMatch('/processing: \{ type: Boolean, default: false \}/')
        ->and($kod)->toMatch('/error: \{ type: String, default: null \}/')
        ->and($kod)->toContain("defineEmits(['update:modelValue', 'submit'])")
        ->and($kod)->toContain('@submit.prevent=')
        ->and($kod)->toContain('name="add"');
});

it('skickar submit bara för ett värde som inte är tomt och inte under processing', function () {
    $kod = skalKod('QuickCreate.vue');

    expect($kod)->toMatch('/props\.processing \|\| props\.modelValue\.trim\(\) === \'\'/')
        ->and($kod)->toMatch('/return;\s*\}\s*emit\(\'submit\'\)/');
});

it('ger QuickCreate en slot leading och exponerar focus', function () {
    $kod = skalKod('QuickCreate.vue');

    expect($kod)->toContain('<slot name="leading" />')
        ->and($kod)->toContain('defineExpose({ focus })')
        ->and($kod)->toMatch('/function focus\(\) \{\s*field\.value\?\.focus\(\)/');
});

it('tömmer ett ifyllt fält med Escape och tar fokus ur ett tomt', function () {
    $kod = skalKod('QuickCreate.vue');

    expect($kod)->toContain("event.key !== 'Escape'")
        ->and($kod)->toMatch('/modelValue !== \'\'\) \{\s*emit\(\'update:modelValue\', \'\'\);\s*return;\s*\}\s*field\.value\?\.blur\(\)/')
        // GenomgangTest förbjuder @keydown.esc; tangenten läses i hanteraren.
        ->and($kod)->not->toMatch('/@keydown\.(tab|esc)/');
});

it('låter sparknappen visa väntetexten och stänga sig under processing', function () {
    $kod = skalKod('QuickCreate.vue');

    expect($kod)->toContain('type="submit"')
        ->and($kod)->toContain(':disabled="processing"')
        ->and($kod)->toContain("processing ? t('common.pending.default') : submitLabel")
        ->and($kod)->toMatch('/\bmin-h-11\b/');
});

it('ritar felet med role alert och markerar fältet aria-invalid', function () {
    $kod = skalKod('QuickCreate.vue');

    expect($kod)->toContain('role="alert"')
        ->and($kod)->toContain(':aria-invalid="error ?')
        ->and($kod)->toContain('v-if="error"');
});

it('håller formspråket i båda skalen', function () {
    foreach (['QuickCreate.vue', 'InlineCreate.vue'] as $fil) {
        $kod = skalKod($fil);

        expect($kod)->toContain('rounded-control border border-border bg-surface')
            ->and($kod)->toMatch('/\bmin-h-11\b/')
            ->and($kod)->not->toContain('<UiButton');
    }
});

it('ger InlineCreate sina props och aria-expanded i stängt läge', function () {
    $kod = skalKod('InlineCreate.vue');

    expect($kod)->toMatch('/open: \{ type: Boolean/')
        ->and($kod)->toMatch('/label: \{ type: String, required: true \}/')
        ->and($kod)->toMatch('/dirty: \{ type: Boolean, default: false \}/')
        ->and($kod)->toContain("defineEmits(['update:open'])")
        ->and($kod)->toContain('type="button"')
        ->and($kod)->toContain('aria-expanded="false"')
        ->and($kod)->toContain("emit('update:open', true)")
        ->and($kod)->toContain("querySelector('input, select, textarea, button')");
});

it('stänger InlineCreate direkt med Escape när dirty är falskt och frågar annars', function () {
    $kod = skalKod('InlineCreate.vue');

    expect($kod)->toContain("event.key !== 'Escape'")
        ->and($kod)->toMatch('/if \(props\.dirty\) \{\s*confirming\.value = true;\s*return;\s*\}\s*emit\(\'update:open\', false\)/')
        ->and($kod)->toContain('<UiConfirmDialog')
        ->and($kod)->toContain("t('inline_create.discard.heading')")
        ->and($kod)->toContain("t('inline_create.discard.confirm')")
        ->and($kod)->toContain('@confirm="discard"')
        ->and($kod)->toContain('@cancel="confirming = false"')
        ->and($kod)->not->toMatch('/@keydown\.(tab|esc)/');
});

it('lämnar fokus på knappen när InlineCreate stängs', function () {
    $kod = skalKod('InlineCreate.vue');

    expect($kod)->toMatch('/confirming\.value = false;\s*focusButton\(\)/')
        ->and($kod)->toContain('ref="openButton"');
});

it('fäller ut InlineCreate med grid-template-rows och respekterar reducerad rörelse', function () {
    $kod = skalKod('InlineCreate.vue');

    expect($kod)->toContain('transition-[grid-template-rows]')
        ->and($kod)->toContain('grid-rows-[0fr]')
        ->and($kod)->toContain('grid-rows-[1fr]')
        ->and($kod)->toContain('motion-reduce:transition-none')
        ->and($kod)->toMatch('/\bduration-200\b/');
});

it('har de tre nycklarna inline_create.discard.* med rätt text', function () {
    $lang = require lang_path('en/ui.php');

    expect($lang['inline_create']['discard'])->toBe([
        'heading' => 'Discard your changes?',
        'confirm' => 'Discard',
        'cancel' => 'Keep editing',
    ]);
});
