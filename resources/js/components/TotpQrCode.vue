<script setup>
import { computed } from 'vue';
import { encode } from 'uqr';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * QR-koden för tvåstegsverifieringens uppsättning, se issue 261 (GitHub #786)
 * och [[ADR-0023 TOTP-bibliotek]] § Beslut.
 *
 * ADR:en säger att servern bara exponerar en `otpauth://`-URI och att klienten
 * ritar QR-koden ur den. Ett senare issuebeslut lämnade koden utanför; det
 * beslutet ersätts av det här, och användaren väljer själv om hon skannar
 * koden eller skriver in nyckeln för hand (Security.vue ritar båda).
 *
 * **`uqr` och inget annat paket.** `encode()` ger matrisen: en tvådimensionell
 * array av booleaner där `true` är en mörk modul. Vi ritar den som `<svg>` med
 * en `<rect>` per mörk modul. Markupen byggs alltså i mallen, aldrig som en
 * färdig sträng ur data som sedan tolkas som HTML.
 *
 * **Fyra modulers tyst zon.** Standarden kräver minst fyra moduler vitt runt
 * koden; utan dem har kameran svårt att hitta hörnen. `border: 4` lägger dem i
 * matrisen, så ingen räknar om koordinater för hand.
 *
 * **Vit bakgrund även i mörkt läge.** En kamera behöver kontrasten; temat får
 * inte tona ned den.
 *
 * Etiketten kommer ur `t()` och inte ur en propp: den är en enda, fast mening
 * och ingen anropare har något att välja (M10 § ingressen).
 */
const props = defineProps({
    uri: { type: String, required: true },
});

const { t } = useTranslations();

// Tyst zon på fyra moduler, se docblocken ovan.
const code = computed(() => encode(props.uri, { border: 4 }));

const darkModules = computed(() => {
    const modules = [];

    code.value.data.forEach((row, y) => {
        row.forEach((dark, x) => {
            if (dark) {
                modules.push({ x, y });
            }
        });
    });

    return modules;
});
</script>

<template>
    <svg
        :viewBox="`0 0 ${code.size} ${code.size}`"
        width="192"
        height="192"
        class="shrink-0 rounded bg-white"
        role="img"
        :aria-label="t('settings.security.totp.qr_label')"
    >
        <rect
            v-for="module in darkModules"
            :key="`${module.x}-${module.y}`"
            :x="module.x"
            :y="module.y"
            width="1"
            height="1"
        />
    </svg>
</template>
