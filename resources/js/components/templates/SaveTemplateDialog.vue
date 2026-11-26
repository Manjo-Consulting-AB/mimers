<script setup>
import { nextTick, ref, watch } from 'vue';
import UiButton from '../UiButton.vue';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Frågan om mallens namn, se issue 422 och [[ADR-0089 Egna fält på items]]
 * § Riktning 6.
 *
 * **Ett `<dialog>` med webbläsarens fokusfälla**, samma form som
 * UiConfirmDialog: `showModal()` håller fokus i rutan medan den är öppen, och
 * Esc fyrar webbläsarens `close`. Ingen egen tabbhanterare och inget
 * `aria-modal`.
 *
 * Komponenten äger bara FORMEN. Namnet fylls i här, men anroparen äger
 * skrivningen och `open`: Edit.vue postar formuläret och stänger rutan när
 * servern svarat. `pending` håller rutan kvar medan anropet är i luften — en
 * dialog som försvinner mitt i en skrivning ser ut som att den avbröts.
 *
 * Ett fältfel (`template.limit_reached`) ritas här och inte i en flash:
 * frågan handlar om namnet och kontots tak, och svaret ska stå där frågan
 * ställdes.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /* Elementet som öppnade dialogen, dit fokus går tillbaka. */
    trigger: { type: Object, default: null },
    pending: { type: Boolean, default: false },
    /* Serverns mening om namnet, eller null. */
    error: { type: String, default: null },
    /* Containern har regler och personen får skriva dem: kryssrutan visas. */
    canIncludeAgentRules: { type: Boolean, default: false },
});

const emit = defineEmits(['save', 'close']);

const { t } = useTranslations();

const dialogElement = ref(null);
const name = ref('');
const includeAgentRules = ref(true);

/* Elementet som hade fokus när dialogen öppnades, om `trigger` saknas. */
let opener = null;

watch(
    () => props.open,
    async (open) => {
        if (open) {
            name.value = '';
            includeAgentRules.value = true;
            opener = document.activeElement;

            // Innehållet finns i DOM:en redan, men dialogen ska vara öppen
            // innan fältet fokuseras.
            await nextTick();

            dialogElement.value?.showModal();

            return;
        }

        if (dialogElement.value?.open) {
            dialogElement.value.close();
        }
    },
);

/*
 * Avbrottet, oavsett väg in: knappen, trycket utanför och Esc. Medan en
 * skrivning pågår avbryts inget.
 */
function close() {
    if (props.pending) {
        return;
    }

    emit('close');
}

function onEscape(event) {
    event.preventDefault();

    close();
}

function onClosed() {
    (props.trigger ?? opener)?.focus?.();
}

function submit() {
    const trimmed = name.value.trim();

    if (trimmed === '') {
        return;
    }

    emit('save', { name: trimmed, includeAgentRules: props.canIncludeAgentRules ? includeAgentRules.value : false });
}
</script>

<template>
    <dialog
        ref="dialogElement"
        :aria-label="t('container.templates.save_dialog_heading')"
        class="m-0 h-full max-h-none w-full max-w-none bg-transparent p-0 backdrop:bg-ink/50"
        @cancel="onEscape"
        @close="onClosed"
    >
        <div class="flex h-full w-full items-center justify-center p-4">
            <!-- Trycket utanför panelen: dialogen täcker hela skärmen, så
                 ytan utanför är ett element och inte en bakgrund utan mål. -->
            <button
                type="button"
                tabindex="-1"
                class="absolute inset-0 cursor-default"
                :aria-label="t('confirm.cancel')"
                @click="close"
            ></button>

            <form
                class="relative flex w-full max-w-sm flex-col gap-3 rounded-card border border-border bg-surface p-4"
                @submit.prevent="submit"
            >
                <h2 class="text-title font-semibold text-ink">
                    {{ t('container.templates.save_dialog_heading') }}
                </h2>

                <p class="text-body text-ink-muted">{{ t('container.templates.save_description') }}</p>

                <div class="flex flex-col gap-1">
                    <label for="template-name" class="text-sm font-medium text-ink">
                        {{ t('container.templates.name') }}
                    </label>

                    <input
                        id="template-name"
                        v-model="name"
                        type="text"
                        name="name"
                        required
                        maxlength="255"
                        class="rounded border border-border bg-surface px-3 py-2"
                    >
                </div>

                <label v-if="canIncludeAgentRules" for="template-include-agent-rules" class="flex min-h-11 items-center gap-2 text-sm text-ink">
                    <input id="template-include-agent-rules" v-model="includeAgentRules" type="checkbox" name="include_agent_rules">
                    {{ t('container.templates.include_agent_rules') }}
                </label>

                <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>

                <div class="mt-2 flex flex-wrap justify-end gap-2">
                    <UiButton variant="secondary" :disabled="pending" @click="close">
                        {{ t('confirm.cancel') }}
                    </UiButton>

                    <UiButton type="submit" :pending="pending">
                        {{ pending ? t('common.pending.default') : t('container.templates.save_confirm') }}
                    </UiButton>
                </div>
            </form>
        </div>
    </dialog>
</template>
