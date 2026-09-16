<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import { parentOptions } from './categoryTree.js';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Formuläret för en NY kategori — se issue 56a § Beslut 1, 2 och 3.
 *
 * `parent` är ett eget val och `null` betyder "lägg i roten": POST skapar alltså
 * både rotkategorier och underkategorier ur samma formulär. Valet är en väljare
 * och inte drag-and-drop, av skälen i CategoryRow.
 *
 * **Ingen `position`.** Den nya kategorin hamnar sist bland sina syskon:
 * servern sätter `max(position) + 1` när fältet utelämnas
 * (App\Actions\Category\CreateCategory). Att fylla i en position vid skapandet
 * är att sortera innan raden finns, och den som vill flytta den gör det i
 * raden efteråt.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    categories: { type: Array, required: true },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const options = computed(() => parentOptions(props.categories));

const form = useForm({
    name: '',
    parent: null,
});

function submit() {
    form.post(`/containers/${props.containerUlid}/categories`, {
        preserveScroll: true,
        onError: focusFirstError,
        // Formuläret blir stående: den som bygger ett träd skapar sällan en
        // nod i taget, och ett tömt fält är billigare än ett omskrivet namn.
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <form class="flex max-w-lg flex-col gap-4" @submit.prevent="submit">
        <FormField
            v-slot="{ describedBy }"
            :label="t('container.categories.name')"
            id="category-create-name"
            :error="form.errors.name"
        >
            <input
                id="category-create-name"
                v-model="form.name"
                :aria-describedby="describedBy"
                type="text"
                name="name"
                required
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('container.categories.parent')"
            id="category-create-parent"
            :error="form.errors.parent"
        >
            <select
                id="category-create-parent"
                v-model="form.parent"
                :aria-describedby="describedBy"
                name="parent"
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
                <option :value="null">{{ t('container.categories.parent_root') }}</option>
                <option v-for="option in options" :key="option.ulid" :value="option.ulid">
                    {{ option.name }}
                </option>
            </select>
        </FormField>

        <button
            type="submit"
            :disabled="form.processing"
            class="inline-flex min-h-11 items-center self-start rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
        >
            {{ form.processing ? t('common.pending.default') : t('container.categories.create') }}
        </button>
    </form>
</template>
