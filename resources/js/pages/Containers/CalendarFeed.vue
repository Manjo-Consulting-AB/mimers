<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import CalendarFeedRow from '../../components/CalendarFeedRow.vue';
import SecretOnce from '../../components/SecretOnce.vue';
import { useTranslations } from '../../composables/useTranslations.js';
import { useErrorFocus } from '../Auth/useErrorFocus.js';

/*
 * Pärmens kalenderlänk, se issue 65b § Beslut 1, 2 och 4.
 *
 * Sidan svarar på EN fråga — "hur får jag pärmens uppgifter in i kalendern
 * jag redan tittar i?" — och har tre delar i den ordningen en användare möter
 * dem: adressen om den just skapades, knappen som skapar en ny, och listan
 * över de länkar som finns.
 *
 * **Adressen visas EN gång.** `url` kommer ur redirectens flash via
 * App\Http\Controllers\CalendarFeedController::index() och är `null` vid
 * varje annan visning. Den renderas av SecretOnce — som text, aldrig som en
 * `<a href>` — och den som tappat bort den återkallar och skapar en ny
 * (Beslut 2). Att ha kvar den i en komponent, en cookie eller localStorage
 * vore att göra en engångshemlighet beständig, samma regel som 53b:s
 * återställningskoder följer.
 *
 * **Listan visar aldrig adressen**, bara att länken finns: när den skapades,
 * när den senast hämtades och om den är återkallad (CalendarFeedRow).
 *
 * **Ingen behörighetsflagga.** Alla tre rutterna grindas av `view` på pärmen —
 * den som ser sidan får skapa och återkalla, för feeden visar inget hon inte
 * redan kan se (36a § Beslut 4) — så det finns inget svar att rita olika för
 * två användare.
 *
 * Knappen är en POST utan kropp, precis som `/api`:s store(): feeden är bara
 * (pärm, användare, token). Ingen klientvalidering och inget eget felmeddelande
 * — det finns inget fält att fylla i fel.
 */
const props = defineProps({
    /* Pärmen ur App\Http\Resources\ContainerResource. */
    container: { type: Object, required: true },

    /* Användarens egna feeder, ur App\Http\Resources\CalendarFeedResource. */
    feeds: { type: Array, required: true },

    /* Klartextadressen ur flashen — bara satt direkt efter ett skapande. */
    url: { type: String, default: null },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const form = useForm({});

function create() {
    form.post(`/containers/${props.container.ulid}/calendar`, {
        onError: focusFirstError,
    });
}
</script>

<template>
    <ContainerLayout :container="props.container">
        <Head :title="t('calendar.title')" />

        <h1 class="text-2xl font-semibold">{{ t('calendar.heading') }}</h1>
        <p class="mt-2 text-sm text-slate-700">{{ t('calendar.intro') }}</p>

        <SecretOnce
            v-if="props.url"
            :label="t('calendar.url_label')"
            :value="props.url"
            :description="t('calendar.url_description')"
            :once="t('calendar.url_once')"
            :copy-label="t('calendar.copy')"
            :copied-label="t('calendar.copied')"
        />

        <form class="mt-8" @submit.prevent="create">
            <button
                type="submit"
                :disabled="form.processing"
                class="inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
            >
                {{ form.processing ? t('common.pending.default') : t('calendar.create') }}
            </button>
        </form>

        <section class="mt-8">
            <h2 class="text-lg font-semibold">{{ t('calendar.list_heading') }}</h2>

            <p v-if="props.feeds.length === 0" class="mt-2 text-sm text-slate-700">
                {{ t('calendar.empty') }}
            </p>

            <ul v-else class="mt-2 flex flex-col gap-2">
                <CalendarFeedRow
                    v-for="feed in props.feeds"
                    :key="feed.ulid"
                    :container-ulid="props.container.ulid"
                    :feed="feed"
                />
            </ul>
        </section>
    </ContainerLayout>
</template>
