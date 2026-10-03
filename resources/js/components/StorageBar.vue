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
 * **Stapeln och procenten är `aria-hidden`.** De är en bild av samma tal som
 * meningen ovanför dem redan säger, och en skärmläsare ska höra kvoten EN
 * gång. Att läsa upp "10 procent" efter "2,4 GB av 25 GB" är samma upplysning
 * två gånger. Procenten står till höger om stapeln och ritas bara när det
 * finns ett tak att fylla — samma gren som stapeln.
 *
 * **Databasikonen till vänster är dekor och inget mer.** Den säger vad rutan
 * handlar om och bär därför `aria-hidden`: meningen intill namnger kontot och
 * talen, och en skärmläsare ska inte höra en ikon beskriva dem.
 *
 * **Ingen sträng i JavaScript** (issue 52 · [[ADR-0013 Språk och i18n]]): de
 * tre meningarna kommer ur `container.documents.*` och kontots namn ur datat.
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
        <div class="flex items-start gap-3">
            <!-- Databasikonen: vad rutan handlar om, och ingenting en
                 skärmläsare behöver höra. -->
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                class="size-5 shrink-0 text-ink-subtle"
                aria-hidden="true"
            >
                <ellipse cx="12" cy="5" rx="9" ry="3" />
                <path d="M3 5v14c0 1.66 4.03 3 9 3s9-1.34 9-3V5" />
                <path d="M3 12c0 1.66 4.03 3 9 3s9-1.34 9-3" />
            </svg>

            <div class="min-w-0 flex-1">
                <p class="text-meta text-ink-subtle">
                    {{ t('container.documents.uploads', { account: storage.account.name }) }}
                </p>

                <p class="mt-1 text-body font-medium text-ink">{{ label }}</p>

                <!-- Stapeln och procenten ritas bara när det finns ett tak att
                     fylla. Båda är `aria-hidden`: meningen ovanför säger redan
                     kvoten, och procenten är samma tal en gång till. -->
                <div v-if="storage.percent !== null" class="mt-2 flex items-center gap-2">
                    <div
                        class="h-2 flex-1 overflow-hidden rounded-pill bg-surface-sunken"
                        aria-hidden="true"
                    >
                        <div
                            class="h-2 rounded-pill bg-accent"
                            :style="{ width: `${storage.percent}%` }"
                        ></div>
                    </div>

                    <span class="text-meta text-ink-subtle" aria-hidden="true">
                        {{ t('container.documents.percent', { percent: storage.percent }) }}
                    </span>
                </div>
            </div>
        </div>
    </section>
</template>
