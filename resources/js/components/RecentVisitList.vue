<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import UiListRow from './UiListRow.vue';
import { useTranslations } from '../composables/useTranslations.js';
import { useRelativeDate } from '../composables/useRelativeDate.js';

/*
 * Skalets sektion *Nyligen besökta*, se [[ADR-0049 Nyligen besökta]] och
 * [[M23 Mobilen och kartan]] § 160.
 *
 * **Listan är personens och ligger på servern.** Den kommer ur
 * `recent_visit` och byggs av App\Actions\Item\ListRecentVisits, som filtrerar
 * den genom `ResolveItemScope` som favoritlistan (issue 106): ett item man
 * förlorat åtkomsten till — eller som ligger i papperskorgen — finns inte i
 * listan, och ingen räknare berättar att något föll bort (issue 73 § Beslut 6).
 * Vyn prövar inte omfånget en gång till: en andra regel om vad man når är en
 * andra sanning om samma sak.
 *
 * **Listan är en OPTIONAL prop, och den hämtas först när ytan ritas**
 * ([[ADR-0049 Nyligen besökta]] § Beslut). `load` är den ytans svar på frågan
 * "ritas jag?": AppLayout sätter den när desktopens sidopanel ritas, och
 * MobileMenu när sidomenyn öppnas. Är den sann en enda gång ställs frågan —
 * `requested` ser till att en stängd och åter öppnad meny inte frågar servern
 * igen om samma lista, samma mönster som `loaded` i NotificationBell.
 *
 * **Proppen är `undefined` till dess att svaret är här, och `[]` när det
 * kommit och är tomt.** Skillnaden är hela villkoret nedan: en rubrik över en
 * lista som är på väg hade varit ett svar komponenten inte har, och en rubrik
 * över en tom lista är en yta som lovar något den inte har — samma regel som
 * favoritsektionen i ShellSections.
 *
 * **Raden visar itemets namn, containerns namn och hur länge sedan besöket
 * var.** Vägen genom trädet står inte där: ett item kan ha flera
 * ([[ADR-0041 Itemets vy]]). Tiden skrivs av `useRelativeDate()` och aldrig
 * här — servern skickar tidsstämpeln som ISO 8601 och regeln för hur den
 * skrivs bor i EN modul (issue 104 · [[ADR-0042 Designsystemet]]).
 */
const props = defineProps({
    /*
     * Sant när ytan som bär listan ritas: sidomenyn är öppen, eller
     * desktopens sidopanel är ritad. Se docblocken ovan.
     */
    load: { type: Boolean, default: false },
});

const { t } = useTranslations();
const { eventDate } = useRelativeDate();
const page = usePage();

const rows = computed(() => page.props.recentVisits ?? []);

/* "Frågan är ställd" — en gång per komponent, se docblocken. */
const requested = ref(false);

watch(
    () => props.load,
    (load) => {
        if (!load || requested.value) {
            return;
        }

        requested.value = true;

        router.reload({ only: ['recentVisits'] });
    },
    { immediate: true },
);
</script>

<template>
    <nav
        v-if="rows.length"
        :aria-label="t('nav.recent_visits')"
        class="mx-auto w-full max-w-3xl px-4 pt-6"
    >
        <h2 class="text-meta font-semibold uppercase tracking-wide text-ink-subtle">
            {{ t('nav.recent_visits') }}
        </h2>

        <ul class="flex flex-col">
            <UiListRow v-for="visit in rows" :key="visit.url">
                <template #title>
                    <Link :href="visit.url" class="flex min-h-11 items-center hover:underline">
                        {{ visit.name }}
                    </Link>
                </template>

                <template #subtitle>{{ visit.container }}</template>

                <template #meta>{{ eventDate(visit.visited_at).text }}</template>
            </UiListRow>
        </ul>
    </nav>
</template>
