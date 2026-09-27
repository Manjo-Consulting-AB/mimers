<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import TagColorField from './TagColorField.vue';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * En tagg i listan, med sitt EGET formulär och sin EGEN PATCH — se issue 56a
 * § Beslut 6 och 8.
 *
 * Egen komponent och inte en v-for i Tags.vue, av samma skäl som 53c:s
 * AccountSettingsForm och 55a:s ContainerAccessRow: ett valideringsfel på en
 * rad färgar annars alla andra röda, och ett `form.processing` hade låst varje
 * spara-knapp i listan.
 *
 * **`count` är träffräknaren, och den är per OMFÅNG** (Beslut 6). Talet kommer
 * ur `counts`-propen, som App\Http\Controllers\TagController fyller ur
 * `ListTags::counts()` — en mottagare som når fyra items ser att taggen sitter
 * på två av dem, aldrig att den sitter på nittio. Talet bor INTE i
 * `TagResource`: `/api` har inte bett om det.
 *
 * **Färgen visas som en prick, och `null` är en omålad prick** (Beslut 8).
 * Ingen standardfärg hittas på — se TagColorField.
 *
 * **Ett tomt färgfält betyder "ingen färg", inte ett valideringsfel.** Raden
 * normaliserar `''` till `null` i `transform()` innan den skickar; formen på
 * ett värde är serverns regel, den bor i UpdateTagRequest.
 *
 * **Radera är en <Link method="delete">**, som i ContainerAccessRow. Taggen är
 * platt, så raderingen nekas aldrig och ingen domänfelkod kan komma ur den —
 * ingen felruta hör hit.
 *
 * **Sedan issue 150 frågar raden först när taggen sitter på något.** Raderingen
 * på servern är oförändrad; det är bara frågan som är ny. `router.delete` och
 * inte en `<Link method="delete">`, av samma skäl som ItemAttachmentSection.vue:
 * en bekräftelse måste kunna AVBRYTA navigeringen. Talet är `count`, alltså
 * SAMMA träffräknare som raden redan visar — den är per omfång, och en mottagare
 * som når fyra items får frågan om sina fyra, inte om containerns nittio. En
 * tagg utan items raderas utan fråga, som i dag: där finns ingenting att ångra
 * som användaren inte redan ser.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    tag: { type: Object, required: true },
    count: { type: Number, default: 0 },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

/* ULID:n för den tagg vars radering väntar på svar, annars null — knappen
   stängs medan den väntar, så samma rad inte kan skickas två gånger. */
const pending = ref(null);

/* Fältens id:n måste vara unika i listan — varje rad har samma fältnamn. */
const field = (name) => `tag-${props.tag.ulid}-${name}`;

const form = useForm({
    name: props.tag.name,
    color: props.tag.color,
});

function submit() {
    form
        .transform((data) => ({
            ...data,
            color: data.color === null || data.color === '' ? null : data.color,
        }))
        .patch(`/containers/${props.containerUlid}/tags/${props.tag.ulid}`, {
            preserveScroll: true,
            onError: focusFirstError,
        });
}

function destroy() {
    if (props.count > 0 && ! window.confirm(t('container.tags.destroy_confirm', { count: props.count }))) {
        return;
    }

    router.delete(`/containers/${props.containerUlid}/tags/${props.tag.ulid}`, {
        preserveScroll: true,
        onStart: () => { pending.value = props.tag.ulid; },
        onFinish: () => { pending.value = null; },
    });
}
</script>

<template>
    <li class="flex flex-col gap-3 rounded border border-slate-300 bg-white p-4">
        <p class="flex items-center gap-2 text-sm font-medium text-slate-800">
            <span
                aria-hidden="true"
                class="inline-block h-4 w-4 shrink-0 rounded-full border border-slate-500"
                :style="tag.color ? { backgroundColor: tag.color } : null"
            />
            <span>{{ tag.name }}</span>
            <span class="font-normal text-slate-600">
                {{ t('container.tags.item_count', { count }) }}
            </span>
        </p>

        <form class="flex flex-wrap items-end gap-3" @submit.prevent="submit">
            <FormField
                v-slot="{ describedBy }"
                :label="t('container.tags.name')"
                :id="field('name')"
                :error="form.errors.name"
            >
                <input
                    :id="field('name')"
                    v-model="form.name"
                    :aria-describedby="describedBy"
                    type="text"
                    name="name"
                    required
                    class="rounded border border-slate-300 bg-white px-3 py-2"
                >
            </FormField>

            <TagColorField
                v-model="form.color"
                :id="field('color')"
                :error="form.errors.color"
            />

            <button
                type="submit"
                :disabled="form.processing"
                class="inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
            >
                {{ form.processing ? t('common.pending.default') : t('container.tags.save') }}
            </button>
        </form>

        <button
            type="button"
            :disabled="pending !== null"
            class="inline-flex min-h-11 items-center self-start text-sm text-red-700 underline disabled:opacity-50"
            @click="destroy"
        >
            {{ t('container.tags.destroy') }}
        </button>
    </li>
</template>
