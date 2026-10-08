<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Plusknappen, se [[ADR-0048 Mobilen och plusknappen]] § 2 och
 * [[M23 Mobilen och kartan]] § 152.
 *
 * **Knappen vet inte vad den gör — den får sitt mål.** Målet kommer ur
 * `App\Support\Frontend\CreateTarget` som sidans `create`-propp, och formen
 * avgör vad knappen blir:
 *
 *   - `{ kind: 'container' | 'item' | 'task', href }` → en `<Link>` till
 *     formuläret
 *   - `{ kind: 'menu', rows }`                       → en `<button>` som
 *     öppnar menyn
 *
 * Det är hela skillnaden mellan en knapp som skapar en container, en som
 * skapar ett item, en som öppnar uppgiftsformuläret och en som öppnar en meny:
 * samma knapp, fyra mål. En knapp
 * som själv räknade ut sitt mål ur adressen hade varit samma fråga ställd på
 * två ställen — och den hade svarat fel på varje sida vars adress inte säger
 * vad man får skapa.
 *
 * **Knappen öppnar ingenting själv.** Menyns öppna-läge bor i skalet
 * (AppLayout), som MobileMenus gör: knappen är en knapp, menyn en yta, och
 * den som äger båda är layouten. Knappen skickar med sig sitt eget element så
 * att arket kan fästas under den och lämna tillbaka fokus till den.
 *
 * **Träffytan är 44 px i båda skalarna** (issue 68a § Beslut 3): knappen ritas
 * i flikraden på mobilen och i sidhuvudet över `md:`, och i båda fallen är den
 * ett mål en tumme eller en mus ska träffa.
 *
 * Texten är ankarets namn och kommer ur `lang/` ([[ADR-0013 Språk och i18n]]):
 * knappen är ett plustecken och bär inget synligt ord i flikraden, så
 * `aria-label` är det enda en skärmläsare möter.
 *
 * **Bredden fyller sin plats först när anroparen säger det.** Flikraden
 * skickar in `class="w-full"` så att platsen i mitten fylls; sidhuvudet gör
 * det inte, och där står knappen i sin egen storlek bland sökfältet och
 * klockan. Komponenten väljer alltså inte sin bredd — den fyller den yta den
 * ställs i, vilket är samma regel som för varje annan radåtgärd.
 */
const props = defineProps({
    /* Målet ur App\Support\Frontend\CreateTarget. */
    create: { type: Object, required: true },
});

const emit = defineEmits(['open']);

const { t } = useTranslations();

const isMenu = computed(() => props.create.kind === 'menu');
</script>

<template>
    <!--
        Plusknappen i flikraden är en fylld cirkel (docs/Design/mobil.png,
        bild 1): den är radens enda handling och ska synas bland fyra länkar
        som alla är text och linje.
    -->
    <button
        v-if="isMenu"
        type="button"
        class="flex min-h-11 min-w-11 flex-col items-center justify-center gap-0.5 px-2 py-1"
        :aria-label="t('create.label')"
        @click="emit('open', $event.currentTarget)"
    >
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="h-9 w-9 rounded-pill bg-accent p-1.5 text-ink-on-accent"
            aria-hidden="true"
        >
            <path d="M12 5v14"></path>
            <path d="M5 12h14"></path>
        </svg>
    </button>

    <Link
        v-else
        :href="create.href"
        class="flex min-h-11 min-w-11 flex-col items-center justify-center gap-0.5 px-2 py-1"
        :aria-label="t('create.label')"
    >
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="h-9 w-9 rounded-pill bg-accent p-1.5 text-ink-on-accent"
            aria-hidden="true"
        >
            <path d="M12 5v14"></path>
            <path d="M5 12h14"></path>
        </svg>
    </Link>
</template>
