<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import { parentOptions } from './categoryTree.js';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * En nod i kategoriträdet, med sitt EGET formulär och sin EGEN PATCH — se
 * issue 56a § Beslut 1, 3 och 5.
 *
 * Egen komponent och inte en v-for i Categories.vue, av samma skäl som
 * 53c:s AccountSettingsForm och 55a:s ContainerAccessRow: ett valideringsfel
 * på en rad färgar annars alla andra röda, och ett `form.processing` hade låst
 * varje spara-knapp i trädet.
 *
 * **En flytt är två fält, inte drag-and-drop** (Beslut 3). Att ändra förälder
 * är en väljare över pärmens övriga kategorier, position ett heltalsfält.
 * Inget dragbibliotek: det vore ett nytt beroende (AGENTS.md § Nya beroenden),
 * det kräver tangentbordsstöd som issue 68 annars får städa, och det löser ett
 * problem trädet inte har vid fem nivåer.
 *
 * **`parent` skickas ALLTID**, också när den är `null` — se
 * App\Http\Controllers\CategoryController::update() och Beslut 5. Väljaren har
 * alltid ett valt värde, så servern anropar `MoveCategory` villkorslöst.
 *
 * Väljaren erbjuder inte kategorin själv eller någon av dess ättlingar, men
 * filtreringen är en artighet: `MoveCategory` prövar cykeln och djupet på
 * servern, och dess svar är det som gäller (Beslut 3).
 *
 * **Radera är en <Link method="delete">**, inte ett eget formulär: rutten är en
 * DELETE och Inertia skickar CSRF-tokenet åt oss, samma mönster som
 * ContainerAccessRow. Ingen knapp döljs — behörighetskontroller görs i
 * policies, aldrig genom att gömma en knapp. Nekas raderingen hamnar felet på
 * formulärnyckeln `category` och ritas av sidan, inte här: felpåsen kan inte
 * säga vilken rad det gäller.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    category: { type: Object, required: true },
    categories: { type: Array, required: true },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

/* Fältens id:n måste vara unika i trädet — varje nod har samma fältnamn. */
const field = (name) => `category-${props.category.ulid}-${name}`;

const options = computed(() => parentOptions(props.categories, props.category.ulid));

const form = useForm({
    name: props.category.name,
    position: props.category.position,
    parent: props.category.parent,
});

function submit() {
    form.patch(`/containers/${props.containerUlid}/categories/${props.category.ulid}`, {
        preserveScroll: true,
        onError: focusFirstError,
    });
}
</script>

<template>
    <div class="flex flex-col gap-3 rounded border border-slate-300 bg-white p-4">
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="submit">
            <FormField
                v-slot="{ describedBy }"
                :label="t('container.categories.name')"
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

            <FormField
                v-slot="{ describedBy }"
                :label="t('container.categories.parent')"
                :id="field('parent')"
                :error="form.errors.parent"
            >
                <select
                    :id="field('parent')"
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

            <FormField
                v-slot="{ describedBy }"
                :label="t('container.categories.position')"
                :id="field('position')"
                :error="form.errors.position"
            >
                <input
                    :id="field('position')"
                    v-model="form.position"
                    :aria-describedby="describedBy"
                    type="number"
                    name="position"
                    class="w-24 rounded border border-slate-300 bg-white px-3 py-2"
                >
            </FormField>

            <button
                type="submit"
                :disabled="form.processing"
                class="rounded bg-blue-700 px-4 py-2 font-medium text-white disabled:opacity-50"
            >
                {{ t('container.categories.save') }}
            </button>
        </form>

        <Link
            :href="`/containers/${containerUlid}/categories/${category.ulid}`"
            method="delete"
            as="button"
            preserve-scroll
            class="self-start text-sm text-red-700 underline"
        >
            {{ t('container.categories.destroy') }}
        </Link>
    </div>
</template>
