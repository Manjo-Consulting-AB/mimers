<script setup>
import { computed } from 'vue';

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
 * (ADR-0047 § Beslut). Ytan är `surface-sunken` och glyfen är det allmänna
 * containertecknet — inte artens. `container.kind` är ett FRITT textfält
 * ([[ADR-0036 Containerns art]]), så det finns ingen tabell att slå upp en
 * ikon i, och att bygga en vore att lägga domänen i koden, precis det
 * [[ADR-0033 Produktens omfång]] förbjöd i artens värdelista.
 *
 * **Den begärda varianten ritas om den finns, annars originalet.** Anroparen
 * säger vilken variant den vill ha (`variant`, förval `thumb`), och `variants`
 * bär de varianter som FINNS (samma form som `variants`-proppen på itemets
 * detaljvy, issue 61b § Beslut 1). Finns den begärda varianten i listan ritas
 * `?variant=<den>`, annars originalet. Ingen kedja `medium` → `thumb`:
 * `App\Jobs\GenerateImageDerivatives` skriver aldrig en variant som vore en
 * förstoring, så en bild utan `medium` är antingen mindre än 1024 px
 * (originalet duger) eller ännu inte bearbetad (originalet är det enda som
 * finns). Ett nyuppladdat foto har inga derivat förrän kön kört, och en
 * `<img>` mot en variant som saknas är en trasig bild (issue 19a § Beslut 5) —
 * originalet är alltid en giltig URL, så fallbacken är ett faktum och ingen
 * gissning.
 *
 * **`medium` på de stora ytorna, `thumb` på de små** (fynd från testarna
 * 2026-10-03, issue 681): hjälten (`ContainerHero.vue`) och korten
 * (`ContainerCard.vue`, `pages/Containers/Index.vue`) breder ut sig upp till
 * 1200 px och ber därför om `medium` (1024 px) — en `thumb` (320 px) sträcks
 * och blir suddig. De små fyrkanterna — toppraden i `ContainerLayout.vue`
 * (`h-10 w-10`) och förhandsvisningen i `Containers/Edit.vue` (`h-16 w-16`) —
 * behåller förvalet `thumb` och säger ingenting.
 *
 * **Adressen byggs här och går alltid till appdomänen.** `/files/{ulid}` svarar
 * 302 till en signerad länk på filoriginet, och signaturen präglas där
 * behörigheten prövas (issue 61a § Beslut 1 och 4). Att bygga filoriginets URL
 * i klienten hade varit en andra och osignerad väg till samma byten.
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
     * Varianten anroparen vill rita. Förvalet `thumb` behåller dagens beteende
     * för varje anropare som inte säger något — de små fyrkanterna i skalets
     * topprad och i förhandsvisningen.
     */
    variant: { type: String, default: 'thumb' },
});

const url = computed(() => {
    if (! props.cover) {
        return null;
    }

    const variant = props.cover.variants?.includes(props.variant) ? props.variant : null;

    return variant === null
        ? `/files/${props.cover.ulid}`
        : `/files/${props.cover.ulid}?variant=${variant}`;
});
</script>

<template>
    <img v-if="url" :src="url" alt="" class="h-full w-full object-cover">

    <span
        v-else
        aria-hidden="true"
        class="flex h-full w-full items-center justify-center bg-surface-sunken text-ink-subtle"
    >
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="h-1/3 w-1/3 max-h-6 max-w-6"
        >
            <path d="M3 8.5 12 4l9 4.5v7L12 20l-9-4.5z"></path>
            <path d="M3 8.5 12 13l9-4.5"></path>
            <path d="M12 13v7"></path>
        </svg>
    </span>
</template>
