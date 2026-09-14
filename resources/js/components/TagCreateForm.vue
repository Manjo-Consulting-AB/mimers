<script setup>
import { useForm } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import TagColorField from './TagColorField.vue';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Formuläret för en NY tagg — se issue 56a § Beslut 6, 7 och 8.
 *
 * `color` börjar som `null`: en ny tagg har ingen färg förrän användaren ger
 * den en. Ingen standardfärg, se TagColorField.
 *
 * **Ett namn som redan finns som AKTIV tagg ger ett valideringsfel på `name`**
 * — regeln bor i StoreTagRequest och ritas av FormField. Finns namnet på en
 * MJUKRADERAD tagg är det inget fel: App\Actions\Tag\CreateTag återupplivar
 * den raden med sin gamla ULID (issue 12 § Beslut 4), och det är hela
 * avvikelsen från ren CRUD.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const form = useForm({
    name: '',
    color: null,
});

function submit() {
    form
        .transform((data) => ({
            ...data,
            color: data.color === null || data.color === '' ? null : data.color,
        }))
        .post(`/containers/${props.containerUlid}/tags`, {
            preserveScroll: true,
            onError: focusFirstError,
            // Formuläret blir stående: taggar läggs sällan in en i taget.
            onSuccess: () => form.reset(),
        });
}
</script>

<template>
    <form class="flex max-w-lg flex-col gap-4" @submit.prevent="submit">
        <FormField
            v-slot="{ describedBy }"
            :label="t('container.tags.name')"
            id="tag-create-name"
            :error="form.errors.name"
        >
            <input
                id="tag-create-name"
                v-model="form.name"
                :aria-describedby="describedBy"
                type="text"
                name="name"
                required
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
        </FormField>

        <TagColorField v-model="form.color" id="tag-create-color" :error="form.errors.color" />

        <button
            type="submit"
            :disabled="form.processing"
            class="self-start rounded bg-blue-700 px-4 py-2 font-medium text-white disabled:opacity-50"
        >
            {{ t('container.tags.create') }}
        </button>
    </form>
</template>
