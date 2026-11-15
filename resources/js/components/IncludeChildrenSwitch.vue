<script setup>
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Växeln *Include child items* ([[ADR-0068 Itemets sida och skapandet]]
 * § Uppföljning 2026-11-14 punkt 1). Komponenten bröts ut ur
 * ScheduleListSection.vue inför att *Documents* fick samma växel — *Costs*
 * och *Loans* återanvänder den sedan — och ritar exakt samma knapp och
 * hjälptext som *Tasks* gjorde: `role="switch"` med `aria-checked`, etiketten
 * i knappen (den blir dess namn), och spåret och knoppen i samma klasser.
 *
 * **Komponenten är ren form.** Den äger inget läge och ingen navigering:
 * `modelValue` kommer ur serverns `includeChildren`, `pending` ur
 * `useIncludeChildren`, och `help` är flikens färdiga mening — *Tasks* säger
 * "tasks" och *Documents* säger "documents" om samma sak. Ett klick emitterar
 * `update:modelValue`, och anroparen vrider läget och skickar det.
 *
 * **Etiketten delas med *Tasks*** (issuens § 5): orden är identiska, och en
 * nyckel som säger samma sak på två ställen glider förr eller senare isär.
 * Därför står `item.attachment.include_children` här och inte i flikarna.
 *
 * Hjälptexten står under etiketten och i linje med den: spåret är 36 px och
 * gapet 8, alltså `pl-11`.
 */
const props = defineProps({
    /* Växelns läge, ur serverns `includeChildren`. */
    modelValue: { type: Boolean, required: true },
    /* Sant medan servern svarar; knappen stängs då (issue 68a § Beslut 4). */
    pending: { type: Boolean, default: false },
    /* Flikens färdiga hjälptext — namnet och ordet ägs av anroparen. */
    help: { type: String, required: true },
});

const emit = defineEmits(['update:modelValue']);

const { t } = useTranslations();

function toggle() {
    emit('update:modelValue', ! props.modelValue);
}
</script>

<template>
    <div>
        <button
            type="button"
            role="switch"
            :aria-checked="modelValue ? 'true' : 'false'"
            :disabled="pending"
            class="mt-4 inline-flex min-h-11 w-fit items-center gap-2 text-meta font-medium text-ink outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
            @click="toggle"
        >
            <span
                aria-hidden="true"
                class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                :class="modelValue ? 'bg-accent' : 'bg-border'"
            >
                <span
                    class="inline-block size-3.5 rounded-full bg-surface transition-transform"
                    :class="modelValue ? 'translate-x-[1.25rem]' : 'translate-x-1'"
                />
            </span>

            <span>{{ t('item.attachment.include_children') }}</span>
        </button>

        <p class="mt-1 pl-11 text-meta text-ink-muted">{{ help }}</p>
    </div>
</template>
