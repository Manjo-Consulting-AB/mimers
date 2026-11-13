<script setup>
import { nextTick, ref } from 'vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Bildvisaren och PDF-visaren (M57 · issue 372), utbruten ur
 * ItemAttachmentSection.vue så att bilagefliken och containerns Documents-flik
 * använder samma dialog.
 *
 * Ett enda `<dialog>` per sida och inte ett per rad: raden är en länk, dialogen
 * är en yta, och tio dolda dialoger hade varit tio element som ingen ser.
 *
 * `attachment` är raden som visas (`null` när visaren är stängd) och `preview`
 * är dess `attachmentPreview()`-objekt. Föräldern äger raden: `open()` ber den
 * sätta raden genom `update:attachment`, väntar på `nextTick` och kör
 * `showModal()`, och stängningen sätter den till `null` på samma väg.
 *
 * Fokus går tillbaka till `trigger` — <dialog> gör det av sig själv, men bara
 * till ett element som fortfarande finns, och det vet bara den här filen.
 * Därför sätts fokus uttryckligen i stället för att litas på.
 *
 * Bilden ritas som förut — källan är `medium` och originalet när den varianten
 * saknas. En PDF ritas i SAMMA dialog, i en ram mot filoriginet, och ramen
 * skapas först när dialogen öppnas — ingen förfrågan mot filoriginet förrän
 * användaren ber om den.
 */
defineProps({
    attachment: { type: Object, default: null },
    preview: { type: Object, default: null },
});

const emit = defineEmits(['update:attachment']);

const { t } = useTranslations();

const viewerTrigger = ref(null);
const viewerElement = ref(null);

async function open(attachment, trigger) {
    viewerTrigger.value = trigger ?? null;
    emit('update:attachment', attachment);

    // Innehållet är `v-if="attachment"` och finns alltså inte i DOM:en förrän
    // Vue har patchat. `autofocus` läses av `showModal()`, inte av webbläsaren
    // senare, så utan väntan landar första fokus på nedladdningslänken.
    await nextTick();

    viewerElement.value?.showModal();
}

/*
 * Stänger visaren. Anropas av stängknappen; Esc går samma väg genom dialogens
 * `close`-händelse, som webbläsaren fyrt av även då.
 */
function closeViewer() {
    viewerElement.value?.close();
}

function onViewerClosed() {
    emit('update:attachment', null);
    viewerTrigger.value?.focus();
    viewerTrigger.value = null;
}

defineExpose({ open });
</script>

<template>
    <dialog
        ref="viewerElement"
        class="m-auto max-h-[90vh] max-w-full overflow-auto rounded border border-border bg-surface p-4 backdrop:bg-shell/50"
        :class="attachment && preview?.frame ? 'w-[min(64rem,95vw)]' : ''"
        @close="onViewerClosed"
    >
        <div
            v-if="attachment && preview"
            class="flex flex-col gap-3"
            :class="preview.frame ? 'w-full' : 'max-w-3xl'"
        >
            <h3 class="text-lg font-semibold">
                {{ preview.frame ? t('item.attachment.pdf_viewer_heading') : t('item.attachment.viewer_heading') }}
            </h3>

            <!--
                Ramen bär ingen isolering: det är 61a:s CSP som stänger av
                skriptet i dokumentet, och en sådan hade slagit av
                webbläsarens egen läsare med. `title` är filnamnet ur datan.
            -->
            <iframe
                v-if="preview.frame"
                :src="preview.frame"
                :title="attachment.filename"
                class="h-[75vh] w-full rounded border border-border"
            ></iframe>

            <img
                v-else
                :src="preview.image"
                :alt="attachment.filename"
                class="max-h-[70vh] max-w-full object-contain"
            >

            <p v-if="preview.frame" class="text-sm text-ink-muted">{{ t('item.attachment.pdf_fallback') }}</p>

            <div class="flex flex-wrap items-center gap-3">
                <span class="font-medium text-ink">{{ attachment.filename }}</span>

                <a
                    :href="`/files/${attachment.ulid}?download=1`"
                    class="inline-flex min-h-11 items-center font-medium text-accent hover:underline"
                >
                    {{ t('item.attachment.download') }}
                </a>

                <!--
                    Stängningen är returvägen ut och det minst ingripande
                    elementet i ytan. Utan `autofocus` landar första fokus på
                    nedladdningslänken, och första Enter startar en
                    nedladdning användaren inte bad om.
                -->
                <button
                    type="button"
                    autofocus
                    class="ml-auto inline-flex min-h-11 items-center text-sm font-medium text-ink-muted hover:underline"
                    @click="closeViewer"
                >
                    {{ t('item.attachment.viewer_close') }}
                </button>
            </div>
        </div>
    </dialog>
</template>
