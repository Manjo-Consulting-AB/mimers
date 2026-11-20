<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import ItemVersionDialog from './ItemVersionDialog.vue';
import ItemVersionRow from './ItemVersionRow.vue';
import UiConfirmDialog from './UiConfirmDialog.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Avsnittet *Earlier versions* på itemets *History*-flik, se issue 413 ·
 * [[ADR-0088 Versionshistorik för items]] Riktning 5.
 *
 * **Nyast först och en rad per version.** Listan kommer färdig ur
 * ItemController::show — `versions` finns bara när fliken är aktiv, och är
 * tom när historiken är avstängd — så avsnittet ritar aldrig något när det
 * inte finns något att visa. Servern äger ordningen (`versions()` sorterar
 * `created_at` fallande) och vyn sorterar aldrig om.
 *
 * **En rad öppnar versionen i dialogen**, och *Restore* står i dialogen
 * bakom `can.update` — samma grind som webbens rutt prövar. Frågan ställs i
 * `UiConfirmDialog` innan något skrivs; en återställning skriver om det som
 * står där nu, och frågan säger att nuläget först sparas som en ny version.
 *
 * **Efteråt kommer kvittot från servern**: en flash-kod (`flash.status`) och
 * `restoredSkipped`, de fält som inte längre fanns och därför lämnades
 * orörda. De räknas upp här, med samma ord som API:ets `meta.skipped` bär.
 */
const props = defineProps({
    /* Versionerna, nyast först, ur ItemVersionResource. */
    versions: { type: Array, required: true },
    /* Det nuvarande itemet ur ItemResource, att jämföra fälten mot. */
    item: { type: Object, required: true },
    /* `{update, delete, create}` — återställningen ritas bakom `update`. */
    can: { type: Object, required: true },
    /* Containern, för ruttens adress. */
    container: { type: Object, required: true },
    /* Kategoriernas namn, ULID → namn. */
    categories: { type: Object, required: true },
    /* Omslagsbildens ULID som itemet pekar på NU, eller null. */
    itemCover: { type: String, default: null },
    /* De fält en återställning hoppade över, ur sessionen. */
    restoredSkipped: { type: Array, default: () => [] },
});

const { t } = useTranslations();

const openVersion = ref(null);
const restoreVersion = ref(null);
const restoring = ref(false);
const trigger = ref(null);

function show(payload) {
    trigger.value = payload.event?.currentTarget ?? null;
    openVersion.value = payload.version;
}

function closeDialog() {
    openVersion.value = null;
}

/*
 * Frågan ställs i stället för versionen: två modala `<dialog>` ovanpå
 * varandra är en fälla, och den första stängs därför innan den andra öppnas.
 */
function askRestore() {
    restoreVersion.value = openVersion.value;
    openVersion.value = null;
}

function cancelRestore() {
    if (!restoring.value) {
        restoreVersion.value = null;
    }
}

function confirmRestore() {
    if (restoreVersion.value === null) {
        return;
    }

    restoring.value = true;

    router.post(
        `/containers/${props.container.ulid}/items/${props.item.ulid}/versions/${restoreVersion.value.ulid}/restore`,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                restoring.value = true;
            },
            onFinish: () => {
                restoring.value = false;
                restoreVersion.value = null;
                openVersion.value = null;
            },
        },
    );
}

/* De hoppade fälten, med samma ord som API:ets `meta.skipped` räknar upp. */
const skippedText = computed(
    () => props.restoredSkipped
        .map((field) => t(`item.version.skipped.${field}`))
        .join(', '),
);
</script>

<template>
    <div v-if="versions.length > 0" class="mb-8">
        <h2 class="text-sm font-medium text-ink-muted">{{ t('item.version.heading') }}</h2>

        <p v-if="restoredSkipped.length > 0" class="mt-2 text-body text-ink-muted" role="status">
            {{ t('item.version.restored_skipped', { fields: skippedText }) }}
        </p>

        <ul class="mt-2">
            <ItemVersionRow
                v-for="version in versions"
                :key="version.ulid"
                :version="version"
                @open="show"
            />
        </ul>

        <ItemVersionDialog
            :open="openVersion !== null"
            :version="openVersion"
            :item="item"
            :categories="categories"
            :item-cover="itemCover"
            :can-update="can.update"
            :pending="restoring"
            @close="closeDialog"
            @restore="askRestore"
        />

        <UiConfirmDialog
            :open="restoreVersion !== null"
            :heading="t('item.version.restore_heading')"
            :body="t('item.version.restore_body')"
            :confirm-label="t('item.version.restore')"
            :pending="restoring"
            :trigger="trigger"
            @confirm="confirmRestore"
            @cancel="cancelRestore"
        />
    </div>
</template>
