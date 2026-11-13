<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import UiIcon from './UiIcon.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Slutet på en dokumentrad, se issue 373 (M57). Två knappar: *Download* och
 * *More*. *More* öppnar en meny med *Move…*, *Back to Inbox*, *Copy…* och
 * *Delete*. Samma komponent ritas på *Documents* och på itemets bilageflik.
 *
 * Komponenten postar ingenting. Den skickar `move`, `copy`, `inbox` och
 * `destroy`, och sidan behåller sina skrivvägar, sin bekräftelse och sin
 * `ItemTargetPicker`. `move` och `copy` bär ett objekt med `currentTarget` =
 * *More*-knappen: menyn är stängd när väljaren stängs, så fokus ska tillbaka
 * till en knapp som syns och inte till menyraden som doldes.
 *
 * *Download* och *More* delar klasserna ordagrant, så att de har samma
 * typsnitt, storlek och vikt. *Copy…* står på varje rad, så menyn har alltid
 * minst en rad att välja och *More* ritas alltid; en läsare utan `can.delete`
 * ser bara *Copy…*.
 */
const props = defineProps({
    attachment: { type: Object, required: true },
    containerIsInbox: { type: Boolean, default: false },
    /* Sant medan en skrivning på raden pågår; menyvalen är då stängda. */
    pending: { type: Boolean, default: false },
});

const emit = defineEmits(['move', 'copy', 'inbox', 'destroy']);

const { t } = useTranslations();

const BUTTON_CLASSES = 'inline-flex min-h-11 items-center gap-1 rounded-control border border-border px-3 text-sm font-medium text-ink hover:bg-surface-sunken';

const menu = ref(null);
const open = ref(false);

const canDelete = computed(() => props.attachment.can?.delete === true);

const summary = () => menu.value?.querySelector('summary') ?? null;

function choose(name) {
    const trigger = summary();

    open.value = false;

    emit(name, { currentTarget: trigger });
}

function onKeydown(event) {
    if (event.key !== 'Escape' || ! open.value) {
        return;
    }

    open.value = false;
    summary()?.focus();
    event.preventDefault();
    event.stopPropagation();
}

function onDocumentClick(event) {
    if (open.value && menu.value && ! menu.value.contains(event.target)) {
        open.value = false;
    }
}

onMounted(() => document.addEventListener('click', onDocumentClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick));
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <a
            :href="`/files/${attachment.ulid}?download=1`"
            :class="BUTTON_CLASSES"
        >
            <UiIcon name="download" :size="20" class="size-5 shrink-0" />
            {{ t('item.attachment.download') }}
        </a>

        <details
            ref="menu"
            class="relative"
            :open="open"
            @toggle="open = $event.target.open"
            @keydown="onKeydown"
        >
            <summary :class="[BUTTON_CLASSES, 'cursor-pointer list-none']">
                {{ t('item.attachment.more') }}
                <UiIcon name="chevron-down" :size="20" class="size-5 shrink-0" />
            </summary>

            <div class="absolute right-0 z-10 mt-2 w-48 rounded-card border border-border bg-surface p-1 shadow-sm">
                <button
                    v-if="canDelete"
                    type="button"
                    :disabled="pending"
                    class="flex min-h-11 w-full items-center rounded-control px-2 text-left text-sm font-medium text-ink hover:bg-surface-sunken"
                    @click="choose('move')"
                >
                    {{ t('item.attachment.move') }}
                </button>

                <button
                    v-if="canDelete && ! containerIsInbox"
                    type="button"
                    :disabled="pending"
                    class="flex min-h-11 w-full items-center rounded-control px-2 text-left text-sm font-medium text-ink hover:bg-surface-sunken"
                    @click="choose('inbox')"
                >
                    {{ t('todo.back_to_inbox') }}
                </button>

                <button
                    type="button"
                    :disabled="pending"
                    class="flex min-h-11 w-full items-center rounded-control px-2 text-left text-sm font-medium text-ink hover:bg-surface-sunken"
                    @click="choose('copy')"
                >
                    {{ t('item.attachment.copy') }}
                </button>

                <button
                    v-if="canDelete"
                    type="button"
                    :disabled="pending"
                    class="mt-1 flex min-h-11 w-full items-center rounded-control border-t border-border px-2 text-left text-sm font-medium text-danger hover:bg-surface-sunken"
                    @click="choose('destroy')"
                >
                    {{ t('item.attachment.delete') }}
                </button>
            </div>
        </details>
    </div>
</template>
