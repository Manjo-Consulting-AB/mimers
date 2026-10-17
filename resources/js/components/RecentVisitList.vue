<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import UiIcon from './UiIcon.vue';
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
 * MobileMenu när sidomenyn öppnas.
 *
 * **Frågan ställs om efter varje navigering**, samma regel som i
 * ShellContainerList. Layouten är persistent, men en vanlig sidladdning bär
 * inte proppen — att fråga en gång per komponent tömde sektionen på varje sida
 * utom den första. Listan hämtas nu varje gång ytan ritas och sidan saknar
 * proppen, och det senaste svaret står kvar under tiden så att sektionen inte
 * blinkar bort. Ett besök läggs till vid varje navigering, så en ny fråga per
 * sida är dessutom det som håller listan aktuell.
 *
 * **Proppen är `undefined` till dess att svaret är här, och `[]` när det
 * kommit och är tomt.** Skillnaden är hela villkoret nedan: en rubrik över en
 * lista som är på väg hade varit ett svar komponenten inte har, och en rubrik
 * över en tom lista är en yta som lovar något den inte har — samma regel som
 * favoritsektionen i ShellSections.
 *
 * **Ramen blev kolumnens i issue 169.** Sektionen står i desktopens sidopanel
 * och i mobilens sidomeny — båda smala kolumner med sin egen padding — och
 * `mx-auto w-full max-w-3xl px-4` var ett band på full bredd. Utan ändringen
 * hade de fyra sektionerna i samma kolumn haft olika indrag.
 *
 * **Raden visar itemets namn, containerns namn och hur länge sedan besöket
 * var.** Vägen genom trädet står inte där: ett item kan ha flera
 * ([[ADR-0041 Itemets vy]]). Tiden skrivs av `useRelativeDate()` och aldrig
 * här — servern skickar tidsstämpeln som ISO 8601 och regeln för hur den
 * skrivs bor i EN modul (issue 104 · [[ADR-0042 Designsystemet]]).
 *
 * **Raden bär `item`-ikonen** i `UiListRow`s `icon`-slot (issue 289): ett
 * besök är en genväg till ett item, och ikonen upprepar den rollen i stället
 * för att gissa ur namnet.
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

/*
 * Har sidan svaret? `undefined` är "frågan är inte besvarad för den här
 * sidan" — efter varje vanlig navigering, se docblocken.
 */
const answered = computed(() => page.props.recentVisits !== undefined);

/* Det senaste svaret, kvar över navigeringarna — se docblocken. */
const latest = ref(undefined);

watch(
    () => page.props.recentVisits,
    (list) => {
        if (list !== undefined) {
            latest.value = list;
        }
    },
    { immediate: true },
);

const rows = computed(() => latest.value ?? []);

/*
 * Ritas ytan och saknar sidan svaret — fråga. Villkoret blir sant igen efter
 * varje navigering och förblir sant medan omladdningen pågår, så samma sida
 * frågar bara en gång.
 */
watch(
    () => props.load && !answered.value,
    (missing) => {
        if (missing) {
            router.reload({ only: ['recentVisits'] });
        }
    },
    { immediate: true },
);
</script>

<template>
    <!--
        Ramen är `w-full pt-6` och ingen centrerad bandbredd: sektionen står i
        skalets sidopanel över `md:` och i sidomenyn under (issue 151, 169),
        och båda är smala kolumner med sin egen padding. `max-w-3xl` och
        `px-4` var bandets mått på en full bredd, och de hade gett de fyra
        sektionerna olika indrag i samma kolumn.
    -->
    <nav v-if="rows.length" :aria-label="t('nav.recent_visits')" class="w-full pt-6">
        <h2 class="text-meta font-semibold uppercase tracking-wide text-ink-subtle">
            {{ t('nav.recent_visits') }}
        </h2>

        <ul class="flex flex-col">
            <UiListRow v-for="visit in rows" :key="visit.url">
                <template #icon>
                    <UiIcon name="item" />
                </template>

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
