<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import ContainerCover from '../../components/ContainerCover.vue';
import UiBadge from '../../components/UiBadge.vue';
import UiCard from '../../components/UiCard.vue';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Containerlistan, se issue 54 § Beslut 6, 9 och 10.
 *
 * Sorteringen och urvalet kommer från servern — den här vyn filtrerar
 * ingenting. Det som avgörs HÄR är presentationen, och två saker härleds ur
 * de delade propsen i stället för ur ett eget serverfält (Beslut 10):
 *
 *   - Är containern delad med mig? `ContainerResource` bär ägarkontots ULID i
 *     `account`. Är den ULID:n inte ett av mina konton (`auth.accounts`) är
 *     containern någon annans. Ett `shared`-fält i `/api` som bara webben
 *     behöver är precis den drift [[ADR-0021 Frontendteknik]] § Konsekvenser
 *     varnar för.
 *   - Ägarkontots namn visas bara när användaren är med i MER än ett konto.
 *     Med ett enda konto är namnet brus.
 *
 * Den aktiva raden bär `aria-current` och en synlig etikett. Den aktiva
 * containern läses ur den delade propen `activeContainer`, som bär ULID:t och
 * ingenting annat.
 *
 * **Ingen knapp sätter kontexten** (issue 83). Kontexten är bokföring över
 * vilken container användaren arbetar i, och bokföringen sköter sig själv: den
 * sätts av att containern ÖPPNAS — namnet här är länken dit — och knappen som
 * gjorde det för hand finns inte längre. Markeringen står kvar och visar
 * vilken container som senast öppnades.
 *
 * Redigeringslänken visas efter `can.update`, som kontrollern räknat med
 * policyn (Beslut 9). Flaggan är presentation; rutten auktoriserar ändå.
 *
 * Containernamnet är en länk till containerns EGEN sida — itemlistan, se issue 57a
 * § Beslut 1.
 *
 * **Listan är ett rutnät av kort sedan M24 · [[ADR-0050 Desktopdesignen]].**
 * Raden var en tunn remsa med en liten miniatyr; förlagan är dashboardens kort
 * (`ContainerCard.vue`), så att en container ser likadan ut här och där. Varje
 * rad bär ett `UiCard` med bilden i `#media` över rubrikraden, och rutnätet är
 * en kolumn, två från `md:` och tre från `lg:`.
 *
 * Brytpunkterna är `md:` och `lg:` och inte issue 634 § Beslut 1:s `sm:` och
 * `xl:`: GenomgangTest tillåter två brytpunkter uppåt ([[ADR-0050
 * Desktopdesignen]] § 6), och ett `sm:`/`xl:`-rutnät hade fällt det provet —
 * filen ligger utanför issuen och rörs inte. Samma två steg, samma form.
 *
 * **Kortet visar radens innehåll och ingenting mer.** Dashboardens kort bär
 * antal items och antal uppgifter, men de talen kommer ur
 * App\Actions\Container\ListContainerSummaries och finns inte i den här
 * propens `containers` — att lägga till dem vore en ändring i
 * `ContainerController::index` och en annan issue.
 *
 * Bilden är containerns egen och kommer färdig i `container.cover` ur
 * `ContainerResource` — kontrollern eager-loadar den, så listan kostar ett
 * konstant antal frågor oavsett antal containers. Utan bild ritar
 * `ContainerCover` den neutrala ytan, aldrig en tom ram ([[ADR-0047
 * Containerns bild]] § Beslut).
 *
 * Bilden är INTE en länk: kortet har redan två mål (namnet och
 * redigeringslänken), och en tredje väg till samma sida hade varit ett mål en
 * tumme kan träffa i misstag.
 */
defineProps({
    containers: { type: Array, required: true },
    /*
     * Plusknappens mål, ur App\Support\Frontend\CreateTarget (issue 152 ·
     * [[ADR-0048 Mobilen och plusknappen]] § 2), eller null. Här skapar
     * knappen en container — samma formulär som raden i huvudet redan leder
     * till, och samma grind: `ContainerPolicy::create()` på ett av
     * användarens konton.
     */
    create: { type: Object, default: null },
});

const { t } = useTranslations();
const page = usePage();

const accounts = computed(() => page.props.auth?.accounts ?? []);
const activeUlid = computed(() => page.props.activeContainer ?? null);

const accountByUlid = computed(() => Object.fromEntries(accounts.value.map((account) => [account.ulid, account])));

const showsAccountName = computed(() => accounts.value.length > 1);

const accountName = (container) => accountByUlid.value[container.account]?.name ?? null;

const isShared = (container) => accountName(container) === null;
</script>

<template>
    <AppLayout :create="create">
        <Head :title="t('container.index.title')" />

        <div class="flex items-center justify-between gap-4">
            <h1 class="text-2xl font-semibold">{{ t('container.index.heading') }}</h1>

            <Link href="/containers/create" class="inline-flex min-h-11 items-center rounded bg-blue-700 px-4 text-sm font-medium text-white">
                {{ t('container.index.create') }}
            </Link>
        </div>

        <p v-if="containers.length === 0" class="mt-8 text-slate-700">
            {{ t('container.index.empty') }}
        </p>

        <ul v-else class="mt-8 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            <li v-for="container in containers" :key="container.ulid">
                <UiCard class="h-full">
                    <template #media>
                        <div class="aspect-video w-full">
                            <ContainerCover :cover="container.cover" />
                        </div>
                    </template>

                    <!-- Namnlänken går till ITEMLISTAN och inte till översikten
                         (issue 89 · [[ADR-0039 Containerns översikt]]
                         § Konsekvenser). Den menade listan redan före flytten, och
                         den som väljer en container ur listan vill in i den — inte
                         förbi en mellansida. -->
                    <template #heading>
                        <Link
                            :href="`/containers/${container.ulid}/items`"
                            class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
                        >
                            {{ container.name }}
                        </Link>
                    </template>

                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <!-- Arten skrivs ut ORDAGRANT (issue 84 · [[ADR-0036
                             Containerns art]]). Ingen översättningsnyckel byggs ur
                             värdet: `t()` returnerar nyckeln själv när uppslaget
                             misslyckas, så den gamla raden hade skrivit
                             `container.kind.Segelbåt` på skärmen första gången någon
                             skrev en egen art.
                             Spärren frågar om fältet är SATT, aldrig vilket värde det
                             bär — samma behandling som varje annat nullbart fält
                             (`description`), och den domänlogik regeln stänger ute är
                             en förgrening på VILKEN art det är. En rad med en tom art
                             vore ett synligt fel, och "ingen art angiven" är ett
                             tillstånd [[ADR-0036]] § Konsekvenser pekar ut. -->
                        <span v-if="container.kind" class="text-sm text-slate-600">{{ container.kind }}</span>

                        <span v-if="showsAccountName && !isShared(container)" class="text-sm text-slate-600">
                            {{ accountName(container) }}
                        </span>

                        <UiBadge v-if="isShared(container)">
                            {{ t('container.index.shared') }}
                        </UiBadge>

                        <UiBadge v-if="container.ulid === activeUlid" aria-current="true">
                            {{ t('container.index.active') }}
                        </UiBadge>

                        <Link
                            v-if="container.can.update"
                            :href="`/containers/${container.ulid}/edit`"
                            class="inline-flex min-h-11 items-center text-sm text-blue-700 hover:underline"
                        >
                            {{ t('container.index.edit') }}
                        </Link>
                    </div>
                </UiCard>
            </li>
        </ul>

        <!--
            Vägen tillbaka, se issue 62b § Beslut 8. Raden ligger under listan
            och är ALLTID synlig — också för en tom lista, för den som raderat
            sin enda container är den som mest behöver den. Ingen räknare: ett tal
            hade varit en fråga per sidladdning, och texten är konstant.
        -->
        <p class="mt-8 text-sm">
            <Link href="/trash/containers" class="inline-flex min-h-11 items-center text-blue-700 hover:underline">
                {{ t('trash.containers.link') }}
            </Link>
        </p>
    </AppLayout>
</template>
