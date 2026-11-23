<script setup>
import { computed } from 'vue';
import UiIcon from './UiIcon.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Hjälplänken i en vy — M81 · issue 468, [[ADR-0094 Hjälpcentret]] § 6.
 *
 * **Vyn länkar med sammanhanget, inte med en artikeladress.** Adressen är
 * `/help/context/{sammanhang}`; servern slår upp artikeln som bär
 * sammanhanget i sin front matter och skickar vidare. En vy behöver alltså
 * aldrig veta vad artikeln heter, och när en fråga får ett nytt id flyttar
 * länken med utan att någon vy ändras.
 *
 * **En vanlig `<a>`, inte Inertias `Link`.** Omdirigeringen sker på servern,
 * och `Link` hade låtit Inertia följa den i klienten — då hade `#ankaret`
 * hamnat efter en sidvisning som redan skett i stället för i adressen.
 *
 * **Ny flik.** Läser man hjälpen mitt i en dialog eller ett formulär ska den
 * ytan stå kvar när man kommer tillbaka. `target="_blank"` och
 * `rel="noopener"` öppnar artikeln i en ny flik utan att ge den åtkomst till
 * den här sidans fönster, och det tillgängliga namnet säger att länken gör
 * det (`help.link.new_tab`).
 *
 * **Tryckytan är minst 44 px** (`min-h-11`), som varje annan länk i appen.
 * `iconOnly` ritar bara ikonen; etiketten blir då det tillgängliga namnet,
 * och ytan får `min-w-11` så att den är lika bred som den är hög.
 *
 * **Ingen sträng står i filen** (issue 52 · [[ADR-0013 Språk och i18n]]):
 * etiketten är proppen `label`, annars `help.link.default`, och orden kommer
 * ur `lang/{locale}/ui.php` via `t()`.
 */
const props = defineProps({
    /* Sammanhanget ur artikelns front matter, till exempel `container.sharing`. */
    context: { type: String, required: true },
    /* Etiketten; `help.link.default` när vyn inte sänder en egen. */
    label: { type: String, default: null },
    /* Bara ikonen, med etiketten som tillgängligt namn. */
    iconOnly: { type: Boolean, default: false },
});

const { t } = useTranslations();

/* Etiketten som syns — och som det tillgängliga namnet bygger på. */
const labelText = computed(() => props.label ?? t('help.link.default'));

/* Namnet säger både vad länken heter och att den öppnas i en ny flik. */
const accessibleLabel = computed(() => t('help.link.new_tab', { label: labelText.value }));
</script>

<template>
    <a
        :href="`/help/context/${props.context}`"
        target="_blank"
        rel="noopener"
        :aria-label="accessibleLabel"
        class="inline-flex min-h-11 items-center gap-1.5 text-accent hover:underline"
        :class="props.iconOnly ? 'min-w-11 justify-center' : null"
    >
        <UiIcon name="help" :size="16" />
        <span v-if="!props.iconOnly">{{ labelText }}</span>
    </a>
</template>
