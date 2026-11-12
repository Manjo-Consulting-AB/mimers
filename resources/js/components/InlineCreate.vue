<script setup>
import { nextTick, ref, watch } from 'vue';
import UiConfirmDialog from './UiConfirmDialog.vue';
import UiIcon from './UiIcon.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Det infällbara formuläret, se [[ADR-0074 Registrering i vyn]] § 1–3 ·
 * M52 · issue 356.
 *
 * **Presentation och inget annat.** Skalet vet ingenting om uppgifter, items
 * eller filer. Vyn äger `open` och `dirty` och lägger sitt formulär i
 * standardslotten; skalet fäller ut det, flyttar fokus och frågar innan
 * osparade ändringar kastas.
 *
 * **Stängt läge** är en rå `<button type="button">` med `aria-expanded`
 * (samma skäl som `Tasks/Create.vue`: GenomgangTest tillåter bara webbläsarens
 * egna element som klickbar yta). Öppet läge ritar slotten i en ruta med
 * samma formspråk, och det första fokuserbara elementet får fokus.
 *
 * **Escape i rutan** stänger direkt när `dirty` är falskt. Är den sann öppnas
 * bekräftelsedialogen: *Discard* stänger, *Keep editing* lämnar rutan öppen.
 * Tangenten läses med `@keydown` och en nyckelkontroll: GenomgangTest förbjuder
 * `@keydown.esc`-modifierare, eftersom `<dialog>` äger fokusfällan.
 *
 * **Fokus återgår till knappen** när rutan stängts, på vilket sätt det än sker.
 *
 * **Övergången** är en höjdövergång med `grid-template-rows` från `0fr` till
 * `1fr`, högst 200 ms. Den stängda ytan är `inert`, så ingenting i den nås
 * med tabb eller skärmläsare.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /* Radens text i stängt läge, färdigöversatt av vyn. */
    label: { type: String, required: true },
    /* Sant när formuläret har osparade ändringar. */
    dirty: { type: Boolean, default: false },
});

const emit = defineEmits(['update:open']);

const { t } = useTranslations();

const openButton = ref(null);
const box = ref(null);
const confirming = ref(false);

function focusFirst() {
    box.value?.querySelector('input, select, textarea, button')?.focus();
}

function focusButton() {
    openButton.value?.focus();
}

watch(
    () => props.open,
    async (open) => {
        await nextTick();

        if (open) {
            focusFirst();

            return;
        }

        confirming.value = false;
        focusButton();
    },
);

function onKeydown(event) {
    if (event.key !== 'Escape') {
        return;
    }

    event.stopPropagation();

    if (props.dirty) {
        confirming.value = true;

        return;
    }

    emit('update:open', false);
}

function discard() {
    confirming.value = false;
    emit('update:open', false);
}

/* Dialogens fokus tillbaka: rutan om den lever kvar, annars knappen. */
const dialogTrigger = {
    focus() {
        if (props.open) {
            focusFirst();
        } else {
            focusButton();
        }
    },
};
</script>

<template>
    <div>
        <button
            v-if="!open"
            ref="openButton"
            type="button"
            aria-expanded="false"
            class="flex min-h-11 w-full items-center gap-2 rounded-control border border-border bg-surface px-3 text-left text-body text-ink-muted outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus"
            @click="emit('update:open', true)"
        >
            <UiIcon name="add" :size="16" class="shrink-0" />
            {{ label }}
        </button>

        <div
            class="grid transition-[grid-template-rows] duration-200 motion-reduce:transition-none"
            :class="open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
            :inert="!open"
        >
            <div class="min-h-0 overflow-hidden">
                <div
                    ref="box"
                    class="rounded-control border border-border bg-surface p-3"
                    @keydown="onKeydown"
                >
                    <slot />
                </div>
            </div>
        </div>

        <UiConfirmDialog
            :open="confirming"
            :heading="t('inline_create.discard.heading')"
            :cancel-label="t('inline_create.discard.cancel')"
            :confirm-label="t('inline_create.discard.confirm')"
            :trigger="dialogTrigger"
            @confirm="discard"
            @cancel="confirming = false"
        />
    </div>
</template>
