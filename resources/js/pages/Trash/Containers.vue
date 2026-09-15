<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import TrashRow from '../../components/TrashRow.vue';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Papperskorgen för raderade pärmar, se issue 62b § Beslut 1, 3, 7 och 8.
 *
 * **Toppnivå och AppLayout, inte ContainerLayout.** Sidan kan inte ligga i en
 * pärm: pärmen är raderad, och en layout som kräver propen `container` hade
 * krävt en pärm som inte finns. Det är samma skäl som gör att rutten ligger
 * på `/trash/containers` och inte under `{container}` (Beslut 1).
 *
 * **`entries` är `TrashEntryResource`-rader**, samma sex nycklar som `/api`
 * svarar med — vyn formulerar ingen egen form av en papperskorgspost, den
 * formulerar texten runt den. `canRestore` är uppslaget `ulid → bool` BREDVID
 * raderna (Beslut 3): flaggan bor utanför `TrashEntryResource`, som är delad
 * med `/api`.
 *
 * **Raden är `TrashRow`, samma komponent som 62a:s pärmpapperskorg använder**
 * (Beslut 7). 20c § Beslut 3 lovade att en klient som ritar papperskorgen
 * skulle kunna använda samma komponent för båda listorna, och det är den här
 * raden som infriar det: bara målet för återställningen skiljer sig — och
 * raden härleder det ur `entry.type`, som är `container` för varje post i den
 * här listan. Sidan skickar alltså varken URL eller kropp; den ritar listan.
 *
 * **En tom lista säger att papperskorgen är tom** (Beslut 7) och räknar
 * ingenting. Listan är redan begränsad till ägarkontots medlemmar, så det
 * finns inget dolt att antyda något om — till skillnad från 62a, där en
 * omfångsbegränsad mottagare kunde se en tom lista över en pärm som inte var
 * tom.
 *
 * Ett valideringsfel på `ulid` visas som en ruta: `RestoreContainerRequest`
 * delas med `/api`, och en ULID som inte ligger i papperskorgen är ett
 * fältfel. Det kan bara hända en klient som postar förbi vyn — men en knapp
 * som inte gör någonting alls är värre än en mening.
 */
defineProps({
    entries: { type: Array, required: true },
    canRestore: { type: Object, required: true },
});

const { t } = useTranslations();
const page = usePage();
</script>

<template>
    <AppLayout>
        <Head :title="t('trash.containers.title')" />

        <h1 class="text-2xl font-semibold">{{ t('trash.containers.heading') }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ t('trash.containers.description') }}</p>

        <p
            v-if="page.props.errors.ulid"
            role="alert"
            class="mt-4 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
        >
            {{ page.props.errors.ulid }}
        </p>

        <ul v-if="entries.length > 0" class="mt-6 flex flex-col gap-3">
            <TrashRow
                v-for="entry in entries"
                :key="entry.ulid"
                :entry="entry"
                :can-restore="canRestore[entry.ulid] === true"
            />
        </ul>

        <p v-else class="mt-6 text-sm text-slate-600">{{ t('trash.containers.empty') }}</p>

        <p class="mt-8 text-sm">
            <Link href="/containers" class="text-blue-700 hover:underline">
                {{ t('trash.containers.back') }}
            </Link>
        </p>
    </AppLayout>
</template>
