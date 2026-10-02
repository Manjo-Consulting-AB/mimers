<script setup>
import { computed } from 'vue';
import { formatByteSize } from './attachmentPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Lagringsstapeln på containerns dokumentflik, se issue 178 ·
 * [[ADR-0050 Desktopdesignen]] § 15 och `docs/Design/dokument.png`.
 *
 * **Stapeln visar det konto en uppladdning i containern debiteras** (§ 15).
 * Kvoter räknas på det uppladdande kontot, och kontot kommer ur
 * `storage.account` — containerns konto för dess medlem, gästens eget för en
 * gäst. Vilket av dem det är avgörs på servern (App\Http\Controllers\
 * ContainerDocumentController::storage()) efter samma förval som
 * ItemAttachmentSection.vue gör i klienten, och den här filen väljer aldrig
 * ett konto själv: två val av samma konto glider isär, och det ena hade visat
 * en förbrukning som inte är den uppladdningen belastar.
 *
 * **Talet är serverns hela vägen** (Beslut 4). `usedBytes` och `limitBytes`
 * kommer ur `usage_counter.storage_bytes` och `planLimit('storage_bytes')` —
 * samma två läsningar som App\Support\Plan\Entitlements::
 * assertStorageWithinLimit() gör — och `percent` räknas där. Vyn formaterar
 * bytena med `formatByteSize()` och räknar ingenting själv: en kvot räknad i
 * klienten är en andra sanning om samma tal, och den hade glidit isär från
 * det uppladdningen nekas för.
 *
 * **`formatByteSize()` och inte ett eget tal.** Funktionen speglar
 * `Illuminate\Support\Number::fileSize()` rad för rad, så samma fil visar
 * samma storlek här som i kvotmeningen en nekad uppladdning möts av — se
 * attachmentPresentation.js.
 *
 * **Ett obegränsat tak ritar bara förbrukningen** (Beslut 4). `limitBytes` är
 * `null` för ett konto utan tak, och då finns det ingenting att fylla: raden
 * säger vad som är använt och ingen stapel ritas. En stapel mot ett tak som
 * inte finns hade varit ett påhittat mätetal.
 *
 * **Stapeln är `aria-hidden`.** Den är en bild av samma tal som meningen
 * ovanför den redan säger, och en skärmläsare ska höra kvoten EN gång. Att
 * läsa upp "10 procent" efter "2,4 GB av 25 GB" är samma upplysning två
 * gånger.
 *
 * **Ingen sträng i JavaScript** (issue 52 · [[ADR-0013 Språk och i18n]]): de
 * två meningarna kommer ur `container.documents.*` och kontots namn ur datat.
 */
const props = defineProps({
    /*
     * Kontot och dess tal, ur ContainerDocumentController::storage():
     * `{account: {ulid, name}, usedBytes, limitBytes, percent}`. `limitBytes`
     * och `percent` är `null` för ett tak som inte finns.
     */
    storage: { type: Object, required: true },
});

const { t } = useTranslations();

const used = computed(() => formatByteSize(props.storage.usedBytes) ?? '');
const limit = computed(() => (props.storage.limitBytes === null
    ? null
    : formatByteSize(props.storage.limitBytes)));

/*
 * Meningen. Två grenar och ingen tredje: ett tak som finns säger *av*, ett
 * tak som inte finns säger bara vad som är använt.
 */
const label = computed(() => (limit.value === null
    ? t('container.documents.unlimited', { used: used.value })
    : t('container.documents.of', { used: used.value, limit: limit.value })));
</script>

<template>
    <section class="rounded-card border border-border bg-surface p-4">
        <p class="text-meta text-ink-subtle">
            {{ t('container.documents.uploads', { account: storage.account.name }) }}
        </p>

        <p class="mt-1 text-body font-medium text-ink">{{ label }}</p>

        <!-- Stapeln ritas bara när det finns ett tak att fylla. -->
        <div
            v-if="storage.percent !== null"
            class="mt-2 h-2 w-full overflow-hidden rounded-pill bg-surface-sunken"
            aria-hidden="true"
        >
            <div
                class="h-2 rounded-pill bg-accent"
                :style="{ width: `${storage.percent}%` }"
            ></div>
        </div>
    </section>
</template>
