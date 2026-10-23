<?php

// Prövar kärnorna vueMallUr() och vueSkriptUr() på strängar, se tests/Support/Testhjalpare.php.

function vueProvFil(): string
{
    return <<<'VUE'
<script setup>
const a = 1;
</script>

<template>
  <!-- en kommentar
       över flera rader -->
  <div>yttre</div>
  <template #slot>
    <span>inre</span>
  </template>
  <p>efter</p>
</template>
VUE;
}

it('vueMallUr tar inte med script setup-blocket', function () {
    expect(vueMallUr(vueProvFil()))->not->toContain('const a = 1');
});

it('vueMallUr tar bort en HTML-kommentar över flera rader', function () {
    $mall = vueMallUr(vueProvFil());

    expect($mall)->not->toContain('en kommentar');
    expect($mall)->not->toContain('<!--');
    expect($mall)->toContain('yttre');
});

it('vueMallUr behåller en inre template-slot och hela den yttre mallen', function () {
    $mall = vueMallUr(vueProvFil());

    expect($mall)->toContain('<template #slot>');
    expect($mall)->toContain('inre');
    expect($mall)->toContain('<p>efter</p>');
});

it('vueSkriptUr tar bort docblock och //-rad men behåller URL i sträng', function () {
    $skript = vueSkriptUr(<<<'VUE'
<script setup>
/**
 * Docblock-text
 */
// hel rad
const url = 'https://example.com';
</script>
<template>
</template>
VUE);

    expect($skript)->not->toContain('Docblock-text');
    expect($skript)->not->toContain('hel rad');
    expect($skript)->toContain("'https://example.com'");
});

it('vueSkriptUr tar inte med mallen', function () {
    $skript = vueSkriptUr(vueProvFil());

    expect($skript)->toContain('const a = 1');
    expect($skript)->not->toContain('yttre');
});

it('kastar RuntimeException när mall eller fil saknas', function () {
    expect(fn () => vueMallUr('<script setup>const a = 1;</script>'))->toThrow(RuntimeException::class);
    expect(fn () => vueSkriptUr("<template>\n</template>"))->toThrow(RuntimeException::class);
    expect(fn () => vueMall('js/finns-inte.vue'))->toThrow(RuntimeException::class, 'js/finns-inte.vue');
});

it('vueMall på ItemRow.vue tar inte med kommentarens ContainerMapNode', function () {
    expect(vueMall('js/components/ItemRow.vue'))->not->toContain('ContainerMapNode');
});

it('taxonomiväljarens mall bär combobox-rollerna och inte kommentarens ord', function () {
    $mall = vueMall('js/components/TaxonomyPicker.vue');
    $skript = vueSkript('js/components/TaxonomyPicker.vue');

    expect($mall)->toContain('role="combobox"');
    expect($mall)->toContain('role="listbox"');
    expect($mall)->not->toContain('preventDefault');
    expect($skript)->toContain("event.key === 'Backspace'");
    expect($skript)->not->toContain('Tangentbordet är combobox-mönstret');
});
