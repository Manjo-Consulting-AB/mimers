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
 * **Rubriken ritas bara när någon skickar den** (granskningsfynd 2026-10-08).
 * En tom `<h2>` är ett tillgänglighetsfel — skärmläsaren annonserar en rubrik
 * utan ord — så villkoret står på elementet. Fångstkortet i `/inbox` skickar
 * ingen rubrik och fick tidigare en tom sådan.
 *
 * **Bilden kom med issue 159 · [[ADR-0047 Containerns bild]].** `media` är en
 * tredje valfri slot, och den ligger ÖVER rubrikraden och kant i kant med
 * kortets ram — så kortets foto ser ut som i `docs/Design/main.jpeg`, där
 * bilden fyller kortets överkant. Kortet väljer ingen höjd och ingen bild: är
 * slotten tom ritas ingenting, och den som skickar en bild äger dess mått.
 * Kortet har fortfarande inga proppar, och de sex anropare som inte skickar
 * `media` ritas precis som förut.
 *
 * Rubriken är en `<h2>`. Sidan äger sin `<h1>` (GenomgangTest), och korten
 * ligger under den — därför ingen rubriknivå som propp.
 *
 * Ingen sträng står i filen: rubriken och åtgärdens ord kommer ur slotarna
 * och översätts av anroparen (SprakTest).
 */
</script>

<template>
    <section class="overflow-hidden rounded-card border border-border bg-surface">
        <!--
            Kortets bild. `overflow-hidden` på ramen skär hörnen, så bilden
            följer kortets radie utan att klippas i en egen ruta.
        -->
        <div v-if="$slots.media" class="border-b border-border bg-surface-sunken">
            <slot name="media" />
        </div>

        <div class="p-4">
            <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <h2 v-if="$slots.heading" class="text-title font-semibold text-ink">
                    <slot name="heading" />
                </h2>

                <div v-if="$slots.action" class="text-meta">
                    <slot name="action" />
                </div>
            </div>

            <div class="mt-3">
                <slot />
            </div>
        </div>
    </section>
</template>
