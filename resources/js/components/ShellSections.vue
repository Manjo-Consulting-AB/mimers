<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import UiListRow from './UiListRow.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Skalets sektioner, se [[ADR-0048 Mobilen och plusknappen]] § 1 och
 * [[M23 Mobilen och kartan]] § 151.
 *
 * **En lista, två ytor.** Över `md:` ritar AppLayout den i sidhuvudet; under
 * `md:` ritar MobileMenu samma rader i sidomenyn. Sektionerna kommer ur SAMMA
 * data — listan `sections` nedan — så att ingenting finns i den ena och
 * saknas i den andra: en rad som läggs till här syns på båda ställena, och en
 * rad som tas bort försvinner från båda. Det är `Klart när`-punkten, och den
 * är därför komponenten finns och inte två avskrifter.
 *
 * **Ordningen är desktopens**, `dashboard, tasks, containers, transfers,
 * search, settings`, samma ordning raderna har haft sedan issue 51 och 122 och
 * samma ord som sidorna de leder till. [[ADR-0048 Mobilen och plusknappen]]
 * § 1 ritar mobilmenyn med *Översikt* och *Sök* främst; det är samma två rader
 * i en annan ordning, och ordningen följer skalet i stället för bilden. Skälet
 * är bindande och inte kosmetiskt: `Klart när` kräver att de två ytorna visar
 * samma sektioner i samma ordning, och att skalet över `md:` är oförändrat —
 * en egen ordning i menyn hade brutit det ena eller det andra.
 *
 * **Etiketten slås upp ur `nav.<key>`** och kommer aldrig ur en sträng här
 * ([[ADR-0013 Språk och i18n]]): texten formuleras på servern och slås bara
 * upp på klienten, precis som `container.nav.<key>` i ContainerLayout.
 *
 * **Nyligen besökta hör hit och kommer i issue 160.** Den ritas mellan
 * `search` och `settings`, på samma plats i båda ytorna — [[ADR-0049 Nyligen
 * besökta]] § Beslut lägger den direkt ovanför favoriterna, och den här issuen
 * lämnar bara platsen i ordningen.
 *
 * `part` skiljer de två blocken åt. `rows` är navigeringen, `favorites` är
 * `FAVORITER`-sektionen ur issue 106. De ritas i olika delar av skalet — raden
 * i sidhuvudet och bandet under det — och därför är de två anrop av samma
 * komponent i stället för två komponenter: sektionen är densamma, och det är
 * bara var den står som skiljer.
 */
const props = defineProps({
    /*
     * Vilket block som ritas: `rows` (förval) eller `favorites`.
     */
    part: { type: String, default: 'rows' },
});

const { t } = useTranslations();
const page = usePage();

const user = computed(() => page.props.auth.user);

/*
 * Den delade proppen `favorites`, filtrerad genom `ResolveItemScope` på
 * servern (issue 106). Vyn prövar inte omfånget en gång till: en andra regel
 * om vad man når är en andra sanning om samma sak.
 */
const favorites = computed(() => page.props.favorites ?? []);

const sections = [
    { key: 'dashboard', href: '/dashboard' },
    { key: 'tasks', href: '/tasks' },
    { key: 'containers', href: '/containers' },
    { key: 'transfers', href: '/transfers' },
    { key: 'search', href: '/search' },
    // Här ritas *Nyligen besökta* — issue 160, se docblocken ovan.
    { key: 'settings', href: '/settings' },
];
</script>

<template>
    <ul
        v-if="props.part === 'rows'"
        class="flex flex-col gap-1 text-sm md:flex-row md:items-center md:gap-4"
    >
        <li v-for="section in sections" :key="section.key">
            <Link
                v-if="user"
                :href="section.href"
                class="inline-flex min-h-11 items-center hover:underline"
            >
                {{ t(`nav.${section.key}`) }}
            </Link>
        </li>

        <li v-if="user">
            <span class="inline-flex min-h-11 items-center text-slate-600">{{ user.name }}</span>
        </li>

        <!--
            Utloggningen är en <Link method="post">, inte ett eget formulär:
            /logout är en POST-rutt (routes/web.php) och Inertia skickar
            CSRF-tokenet åt oss. Utan den går det att logga in men inte ut i
            webbläsaren — och i sidomenyn är raden den enda vägen ut.
        -->
        <li v-if="user">
            <Link
                href="/logout"
                method="post"
                as="button"
                class="inline-flex min-h-11 items-center hover:underline"
            >
                {{ t('auth.logout') }}
            </Link>
        </li>

        <li v-else>
            <Link href="/login" class="inline-flex min-h-11 items-center hover:underline">
                {{ t('nav.login') }}
            </Link>
        </li>
    </ul>

    <!--
        FAVORITER, se issue 106 och [[M17 Designsystemet]] § 106. Sektionen
        ritas bara när listan har rader: en rubrik över en tom lista är en yta
        som lovar något den inte har, och den som inte har några favoriter ska
        inte mötas av ett tomt fack. Ingen räknare och ingen antydan om hur
        många som fallit bort ur omfånget (issue 73 § Beslut 6).
    -->
    <nav
        v-else-if="favorites.length"
        :aria-label="t('nav.favorites')"
        class="mx-auto w-full max-w-3xl px-4 pt-6"
    >
        <h2 class="text-meta font-semibold uppercase tracking-wide text-ink-subtle">
            {{ t('nav.favorites') }}
        </h2>

        <ul class="flex flex-col">
            <UiListRow v-for="favorite in favorites" :key="favorite.url">
                <template #title>
                    <Link :href="favorite.url" class="flex min-h-11 items-center hover:underline">
                        {{ favorite.name }}
                    </Link>
                </template>
            </UiListRow>
        </ul>
    </nav>
</template>
