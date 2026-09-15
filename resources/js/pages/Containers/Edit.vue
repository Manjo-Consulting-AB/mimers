<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import FormField from '../../components/FormField.vue';
import { useTranslations } from '../../composables/useTranslations.js';
import { useErrorFocus } from '../Auth/useErrorFocus.js';

/*
 * Pärmens inställningar, se issue 54 § Beslut 7, 8 och 9 och issue 62b
 * § Beslut 4, 5 och 6.
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver:
 * `container` ur App\Http\Resources\ContainerResource.
 *
 * EN PATCH mot /containers/{ulid}, och bara `name` och `kind` —
 * `UpdateContainerRequest` tar inte emot något annat, så ett `account`-fält
 * här hade varit en yta som inte gör något. Att flytta en pärm mellan konton
 * är ägarbyte (issue 39), inte en inställning.
 *
 * Ingen egen ägarkontouppgift i vyn: den här sidan handlar om pärmen, och
 * delningsstatus hör till listan.
 *
 * Typ-listan kommer som prop ur `Container::KINDS` (Beslut 8) — samma lista
 * som validerar, aldrig en avskrift i JavaScript. `kind` är presentation och
 * bara presentation: ingen gren i den här vyn läser värdet.
 *
 * **Raderingsknappen kom med 62b § Beslut 4 och bor HÄR, aldrig i listan.**
 * Det här är sidan där man ändrar pärmen, och därför också där man tar bort
 * den; en raderingsknapp i listan, bredvid *Gör aktiv*, är en felklickning
 * från att pärmen försvinner. Den ritas ur `can.delete`, som kontrollern
 * räknar med samma grind som rutten prövar (Beslut 6) — flaggan är
 * presentation, och en delegerad åtkomst som postar förbi vyn får 403.
 *
 * **Bekräftelsen är `window.confirm` med serverns mening ur `lang/`**
 * (Beslut 5), samma mönster som itemets radering (57b § Beslut 8) — ingen
 * modal komponent. Meningen bär pärmens namn och säger tre saker: att allt i
 * pärmen följer med, att den ligger kvar i papperskorgen i 30 dagar, och att
 * den går att återställa därifrån. Den säger INTE "raderas permanent", vilket
 * vore osant — raderingen är mjuk (issue 8).
 */
const props = defineProps({
    container: { type: Object, required: true },
    kinds: { type: Array, required: true },
    can: { type: Object, required: true },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const form = useForm({
    name: props.container.name,
    kind: props.container.kind,
});

function submit() {
    form.patch(`/containers/${props.container.ulid}`, { onError: focusFirstError });
}

function destroy() {
    if (! window.confirm(t('container.destroy.confirm', { name: props.container.name }))) {
        return;
    }

    router.delete(`/containers/${props.container.ulid}`);
}
</script>

<template>
    <ContainerLayout :container="container">
        <Head :title="t('container.edit.title')" />

        <h1 class="text-2xl font-semibold">{{ t('container.edit.heading') }}</h1>

        <form class="mt-8 flex max-w-lg flex-col gap-4" @submit.prevent="submit">
            <FormField
                v-slot="{ describedBy }"
                :label="t('container.edit.name')"
                id="name"
                :error="form.errors.name"
            >
                <input
                    id="name"
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
                :label="t('container.edit.kind')"
                id="kind"
                :error="form.errors.kind"
            >
                <select
                    id="kind"
                    v-model="form.kind"
                    :aria-describedby="describedBy"
                    name="kind"
                    class="rounded border border-slate-300 bg-white px-3 py-2"
                >
                    <option v-for="kind in kinds" :key="kind" :value="kind">
                        {{ t(`container.kind.${kind}`) }}
                    </option>
                </select>
            </FormField>

            <button
                type="submit"
                :disabled="form.processing"
                class="self-start rounded bg-blue-700 px-4 py-2 font-medium text-white disabled:opacity-50"
            >
                {{ t('container.edit.submit') }}
            </button>
        </form>

        <div v-if="can.delete" class="mt-12 border-t border-slate-200 pt-6">
            <button
                type="button"
                class="rounded border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50"
                @click="destroy"
            >
                {{ t('container.destroy.action') }}
            </button>
        </div>
    </ContainerLayout>
</template>
