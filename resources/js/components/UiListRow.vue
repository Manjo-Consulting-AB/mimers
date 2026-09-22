<script setup>
/*
 * Listraden, se issue 99 och [[ADR-0042 Designsystemet]] § Beslut.
 *
 * **Ikon, titel, undertitel och meta är fyra slots och ingen propp för
 * innehållet.** Raderna i bilderna bär länkar, färgprickar, datum och
 * brickor på samma platser; vad som står där vet bara anroparen. Formen är
 * det här kortet äger: ordningen, träffytan och hur raden bryter.
 *
 * **Träffytan är `min-h-11`** — 44 px ur issue 68a § Beslut 3 — och den bor
 * på radens ROT. GenomgangTest mäter en `<button>` eller `<Link>` inuti en
 * rad; den här raden är formen de ställs i, och ytan ska inte bero på vad
 * anroparen stoppar in.
 *
 * **Metan hamnar till höger och faller först.** `flex-wrap` lägger den under
 * titeln på en smal skärm i stället för att skrolla i sidled (issue 68a
 * § Beslut 1).
 *
 * Ikonen är `aria-hidden`: den färgar raden och upprepar aldrig titeln, och
 * en skärmläsare som läste upp den hade läst samma sak två gånger.
 *
 * Raden är en `<li>` och hör i en `<ul>` — ingen annan stans.
 */
</script>

<template>
    <li class="flex min-h-11 flex-wrap items-center gap-x-3 gap-y-1 py-2">
        <span v-if="$slots.icon" class="shrink-0 text-ink-subtle" aria-hidden="true">
            <slot name="icon" />
        </span>

        <span class="flex min-w-0 flex-col">
            <span class="text-title text-ink">
                <slot name="title" />
            </span>

            <span v-if="$slots.subtitle" class="text-meta text-ink-subtle">
                <slot name="subtitle" />
            </span>
        </span>

        <span v-if="$slots.meta" class="ml-auto text-meta text-ink-muted">
            <slot name="meta" />
        </span>
    </li>
</template>
