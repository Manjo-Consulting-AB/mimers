<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FieldTypeSelect from './FieldTypeSelect.vue';
import FormField from '../FormField.vue';
import UiButton from '../UiButton.vue';
import UiConfirmDialog from '../UiConfirmDialog.vue';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * En rad i containerns *Fields*-lista, se issue 419 och [[ADR-0089 Egna fält
 * på items]] § Riktning 1–4.
 *
 * **Egen komponent med sin egen PATCH**, av samma skäl som CategoryRow: ett
 * valideringsfel på en rad ska inte färga de andra, och en rad som sparar ska
 * inte låsa listan. Rubriken ändras med en PATCH som bär `label` och `type`;
 * ordningen ändras aldrig här — den har sin egen PATCH i sidan, för det är
 * listan och inte raden som byter plats.
 *
 * **Typen är låst när fältet har värden** (§ Riktning 3). Låset prövas på
 * servern (`field.type_locked`) och förklaras här: väljaren är avstängd och
 * meningen under raden säger varför. Ett grått val utan orsak ser ut som ett
 * fel.
 *
 * **Raderingen frågar först**, med UiConfirmDialog och text ur `lang/`.
 * Fältet går till papperskorgen och värdet följer med — frågan säger det, så
 * trycket inte är ett oåterkalleligt misstag ([[ADR-0008 Soft delete och
 * papperskorg]]).
 *
 * **Draget är radens egen platta, inte hela raden.** Handtaget är en
 * `<span draggable="true">` med `aria-hidden` — tangentbordet har
 * upp- och nedknapparna i sidan, och en knapp utan handling hade varit ett
 * tabbsteg som inte gör något. Riktningen (vilken rad som flyttas var) ägs av
 * sidan, som också skickar ordningen.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    /* Fältet ur ItemFieldResource: `{ulid, label, type, position, …}`. */
    field: { type: Object, required: true },
    /* Fältets typer, ur `types`-propen (ItemField::TYPES). */
    types: { type: Array, required: true },
    /* Har fältet minst ett värde? Då är typvalet låst. */
    hasValues: { type: Boolean, default: false },
    /* Det generella felmeddelandet för DEN HÄR raden, eller null. Sidan äger
       vilken rad som får rita det — felpåsen har ingen radidentitet. */
    error: { type: String, default: null },
    /* Sant när det är den här raden som skickades senast: bara då ritas
       `label`- och `type`-felen här. */
    showErrors: { type: Boolean, default: false },
    /* Sant medan raden väntar på svar från en sortering. */
    pending: { type: Boolean, default: false },
});

const emit = defineEmits(['submit', 'move', 'dragstart', 'drop']);

const { t } = useTranslations();

/* Fältens id:n måste vara unika i listan — varje rad har samma fältnamn. */
const field = (name) => `field-${props.field.ulid}-${name}`;

const form = useForm({
    label: props.field.label,
    type: props.field.type,
});

/* Ett fel på `label` eller `type` hör till den rad som senast skickades;
   övriga rader ska se samma felpåse men inte rita den. */
const labelError = computed(() => (props.showErrors ? (form.errors.label ?? null) : null));
const typeError = computed(() => (props.showErrors ? (form.errors.type ?? null) : null));

/* Dialogen ägs här och öppnas av raderingens knapp; fokus går tillbaka dit. */
const confirmOpen = ref(false);
const confirmTrigger = ref(null);
const deleting = ref(false);

function submit() {
    emit('submit', props.field.ulid);

    /* Ett fält med värden byter aldrig typ: servern hade svarat
       `field.type_locked` på det, och ett avstängt val ska inte skickas. */
    const data = props.hasValues
        ? { label: form.label }
        : { label: form.label, type: form.type };

    form.transform(() => data)
        .patch(`/containers/${props.containerUlid}/fields/${props.field.ulid}`, {
            preserveScroll: true,
            onFinish: () => form.transform((values) => values),
        });
}

/* Draget: webbläsaren kräver ett dataobjekt för att starta det (Firefox
   vägrar annars), och `move` säger vad som händer med raden. */
function dragStart(event) {
    event.dataTransfer?.setData('text/plain', props.field.ulid);

    if (event.dataTransfer !== null) {
        event.dataTransfer.effectAllowed = 'move';
    }

    emit('dragstart', props.field.ulid);
}

function openConfirm(event) {
    confirmTrigger.value = event.currentTarget;
    confirmOpen.value = true;
}

function destroy() {
    deleting.value = true;

    form.delete(`/containers/${props.containerUlid}/fields/${props.field.ulid}`, {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            confirmOpen.value = false;
        },
    });
}
</script>

<template>
    <li
        class="flex flex-col gap-3 rounded-control border border-border bg-surface p-4"
        @dragover.prevent
        @drop.prevent="emit('drop', field.ulid)"
    >
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="submit">
            <!-- Handtaget: draget börjar här och inte på hela raden, så
                 textmarkering i rubrikfältet fungerar som vanligt. -->
            <span
                draggable="true"
                aria-hidden="true"
                class="flex min-h-11 cursor-grab items-center px-1 text-ink-subtle"
                :title="t('container.fields.drag')"
                @dragstart="dragStart"
            >⠿</span>

            <FormField
                v-slot="{ describedBy }"
                :label="t('container.fields.label')"
                :id="field('label')"
                :error="labelError"
            >
                <input
                    :id="field('label')"
                    v-model="form.label"
                    :aria-describedby="describedBy"
                    type="text"
                    name="label"
                    required
                    maxlength="100"
                    class="min-h-11 rounded-control border border-border bg-surface px-3 py-2 text-body text-ink outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                >
            </FormField>

            <FormField
                v-slot="{ describedBy }"
                :label="t('container.fields.type')"
                :id="field('type')"
                :error="typeError"
            >
                <FieldTypeSelect
                    :id="field('type')"
                    v-model="form.type"
                    :types="types"
                    :described-by="describedBy"
                    :disabled="hasValues"
                />
            </FormField>

            <UiButton type="submit" :pending="form.processing || pending">
                {{ form.processing ? t('common.pending.default') : t('container.fields.save') }}
            </UiButton>
        </form>

        <p v-if="hasValues" class="text-meta text-ink-muted">
            {{ t('container.fields.type_locked') }}
        </p>

        <div class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                class="inline-flex min-h-11 items-center justify-center rounded-control border border-border bg-surface px-3 text-meta font-medium text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                :aria-label="t('container.fields.move_up')"
                @click="emit('move', field.ulid, -1)"
            >
                ↑
            </button>

            <button
                type="button"
                class="inline-flex min-h-11 items-center justify-center rounded-control border border-border bg-surface px-3 text-meta font-medium text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                :aria-label="t('container.fields.move_down')"
                @click="emit('move', field.ulid, 1)"
            >
                ↓
            </button>

            <button
                ref="confirmTrigger"
                type="button"
                class="inline-flex min-h-11 items-center justify-center rounded-control border border-border bg-surface px-3 text-meta font-medium text-danger outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                @click="openConfirm"
            >
                {{ t('container.fields.destroy') }}
            </button>
        </div>

        <!-- Ett domänfel ur en nekad skrivning — gränsen eller rubrikkrocken —
             har ingen egen fältnyckel på servern och ritas därför här, på den
             rad sidan pekat ut (samma mönster som CategoryRow). -->
        <p
            v-if="error"
            role="alert"
            class="rounded border border-danger bg-surface px-3 py-2 text-meta text-danger"
        >
            {{ error }}
        </p>

        <UiConfirmDialog
            :open="confirmOpen"
            :heading="t('container.fields.destroy_heading', { label: field.label })"
            :body="t('container.fields.destroy_body')"
            :confirm-label="t('container.fields.destroy_confirm')"
            :pending="deleting"
            :trigger="confirmTrigger"
            tone="danger"
            @confirm="destroy"
            @cancel="confirmOpen = false"
        />
    </li>
</template>
