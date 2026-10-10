<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Skalets containerlista, se issue 169 · [[ADR-0050 Desktopdesignen]] § 1 och
 * [[ADR-0036 Containerns art]].
 *
 * **En lista, två ytor** — samma konstruktion som ShellSections: AppLayout
 * ritar den i sidopanelen över `md:`, MobileMenu i
 * sidomenyn under, och båda ur SAMMA propp. En sektion som finns i den ena
 * och saknas i den andra är ett fel ([[ADR-0048 Mobilen och plusknappen]] § 1),
 * och det är därför den här filen finns och inte två avskrifter.
 *
 * **Listan är en OPTIONAL prop, och den hämtas först när ytan ritas.**
 * `shellContainers` delas av HandleInertiaRequests som `Inertia::optional()`
 * — av samma skäl som `recentVisits` (issue 160 · [[ADR-0049 Nyligen
 * besökta]]): raden behövs bara i de två ytorna, och en vanlig sidladdning
 * ska inte bära den. `load` är ytans svar på frågan "ritas jag?": AppLayout
 * sätter den när sidopanelen ritas, MobileMenu när menyn öppnas. Är den sann
 * en enda gång ställs frågan — `requested` ser till att en stängd och åter
 * öppnad meny inte frågar servern igen om samma lista.
 *
 * **Proppen är `undefined` till dess att svaret är här, och `[]` när det
 * kommit och är tomt** — samma skillnad som i RecentVisitList: en rubrik över
 * en lista som är på väg hade varit ett svar komponenten inte har. Blocket
 * ritas därför på `loaded` och inte på listans längd (issue 677): den sista
 * raden är *Add container*, och den finns för varje inloggad. Är listan tom
 * står högens rubrik (*My containers*) ensam över den raden, i stället för
 * ingenting alls.
 *
 * **Rubriken är artens eget ord** och slås aldrig upp i `lang/`
 * ([[ADR-0036 Containerns art]]): fältet är fritt och kan innehålla vad som
 * helst, så en översättning hade varit ett påstående om vilka arter som
 * finns. Undantaget är högen, som servern skickar som `kind: null` och som
 * skalet formulerar själv (`dashboard.containers.others`) — samma ord som
 * dashboardens kort använder.
 *
 * **Adressen byggs här och inte på servern**, till skillnad från favoriternas
 * och besökens (issue 51 § Beslut 7): `/containers/{ulid}` är ruttens enda
 * variabel och ULID:n står i raden, precis som i `ContainerCard`. Servern
 * hade behövt skicka samma ULID två gånger — en gång som data och en gång
 * inbakad i en adress.
 *
 * **Den aktiva containern bär `aria-current="page"` och en token-färg**
 * ([[ADR-0050 Desktopdesignen]] § 1, beslut 3). Vilken container som är aktiv
 * avgörs av `page.url` — samma form som `currentPath` i ShellSections: vägen
 * utan querysträng, jämförd mot `/containers/{ulid}` och dess undersidor.
 * Serverns `activeContainer` läses INTE här (issue 676): proppen sätts av den
 * kontroller som öppnade containern (issue 83) och är sann även på
 * `/dashboard`, där ingen containerrad är den användaren står på — två rader
 * markerade samtidigt. Proppen lever kvar för Containers/Index.vue och rörs
 * inte; det är bara den här listan som slutar fråga den.
 *
 * **Varje rad är en `<Link>` med fokusring.** Träffytan är `min-h-11` (44 px,
 * issue 68a § Beslut 3) och ringen är `--color-focus` ([[ADR-0042
 * Designsystemet]] § Beslut) — samma form som raderna i ShellSections, och
 * `outline-none` utan en ring som tar över river tangentbordsarbetet.
 *
 * **Hover är en bakgrund, inte en understrykning** (issue 676): klassen är
 * `hover:bg-shell-active/50` i `:class`-grenen för en INAKTIV rad, så den
 * aktiva raden behåller sin fulla markering när pekaren vilar på den.
 * Tailwinds `hover:` gäller bara enheter med pekare (`@media (hover: hover)`),
 * så mobilmenyn får ingen kvarhängande bakgrund efter ett tryck.
 */
const props = defineProps({
    /*
     * Sant när ytan som bär listan ritas: sidomenyn är öppen, eller
     * desktopens sidopanelen är ritad. Se docblocken ovan.
     */
    load: { type: Boolean, default: false },
});

const { t } = useTranslations();
const page = usePage();

const groups = computed(() => page.props.shellContainers ?? []);

/*
 * Har svaret kommit? `undefined` är "frågan är ställd men inte besvarad", och
 * `[]` är ett svar: användaren har inga containrar. Blocket ritas i båda fallen
 * — se docblocken — men bara det sista fallet betyder att raden *Add container*
 * står ensam.
 */
const loaded = computed(() => page.props.shellContainers !== undefined);

/*
 * Sidans väg, utan querysträngen — samma form som `currentPath` i
 * ShellSections: `page.url` bär frågan och dess parametrar.
 */
const currentPath = computed(() => page.url.split('?')[0]);

/* "Frågan är ställd" — en gång per komponent, se docblocken. */
const requested = ref(false);

watch(
    () => props.load,
    (load) => {
        if (!load || requested.value) {
            return;
        }

        requested.value = true;

        router.reload({ only: ['shellContainers'] });
    },
    { immediate: true },
);

/*
 * Är den här containern den sidan användaren står på? Sidans väg och
 * ingenting annat — se docblocken. På `/dashboard`, `/tasks`, `/search` och
 * `/containers` är ingen rad aktiv.
 */
function isActive(container) {
    return currentPath.value === `/containers/${container.ulid}`
        || currentPath.value.startsWith(`/containers/${container.ulid}/`);
}
</script>

<template>
    <nav v-if="loaded" :aria-label="t('nav.containers')" class="w-full pt-6">
        <!--
            En grupp per art, och högen först — ordningen kommer ur servern
            (App\Actions\Container\ContainerKindGroups) och skrivs inte om
            här. Rubriken är artens eget ord; en art med en enda container
            har ingen grupp alls, för servern lägger den i högen.
        -->
        <div v-for="group in groups" :key="group.kind ?? 'others'" class="flex flex-col gap-1">
            <h2 class="text-meta font-semibold tracking-wide text-ink-subtle uppercase">
                {{ group.kind ?? t('dashboard.containers.others') }}
            </h2>

            <ul class="flex flex-col gap-1 px-2 text-sm">
                <li v-for="container in group.containers" :key="container.ulid" class="flex">
                    <Link
                        :href="`/containers/${container.ulid}`"
                        prefetch
                        :aria-current="isActive(container) ? 'page' : undefined"
                        class="inline-flex min-h-11 w-full items-center rounded-control outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                        :class="isActive(container) ? 'bg-shell-active text-white' : 'hover:bg-shell-active/50'"
                    >
                        {{ container.name }}
                    </Link>
                </li>
            </ul>
        </div>

        <!--
            Högens rubrik står ensam när användaren inte har några containrar
            (issue 677): blocket ritas ändå, för raden under är *Add
            container*. Samma ord och samma klasser som grupprubriken ovanför.
        -->
        <h2 v-if="!groups.length" class="text-meta font-semibold tracking-wide text-ink-subtle uppercase">
            {{ t('dashboard.containers.others') }}
        </h2>

        <!--
            Sista raden i listan (issue 677), i samma `<ul>`-form som raderna
            ovanför: vägen till att skapa en container, alltid för en inloggad
            — `GET /containers/create` har ingen grind, och `POST /containers`
            prövar `ContainerPolicy::create()`. Klassen är containrarnas, plus
            `gap-2` för ikonen, och hover är en bakgrund som på varje annan rad.
        -->
        <ul class="flex flex-col gap-1 px-2 text-sm">
            <li class="flex">
                <Link
                    href="/containers/create"
                    prefetch
                    class="inline-flex min-h-11 w-full items-center gap-2 rounded-control outline-none hover:bg-shell-active/50 focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        class="h-5 w-5"
                        aria-hidden="true"
                    >
                        <path d="M12 5v14"></path>
                        <path d="M5 12h14"></path>
                    </svg>

                    {{ t('nav.add_container') }}
                </Link>
            </li>
        </ul>
    </nav>
</template>
