<script setup>
import { nextTick, ref, watch } from 'vue';
import UiButton from './UiButton.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Bekräftelsedialogen, se [[ADR-0068 Itemets sida och skapandet]] § 6 och
 * [[M45 Itemets sida]] § 330.
 *
 * **Appens egen fråga i stället för `window.confirm`.** Rubrik, brödtext och
 * bekräftelsens etikett är props, färdigöversatta av anroparen: dialogen bär
 * ingen egen mening om vad som frågas, bara formen. Det enda den själv säger
 * är *Cancel*, och det kommer ur `lang/en/ui.php` som allt annat. Komponenten
 * är generell så att containerns och bilagornas radering kan gå över senare;
 * de flyttas inte i den issue som byggde den.
 *
 * **Samma grund som arket** (`UiSheet`): ett `<dialog>` som öppnas med
 * `showModal()`. Fokusfällan är webbläsarens och Esc fyrar webbläsarens
 * `cancel`/`close`, så ingen egen tabbhanterare och inget `aria-modal`.
 *
 * **Cancel har fokus när dialogen öppnas.** `autofocus` på *Cancel* är det
 * `showModal()` läser. Den som trycker Enter i farten avbryter alltså, och
 * den destruktiva vägen kräver ett aktivt val.
 *
 * **Esc och trycket utanför är *Cancel*.** Dialogen täcker hela skärmen, och
 * ytan utanför panelen är en `<button>` och inte en `<div>` med `@click`, som
 * i UiSheet. Den står utanför tabbordningen: tangentbordet har *Cancel*.
 *
 * **Fokus går tillbaka till knappen som öppnade dialogen.** `trigger` är den
 * knappen; saknas den används elementet som hade fokus när dialogen öppnades.
 *
 * **`pending` håller dialogen kvar.** Medan anroparens skrivning pågår är
 * bekräftelsen stängd och byter ord, och varken Esc eller bakgrunden stänger:
 * en dialog som försvinner mitt i en radering ser ut som att den avbröts.
 * Anroparen äger `open` och stänger den när svaret kommit.
 *
 * `tone` är `danger` för en destruktiv fråga och `neutral` för en vanlig.
 * Färgen kommer ur `UiButton`s roller, ingen råfärg står här.
 */
const props = defineProps({
    /* Sant medan dialogen är öppen. Anroparen äger tillståndet. */
    open: { type: Boolean, default: false },
    /* Frågans rubrik, färdigöversatt av anroparen. */
    heading: { type: String, required: true },
    /* Frågans brödtext, färdigöversatt av anroparen. */
    body: { type: String, required: true },
    /* Bekräftelsens etikett, färdigöversatt av anroparen. */
    confirmLabel: { type: String, required: true },
    /* `danger` eller `neutral`. */
    tone: {
        type: String,
        default: 'neutral',
        validator: (value) => ['danger', 'neutral'].includes(value),
    },
    /* Sant medan skrivningen pågår. */
    pending: { type: Boolean, default: false },
    /* Elementet som öppnade dialogen, dit fokus går tillbaka. */
    trigger: { type: Object, default: null },
});

const emit = defineEmits(['confirm', 'cancel']);

const { t } = useTranslations();

const dialogElement = ref(null);

/* Elementet som hade fokus när dialogen öppnades, om `trigger` saknas. */
let opener = null;

watch(
    () => props.open,
    async (open) => {
        if (open) {
            opener = document.activeElement;

            // Innehållet finns i DOM:en redan, men `autofocus` läses av
            // `showModal()` och dialogen ska vara öppen först.
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
function cancel() {
    if (props.pending) {
        return;
    }

    emit('cancel');
}

/*
 * Esc. Webbläsaren stänger dialogen på egen hand om vi låter den; vi
 * stänger den i stället via anroparen, så att `open` och dialogen alltid
 * är överens.
 */
function onEscape(event) {
    event.preventDefault();

    cancel();
}

/*
 * Dialogen är stängd, av vilken väg som helst. Fokus tillbaka till
 * öppnaren — utan den står fokus kvar i en dialog som inte finns.
 */
function onClosed() {
    (props.trigger ?? opener)?.focus?.();
}
</script>

<template>
    <dialog
        ref="dialogElement"
        :aria-label="heading"
        class="m-0 h-full max-h-none w-full max-w-none bg-transparent p-0 backdrop:bg-ink/50"
        @cancel="onEscape"
        @close="onClosed"
    >
        <div class="flex h-full w-full items-center justify-center p-4">
            <!--
                Ytan utanför panelen. Dialogen täcker hela skärmen, så trycket
                utanför landar här och inte i en bakgrund utan mål.
            -->
            <button
                type="button"
                tabindex="-1"
                class="absolute inset-0 cursor-default"
                :aria-label="t('confirm.cancel')"
                @click="cancel"
            ></button>

            <div class="relative flex w-full max-w-sm flex-col gap-3 rounded-card border border-border bg-surface p-4">
                <h2 class="text-title font-semibold text-ink">{{ heading }}</h2>

                <p class="text-body text-ink-muted">{{ body }}</p>

                <div class="mt-2 flex flex-wrap justify-end gap-2">
                    <UiButton
                        variant="secondary"
                        autofocus
                        :disabled="pending"
                        @click="cancel"
                    >
                        {{ t('confirm.cancel') }}
                    </UiButton>

                    <UiButton
                        :variant="tone === 'danger' ? 'danger' : 'primary'"
                        :pending="pending"
                        @click="emit('confirm')"
                    >
                        {{ pending ? t('common.pending.default') : confirmLabel }}
                    </UiButton>
                </div>
            </div>
        </div>
    </dialog>
</template>
