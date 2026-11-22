<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import UiConfirmDialog from './UiConfirmDialog.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Kryssrutan för tvåfaktorkravet, se [[ADR-0092 Tvåfaktor som krav för
 * delade containrar]] och issue 450. Samma ruta på kontonivån
 * (Settings/Security.vue) och på containernivån (Containers/Sharing.vue);
 * anroparen äger texten, adressen och vilka som förlorar åtkomsten.
 *
 * **Valet sparas direkt** (Tonys regel, issue 1245) — ingen *Save*. Slås
 * kravet PÅ och `lacking` har namn, går sparandet via UiConfirmDialog: den
 * listar mottagarna som förlorar åtkomsten direkt. Är listan tom sparas valet
 * utan dialog, och att slå AV kräver ingen bekräftelse.
 *
 * **Ikryssad och låst** (`locked`) är containerns ruta när kontot redan
 * kräver det — en container kan aldrig ha ett lägre krav än kontot. Rutan är
 * då `disabled` med förklaringen i `lockedNote`.
 *
 * **Kryssrutans visade värde följer propen**, inte klicket: ett nekat anrop
 * (ägaren saknar tvåfaktor) ställer tillbaka rutan, och felet står under den.
 * Servern är grinden; `needsOwn` är bara formatering som säger varför det inte
 * går, i stället för att ägaren upptäcker det som ett fel.
 */
const props = defineProps({
    id: { type: String, required: true },
    label: { type: String, required: true },
    url: { type: String, required: true },
    /* Serverns värde. */
    checked: { type: Boolean, required: true },
    /* Extra fält i PATCH-kroppen, t.ex. `account`. */
    data: { type: Object, default: () => ({}) },
    /* Namn på dem som förlorar åtkomsten när kravet slås på. */
    lacking: { type: Array, default: () => [] },
    /* Ikryssad och låst, med raden som förklarar. */
    locked: { type: Boolean, default: false },
    lockedNote: { type: String, default: '' },
    /* Ägaren saknar egen tvåfaktor: raden ersätter kryssrutan. */
    needsOwn: { type: Boolean, default: false },
    needsOwnNote: { type: String, default: '' },
    error: { type: String, default: null },
});

const { t } = useTranslations();

const confirming = ref(false);
const saving = ref(false);
const trigger = ref(null);
const input = ref(null);

const confirmBody = computed(
    () => `${t('sharing.two_factor.confirm_body')} ${props.lacking.map((person) => person.name).join(', ')}`,
);

function save(value) {
    saving.value = true;

    router.patch(
        props.url,
        { ...props.data, require_two_factor: value },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                saving.value = true;
            },
            onFinish: () => {
                saving.value = false;
                confirming.value = false;

                // Propen bär serverns värde; ställ tillbaka rutan om inget sparades.
                if (input.value) {
                    input.value.checked = props.checked || props.locked;
                }
            },
        },
    );
}

function toggle(event) {
    const wanted = event.target.checked;

    if (wanted && props.lacking.length > 0) {
        event.target.checked = props.checked;
        trigger.value = event.target;
        confirming.value = true;

        return;
    }

    save(wanted);
}
</script>

<template>
    <div class="mt-4 flex flex-col gap-1">
        <p v-if="needsOwn" class="rounded-card border border-border bg-surface px-4 py-2 text-body text-ink-muted">
            {{ needsOwnNote }}
        </p>

        <template v-else>
            <label :for="id" class="flex min-h-11 items-center gap-3 text-body text-ink">
                <input
                    :id="id"
                    ref="input"
                    type="checkbox"
                    class="h-5 w-5"
                    :checked="checked || locked"
                    :disabled="locked || saving"
                    :aria-describedby="locked ? `${id}-note` : undefined"
                    @change="toggle"
                >
                <span>{{ label }}</span>
            </label>

            <p v-if="locked" :id="`${id}-note`" class="text-meta text-ink-muted">{{ lockedNote }}</p>
        </template>

        <p v-if="error" role="alert" class="text-meta text-danger">{{ error }}</p>

        <UiConfirmDialog
            :open="confirming"
            :heading="t('sharing.two_factor.confirm_heading')"
            :body="confirmBody"
            :confirm-label="t('sharing.two_factor.confirm_action')"
            tone="danger"
            :pending="saving"
            :trigger="trigger"
            @confirm="save(true)"
            @cancel="confirming = false"
        />
    </div>
</template>
