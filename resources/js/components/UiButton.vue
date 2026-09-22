<script setup>
import { computed } from 'vue';

/*
 * Knappen, se issue 98 och [[ADR-0042 Designsystemet]] § Beslut.
 *
 * **Fyra varianter och två storlekar, och det är hela uppsättningen.** De
 * femton klasskombinationer som låg utspridda över vyerna var varianter av
 * samma sak. `primary` är den enda handlingen på en yta, `secondary` är
 * grannen bredvid, `quiet` är radens tysta åtgärd och `danger` är den som
 * tar bort något. En sextonde variant är en className någon hittade på, inte
 * en knapp.
 *
 * **Varianten är en roll och inte en färg.** Klasserna nedan pekar på tokens
 * ur `resources/css/app.css` — `bg-accent`, `border-border`, `text-accent`,
 * `bg-danger` — och aldrig på en palettfärg. Texten ovanpå en fylld yta bär
 * `text-ink-on-accent` och `text-ink-on-danger`, och de två hör till
 * `--color-ink`-familjen: en textroll hör dit. `--color-surface` är rollen
 * yta man lägger saker på, och binds den om — mörkt läge, eller designerns
 * femte bild — blir texten på den blå knappen mörk.
 *
 * **Vänteläget bor här.** `pending` stänger knappen medan servern svarar —
 * en knapp som går att trycka två gånger är en dubbelpostning (issue 68a
 * § Beslut 5) — och `:disabled` stänger den. Ordet byter anroparen, genom
 * slotten: bara den vet vad knappen gör.
 *
 * **Träffytan är `min-h-11` i båda storlekarna**, 44 px ur issue 68a
 * § Beslut 3. Storlekarna skiljer sig i luft och textstorlek och aldrig i
 * höjd: en radåtgärd på en telefon är samma tumme som den fristående
 * knappen.
 *
 * **Fokusringen är `--color-focus` och får aldrig tas bort.** `outline-none`
 * utan en ring som tar över lämnar fokus osynligt för den som tabbar
 * ([[ADR-0042 Designsystemet]] § Beslut). Här är den `focus-visible:`, som i
 * de fyra kontrollerna: webbläsarens egen heuristik låter ett textfält matcha
 * även vid musklick, medan en knapp slipper ringen efter ett klick.
 * FormFields felmeddelande bär `focus:` — det fokuseras av kod.
 */
const props = defineProps({
    variant: { type: String, default: 'primary' },
    size: { type: String, default: 'md' },
    /*
     * `button` och inte `submit` som standard: en tyst åtgärd inuti ett
     * formulär ska inte skicka det av misstag. Formulären säger `submit`
     * själva.
     */
    type: { type: String, default: 'button' },
    pending: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const VARIANTS = {
    primary: 'bg-accent text-ink-on-accent hover:bg-accent/90',
    secondary: 'border border-border bg-surface text-ink hover:bg-surface-sunken',
    quiet: 'text-accent hover:underline',
    danger: 'bg-danger text-ink-on-danger hover:bg-danger/90',
};

/*
 * Två storlekar: `md` för den fristående åtgärden och `compact` för den som
 * står i en rad. Den mindre kan inte heta `sm` — GenomgangTest läser `sm:`
 * som en brytpunkt, och den enda brytpunkten uppåt är `md:`.
 */
const SIZES = {
    md: 'px-4 text-body',
    compact: 'px-3 text-meta',
};

const variantClasses = computed(() => [VARIANTS[props.variant], SIZES[props.size]]);
</script>

<template>
    <button
        :type="type"
        :disabled="pending || disabled"
        class="inline-flex min-h-11 items-center justify-center rounded-control font-medium outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
        :class="variantClasses"
    >
        <slot />
    </button>
</template>
