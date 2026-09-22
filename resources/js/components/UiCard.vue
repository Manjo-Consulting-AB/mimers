<script setup>
/*
 * Kortet, se issue 99 och [[ADR-0042 Designsystemet]] § Beslut.
 *
 * **Rubrikraden är slots och inte en propp per variant.** Bilderna har kort
 * med rubrik och *Visa alla*, kort med rubrik och en räknare, och kort med
 * bara en rubrik — det som skiljer dem är VAD som står i raden, och det vet
 * bara anroparen. `heading` bär rubriken, `action` den valfria åtgärden till
 * höger, och standard-sloten innehållet. En `variant`-propp hade varit
 * bildernas varianter gjorda till kod, och en propp per variant hade vuxit
 * varje gång en femte bild kom.
 *
 * **Åtgärden ritas bara när någon skickar den.** `$slots.action` frågar om
 * slotten FINNS och aldrig om den är tom: en tom rad till höger är samma sak
 * som ingen rad, men med luft omkring sig.
 *
 * Rubriken är en `<h2>`. Sidan äger sin `<h1>` (GenomgangTest), och korten
 * ligger under den — därför ingen rubriknivå som propp.
 *
 * Ingen sträng står i filen: rubriken och åtgärdens ord kommer ur slotarna
 * och översätts av anroparen (SprakTest).
 */
</script>

<template>
    <section class="rounded-card border border-border bg-surface p-4">
        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
            <h2 class="text-title font-semibold text-ink">
                <slot name="heading" />
            </h2>

            <div v-if="$slots.action" class="text-meta">
                <slot name="action" />
            </div>
        </div>

        <div class="mt-3">
            <slot />
        </div>
    </section>
</template>
