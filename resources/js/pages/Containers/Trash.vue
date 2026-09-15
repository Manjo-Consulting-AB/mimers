<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import TrashRow from '../../components/TrashRow.vue';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Pärmens papperskorg, se issue 62a § Beslut 1, 4, 5, 6 och 7.
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver:
 * `container` ur App\Http\Resources\ContainerResource.
 *
 * **`entries` är `TrashEntryResource`-rader**, samma sex nycklar som
 * `/api/containers/{container}/trash` svarar med — vyn formulerar ingen
 * egen form av en papperskorgspost, den formulerar texten runt den.
 * `canRestore` är uppslaget `ulid → bool` BREDVID raderna (Beslut 6):
 * flaggan bor utanför `TrashEntryResource`, som är delad med `/api`.
 *
 * **Ingen rad räknar rader, och tomt är tomt** (Beslut 5, issue 74
 * § Beslut 1 och issue 73 § Beslut 6). En omfångsbegränsad mottagare ser
 * bara sina egna items och deras bilagor — kategorier och taggar filtreras
 * bort av `App\Actions\Trash\ListTrash` innan de når hit — och en tom
 * papperskorg ger samma mening för henne som för ägaren. Ingen text och
 * inget tal får avslöja att något dolts, och ett utgånget innehåll listas
 * inte alls: vyn säger aldrig att något försvunnit.
 *
 * **Ett domänfel blir en ruta, inte en JSON-kropp** (Beslut 7). Nyckeln är
 * `trash` och inte ett fältnamn: felet handlar inte om vad användaren
 * skrev, och det gäller en rad som redan står i listan — samma mönster som
 * delningssidan valde för en obesvarad inbjudan.
 */
defineProps({
    container: { type: Object, required: true },
    entries: { type: Array, required: true },
    canRestore: { type: Object, required: true },
});

const { t } = useTranslations();
const page = usePage();
</script>

<template>
    <ContainerLayout :container="container">
        <Head :title="t('trash.title')" />

        <h1 class="text-2xl font-semibold">{{ t('trash.heading') }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ t('trash.description') }}</p>

        <p
            v-if="page.props.errors.trash"
            role="alert"
            class="mt-4 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
        >
            {{ page.props.errors.trash }}
        </p>

        <ul v-if="entries.length > 0" class="mt-6 flex flex-col gap-3">
            <TrashRow
                v-for="entry in entries"
                :key="entry.ulid"
                :container-ulid="container.ulid"
                :entry="entry"
                :can-restore="canRestore[entry.ulid] === true"
            />
        </ul>

        <p v-else class="mt-6 text-sm text-slate-600">{{ t('trash.empty') }}</p>
    </ContainerLayout>
</template>
