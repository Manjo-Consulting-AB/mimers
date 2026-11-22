<script setup>
import ContainerCover from './ContainerCover.vue';

/*
 * Hjälteramen, se issue 435 · [[ADR-0090 Dashboardens hjälte]] § 1 och 3.
 *
 * **Bilden, toningen och textens placering bor här.** Containerns hjälte och
 * dashboardens hjälte ritar samma huvud: en bild som fyller ytan, toningen ur
 * [[ADR-0057 Ikoner och typsnitt]] § 6, och en innehållsspalt ovanpå den med
 * vit text. Den som ändrar det ena ser det andra — därför en komponent och
 * inte två kopior (ADR-0090 § Konsekvenser).
 *
 * **Anroparen äger höjden och innehållet.** Rammen väljer ingen egen höjd;
 * `min-h-*` kommer med anroparens `class` och faller igenom till rotens
 * `<section>` (samma regel som `ContainerCover` följer för sin ruta). Allt som
 * står i hjälten kommer ur slotten, så containerns namn, art och beskrivning
 * bor kvar i `ContainerHero` och dashboardens hälsning, rubrik, brickor och
 * donut i `DashboardHero`.
 *
 * **Antingen en bild med fokuspunkt och zoom, eller en bildadress.** `cover`
 * är en bilaga av samma form som `ContainerCover` ritar — containerns `cover`
 * eller personens `hero` — och fokuspunkt och zoom följer med in i
 * `ContainerCover`, som redan äger den ritningen (issue 433 och 434). Saknas
 * `cover` och `src` är satt ritas adressen centrerad: det är standardbilden
 * ur ADR-0090 § 3, som inte är en bilaga och därför inte har något utsnitt.
 * Saknas båda ritar `ContainerCover` den neutrala ytan ur
 * [[ADR-0047 Containerns bild]] § Beslut, precis som containerns hjälte gör
 * utan bild.
 *
 * **Toningen ligger över bilden och under texten.** Den håller full täthet en
 * bit in — till 20 %, nästan full till 30 % — och planar sedan ut, så texten
 * står på den täta delen och bilden syns i hjältens högra del. Samma klasser
 * som `ContainerHero` bar före utbrytningen, så containerns hjälte ritas
 * exakt som förut.
 */
const props = defineProps({
    /*
     * Bilden med fokuspunkt och zoom: containerns `cover` eller personens
     * `hero`, eller null. Skickas rakt vidare till `ContainerCover`.
     */
    cover: { type: Object, default: null },
    /* Containerns valda ikon; ritas bara när bilden saknas. */
    icon: { type: String, default: null },
    /* Palettnyckeln för ikonen; `ItemIcon` äger fallbacken. */
    color: { type: String, default: null },
    /*
     * En bildadress utan utsnitt, ritad centrerad. Dashboardens standardbild
     * är den enda anroparen; den används bara när `cover` saknas.
     */
    src: { type: String, default: null },
    /* Varianten `ContainerCover` ber om; hjältarna vill ha `medium`. */
    variant: { type: String, default: 'medium' },
});
</script>

<template>
    <!--
        `overflow-hidden` sitter på bild- och toningslagren och inte på
        sektionen: bilden ska ha hjältens rundning, och sektionens egen
        bakgrund klipps av `rounded-card` ändå.
    -->
    <section class="relative rounded-card bg-shell">
        <!--
            Bilden, som ett lager under texten. `aria-hidden` för att ytan är
            pynt: namnet och rubriken står intill i slotten, och bilden bär
            redan `alt=""` av samma skäl (issue 68a § Beslut 8).
        -->
        <span class="absolute inset-0 overflow-hidden rounded-card" aria-hidden="true">
            <img
                v-if="cover === null && src"
                :src="src"
                alt=""
                class="h-full w-full object-cover"
            >

            <ContainerCover
                v-else
                :cover="cover"
                :icon="icon"
                :color="color"
                :variant="variant"
            />
        </span>

        <!--
            Toningen ur [[ADR-0057 Ikoner och typsnitt]] § 6: tät till vänster
            där texten står, genomskinlig till höger där bilden syns.
        -->
        <span
            class="absolute inset-0 rounded-card bg-linear-to-r from-shell from-20% via-shell/90 via-30% to-transparent"
            aria-hidden="true"
        ></span>

        <div class="relative flex flex-col gap-4 p-6 text-ink-on-accent">
            <slot />
        </div>
    </section>
</template>
