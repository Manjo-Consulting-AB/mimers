<script setup>
import { computed } from 'vue';
import ItemIcon from './ItemIcon.vue';
import UiIcon from './UiIcon.vue';
import { coverUrl } from './coverPresentation.js';

/*
 * Containerns bild, se [[ADR-0047 Containerns bild]] § Beslut och
 * [[M23 Mobilen och kartan]] § 159.
 *
 * **Formen är ytan och ingenting annat.** Komponenten fyller den ruta
 * anroparen ställer den i (`h-full w-full object-cover`) och väljer aldrig sin
 * egen storlek: samma bild är en liten fyrkant i containerlistans rad, en bred
 * remsa överst på dashboardkortet (`docs/Design/main.jpeg`) och en liten
 * fyrkant i containerns topprad (`docs/Design/mobil.png`, bild 1 och 2).
 * Måtten bor hos anroparen, där de hör hemma.
 *
 * **Utan bild ritas en neutral yta med en ikon, aldrig en tom ram**
 * (ADR-0047 § Beslut). Ordningen är bild, containerns ikon och sist lådan
 * ([[ADR-0064 Containerns ikon]] § 5): en vald ikon ritas med `ItemIcon` i
 * `tile`, sin färg och `fallback="container"`, och en container utan både bild
 * och ikon får det allmänna containertecknet. Bilden vinner alltid, eftersom
 * en container har högst en bild och den är ett val (ADR-0047). Ytan är
 * `surface-sunken` och glyfen skalas med rutan som förut — `h-1/3 w-1/3`,
 * högst 24 px — så att en liten fyrkant och ett kort behåller samma
 * proportioner.
 *
 * Lådan är det allmänna containertecknet — inte artens. `container.kind` är
 * ett FRITT textfält ([[ADR-0036 Containerns art]]), så det finns ingen tabell
 * att slå upp en ikon i, och att bygga en vore att lägga domänen i koden,
 * precis det [[ADR-0033 Produktens omfång]] förbjöd i artens värdelista.
 *
 * **Adressen och variantregeln bor i `coverPresentation.js`.** `coverUrl`
 * väljer den begärda varianten om den finns i `cover.variants` och originalet
 * annars (issue 681) och bygger alltid appdomänens `/files/{ulid}`. Regeln
 * flyttade dit i issue 683 § Beslut 1, när väljaren i arket behövde samma
 * adress: två formuleringar av samma regel hade glidit isär.
 *
 * **Fokuspunkten ritas som `object-position`.** `cover.focus` är `{ x, y }` i
 * procent eller null för mitten (issue 682), och blir bildens `object-position`
 * — den del användaren pekat ut ligger kvar i rutan när `object-cover` beskär.
 * Punkten kommer med proppen, så varje yta som ritar komponenten får den utan
 * att en anropare ändras (issue 683 § Beslut 2).
 *
 * **`medium` på de stora ytorna, `thumb` på de små** (fynd från testarna
 * 2026-10-03, issue 681): hjälten (`ContainerHero.vue`) och korten
 * (`ContainerCard.vue`, `pages/Containers/Index.vue`) breder ut sig upp till
 * 1200 px och ber därför om `medium` (1024 px) — en `thumb` (320 px) sträcks
 * och blir suddig. De små fyrkanterna — toppraden i `ContainerLayout.vue`
 * (`h-10 w-10`) och förhandsvisningen i `Containers/Edit.vue` (`h-16 w-16`) —
 * behåller förvalet `thumb` och säger ingenting.
 *
 * **`alt=""` är rätt och inte en glömska.** Bilden är containerns ansikte och
 * står alltid intill containerns namn, som är den text en skärmläsare ska ha;
 * en alt-text som upprepade namnet hade lästs två gånger (issue 68a § Beslut
 * 8).
 */
const props = defineProps({
    /* `{ ulid, variants }` eller null, ur container-proppen (`cover`). */
    cover: { type: Object, default: null },
    /*
     * Containerns valda ikon — ett Lucide-namn ur `container.icon` — eller
     * `null`. Ritas bara när bilden saknas ([[ADR-0064 Containerns ikon]] § 5).
     */
    icon: { type: String, default: null },
    /* Palettnyckeln ur `container.icon_color`; `ItemIcon` äger fallbacken. */
    color: { type: String, default: null },
    /*
     * Varianten anroparen vill rita. Förvalet `thumb` behåller dagens beteende
     * för varje anropare som inte säger något — de små fyrkanterna i skalets
     * topprad och i förhandsvisningen.
     */
    variant: { type: String, default: 'thumb' },
});

const url = computed(() => coverUrl(props.cover, props.variant));

/*
 * Punkten användaren pekat ut, eller null för mitten. `null` (och inte
 * `{ x: 50, y: 50 }`) när ingen punkt finns: `object-cover` centrerar redan av
 * sig själv, och en stil som säger "mitten" är samma svar två gånger.
 */
const position = computed(() => (props.cover?.focus
    ? { objectPosition: `${props.cover.focus.x}% ${props.cover.focus.y}%` }
    : null));
</script>

<template>
    <img v-if="url" :src="url" :style="position" alt="" class="h-full w-full object-cover">

    <span
        v-else
        aria-hidden="true"
        class="flex h-full w-full items-center justify-center bg-surface-sunken text-ink-subtle"
    >
        <!--
            Containerns ikon i sin färg (issue 317 · [[ADR-0064 Containerns
            ikon]] § 5). Ytan och dess mått ägs av anroparen; glyfen skalas som
            lådan gjorde förut — en tredjedel av rutan, högst 24 px. `ItemIcon`s
            egen ruta fyller samma yta, och `p-0!` tar bort dess inre luft så
            att strecket fyller rutan.
        -->
        <ItemIcon
            v-if="icon"
            :name="icon"
            :color="color"
            :size="24"
            tile
            fallback="container"
            class="h-1/3 w-1/3 max-h-6 max-w-6 shrink-0 p-0! [&_svg]:h-full [&_svg]:w-full"
        />

        <UiIcon v-else name="container" :size="24" class="h-1/3 w-1/3 max-h-6 max-w-6" />
    </span>
</template>
